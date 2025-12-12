<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( __NAMESPACE__ . '\\dsb_get_current_identity' ) ) {
    function dsb_get_current_identity(): array {
        $user = wp_get_current_user();
        $identity = [
            'customer_email' => $user && $user->exists() ? sanitize_email( $user->user_email ) : '',
        ];

        $order_id       = '';
        $subscription_id = '';

        if ( function_exists( 'wc_get_orders' ) ) {
            $email = $identity['customer_email'];
            $args  = [
                'limit'   => 1,
                'orderby' => 'date',
                'order'   => 'DESC',
            ];

            if ( $user && $user->ID ) {
                $args['customer_id'] = (int) $user->ID;
            } elseif ( $email ) {
                $args['billing_email'] = $email;
            }

            $orders = wc_get_orders( $args );
            if ( empty( $orders ) && $email ) {
                $orders = wc_get_orders(
                    [
                        'limit'         => 1,
                        'billing_email' => $email,
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

        if ( $order_id ) {
            $identity['order_id'] = $order_id;
        }
        if ( $subscription_id ) {
            $identity['subscription_id'] = $subscription_id;
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
        add_action( 'wp_ajax_dsb_dashboard_summary', [ $this, 'summary' ] );
        add_action( 'wp_ajax_dsb_dashboard_usage', [ $this, 'usage' ] );
        add_action( 'wp_ajax_dsb_dashboard_rotate', [ $this, 'rotate' ] );
        add_action( 'wp_ajax_dsb_dashboard_toggle', [ $this, 'toggle' ] );
    }

    public function summary(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required.', 'davix-sub-bridge' ) ], 401 );
        }

        check_ajax_referer( 'dsb_dashboard', 'nonce' );

        $identity = dsb_get_current_identity();
        $result   = $this->client->user_summary( $identity );

        if ( is_wp_error( $result['response'] ) ) {
            wp_send_json_error( [ 'message' => $result['response']->get_error_message() ], 500 );
        }

        $decoded = $result['decoded'] ?? [];
        if ( 200 !== $result['code'] || ( $decoded['status'] ?? '' ) !== 'ok' ) {
            wp_send_json_error( [ 'message' => __( 'Unable to load summary.', 'davix-sub-bridge' ) ], 500 );
        }

        $plan   = $decoded['plan'] ?? [];
        $key    = $decoded['key'] ?? [];
        $usage  = $decoded['usage'] ?? [];
        $period = $usage['period'] ?? '';

        $total_calls_used  = (int) ( $usage['total_calls_used'] ?? $usage['total_calls'] ?? 0 );
        $total_calls_limit = isset( $usage['total_calls_limit'] )
            ? (int) $usage['total_calls_limit']
            : (int) ( $plan['call_limit_per_month'] ?? $plan['monthly_quota_files'] ?? 0 );

        $percent = $total_calls_limit > 0 ? min( 100, round( ( $total_calls_used / $total_calls_limit ) * 100, 2 ) ) : null;

        $per_endpoint = $usage['per_endpoint'] ?? [];

        $response = [
            'status'        => 'ok',
            'plan'          => [
                'name'                 => sanitize_text_field( $plan['name'] ?? '' ),
                'call_limit_per_month' => $total_calls_limit ?: null,
            ],
            'key'           => [
                'key_prefix' => sanitize_text_field( $key['key_prefix'] ?? '' ),
                'key_last4'  => sanitize_text_field( $key['key_last4'] ?? '' ),
                'status'     => sanitize_text_field( $key['status'] ?? ( $decoded['status'] ?? '' ) ),
                'created_at' => sanitize_text_field( $key['created_at'] ?? '' ),
            ],
            'billing'       => [
                'start' => sanitize_text_field( $usage['billing_start'] ?? $usage['billing_window_start'] ?? '' ),
                'end'   => sanitize_text_field( $usage['billing_end'] ?? $usage['billing_window_end'] ?? '' ),
                'period'=> sanitize_text_field( $period ),
            ],
            'usage'         => [
                'total_calls_used'  => $total_calls_used,
                'total_calls_limit' => $total_calls_limit ?: null,
                'percent'           => $percent,
            ],
            'per_endpoint'  => [
                'h2i_calls'   => (int) ( $per_endpoint['h2i']['calls'] ?? $per_endpoint['h2i_calls'] ?? 0 ),
                'image_calls' => (int) ( $per_endpoint['image']['calls'] ?? $per_endpoint['image_calls'] ?? 0 ),
                'pdf_calls'   => (int) ( $per_endpoint['pdf']['calls'] ?? $per_endpoint['pdf_calls'] ?? 0 ),
                'tools_calls' => (int) ( $per_endpoint['tools']['calls'] ?? $per_endpoint['tools_calls'] ?? 0 ),
            ],
        ];

        wp_send_json_success( $response );
    }

    public function usage(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required.', 'davix-sub-bridge' ) ], 401 );
        }

        check_ajax_referer( 'dsb_dashboard', 'nonce' );

        $range   = isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : 'daily';
        $allowed = [ 'hourly', 'daily', 'monthly', 'billing_period' ];
        if ( ! in_array( $range, $allowed, true ) ) {
            $range = 'daily';
        }

        $identity = dsb_get_current_identity();
        $result   = $this->client->user_usage( $identity, $range );

        if ( is_wp_error( $result['response'] ) ) {
            wp_send_json_error( [ 'message' => $result['response']->get_error_message() ], 500 );
        }

        $decoded = $result['decoded'] ?? [];
        if ( 200 !== $result['code'] || ( $decoded['status'] ?? '' ) !== 'ok' ) {
            wp_send_json_error( [ 'message' => __( 'Unable to load usage.', 'davix-sub-bridge' ) ], 500 );
        }

        $series = $decoded['series'] ?? [];
        $labels = $decoded['labels'] ?? [];

        $payload = [
            'status' => 'ok',
            'range'  => $range,
            'labels' => array_map( 'sanitize_text_field', $labels ),
            'series' => [
                'h2i'   => array_map( 'intval', $series['h2i'] ?? [] ),
                'image' => array_map( 'intval', $series['image'] ?? [] ),
                'pdf'   => array_map( 'intval', $series['pdf'] ?? [] ),
                'tools' => array_map( 'intval', $series['tools'] ?? [] ),
            ],
            'totals' => array_map( 'intval', $decoded['totals'] ?? [] ),
        ];

        wp_send_json_success( $payload );
    }

    public function rotate(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required.', 'davix-sub-bridge' ) ], 401 );
        }

        check_ajax_referer( 'dsb_dashboard', 'nonce' );

        $user    = wp_get_current_user();
        $now     = time();
        $metaKey = '_dsb_last_key_rotation';
        $last    = (int) get_user_meta( $user->ID, $metaKey, true );
        if ( $last && ( $now - $last ) < 60 ) {
            wp_send_json_error( [ 'message' => __( 'Please wait before rotating again.', 'davix-sub-bridge' ) ], 429 );
        }

        $identity = dsb_get_current_identity();
        $result   = $this->client->user_rotate( $identity );

        if ( is_wp_error( $result['response'] ) ) {
            wp_send_json_error( [ 'message' => $result['response']->get_error_message() ], 500 );
        }

        $decoded = $result['decoded'] ?? [];
        if ( 200 !== $result['code'] || ( $decoded['status'] ?? '' ) !== 'ok' ) {
            wp_send_json_error( [ 'message' => __( 'Unable to rotate key.', 'davix-sub-bridge' ) ], 500 );
        }

        update_user_meta( $user->ID, $metaKey, $now );

        $response = [
            'status'     => 'ok',
            'key'        => sanitize_text_field( $decoded['key'] ?? '' ),
            'key_prefix' => sanitize_text_field( $decoded['key_prefix'] ?? '' ),
            'key_last4'  => sanitize_text_field( $decoded['key_last4'] ?? '' ),
            'subscription_id' => sanitize_text_field( $decoded['subscription_id'] ?? '' ),
        ];

        wp_send_json_success( $response );
    }

    public function toggle(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Authentication required.', 'davix-sub-bridge' ) ], 401 );
        }

        check_ajax_referer( 'dsb_dashboard', 'nonce' );

        $action = isset( $_POST['action_name'] ) ? sanitize_key( wp_unslash( $_POST['action_name'] ) ) : '';
        if ( ! in_array( $action, [ 'enable', 'disable' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid action.', 'davix-sub-bridge' ) ], 400 );
        }

        $identity = dsb_get_current_identity();
        $result   = $this->client->user_toggle( $identity, $action );

        if ( is_wp_error( $result['response'] ) ) {
            wp_send_json_error( [ 'message' => $result['response']->get_error_message() ], 500 );
        }

        $decoded = $result['decoded'] ?? [];
        if ( 200 !== $result['code'] || ( $decoded['status'] ?? '' ) !== 'ok' ) {
            wp_send_json_error( [ 'message' => __( 'Unable to update status.', 'davix-sub-bridge' ) ], 500 );
        }

        $response = [
            'status' => 'ok',
            'action' => sanitize_text_field( $decoded['action'] ?? $action ),
            'key'    => [
                'status'     => sanitize_text_field( $decoded['key']['status'] ?? $decoded['status'] ?? '' ),
                'key_prefix' => sanitize_text_field( $decoded['key']['key_prefix'] ?? '' ),
                'key_last4'  => sanitize_text_field( $decoded['key']['key_last4'] ?? '' ),
            ],
        ];

        wp_send_json_success( $response );
    }
}
