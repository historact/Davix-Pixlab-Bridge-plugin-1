<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Events {
    protected $client;
    protected $db;

    public function __construct( DSB_Client $client, DSB_DB $db ) {
        $this->client = $client;
        $this->db     = $db;
    }

    public function init(): void {
        add_action( 'wps_sfw_after_renewal_payment', [ $this, 'handle_wps_renewal' ], 10, 2 );
        add_action( 'wps_sfw_expire_subscription_scheduler', [ $this, 'handle_wps_expire' ], 10, 1 );
        add_action( 'wps_sfw_subscription_cancel', [ $this, 'handle_wps_cancel' ], 10, 1 );

        add_action( 'woocommerce_checkout_order_processed', [ $this, 'handle_checkout' ], 10, 3 );
        add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_change' ], 10, 4 );
    }

    public function handle_wps_renewal( $subscription_id, $order_id = null ): void {
        $order = $order_id ? wc_get_order( $order_id ) : null;
        $payload = $this->build_payload( (string) $subscription_id, 'renewed', $order );
        $this->maybe_send( $payload );
    }

    public function handle_wps_expire( $subscription_id ): void {
        $payload = $this->build_payload( (string) $subscription_id, 'expired' );
        $this->maybe_send( $payload );
    }

    public function handle_wps_cancel( $subscription_id ): void {
        $payload = $this->build_payload( (string) $subscription_id, 'cancelled' );
        $this->maybe_send( $payload );
    }

    public function handle_checkout( $order_id, $posted_data, $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $subscription_id = $this->find_subscription_id_for_order( $order );
        $payload         = $this->build_payload( $subscription_id ? (string) $subscription_id : '', $subscription_id ? 'activated' : 'activated_pending_subscription_id', $order );
        if ( $payload && $this->order_contains_mapped_product( $order ) ) {
            $this->maybe_send( $payload );
        }
    }

    public function handle_order_status_change( $order_id, $old_status, $new_status, $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $subscription_id = $this->find_subscription_id_for_order( $order );
        if ( ! $subscription_id ) {
            $this->db->log_event(
                [
                    'event'         => 'subscription_missing',
                    'order_id'      => $order->get_id(),
                    'error_excerpt' => __( 'Subscription ID missing; event skipped.', 'davix-sub-bridge' ),
                ]
            );
            return;
        }
        $event = $this->map_status_to_event( $new_status );
        if ( ! $event ) {
            return;
        }
        $payload = $this->build_payload( (string) $subscription_id, $event, $order );
        $this->maybe_send( $payload );
    }

    protected function maybe_send( ?array $payload ): void {
        if ( ! $payload ) {
            return;
        }
        $plans    = $this->client->get_product_plans();
        if ( empty( $payload['plan_slug'] ) || ! $this->plan_exists( $payload['plan_slug'], $plans ) ) {
            $this->db->log_event(
                [
                    'event'         => 'plan_missing',
                    'customer_email'=> $payload['customer_email'] ?? '',
                    'order_id'      => $payload['order_id'] ?? '',
                    'subscription_id'=> $payload['subscription_id'] ?? '',
                    'plan_slug'     => $payload['plan_slug'] ?? '',
                    'error_excerpt' => __( 'Plan mapping missing; event skipped.', 'davix-sub-bridge' ),
                ]
            );
            add_action( 'admin_notices', static function () {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'Davix Bridge: plan mapping missing for last subscription event.', 'davix-sub-bridge' ) . '</p></div>';
            } );
            return;
        }
        $this->client->send_event( $payload );
    }

    protected function plan_exists( string $plan_slug, array $plans ): bool {
        return in_array( $plan_slug, array_values( $plans ), true );
    }

    protected function map_status_to_event( string $status ): ?string {
        $status = strtolower( $status );
        switch ( $status ) {
            case 'completed':
            case 'processing':
            case 'active':
                return 'activated';
            case 'cancelled':
            case 'refunded':
            case 'trash':
                return 'cancelled';
            case 'failed':
                return 'payment_failed';
            case 'expired':
                return 'expired';
            default:
                return null;
        }
    }

    protected function find_subscription_id_for_order( \WC_Order $order ): string {
        $meta_keys = [ 'wps_sfw_subscription_id', 'subscription_id', '_subscription_id' ];
        foreach ( $meta_keys as $meta_key ) {
            $value = $order->get_meta( $meta_key );
            if ( $value ) {
                return (string) $value;
            }
        }
        return '';
    }

    protected function order_contains_mapped_product( \WC_Order $order ): bool {
        $plans = $this->client->get_product_plans();
        foreach ( $order->get_items() as $item ) {
            $pid = $item->get_variation_id() ?: $item->get_product_id();
            if ( isset( $plans[ $pid ] ) ) {
                return true;
            }
        }
        $this->db->log_event(
            [
                'event'         => 'plan_missing',
                'order_id'      => $order->get_id(),
                'customer_email'=> $order->get_billing_email(),
                'error_excerpt' => __( 'No mapped plan for order products; skipping.', 'davix-sub-bridge' ),
            ]
        );
        return false;
    }

    public function build_payload( string $subscription_id, string $event, ?\WC_Order $order = null ): ?array {
        $plan_slug = '';
        $customer_email = '';
        $order_id = '';
        $product_id = 0;

        if ( '' === $subscription_id ) {
            $this->db->log_event(
                [
                    'event'         => 'subscription_missing',
                    'order_id'      => $order ? $order->get_id() : '',
                    'error_excerpt' => __( 'Subscription ID missing; event skipped.', 'davix-sub-bridge' ),
                ]
            );
            return null;
        }

        if ( $order ) {
            $customer_email = $order->get_billing_email();
            $order_id       = $order->get_id();
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_variation_id() ?: $item->get_product_id();
                break;
            }
        }

        if ( ! $customer_email && $subscription_id ) {
            $user_id = get_post_meta( $subscription_id, 'user_id', true );
            if ( $user_id ) {
                $user = get_user_by( 'id', (int) $user_id );
                if ( $user ) {
                    $customer_email = $user->user_email;
                }
            }
        }

        $plans = $this->client->get_product_plans();
        if ( $product_id && isset( $plans[ $product_id ] ) ) {
            $plan_slug = $plans[ $product_id ];
        }

        if ( ! $plan_slug && $subscription_id ) {
            $plan_slug = get_post_meta( $subscription_id, '_dsb_plan_slug', true );
            if ( ! $plan_slug ) {
                $plan_slug = get_post_meta( $subscription_id, 'wps_sfw_plan_slug', true );
            }
        }

        if ( ! $plan_slug && $product_id ) {
            $this->db->log_event(
                [
                    'event'           => 'plan_missing',
                    'order_id'        => $order_id,
                    'subscription_id' => $subscription_id,
                    'customer_email'  => $customer_email,
                    'error_excerpt'   => __( 'No plan mapping found for product.', 'davix-sub-bridge' ),
                ]
            );
            return null;
        }

        if ( ! $customer_email ) {
            $this->db->log_event(
                [
                    'event'           => 'customer_missing',
                    'order_id'        => $order_id,
                    'subscription_id' => $subscription_id,
                    'error_excerpt'   => __( 'Customer email missing; event skipped.', 'davix-sub-bridge' ),
                ]
            );
            return null;
        }

        return [
            'event'           => $event,
            'customer_email'  => $customer_email,
            'plan_slug'       => $plan_slug,
            'subscription_id' => $subscription_id,
            'order_id'        => $order_id,
        ] + $this->maybe_add_validity_window( $event, $product_id, $order, $subscription_id, $customer_email, $plan_slug );
    }

    protected function maybe_add_validity_window( string $event, int $product_id, ?\WC_Order $order, string $subscription_id, string $customer_email, string $plan_slug ): array {
        $eligible_events = [ 'activated', 'activated_pending_subscription_id', 'renewed' ];
        if ( ! in_array( $event, $eligible_events, true ) ) {
            return [];
        }

        $interval = $this->get_product_expiry_interval( $product_id );
        if ( ! $interval ) {
            return [];
        }

        $valid_from  = null;
        $valid_until = null;

        if ( 'renewed' === $event ) {
            $existing = $this->fetch_current_validity( $subscription_id, $customer_email );
            if ( isset( $existing['valid_from'] ) ) {
                $valid_from = $this->format_datetime_for_node( $existing['valid_from'] );
            }
            if ( isset( $existing['valid_until'] ) ) {
                $valid_until = $this->format_datetime_for_node( $existing['valid_until']->add( $interval ) );
            } elseif ( isset( $existing['valid_from'] ) ) {
                $valid_until = $this->format_datetime_for_node( $existing['valid_from']->add( $interval ) );
            }

            if ( ! $valid_from ) {
                $activation = $this->determine_activation_time( $order );
                if ( $activation ) {
                    $valid_from = $this->format_datetime_for_node( $activation );
                }
            }

            if ( ! $valid_until && $valid_from ) {
                $start_dt = $this->parse_datetime_string( $valid_from );
                if ( $start_dt ) {
                    $valid_until = $this->format_datetime_for_node( $start_dt->add( $interval ) );
                }
            }
        } else {
            $activation = $this->determine_activation_time( $order );
            if ( $activation ) {
                $valid_until = $this->format_datetime_for_node( $activation->add( $interval ) );
            }
        }

        $payload = [];
        if ( $valid_from ) {
            $payload['valid_from'] = $valid_from;
        }
        if ( $valid_until ) {
            $payload['valid_until'] = $valid_until;
        }

        return $payload;
    }

    protected function get_product_expiry_interval( int $product_id ): ?\DateInterval {
        if ( $product_id <= 0 ) {
            return null;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product instanceof \WC_Product ) {
            return null;
        }

        $value_keys = [
            'wps_sfw_subscription_expiry_interval',
            'wps_sfw_subscription_expiry_value',
            'wps_sfw_subs_expiry_interval',
            '_subscription_expiry_interval',
            'wps_sfw_expiry_interval',
        ];
        $unit_keys  = [
            'wps_sfw_subscription_expiry_interval_type',
            'wps_sfw_subscription_expiry_unit',
            'wps_sfw_subs_expiry_interval_type',
            '_subscription_expiry_period',
            'wps_sfw_subscription_expiry_type',
            'wps_sfw_expiry_unit',
        ];

        $interval_value = null;
        foreach ( $value_keys as $key ) {
            $value = $product->get_meta( $key, true );
            if ( '' !== $value && null !== $value && is_numeric( $value ) ) {
                $interval_value = (int) $value;
                break;
            }
        }

        if ( ! $interval_value || $interval_value <= 0 ) {
            return null;
        }

        $unit = '';
        foreach ( $unit_keys as $key ) {
            $candidate = $product->get_meta( $key, true );
            if ( $candidate ) {
                $unit = $this->normalize_interval_unit( (string) $candidate );
                if ( $unit ) {
                    break;
                }
            }
        }

        if ( ! $unit ) {
            $unit = $this->normalize_interval_unit( $this->detect_billing_period( $product ) );
        }

        if ( ! $unit ) {
            return null;
        }

        try {
            switch ( $unit ) {
                case 'day':
                    return new \DateInterval( 'P' . $interval_value . 'D' );
                case 'week':
                    return new \DateInterval( 'P' . $interval_value . 'W' );
                case 'month':
                    return new \DateInterval( 'P' . $interval_value . 'M' );
                case 'year':
                    return new \DateInterval( 'P' . $interval_value . 'Y' );
                default:
                    return null;
            }
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    protected function normalize_interval_unit( string $unit ): string {
        $unit = strtolower( trim( $unit ) );
        switch ( $unit ) {
            case 'day':
            case 'days':
                return 'day';
            case 'week':
            case 'weeks':
                return 'week';
            case 'month':
            case 'months':
            case 'monthly':
                return 'month';
            case 'year':
            case 'years':
            case 'yearly':
            case 'annually':
                return 'year';
            default:
                return '';
        }
    }

    protected function detect_billing_period( \WC_Product $product ): string {
        $candidates = [
            $product->get_meta( 'wps_sfw_subscription_interval_type', true ),
            $product->get_meta( 'wps_sfw_subscription_period', true ),
            $product->get_meta( '_subscription_period', true ),
            $product->get_meta( 'wps_sfw_billing_period', true ),
        ];

        foreach ( $candidates as $value ) {
            $period = strtolower( (string) $value );
            if ( in_array( $period, [ 'month', 'monthly' ], true ) ) {
                return 'month';
            }
            if ( in_array( $period, [ 'year', 'yearly', 'annual', 'annually' ], true ) ) {
                return 'year';
            }
        }

        return '';
    }

    protected function determine_activation_time( ?\WC_Order $order ): ?\DateTimeImmutable {
        if ( $order ) {
            $dt_candidates = [ $order->get_date_completed(), $order->get_date_paid(), $order->get_date_created() ];
            foreach ( $dt_candidates as $dt ) {
                if ( $dt instanceof \WC_DateTime ) {
                    return ( new \DateTimeImmutable( '@' . $dt->getTimestamp() ) )->setTimezone( wp_timezone() );
                }
            }
        }

        try {
            return new \DateTimeImmutable( 'now', wp_timezone() );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    protected function format_datetime_for_node( \DateTimeInterface $dt ): string {
        return DSB_Util::to_iso_utc( $dt );
    }

    protected function parse_datetime_string( $value ): ?\DateTimeImmutable {
        if ( empty( $value ) ) {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable( is_string( $value ) ? $value : '' );
            return $dt;
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    protected function fetch_current_validity( string $subscription_id, string $customer_email ): array {
        if ( '' === $subscription_id && '' === $customer_email ) {
            return [];
        }

        $identity = [];
        if ( $subscription_id ) {
            $identity['subscription_id'] = $subscription_id;
        }
        if ( $customer_email ) {
            $identity['customer_email'] = $customer_email;
        }

        $result = $this->client->user_summary( $identity );
        if ( is_wp_error( $result['response'] ?? null ) || 200 !== ( $result['code'] ?? 0 ) || ( $result['decoded']['status'] ?? '' ) !== 'ok' ) {
            return [];
        }

        $key = $result['decoded']['key'] ?? $result['decoded'];
        $valid_from  = $this->parse_datetime_string( $key['valid_from'] ?? null );
        $valid_until = $this->parse_datetime_string( $key['valid_until'] ?? null );

        $payload = [];
        if ( $valid_from ) {
            $payload['valid_from'] = $valid_from;
        }
        if ( $valid_until ) {
            $payload['valid_until'] = $valid_until;
        }

        return $payload;
    }
}
