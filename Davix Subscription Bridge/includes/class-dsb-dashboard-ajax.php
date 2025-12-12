<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( __NAMESPACE__ . '\\dsb_pixlab_get_identity' ) ) {
    function dsb_pixlab_get_identity(): array {
        $user            = wp_get_current_user();
        $subscription_id = null;
        $order_id        = null;

        $customer_email = ( $user && $user->exists() && $user->user_email ) ? sanitize_email( $user->user_email ) : null;

        if ( function_exists( 'wc_get_orders' ) ) {
            $args = [
                'limit'   => 1,
                'orderby' => 'date',
                'order'   => 'DESC',
            ];

            if ( $user && $user->ID ) {
                $args['customer_id'] = (int) $user->ID;
            } elseif ( $customer_email ) {
                $args['billing_email'] = $customer_email;
            }

            $orders = wc_get_orders( $args );
            if ( empty( $orders ) && $customer_email ) {
                $orders = wc_get_orders(
                    [
                        'limit'         => 1,
                        'billing_email' => $customer_email,
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

        return [
            'subscription_id' => $subscription_id ? sanitize_text_field( $subscription_id ) : null,
            'order_id'        => $order_id ? sanitize_text_field( $order_id ) : null,
            'customer_email'  => $customer_email ? sanitize_email( $customer_email ) : null,
        ];
    }
}

class DSB_Dashboard_Ajax {
    protected $client;

    public function __construct( DSB_Client $client ) {
        $this->client = $client;
    }

    public function init(): void {
        add_action( 'wp_ajax_dsb_dashboard_summary', [ $this, 'summary' ] );
        add_action( 'wp_ajax_dsb_dashboard_usage', [ $this, 'usage' ] );
        add_action( 'wp_ajax_dsb_dashboard_rotate', [ $this, 'rotate_key' ] );
        add_action( 'wp_ajax_dsb_dashboard_toggle', [ $this, 'toggle_key' ] );
    }

    public function summary(): void {
        $identity = $this->validate_request();
        $result   = $this->client->user_summary( $identity );

        $this->respond_from_result( $result, __( 'Unable to load summary.', 'davix-sub-bridge' ) );
    }

    public function usage(): void {
        $identity = $this->validate_request();
        $range    = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : 'daily';
        $allowed  = [ 'hourly', 'daily', 'monthly', 'billing_period' ];
        if ( ! in_array( $range, $allowed, true ) ) {
            $range = 'daily';
        }

        $window = $this->get_window_for_range( $range );

        $result = $this->client->user_usage(
            array_merge( $identity, [
                'range'  => $range,
                'window' => $window,
            ] ),
            $range,
            [ 'window' => $window ]
        );
        $this->respond_from_result( $result, __( 'Unable to load usage.', 'davix-sub-bridge' ) );
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

    public function toggle_key(): void {
        $identity = $this->validate_request();
        $enabled  = isset( $_POST['enabled'] ) ? (bool) sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : true;

        $result = $this->client->user_toggle( $identity, $enabled );
        $this->respond_from_result( $result, __( 'Unable to update key.', 'davix-sub-bridge' ) );
    }

    protected function validate_request(): array {
        if ( ! is_user_logged_in() ) {
            wp_send_json( [ 'status' => 'error', 'message' => __( 'Authentication required.', 'davix-sub-bridge' ) ], 401 );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dsb_dashboard_nonce' ) ) {
            wp_send_json( [ 'status' => 'error', 'message' => __( 'Invalid request.', 'davix-sub-bridge' ) ], 403 );
        }

        $identity = dsb_pixlab_get_identity();
        return $identity;
    }

    protected function respond_from_result( array $result, string $default_message ): void {
        if ( is_wp_error( $result['response'] ) ) {
            wp_send_json( [ 'status' => 'error', 'message' => $result['response']->get_error_message() ], 500 );
        }

        $decoded = $result['decoded'] ?? [];
        if ( 200 !== $result['code'] || ( $decoded['status'] ?? '' ) !== 'ok' ) {
            $message = $decoded['message'] ?? $default_message;
            $payload = [ 'status' => 'error', 'message' => $message ];

            if ( current_user_can( 'manage_options' ) && ( 404 === $result['code'] || false !== strpos( strtolower( (string) $message ), 'endpoint does not exist' ) ) ) {
                $payload['debug'] = [
                    'url'    => $result['url'] ?? '',
                    'method' => $result['method'] ?? 'POST',
                    'http'   => $result['code'],
                ];
            }

            wp_send_json( $payload, 500 );
        }

        wp_send_json( $decoded );
    }

    protected function get_window_for_range( string $range ): array {
        switch ( $range ) {
            case 'hourly':
                return [ 'hours' => 48 ];
            case 'monthly':
                return [ 'months' => 6 ];
            case 'billing_period':
                return [ 'periods' => 2 ];
            case 'daily':
            default:
                return [ 'days' => 30 ];
        }
    }
}
