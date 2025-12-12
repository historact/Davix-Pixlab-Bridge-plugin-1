<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( __NAMESPACE__ . '\\dsb_pixlab_get_identity' ) ) {
    function dsb_pixlab_get_identity(): array {
        $user          = wp_get_current_user();
        $identity      = [];
        $subscription_id = '';
        $order_id        = '';

        if ( $user && $user->exists() && $user->user_email ) {
            $identity['customer_email'] = sanitize_email( $user->user_email );
        }

        if ( function_exists( 'wc_get_orders' ) ) {
            $args = [
                'limit'   => 1,
                'orderby' => 'date',
                'order'   => 'DESC',
            ];

            if ( $user && $user->ID ) {
                $args['customer_id'] = (int) $user->ID;
            } elseif ( isset( $identity['customer_email'] ) ) {
                $args['billing_email'] = $identity['customer_email'];
            }

            $orders = wc_get_orders( $args );
            if ( empty( $orders ) && isset( $identity['customer_email'] ) ) {
                $orders = wc_get_orders(
                    [
                        'limit'         => 1,
                        'billing_email' => $identity['customer_email'],
                        'orderby'       => 'date',
                        'order'         => 'DESC',
                    ]
                );
            }

            if ( ! empty( $orders ) ) {
                $order    = $orders[0];
                $order_id = (string) $order->get_id();

                $meta_keys = [ 'wps_sfw_subscription_id', 'subscription_id', '_subscription_id' ];
                foreach ( $meta_keys as $meta_key ) {
                    $value = $order->get_meta( $meta_key, true );
                    if ( $value ) {
                        $subscription_id = (string) $value;
                        break;
                    }
                }
            }
        }

        if ( $subscription_id ) {
            $identity['subscription_id'] = sanitize_text_field( $subscription_id );
        }
        if ( isset( $identity['customer_email'] ) && $identity['customer_email'] ) {
            $identity['customer_email'] = sanitize_email( $identity['customer_email'] );
        }
        if ( $order_id ) {
            $identity['order_id'] = sanitize_text_field( $order_id );
        }

        return array_filter(
            $identity,
            static function ( $value ) {
                return '' !== $value && null !== $value;
            }
        );
    }
}

class DSB_Dashboard_Ajax {
    protected $client;

    public function __construct( DSB_Client $client ) {
        $this->client = $client;
    }

    public function init(): void {
        add_action( 'wp_ajax_dsb_pixlab_dashboard_summary', [ $this, 'summary' ] );
        add_action( 'wp_ajax_dsb_pixlab_dashboard_usage', [ $this, 'usage' ] );
        add_action( 'wp_ajax_dsb_pixlab_dashboard_history', [ $this, 'history' ] );
        add_action( 'wp_ajax_dsb_pixlab_dashboard_rotate_key', [ $this, 'rotate_key' ] );
    }

    public function summary(): void {
        $identity = $this->validate_request();
        $result   = $this->client->fetch_user_dashboard_summary( $identity );

        $this->respond_from_result( $result, __( 'Unable to load summary.', 'davix-sub-bridge' ) );
    }

    public function usage(): void {
        $identity = $this->validate_request();
        $range    = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : 'daily';
        $allowed  = [ 'hourly', 'daily', 'monthly', 'billing_period' ];
        if ( ! in_array( $range, $allowed, true ) ) {
            $range = 'daily';
        }

        $result = $this->client->fetch_user_dashboard_usage( $identity, $range );
        $this->respond_from_result( $result, __( 'Unable to load usage.', 'davix-sub-bridge' ) );
    }

    public function history(): void {
        $identity = $this->validate_request();
        $range    = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : 'daily';
        $allowed  = [ 'hourly', 'daily', 'monthly', 'billing_period' ];
        if ( ! in_array( $range, $allowed, true ) ) {
            $range = 'daily';
        }

        $result = $this->client->fetch_user_dashboard_history( $identity, $range );
        $this->respond_from_result( $result, __( 'Unable to load history.', 'davix-sub-bridge' ) );
    }

    public function rotate_key(): void {
        $identity = $this->validate_request();

        $user    = wp_get_current_user();
        $now     = time();
        $metaKey = '_dsb_last_key_rotation';
        $last    = $user && $user->ID ? (int) get_user_meta( $user->ID, $metaKey, true ) : 0;

        if ( $last && ( $now - $last ) < 60 ) {
            wp_send_json( [ 'status' => 'error', 'message' => __( 'Please wait before rotating again.', 'davix-sub-bridge' ) ], 429 );
        }

        $result = $this->client->rotate_user_key( $identity );

        if ( $user && $user->ID && ! is_wp_error( $result['response'] ) && 200 === $result['code'] && ( $result['decoded']['status'] ?? '' ) === 'ok' ) {
            update_user_meta( $user->ID, $metaKey, $now );
        }

        $this->respond_from_result( $result, __( 'Unable to regenerate key.', 'davix-sub-bridge' ) );
    }

    protected function validate_request(): array {
        if ( ! is_user_logged_in() ) {
            wp_send_json( [ 'status' => 'error', 'message' => __( 'Authentication required.', 'davix-sub-bridge' ) ], 401 );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dsb_pixlab_dashboard' ) ) {
            wp_send_json( [ 'status' => 'error', 'message' => __( 'Invalid request.', 'davix-sub-bridge' ) ], 403 );
        }

        $identity = dsb_pixlab_get_identity();
        if ( empty( $identity ) ) {
            wp_send_json( [ 'status' => 'error', 'message' => __( 'We could not find your subscription details.', 'davix-sub-bridge' ) ], 404 );
        }

        return $identity;
    }

    protected function respond_from_result( array $result, string $default_message ): void {
        if ( is_wp_error( $result['response'] ) ) {
            wp_send_json( [ 'status' => 'error', 'message' => $result['response']->get_error_message() ], 500 );
        }

        $decoded = $result['decoded'] ?? [];
        if ( 200 !== $result['code'] || ( $decoded['status'] ?? '' ) !== 'ok' ) {
            $message = $decoded['message'] ?? $default_message;
            wp_send_json( [ 'status' => 'error', 'message' => $message ], 500 );
        }

        wp_send_json( $decoded );
    }
}
