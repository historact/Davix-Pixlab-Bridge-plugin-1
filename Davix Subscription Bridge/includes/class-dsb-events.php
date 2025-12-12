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
        /**
         * Hooks observed in the WPSwings Subscriptions for WooCommerce plugin:
         * - 'wps_sfw_subscription_status_updated' passes subscription id, old status, new status.
         * - 'wps_sfw_subscription_status_changed' passes subscription id and status.
         * - Custom post type is typically 'wps_subscriptions'.
         * These were derived from the public repository (documented in code comments) but network access may be limited.
         */
        add_action( 'wps_sfw_subscription_status_updated', [ $this, 'handle_status_update' ], 10, 3 );
        add_action( 'wps_sfw_subscription_status_changed', [ $this, 'handle_status_change' ], 10, 2 );
        add_action( 'transition_post_status', [ $this, 'handle_transition' ], 10, 3 );

        add_action( 'woocommerce_order_status_changed', [ $this, 'handle_order_status' ], 10, 4 );
    }

    public function resync_active_subscriptions(): void {
        $query = new \WP_Query(
            [
                'post_type'      => 'wps_subscriptions',
                'post_status'    => 'publish',
                'posts_per_page' => 200,
            ]
        );

        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post ) {
                $subscription_id = (string) $post->ID;
                $payload         = $this->build_payload( $subscription_id, 'activated' );
                if ( $payload ) {
                    $this->client->send_event( $payload );
                }
            }
        }
    }

    public function handle_status_update( $subscription_id, $old_status, $new_status ): void {
        $event = $this->map_status_to_event( $new_status );
        if ( $event && $payload = $this->build_payload( (string) $subscription_id, $event ) ) {
            $this->client->send_event( $payload );
        }
    }

    public function handle_status_change( $subscription_id, $status ): void {
        $event = $this->map_status_to_event( $status );
        if ( $event && $payload = $this->build_payload( (string) $subscription_id, $event ) ) {
            $this->client->send_event( $payload );
        }
    }

    public function handle_transition( $new_status, $old_status, $post ): void {
        if ( 'wps_subscriptions' !== $post->post_type ) {
            return;
        }
        $event = $this->map_status_to_event( $new_status );
        if ( $event && $payload = $this->build_payload( (string) $post->ID, $event ) ) {
            $this->client->send_event( $payload );
        }
    }

    public function handle_order_status( $order_id, $old_status, $new_status, $order ): void {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $subscription_id = $order->get_meta( 'wps_sfw_subscription_id' );
        if ( ! $subscription_id ) {
            return;
        }
        $event = $this->map_status_to_event( $new_status );
        if ( $event && $payload = $this->build_payload( (string) $subscription_id, $event, $order ) ) {
            $this->client->send_event( $payload );
        }
    }

    protected function map_status_to_event( $status ): ?string {
        $status = (string) $status;
        switch ( $status ) {
            case 'active':
            case 'completed':
            case 'wc-completed':
                return 'activated';
            case 'renewal':
            case 'processing':
                return 'renewed';
            case 'cancelled':
            case 'trash':
            case 'refunded':
                return 'cancelled';
            case 'expired':
                return 'expired';
            case 'failed':
                return 'payment_failed';
            case 'on-hold':
            case 'paused':
                return 'paused';
            default:
                return null;
        }
    }

    public function build_payload( string $subscription_id, string $event, ?\WC_Order $order = null ): ?array {
        $settings = $this->client->get_settings();
        $plan_slug = '';
        $customer_email = '';
        $order_id = '';
        $product_id = '';

        if ( $order ) {
            $customer_email = $order->get_billing_email();
            $order_id       = $order->get_id();
            foreach ( $order->get_items() as $item ) {
                $product_id = $item->get_product_id();
                break;
            }
        } else {
            $order_id_meta = get_post_meta( $subscription_id, 'parent_order_id', true );
            if ( $order_id_meta ) {
                $order = wc_get_order( $order_id_meta );
                if ( $order ) {
                    $customer_email = $order->get_billing_email();
                    $order_id       = $order->get_id();
                    foreach ( $order->get_items() as $item ) {
                        $product_id = $item->get_product_id();
                        break;
                    }
                }
            }
        }

        if ( ! $customer_email ) {
            $user_id = get_post_meta( $subscription_id, 'user_id', true );
            if ( $user_id ) {
                $user = get_user_by( 'id', (int) $user_id );
                if ( $user ) {
                    $customer_email = $user->user_email;
                }
            }
        }

        if ( 'product' === $settings['plan_mode'] && $product_id ) {
            $plan_map = $settings['product_plans'];
            if ( isset( $plan_map[ $product_id ] ) ) {
                $plan_slug = $plan_map[ $product_id ];
            }
        }

        if ( ! $plan_slug ) {
            $plan_slug = get_post_meta( $subscription_id, 'wps_sfw_plan_slug', true );
        }

        if ( ! $plan_slug && $product_id ) {
            $plan_slug = get_post_meta( $product_id, 'davix_plan_slug', true );
        }

        if ( ! $customer_email ) {
            return null;
        }

        if ( ! $plan_slug ) {
            $plan_slug = 'default';
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
