<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Events {
    const ORDER_META_SUBSCRIPTION_ID = '_dsb_subscription_id';
    const ORDER_META_RETRY_COUNT     = '_dsb_subid_retry_count';
    const ORDER_META_RETRY_LOCK      = '_dsb_subid_retry_lock';
    const ORDER_META_LAST_SENT_EVENT = '_dsb_last_sent_event';
    const MAX_RETRY_ATTEMPTS         = 5;
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

        add_action( 'wps_sfw_after_created_subscription', [ $this, 'handle_wps_subscription_created' ], 10, 2 );
        add_action( 'wps_sfw_subscription_order', [ $this, 'handle_wps_subscription_order_created' ], 10, 2 );
        add_action( 'wps_sfw_subscription_process_checkout', [ $this, 'handle_wps_subscription_process_checkout' ], 10, 3 );

        add_action( 'woocommerce_checkout_order_processed', [ $this, 'handle_checkout' ], 10, 3 );
        add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status_change' ], 10, 4 );

        add_action( 'dsb_retry_provision_order', [ $this, 'retry_provision_order' ], 10, 2 );
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

    public function handle_wps_subscription_created( $subscription_id, $order_id ): void {
        dsb_log( 'info', 'WPS subscription created hook fired', [ 'subscription_id' => $subscription_id, 'order_id' => $order_id ] );

        if ( ! $subscription_id || ! $order_id ) {
            return;
        }

        $order = wc_get_order( (int) $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $this->set_subscription_id_on_order( $order, (string) $subscription_id );
        $event = $this->map_status_to_event( $order->get_status() ) ?: 'activated';

        if ( $this->order_contains_mapped_product( $order ) ) {
            $payload = $this->build_payload( (string) $subscription_id, $event, $order );
            $result  = $this->maybe_send( $payload );

            if ( $result && ( $result['decoded']['status'] ?? '' ) === 'ok' ) {
                $this->mark_event_sent( $order, $event );
                $this->clear_retry_state( $order );
            }
        }
    }

    public function handle_wps_subscription_order_created( $subscription, $order_id ): void {
        dsb_log( 'info', 'WPS subscription order hook fired', [ 'subscription' => $subscription, 'order_id' => $order_id ] );

        $subscription_id = $this->extract_subscription_id_from_unknown( $subscription );
        if ( ! $subscription_id && is_numeric( $order_id ) ) {
            $order          = wc_get_order( (int) $order_id );
            $subscription_id = $order instanceof \WC_Order ? $this->find_subscription_id_for_order( $order ) : '';
        }

        if ( ! $subscription_id || ! $order_id ) {
            if ( is_numeric( $order_id ) ) {
                $order = wc_get_order( (int) $order_id );
                if ( $order instanceof \WC_Order ) {
                    $this->schedule_retry_if_needed( $order, 'activated' );
                }
            }
            return;
        }

        $order = wc_get_order( (int) $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $this->set_subscription_id_on_order( $order, (string) $subscription_id );

        if ( $this->order_contains_mapped_product( $order ) ) {
            $payload = $this->build_payload( (string) $subscription_id, 'activated', $order );
            $result  = $this->maybe_send( $payload );

            if ( $result && ( $result['decoded']['status'] ?? '' ) === 'ok' ) {
                $this->mark_event_sent( $order, 'activated' );
                $this->clear_retry_state( $order );
            }
        }
    }

    public function handle_wps_subscription_process_checkout( $order_id, $posted_data, $subscription ): void {
        dsb_log( 'info', 'WPS subscription checkout hook fired', [ 'subscription' => $subscription, 'order_id' => $order_id ] );

        $subscription_id = $this->extract_subscription_id_from_unknown( $subscription );
        $order           = is_numeric( $order_id ) ? wc_get_order( (int) $order_id ) : null;

        if ( $order instanceof \WC_Order ) {
            if ( $subscription_id ) {
                $this->set_subscription_id_on_order( $order, (string) $subscription_id );
            } else {
                $this->schedule_retry_if_needed( $order, 'activated' );
            }
        }

        if ( $subscription_id && $order instanceof \WC_Order && $this->order_contains_mapped_product( $order ) ) {
            $payload = $this->build_payload( (string) $subscription_id, 'activated', $order );
            $result  = $this->maybe_send( $payload );

            if ( $result && ( $result['decoded']['status'] ?? '' ) === 'ok' ) {
                $this->mark_event_sent( $order, 'activated' );
                $this->clear_retry_state( $order );
            }
        }
    }

    public function handle_checkout( $order_id, $posted_data, $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $subscription_id = $this->find_subscription_id_for_order( $order );
        if ( ! $subscription_id ) {
            $this->schedule_retry_if_needed( $order, 'activated' );
        }
        $payload = $this->build_payload( $subscription_id ? (string) $subscription_id : '', $subscription_id ? 'activated' : 'activated_pending_subscription_id', $order );
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
            $this->schedule_retry_if_needed( $order, $this->map_status_to_event( $new_status ) ?: 'activated' );
            return;
        }
        $event = $this->map_status_to_event( $new_status );
        if ( ! $event ) {
            return;
        }
        $payload = $this->build_payload( (string) $subscription_id, $event, $order );
        $this->maybe_send( $payload );
    }

    protected function maybe_send( ?array $payload ): ?array {
        if ( ! $payload ) {
            return null;
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
            return null;
        }
        return $this->client->send_event( $payload );
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
        $meta_keys = [ self::ORDER_META_SUBSCRIPTION_ID, 'wps_sfw_subscription_id', 'subscription_id', '_subscription_id' ];
        $found     = '';

        foreach ( $meta_keys as $meta_key ) {
            $value = $order->get_meta( $meta_key );
            if ( $value ) {
                $found = (string) $value;
                break;
            }
        }

        if ( $found && ! $order->get_meta( self::ORDER_META_SUBSCRIPTION_ID ) ) {
            $this->set_subscription_id_on_order( $order, $found );
        }

        return $found;
    }

    protected function set_subscription_id_on_order( \WC_Order $order, string $subscription_id ): void {
        if ( '' === $subscription_id ) {
            return;
        }

        $existing = $order->get_meta( self::ORDER_META_SUBSCRIPTION_ID );
        if ( (string) $existing === (string) $subscription_id ) {
            return;
        }

        $order->update_meta_data( self::ORDER_META_SUBSCRIPTION_ID, (string) $subscription_id );

        if ( ! $order->get_meta( 'wps_sfw_subscription_id' ) ) {
            $order->update_meta_data( 'wps_sfw_subscription_id', (string) $subscription_id );
        }

        $order->save();

        dsb_log( 'info', 'Subscription ID stored on order', [ 'order_id' => $order->get_id(), 'subscription_id' => $subscription_id ] );
    }

    protected function extract_subscription_id_from_unknown( $maybe_subscription ) {
        if ( is_numeric( $maybe_subscription ) ) {
            return (string) $maybe_subscription;
        }

        if ( is_array( $maybe_subscription ) ) {
            if ( isset( $maybe_subscription['subscription_id'] ) && $maybe_subscription['subscription_id'] ) {
                return (string) $maybe_subscription['subscription_id'];
            }
            if ( isset( $maybe_subscription['id'] ) && $maybe_subscription['id'] ) {
                return (string) $maybe_subscription['id'];
            }
        }

        if ( is_object( $maybe_subscription ) ) {
            if ( method_exists( $maybe_subscription, 'get_id' ) ) {
                $id = $maybe_subscription->get_id();
                if ( $id ) {
                    return (string) $id;
                }
            }

            if ( isset( $maybe_subscription->ID ) && $maybe_subscription->ID ) {
                return (string) $maybe_subscription->ID;
            }
        }

        return '';
    }

    protected function schedule_retry_if_needed( \WC_Order $order, string $event_name ): void {
        if ( $this->order_in_terminal_state( $order ) ) {
            return;
        }

        $attempt = (int) $order->get_meta( self::ORDER_META_RETRY_COUNT );
        if ( $attempt >= self::MAX_RETRY_ATTEMPTS ) {
            dsb_log( 'warning', 'Max retry attempts reached for subscription ID lookup', [ 'order_id' => $order->get_id() ] );
            return;
        }

        $existing = wp_next_scheduled( 'dsb_retry_provision_order', [ $order->get_id(), $event_name ] );
        if ( $existing ) {
            return;
        }

        $delay     = $this->get_retry_delay_seconds( $attempt );
        $timestamp = time() + $delay;
        wp_schedule_single_event( $timestamp, 'dsb_retry_provision_order', [ $order->get_id(), $event_name ] );

        $order->update_meta_data( self::ORDER_META_RETRY_LOCK, $timestamp );
        $order->update_meta_data( self::ORDER_META_RETRY_COUNT, $attempt );
        $order->save();

        dsb_log( 'info', 'Retry scheduled for subscription ID', [ 'order_id' => $order->get_id(), 'attempt' => $attempt + 1, 'run_at' => $timestamp, 'event' => $event_name ] );
    }

    protected function get_retry_delay_seconds( int $attempt ): int {
        $delays = [ 30, 60, 120, 240, 480 ];
        return $delays[ $attempt ] ?? end( $delays );
    }

    public function retry_provision_order( $order_id, $event_name = 'activated' ): void {
        $order = wc_get_order( (int) $order_id );
        if ( ! $order instanceof \WC_Order ) {
            return;
        }

        $subscription_id = $this->find_subscription_id_for_order( $order );
        if ( ! $subscription_id ) {
            $attempt = (int) $order->get_meta( self::ORDER_META_RETRY_COUNT );
            $attempt++;
            $order->update_meta_data( self::ORDER_META_RETRY_COUNT, $attempt );
            $order->delete_meta_data( self::ORDER_META_RETRY_LOCK );
            $order->save();

            if ( $attempt < self::MAX_RETRY_ATTEMPTS && ! $this->order_in_terminal_state( $order ) ) {
                $this->schedule_retry_if_needed( $order, $event_name );
            } else {
                dsb_log( 'warning', 'Subscription ID still missing after retries', [ 'order_id' => $order->get_id() ] );
            }
            return;
        }

        $this->set_subscription_id_on_order( $order, (string) $subscription_id );

        if ( $this->order_contains_mapped_product( $order ) ) {
            $last_sent = $order->get_meta( self::ORDER_META_LAST_SENT_EVENT );
            if ( $last_sent && $last_sent === $event_name ) {
                dsb_log( 'info', 'Provisioning event already sent; skipping duplicate', [ 'order_id' => $order->get_id(), 'event' => $event_name ] );
                $this->clear_retry_state( $order );
                return;
            }

            $payload = $this->build_payload( (string) $subscription_id, $event_name, $order );
            $result  = $this->maybe_send( $payload );

            if ( $result && ( $result['decoded']['status'] ?? '' ) === 'ok' ) {
                $this->mark_event_sent( $order, $event_name );
                $this->clear_retry_state( $order );
            }
        }
    }

    protected function clear_retry_state( \WC_Order $order ): void {
        $order->delete_meta_data( self::ORDER_META_RETRY_LOCK );
        $order->delete_meta_data( self::ORDER_META_RETRY_COUNT );
        $order->save();
    }

    protected function order_in_terminal_state( \WC_Order $order ): bool {
        $terminal = [ 'cancelled', 'failed', 'refunded', 'trash' ];
        return in_array( $order->get_status(), $terminal, true );
    }

    protected function mark_event_sent( \WC_Order $order, string $event ): void {
        $order->update_meta_data( self::ORDER_META_LAST_SENT_EVENT, $event );
        $order->save();
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

        $payload = [];

        if ( $subscription_id ) {
            $wps_expiry = $this->get_wps_expiry_datetime_for_subscription( $subscription_id );
            if ( $wps_expiry ) {
                $activation = $this->determine_activation_time( $order );
                if ( ! $activation ) {
                    try {
                        $activation = new \DateTimeImmutable( 'now', wp_timezone() );
                    } catch ( \Throwable $e ) {
                        $activation = null;
                    }
                }

                if ( $activation ) {
                    $payload['valid_from'] = $this->format_datetime_for_node( $activation );
                }

                $payload['valid_until'] = $wps_expiry;

                dsb_log(
                    'info',
                    'valid_until branch: wps_expiry',
                    [
                        'subscription_id' => $subscription_id,
                        'order_id'        => $order ? $order->get_id() : null,
                        'valid_from_set'  => isset( $payload['valid_from'] ),
                    ]
                );

                return $payload;
            }
        }

        $interval = $this->get_product_expiry_interval( $product_id );
        if ( ! $interval ) {
            dsb_log( 'debug', 'valid_until branch: none', [ 'subscription_id' => $subscription_id, 'order_id' => $order ? $order->get_id() : null ] );
            return [];
        }

        $start_dt    = null;
        $valid_until = null;

        if ( 'renewed' === $event ) {
            $existing = $this->fetch_current_validity( $subscription_id, $customer_email );
            if ( isset( $existing['valid_until'] ) ) {
                $valid_until = $this->format_datetime_for_node( $existing['valid_until']->add( $interval ) );
            } elseif ( isset( $existing['valid_from'] ) ) {
                $start_dt    = $existing['valid_from'];
                $valid_until = $this->format_datetime_for_node( $start_dt->add( $interval ) );
            }

            if ( ! $start_dt ) {
                $activation = $this->determine_activation_time( $order );
                if ( $activation ) {
                    $start_dt = $activation;
                }
            }

            if ( ! $valid_until && $start_dt ) {
                $valid_until = $this->format_datetime_for_node( $start_dt->add( $interval ) );
            }
        } else {
            $activation = $this->determine_activation_time( $order );
            if ( $activation ) {
                $valid_until = $this->format_datetime_for_node( $activation->add( $interval ) );
            }
        }

        $payload = [];
        if ( $valid_until ) {
            $payload['valid_until'] = $valid_until;
            dsb_log(
                'info',
                'valid_until branch: interval',
                [
                    'subscription_id' => $subscription_id,
                    'order_id'        => $order ? $order->get_id() : null,
                    'event'           => $event,
                ]
            );
        }

        return $payload;
    }

    private function get_wps_expiry_datetime_for_subscription( $subscription_id ): ?string {
        $subscription_id = (int) $subscription_id;
        if ( $subscription_id <= 0 ) {
            return null;
        }

        $expiry = apply_filters( 'wps_sfw_susbcription_end_date', '', $subscription_id );
        $dt     = $this->parse_expiry_value( $expiry );

        if ( $dt instanceof \DateTimeInterface ) {
            dsb_log( 'info', 'valid_until_source = wps_filter', [ 'subscription_id' => $subscription_id ] );
            return $this->format_datetime_for_node( $dt );
        }

        $all_meta = get_post_meta( $subscription_id );
        if ( is_array( $all_meta ) ) {
            $patterns = [ 'expiry', 'expire', 'end', 'until', 'valid', 'next_payment', 'trial_end' ];

            foreach ( $all_meta as $meta_key => $values ) {
                foreach ( $patterns as $pattern ) {
                    if ( false !== stripos( (string) $meta_key, $pattern ) ) {
                        $value = is_array( $values ) ? reset( $values ) : $values;
                        $dt    = $this->parse_expiry_value( $value );
                        if ( $dt instanceof \DateTimeInterface ) {
                            dsb_log( 'info', 'valid_until_source = wps_meta', [ 'subscription_id' => $subscription_id, 'meta_key' => $meta_key ] );
                            return $this->format_datetime_for_node( $dt );
                        }
                        break;
                    }
                }
            }
        }

        dsb_log( 'debug', 'valid_until_source = none', [ 'subscription_id' => $subscription_id ] );

        return null;
    }

    private function parse_expiry_value( $value ): ?\DateTimeImmutable {
        if ( empty( $value ) && '0' !== $value ) {
            return null;
        }

        try {
            if ( is_numeric( $value ) ) {
                $dt = new \DateTimeImmutable( '@' . (int) $value );
                return $dt->setTimezone( wp_timezone() );
            }

            if ( is_array( $value ) ) {
                $value = reset( $value );
            }

            if ( is_string( $value ) ) {
                $dt = new \DateTimeImmutable( $value );
                return $dt;
            }
        } catch ( \Throwable $e ) {
            return null;
        }

        return null;
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
