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
        return in_array( $plan_slug, $plans, true ) || isset( $plans[ $plan_slug ] );
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
        $product_id = '';

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
        if ( $product_id ) {
            if ( isset( $plans[ $product_id ] ) ) {
                $plan_slug = $plans[ $product_id ];
            } else {
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
        }

        if ( ! $plan_slug && $subscription_id ) {
            $plan_slug = get_post_meta( $subscription_id, 'wps_sfw_plan_slug', true );
        }

        if ( ! $customer_email || ! $plan_slug ) {
            return null;
        }

        return [
            'event'           => $event,
            'customer_email'  => $customer_email,
            'plan_slug'       => $plan_slug,
            'subscription_id' => $subscription_id,
            'order_id'        => $order_id,
        ];
    }
}
