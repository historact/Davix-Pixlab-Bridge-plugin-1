<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Node_Poll {
    const CRON_HOOK           = 'dsb_node_poll_sync_event';
    const SCHEDULE_KEY        = 'dsb_node_poll_interval';
    const OPTION_LOCK_UNTIL   = 'dsb_node_poll_lock_until';
    const OPTION_LAST_RUN_AT  = 'dsb_node_poll_last_run_at';
    const OPTION_LAST_RESULT  = 'dsb_node_poll_last_result';
    const OPTION_LAST_ERROR   = 'dsb_node_poll_last_error';
    const OPTION_LAST_HTTP    = 'dsb_node_poll_last_http_code';
    const OPTION_LAST_URL     = 'dsb_node_poll_last_url';
    const OPTION_LAST_BODY    = 'dsb_node_poll_last_body_excerpt';

    /** @var DSB_Client */
    protected $client;
    /** @var DSB_DB */
    protected $db;

    public function __construct( DSB_Client $client, DSB_DB $db ) {
        $this->client = $client;
        $this->db     = $db;
    }

    public function init(): void {
        add_filter( 'cron_schedules', [ $this, 'register_schedule' ] );
        add_action( self::CRON_HOOK, [ $this, 'run' ] );
        add_action( 'init', [ $this, 'maybe_schedule' ] );
    }

    public function register_schedule( array $schedules ): array {
        $settings = $this->client->get_settings();
        $minutes  = max( 5, min( 60, (int) ( $settings['node_poll_interval_minutes'] ?? 10 ) ) );

        $schedules[ self::SCHEDULE_KEY ] = [
            'interval' => $minutes * MINUTE_IN_SECONDS,
            'display'  => sprintf( __( 'Every %d minutes (Davix Node poll)', 'davix-sub-bridge' ), $minutes ),
        ];

        return $schedules;
    }

    public function maybe_schedule(): void {
        $settings = $this->client->get_settings();

        if ( empty( $settings['enable_node_poll_sync'] ) ) {
            $this->unschedule();
            return;
        }

        $event = wp_get_scheduled_event( self::CRON_HOOK );
        if ( $event && self::SCHEDULE_KEY === ( $event->schedule ?? '' ) ) {
            return;
        }

        $this->unschedule();
        wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE_KEY, self::CRON_HOOK );
    }

    public function get_last_status(): array {
        return [
            'last_run_at' => get_option( self::OPTION_LAST_RUN_AT, '' ),
            'last_result' => get_option( self::OPTION_LAST_RESULT, '' ),
            'last_error'  => get_option( self::OPTION_LAST_ERROR, '' ),
            'last_http'   => get_option( self::OPTION_LAST_HTTP, '' ),
            'last_url'    => get_option( self::OPTION_LAST_URL, '' ),
            'last_body'   => get_option( self::OPTION_LAST_BODY, '' ),
        ];
    }

    public function run( bool $manual = false ): array {
        $settings = $this->client->get_settings();
        if ( empty( $settings['enable_node_poll_sync'] ) && ! $manual ) {
            return $this->record_status( 'disabled' );
        }

        $lock_minutes = max( 1, (int) ( $settings['node_poll_lock_minutes'] ?? 10 ) );

        $resync_lock_until = (int) get_option( DSB_Resync::OPTION_LOCK_UNTIL );
        if ( $resync_lock_until > time() ) {
            return $this->record_status( 'locked', __( 'Resync in progress; poll skipped.', 'davix-sub-bridge' ) );
        }

        if ( ! $this->acquire_lock( $lock_minutes ) ) {
            return $this->record_status( 'locked', __( 'Poll already running.', 'davix-sub-bridge' ) );
        }

        $started  = microtime( true );
        $per_page = max( 1, min( 500, (int) ( $settings['node_poll_per_page'] ?? 200 ) ) );
        $page     = 1;
        $errors   = [];

        $wp_user_ids      = [];
        $emails           = [];
        $subscription_ids = [];

        $summary = [
            'pages'          => 0,
            'items'          => 0,
            'key_upserts'    => 0,
            'user_upserts'   => 0,
            'deleted_keys'   => 0,
            'deleted_users'  => 0,
        ];

        $error_context = [
            'http_code'     => 0,
            'url'           => '',
            'body_excerpt'  => '',
            'decoded_error' => '',
            'method'        => '',
        ];

        try {
            do {
                $result  = $this->client->fetch_node_export( $page, $per_page );
                $code    = (int) ( $result['code'] ?? 0 );
                $decoded = $result['decoded'] ?? null;

                if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) ) {
                    $details        = $this->build_error_details( $result, $decoded );
                    $errors[]       = $details['message'];
                    $context        = $details['context'];
                    $error_context  = $error_context['http_code'] ? $error_context : $context;
                    break;
                }

                $items = [];
                if ( isset( $decoded['items'] ) && is_array( $decoded['items'] ) ) {
                    $items = $decoded['items'];
                } elseif ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
                    $items = $decoded['data'];
                } elseif ( array_keys( $decoded ) === range( 0, count( $decoded ) - 1 ) ) {
                    $items = $decoded;
                }

                $summary['pages'] ++;
                $summary['items'] += count( $items );

                foreach ( $items as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }

                    $identity = $this->process_item( $item, $code );
                    if ( $identity['wp_user_id'] ) {
                        $wp_user_ids[] = $identity['wp_user_id'];
                    }
                    if ( $identity['customer_email'] ) {
                        $emails[] = $identity['customer_email'];
                    }
                    if ( $identity['subscription_id'] ) {
                        $subscription_ids[] = $identity['subscription_id'];
                    }

                    $summary['key_upserts']  += $identity['key_upserted'] ? 1 : 0;
                    $summary['user_upserts'] += $identity['user_upserted'] ? 1 : 0;
                }

                $total_pages = isset( $decoded['total_pages'] ) ? (int) $decoded['total_pages'] : 0;
                $has_more    = isset( $decoded['has_more'] ) ? (bool) $decoded['has_more'] : false;
                $page ++;
            } while ( $items && ( $has_more || ( $total_pages && $page <= $total_pages ) || count( $items ) >= $per_page ) );

            if ( ! empty( $settings['node_poll_delete_stale'] ) ) {
                $wp_user_ids      = array_values( array_unique( array_filter( array_map( 'absint', $wp_user_ids ) ) ) );
                $emails           = array_values( array_unique( array_filter( array_map( 'sanitize_email', $emails ) ) ) );
                $subscription_ids = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $subscription_ids ) ) ) );

                if ( $wp_user_ids ) {
                    $summary['deleted_users'] = $this->db->delete_users_not_in( $wp_user_ids );
                }

                if ( $wp_user_ids || $emails || $subscription_ids ) {
                    $summary['deleted_keys'] = $this->db->delete_keys_not_in( $wp_user_ids, $emails, $subscription_ids );
                }
            }
        } catch ( \Throwable $e ) {
            $errors[] = $e->getMessage();
        }

        $duration = max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) );

        $summary_text = sprintf(
            'pages:%d items:%d key_upserts:%d user_upserts:%d deleted_keys:%d deleted_users:%d duration_ms:%d',
            $summary['pages'],
            $summary['items'],
            $summary['key_upserts'],
            $summary['user_upserts'],
            $summary['deleted_keys'],
            $summary['deleted_users'],
            $duration
        );

        $error_excerpt = implode( ';', $errors );

        if ( $errors ) {
            $context_parts = [];
            if ( $error_context['url'] ) {
                $context_parts[] = 'url:' . $error_context['url'];
            }
            if ( $error_context['body_excerpt'] ) {
                $context_parts[] = 'body:' . substr( $error_context['body_excerpt'], 0, 200 );
            }
            if ( $error_context['decoded_error'] ) {
                $context_parts[] = 'decoded:' . $error_context['decoded_error'];
            }

            $context_summary = implode( ' ', $context_parts );
            if ( $context_summary ) {
                $error_excerpt = $error_excerpt ? $error_excerpt . ' | ' . $context_summary : $context_summary;
            }
        }

        $this->db->log_event(
            [
                'event'           => 'node_poll_sync',
                'customer_email'  => null,
                'subscription_id' => null,
                'response_action' => $summary_text,
                'http_code'       => $errors ? (int) $error_context['http_code'] : 200,
                'error_excerpt'   => $error_excerpt,
            ]
        );

        $this->release_lock();

        if ( $errors ) {
            $this->record_last_error_context( $error_context );
            return $this->record_status( 'error', implode( ';', $errors ) );
        }

        $this->record_last_error_context();
        return $this->record_status( 'ok' );
    }

    public function run_once(): array {
        return $this->run( true );
    }

    protected function acquire_lock( int $minutes ): bool {
        $lock_until = (int) get_option( self::OPTION_LOCK_UNTIL );
        if ( $lock_until > time() ) {
            return false;
        }

        update_option( self::OPTION_LOCK_UNTIL, time() + ( $minutes * MINUTE_IN_SECONDS ), false );
        return true;
    }

    protected function release_lock(): void {
        delete_option( self::OPTION_LOCK_UNTIL );
    }

    protected function record_status( string $status, string $error = '' ): array {
        update_option( self::OPTION_LAST_RUN_AT, current_time( 'mysql', true ) );
        update_option( self::OPTION_LAST_RESULT, $status );
        update_option( self::OPTION_LAST_ERROR, $error );

        return [ 'status' => $status, 'error' => $error ];
    }

    protected function record_last_error_context( array $context = [] ): void {
        $defaults = [
            'http_code'    => 0,
            'url'          => '',
            'body_excerpt' => '',
        ];

        $context = array_merge( $defaults, $context );

        update_option( self::OPTION_LAST_HTTP, (int) $context['http_code'] );
        update_option( self::OPTION_LAST_URL, $context['url'] );
        update_option( self::OPTION_LAST_BODY, $context['body_excerpt'] );
    }

    protected function build_error_details( array $result, $decoded ): array {
        $response     = $result['response'] ?? null;
        $code         = (int) ( $result['code'] ?? 0 );
        $url          = isset( $result['url'] ) ? (string) $result['url'] : '';
        $method       = isset( $result['method'] ) ? (string) $result['method'] : '';
        $body_excerpt = $this->extract_body_excerpt( $response );

        $decoded_error = '';
        if ( is_array( $decoded ) ) {
            foreach ( [ 'error', 'message', 'status', 'code' ] as $key ) {
                if ( isset( $decoded[ $key ] ) && '' !== (string) $decoded[ $key ] ) {
                    $decoded_error = is_scalar( $decoded[ $key ] ) ? (string) $decoded[ $key ] : wp_json_encode( $decoded[ $key ] );
                    break;
                }
            }
        }

        if ( is_wp_error( $response ) ) {
            $message = $response->get_error_message();
        } elseif ( $code ) {
            $reason  = $decoded_error ?: ( $body_excerpt ?: 'unexpected_response' );
            $message = sprintf( 'HTTP %d %s', $code, $reason );
        } else {
            $message = $decoded_error ?: ( $body_excerpt ?: 'unexpected_response' );
        }

        return [
            'message' => $message,
            'context' => [
                'http_code'     => $code,
                'url'           => $url,
                'method'        => $method,
                'body_excerpt'  => $body_excerpt,
                'decoded_error' => $decoded_error,
            ],
        ];
    }

    protected function extract_body_excerpt( $response ): string {
        if ( is_wp_error( $response ) ) {
            $data = $response->get_error_data();
            $body = is_array( $data ) && isset( $data['body'] ) ? (string) $data['body'] : '';
            return $body ? substr( $body, 0, 500 ) : '';
        }

        if ( is_array( $response ) ) {
            $body = wp_remote_retrieve_body( $response );
            return $body ? substr( $body, 0, 500 ) : '';
        }

        return '';
    }

    protected function process_item( array $item, int $http_code ): array {
        $plan          = isset( $item['plan'] ) && is_array( $item['plan'] ) ? $item['plan'] : [];
        $plan_slug     = dsb_normalize_plan_slug( $plan['plan_slug'] ?? ( $item['plan_slug'] ?? '' ) );
        $subscription  = isset( $item['subscription_id'] ) ? sanitize_text_field( (string) $item['subscription_id'] ) : '';
        $external_sub  = isset( $item['external_subscription_id'] ) ? sanitize_text_field( (string) $item['external_subscription_id'] ) : '';
        $subscription_id = $subscription ?: $external_sub;
        $wp_user_id    = isset( $item['wp_user_id'] ) ? absint( $item['wp_user_id'] ) : 0;
        $customer_email = isset( $item['customer_email'] ) ? sanitize_email( $item['customer_email'] ) : sanitize_email( $item['email'] ?? '' );
        $status         = isset( $item['status'] ) ? sanitize_text_field( $item['status'] ) : '';
        $subscription_status = isset( $item['subscription_status'] ) ? sanitize_text_field( $item['subscription_status'] ) : '';
        $valid_from     = $this->normalize_mysql_datetime( $item['valid_from'] ?? ( $item['valid_from_at'] ?? null ) );
        $valid_until    = $this->normalize_mysql_datetime( $item['valid_until'] ?? ( $item['valid_to'] ?? null ) );
        $node_plan_id   = isset( $plan['plan_id'] ) ? $plan['plan_id'] : ( $item['plan_id'] ?? null );

        if ( ! $subscription_id && $wp_user_id && 'free' === $plan_slug ) {
            $subscription_id = 'free-' . $wp_user_id;
        }

        $key_prefix = null;
        $key_last4  = null;

        if ( isset( $item['key'] ) && is_string( $item['key'] ) ) {
            $key_prefix = substr( $item['key'], 0, 10 );
            $key_last4  = substr( $item['key'], -4 );
        }

        if ( isset( $item['key_prefix'] ) ) {
            $key_prefix = $item['key_prefix'];
        }

        if ( isset( $item['key_last4'] ) ) {
            $key_last4 = $item['key_last4'];
        }

        $this->db->upsert_key(
            [
                'subscription_id'     => $subscription_id,
                'customer_email'      => $customer_email,
                'wp_user_id'          => $wp_user_id ?: null,
                'plan_slug'           => $plan_slug,
                'status'              => $status ?: 'unknown',
                'subscription_status' => $subscription_status ?: null,
                'key_prefix'          => $key_prefix,
                'key_last4'           => $key_last4,
                'valid_from'          => $valid_from,
                'valid_until'         => $valid_until,
                'node_plan_id'        => $node_plan_id,
                'last_action'         => 'node_poll',
                'last_http_code'      => $http_code,
                'last_error'          => null,
            ]
        );

        $user_status = $subscription_status ?: ( 'disabled' === $status ? 'expired' : ( $status ?: null ) );
        $user_upsert = false;
        if ( $wp_user_id > 0 ) {
            $this->db->upsert_user(
                [
                    'wp_user_id'      => $wp_user_id,
                    'customer_email'  => $customer_email,
                    'subscription_id' => $subscription_id ?: null,
                    'order_id'        => isset( $item['order_id'] ) ? absint( $item['order_id'] ) : null,
                    'product_id'      => isset( $item['product_id'] ) ? absint( $item['product_id'] ) : null,
                    'plan_slug'       => $plan_slug,
                    'status'          => $user_status,
                    'valid_from'      => $valid_from,
                    'valid_until'     => $valid_until,
                    'source'          => 'node_poll',
                    'last_sync_at'    => current_time( 'mysql', true ),
                ]
            );
            $user_upsert = true;
        }

        return [
            'wp_user_id'      => $wp_user_id,
            'customer_email'  => $customer_email,
            'subscription_id' => $subscription_id,
            'key_upserted'    => true,
            'user_upserted'   => $user_upsert,
        ];
    }

    protected function normalize_mysql_datetime( $value ): ?string {
        if ( null === $value || '' === $value ) {
            return null;
        }

        try {
            if ( is_numeric( $value ) ) {
                return gmdate( 'Y-m-d H:i:s', (int) $value );
            }

            if ( is_array( $value ) ) {
                $value = reset( $value );
            }

            $dt = new \DateTimeImmutable( is_string( $value ) ? $value : '' );
            return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    protected function unschedule(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
            $timestamp = wp_next_scheduled( self::CRON_HOOK );
        }
    }
}
