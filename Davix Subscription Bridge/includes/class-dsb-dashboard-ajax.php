<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( __NAMESPACE__ . '\\dsb_pixlab_get_identity' ) ) {
    function dsb_pixlab_get_identity(): array {
        $wp_user_id      = (int) get_current_user_id();
        if ( ! $wp_user_id ) {
            wp_send_json_error( [ 'status' => 'error', 'code' => 'unauthorized', 'message' => __( 'Please log in.', 'davix-sub-bridge' ) ], 401 );
        }

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

                $meta_keys = [ DSB_Events::ORDER_META_SUBSCRIPTION_ID, 'wps_sfw_subscription_id', 'subscription_id', '_subscription_id' ];
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
            'wp_user_id'      => $wp_user_id,
            'subscription_id' => $subscription_id ? sanitize_text_field( $subscription_id ) : null,
            'order_id'        => $order_id ? sanitize_text_field( $order_id ) : null,
            'customer_email'  => $customer_email ? sanitize_email( $customer_email ) : null,
        ];
    }
}

if ( ! class_exists( __NAMESPACE__ . '\\DSB_Dashboard_Ajax' ) ) {

class DSB_Dashboard_Ajax {
    protected $client;
    protected $db;
    protected $diag_enabled;

    public function __construct( DSB_Client $client, DSB_DB $db ) {
        $this->client = $client;
        $this->db     = $db;
        $this->diag_enabled = defined( 'DSB_DASH_DIAG' ) ? (bool) DSB_DASH_DIAG : current_user_can( 'manage_options' );
    }

    public function init(): void {
        add_action( 'wp_ajax_dsb_dashboard_summary', [ $this, 'summary' ] );
        add_action( 'wp_ajax_dsb_dashboard_usage', [ $this, 'usage' ] );
        add_action( 'wp_ajax_dsb_dashboard_rotate', [ $this, 'rotate_key' ] );
        add_action( 'wp_ajax_dsb_dashboard_toggle', [ $this, 'toggle_key' ] );
        add_action( 'wp_ajax_dsb_dashboard_logs', [ $this, 'logs' ] );
    }

    public function summary(): void {
        $this->start_response();

        dsb_log( 'debug', 'Dashboard AJAX summary requested' );

        try {
            $identity = $this->validate_request();
            $result   = $this->client->user_summary( $identity );

            if ( ! is_wp_error( $result['response'] ) && 200 === $result['code'] && ( $result['decoded']['status'] ?? '' ) === 'ok' ) {
                $result['decoded'] = $this->normalize_summary_payload( $result['decoded'] );
                $result['decoded'] = array_merge( $result['decoded'], $this->resolve_provisioning_status( $identity, $result['decoded'] ) );
            }

            $this->respond_from_result( $result, __( 'Unable to load summary.', 'davix-sub-bridge' ), 'summary' );
        } catch ( \Throwable $e ) {
            $this->handle_exception( $e );
        }
    }

    public function usage(): void {
        $this->start_response();

        dsb_log( 'debug', 'Dashboard AJAX usage requested' );

        try {
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

            if ( ! is_wp_error( $result['response'] ) && 200 === $result['code'] && ( $result['decoded']['status'] ?? '' ) === 'ok' ) {
                $result['decoded'] = [
                    'status' => 'ok',
                    'labels' => $result['decoded']['labels'] ?? [],
                    'series' => $result['decoded']['series'] ?? [
                        'h2i'   => [],
                        'image' => [],
                        'pdf'   => [],
                        'tools' => [],
                    ],
                    'totals' => $result['decoded']['totals'] ?? [],
                ];
            }
            $this->respond_from_result( $result, __( 'Unable to load usage.', 'davix-sub-bridge' ), 'usage' );
        } catch ( \Throwable $e ) {
            $this->handle_exception( $e );
        }
    }

    public function logs(): void {
        $this->start_response();

        dsb_log( 'debug', 'Dashboard AJAX logs requested' );

        try {
            $identity = $this->validate_request();
            $page     = isset( $_POST['page'] ) ? max( 1, (int) $_POST['page'] ) : 1;
            $per_page = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 20;
            $per_page = min( 50, max( 1, $per_page ) );

            $filters = [];
            $maybe_endpoint = isset( $_POST['endpoint'] ) ? sanitize_text_field( wp_unslash( $_POST['endpoint'] ) ) : '';
            $maybe_status   = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
            if ( $maybe_endpoint ) {
                $filters['endpoint'] = $maybe_endpoint;
            }
            if ( $maybe_status ) {
                $filters['status'] = $maybe_status;
            }

            $result = $this->client->user_logs( $identity, $page, $per_page, $filters );

            if ( ! is_wp_error( $result['response'] ) && 200 === $result['code'] && ( $result['decoded']['status'] ?? '' ) === 'ok' ) {
                $result['decoded'] = $this->normalize_logs_payload( $result['decoded'], $page, $per_page );
            }

            $this->respond_from_result( $result, __( 'Unable to load logs.', 'davix-sub-bridge' ), 'logs' );
        } catch ( \Throwable $e ) {
            $this->handle_exception( $e );
        }
    }

    public function rotate_key(): void {
        $this->start_response();

        dsb_log( 'debug', 'Dashboard AJAX rotate requested' );

        try {
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

            $this->respond_from_result( $result, __( 'Unable to regenerate key.', 'davix-sub-bridge' ), 'rotate' );
        } catch ( \Throwable $e ) {
            $this->handle_exception( $e );
        }
    }

    public function toggle_key(): void {
        $this->start_response();

        dsb_log( 'debug', 'Dashboard AJAX toggle requested' );

        try {
            $identity = $this->validate_request();
            $enabled  = isset( $_POST['enabled'] ) ? (bool) sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : true;

            $result = $this->client->user_toggle( $identity, $enabled );
            $this->respond_from_result( $result, __( 'Unable to update key.', 'davix-sub-bridge' ), 'toggle' );
        } catch ( \Throwable $e ) {
            $this->handle_exception( $e );
        }
    }

    protected function validate_request(): array {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'status' => 'error', 'code' => 'unauthorized', 'message' => __( 'Please log in.', 'davix-sub-bridge' ) ], 401 );
        }

        $nonce = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'dsb_dashboard_nonce' ) ) {
            wp_send_json_error( [ 'status' => 'error', 'code' => 'bad_nonce', 'message' => __( 'Security check failed.', 'davix-sub-bridge' ) ], 403 );
        }

        $identity = dsb_pixlab_get_identity();
        return $identity;
    }

    protected function respond_from_result( array $result, string $default_message, string $action = '' ): void {
        if ( is_wp_error( $result['response'] ) ) {
            $message = $result['response']->get_error_message();
            $payload = [ 'status' => 'error', 'message' => $message ];

            if ( $this->diag_enabled ) {
                $payload['debug'] = $this->debug_payload_from_result( $result, $message );
            }

            $this->log_error( $message, array_merge( $result, [ 'action' => $action ] ) );
            wp_send_json( $payload, 500 );
        }

        $decoded = $result['decoded'] ?? [];
        if ( 200 !== $result['code'] || ( $decoded['status'] ?? '' ) !== 'ok' ) {
            $message = $decoded['message'] ?? $default_message;
            $payload = [ 'status' => 'error', 'message' => $message ];

            if ( $this->diag_enabled ) {
                $payload['debug'] = $this->debug_payload_from_result( $result, $message );
            }

            $this->log_error( $message, array_merge( $result, [ 'action' => $action ] ) );
            wp_send_json( $payload, max( 400, (int) $result['code'] ?: 500 ) );
        }

        dsb_log( 'info', 'Dashboard AJAX success', [
            'action' => $action ?: 'dashboard',
            'code'   => $result['code'] ?? 0,
            'status' => $decoded['status'] ?? '',
        ] );

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

    protected function start_response(): void {
        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
    }

    protected function handle_exception( \Throwable $e ): void {
        $message = $e->getMessage();
        $this->log_error( $message, [ 'file' => $e->getFile(), 'line' => $e->getLine() ] );

        $payload = [ 'status' => 'error', 'message' => __( 'Something went wrong.', 'davix-sub-bridge' ) ];
        if ( $this->diag_enabled ) {
            $payload['debug'] = [
                'error' => $message,
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ];
        }

        wp_send_json( $payload, 500 );
    }

    protected function log_error( string $message, $context = [] ): void {
        $settings = $this->client->get_settings();
        $token    = $settings['bridge_token'] ?? '';
        if ( $token ) {
            $message = str_replace( $token, '***', $message );
        }

        $context = is_array( $context ) ? $context : [ 'context' => $context ];
        dsb_log( 'error', 'Dashboard AJAX error', [
            'message' => $message,
            'code'    => $context['code'] ?? ( $context['response']['response']['code'] ?? null ),
            'action'  => $context['action'] ?? null,
            'error'   => is_wp_error( $context['response'] ?? null ) ? ( $context['response']->get_error_message() ) : null,
        ] );
    }

    protected function debug_payload_from_result( array $result, string $message = '' ): array {
        $body_excerpt = '';
        if ( isset( $result['response'] ) && ! is_wp_error( $result['response'] ) && isset( $result['response']['body'] ) ) {
            $body_excerpt = wp_trim_words( (string) $result['response']['body'], 40, '…' );
        }

        return [
            'url'    => $result['url'] ?? '',
            'method' => $result['method'] ?? 'POST',
            'http'   => $result['code'] ?? 0,
            'body'   => $body_excerpt,
            'error'  => $message,
        ];
    }

    private function fmt_date_ymd( $value ): string {
        if ( empty( $value ) ) {
            return '—';
        }
        try {
            $dt = new \DateTime( is_string( $value ) ? $value : '' );
            return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y/m/d' );
        } catch ( \Throwable $e ) {
            return '—';
        }
    }

    private function fmt_datetime( $value ): string {
        if ( empty( $value ) ) {
            return '—';
        }
        try {
            $dt = new \DateTime( is_string( $value ) ? $value : '' );
            return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y/m/d H:i' );
        } catch ( \Throwable $e ) {
            return '—';
        }
    }

    private function compute_billing_end_from_start( ?string $startUtc ): string {
        if ( empty( $startUtc ) ) {
            return '—';
        }
        try {
            $dt = new \DateTime( $startUtc, new \DateTimeZone( 'UTC' ) );
            $dt->modify( '+1 month' );
            return $dt->format( 'Y/m/d' );
        } catch ( \Throwable $e ) {
            return '—';
        }
    }

    private function placeholder_or_value( $value ) {
        if ( null === $value ) {
            return '—';
        }

        if ( is_string( $value ) && '' === trim( $value ) ) {
            return '—';
        }

        return $value;
    }

    private function normalize_summary_payload( array $decoded ): array {
        $plan   = $decoded['plan'] ?? [];
        $key    = $decoded['key'] ?? [];
        $usage  = $decoded['usage'] ?? [];
        $usagePer = $usage['per_endpoint'] ?? [];

        $planSlug = isset( $plan['plan_slug'] ) ? strtolower( (string) $plan['plan_slug'] ) : '';
        $isFreePlan = ( $plan['is_free'] ?? false ) || 'free' === $planSlug;

        $limit = null;
        if ( isset( $plan['monthly_call_limit'] ) && is_numeric( $plan['monthly_call_limit'] ) ) {
            $limit = (int) $plan['monthly_call_limit'];
        } elseif ( isset( $plan['monthly_quota_files'] ) && is_numeric( $plan['monthly_quota_files'] ) ) {
            $limit = (int) $plan['monthly_quota_files'];
        }

        $used    = isset( $usage['total_calls'] ) ? (int) $usage['total_calls'] : 0;
        $percent = ( $limit && $limit > 0 ) ? min( 100, (int) round( ( $used / $limit ) * 100 ) ) : 0;

        $startUtc = $usage['billing_window']['start_utc'] ?? null;
        $endUtc   = $usage['billing_window']['end_utc'] ?? null;
        $rawValidFrom = $key['valid_from'] ?? null;
        $rawValidUntil = $key['valid_until'] ?? null;
        $validFrom = $this->fmt_date_ymd( $rawValidFrom );
        $validUntil = $this->fmt_date_ymd( $rawValidUntil );
        $hasFrom = $validFrom && '—' !== $validFrom;
        $hasUntil = $validUntil && '—' !== $validUntil;

        $defaultStart = ( new \DateTime( 'first day of this month 00:00:00', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
        $defaultEnd   = ( new \DateTime( 'first day of next month 00:00:00', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );

        $billingStart = $this->fmt_date_ymd( $startUtc ?: $defaultStart );
        if ( ! empty( $endUtc ) ) {
            $billingEnd = $this->fmt_date_ymd( $endUtc );
        } elseif ( ! empty( $startUtc ) ) {
            $billingEnd = $this->compute_billing_end_from_start( $startUtc );
        } else {
            $billingEnd = $this->fmt_date_ymd( $defaultEnd );
        }

        $validity = '';
        $useBillingWindow = $isFreePlan || empty( $rawValidUntil );
        if ( $useBillingWindow ) {
            if ( $billingStart && '—' !== $billingStart && $billingEnd && '—' !== $billingEnd ) {
                $validity = $billingStart . ' – ' . $billingEnd;
            } elseif ( $billingStart && '—' !== $billingStart ) {
                $validity = sprintf( __( 'Valid from %s', 'davix-sub-bridge' ), $billingStart );
            } elseif ( $billingEnd && '—' !== $billingEnd ) {
                $validity = sprintf( __( 'Valid until %s', 'davix-sub-bridge' ), $billingEnd );
            }
        } else {
            if ( $hasFrom && $hasUntil ) {
                $validity = $validFrom . ' – ' . $validUntil;
            } elseif ( $hasFrom ) {
                $validity = sprintf( __( 'Valid from %s', 'davix-sub-bridge' ), $validFrom );
            } elseif ( $hasUntil ) {
                $validity = sprintf( __( 'Valid until %s', 'davix-sub-bridge' ), $validUntil );
            }
        }

        return [
            'status' => 'ok',
            'plan'   => [
                'name'            => $plan['name'] ?? null,
                'billing_period'  => $plan['billing_period'] ?? null,
                'limit'           => $limit,
            ],
            'key'    => [
                'key_prefix' => $key['key_prefix'] ?? null,
                'key_last4'  => $key['key_last4'] ?? null,
                'status'     => $key['status'] ?? null,
                'created_at' => $this->fmt_date_ymd( $key['created_at'] ?? null ),
                'valid_from' => $validFrom,
                'valid_until'=> $validUntil,
            ],
            'usage'  => [
                'total_calls_used'  => $used,
                'total_calls_limit' => $limit,
                'percent'           => $percent,
            ],
            'per_endpoint' => [
                'h2i_calls'   => (int) ( $usagePer['h2i_calls'] ?? 0 ),
                'image_calls' => (int) ( $usagePer['image_calls'] ?? 0 ),
                'pdf_calls'   => (int) ( $usagePer['pdf_calls'] ?? 0 ),
                'tools_calls' => (int) ( $usagePer['tools_calls'] ?? 0 ),
            ],
            'billing' => [
                'period' => $usage['period'] ?? gmdate( 'Y-m' ),
                'start'  => $billingStart,
                'end'    => $billingEnd,
            ],
            'plan_validity' => $validity,
        ];
    }

    private function resolve_provisioning_status( array $identity, array $summary ): array {
        $key = isset( $summary['key'] ) && is_array( $summary['key'] ) ? $summary['key'] : [];
        $key_status = isset( $key['status'] ) ? strtolower( (string) $key['status'] ) : '';
        $has_key_material = ! empty( $key['key_prefix'] ) || ! empty( $key['key_last4'] );
        $has_active_key = $has_key_material && ! in_array( $key_status, [ 'disabled', 'error' ], true );

        if ( $has_active_key ) {
            return [
                'provisioning_status' => 'ok',
                'next_retry_at'       => null,
                'last_error'          => null,
            ];
        }

        $jobs = $this->db->get_provision_jobs_for_identity( $identity );
        if ( empty( $jobs ) ) {
            return [
                'provisioning_status' => 'ok',
                'next_retry_at'       => null,
                'last_error'          => null,
            ];
        }

        $job = $jobs[0];
        $status = strtolower( (string) ( $job['status'] ?? '' ) );

        if ( in_array( $status, [ 'pending', 'processing', 'retry' ], true ) ) {
            $next_run_at = $job['next_run_at'] ?? null;
            $next_retry = null;
            if ( $next_run_at ) {
                $ts = strtotime( (string) $next_run_at );
                $next_retry = $ts ? gmdate( 'c', $ts ) : null;
            }

            return [
                'provisioning_status' => 'pending',
                'next_retry_at'       => $next_retry,
                'last_error'          => null,
            ];
        }

        if ( 'failed' === $status ) {
            $last_error = $job['last_error'] ?? '';
            $message = current_user_can( 'manage_options' )
                ? ( $last_error ? sanitize_text_field( $last_error ) : __( 'Provisioning failed.', 'davix-sub-bridge' ) )
                : __( 'Provisioning failed. Please contact support.', 'davix-sub-bridge' );

            return [
                'provisioning_status' => 'failed',
                'next_retry_at'       => null,
                'last_error'          => $message,
            ];
        }

        return [
            'provisioning_status' => 'ok',
            'next_retry_at'       => null,
            'last_error'          => null,
        ];
    }

    private function normalize_logs_payload( array $decoded, int $page, int $per_page ): array {
        $items = [];
        $rows  = $decoded['items'] ?? $decoded['logs'] ?? [];

        foreach ( $rows as $row ) {
            $files_processed = $row['files_processed'] ?? ( $row['files'] ?? null );
            $bytes_in        = $row['bytes_in'] ?? null;
            $bytes_out       = $row['bytes_out'] ?? null;

            $items[] = [
                'timestamp' => $this->fmt_datetime( $row['timestamp'] ?? ( $row['created_at'] ?? null ) ),
                'endpoint'  => $this->placeholder_or_value( sanitize_text_field( $row['endpoint'] ?? '' ) ),
                'action'    => $this->placeholder_or_value( sanitize_text_field( $row['action'] ?? '' ) ),
                'status'    => $this->placeholder_or_value( sanitize_text_field( $row['status'] ?? '' ) ),
                'files'     => $this->placeholder_or_value( isset( $files_processed ) ? (int) $files_processed : null ),
                'bytes_in'  => $this->placeholder_or_value( isset( $bytes_in ) ? (int) $bytes_in : null ),
                'bytes_out' => $this->placeholder_or_value( isset( $bytes_out ) ? (int) $bytes_out : null ),
                'error'     => $this->placeholder_or_value( sanitize_text_field( $row['error_code'] ?? ( $row['error_message'] ?? ( $row['error'] ?? '' ) ) ) ),
            ];
        }

        return [
            'status'   => 'ok',
            'page'     => $page,
            'per_page' => $per_page,
            'total'    => isset( $decoded['total'] ) ? (int) $decoded['total'] : count( $items ),
            'items'    => $items,
        ];
    }
}

}
