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
    const OPTION_LAST_DURATION_MS = 'dsb_node_poll_last_duration_ms';
    const OPTION_LAST_UNSTABLE = 'dsb_node_poll_last_unstable';
    const OPTION_STABLE_STREAK = 'dsb_node_poll_stable_streak';

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
            'display'  => sprintf( __( 'Every %d minutes (Davix Node poll)', 'pixlab-license-bridge' ), $minutes ),
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
            'last_duration_ms' => (int) get_option( self::OPTION_LAST_DURATION_MS, 0 ),
            'lock_until'       => (int) get_option( self::OPTION_LOCK_UNTIL, 0 ),
        ];
    }

    public function run( bool $manual = false ): array {
        $settings = $this->client->get_settings();
        if ( empty( $settings['enable_node_poll_sync'] ) && ! $manual ) {
            return $this->record_status( 'disabled', '', 0 );
        }

        $lock_minutes = max( 1, (int) ( $settings['node_poll_lock_minutes'] ?? 10 ) );

        $resync_lock_until = (int) get_option( DSB_Resync::OPTION_LOCK_UNTIL );
        if ( $resync_lock_until > time() ) {
            return $this->record_status( 'locked', __( 'Resync in progress; poll skipped.', 'pixlab-license-bridge' ), 0 );
        }

        if ( ! $this->acquire_lock( $lock_minutes ) ) {
            return $this->record_status( 'locked', __( 'Poll already running.', 'pixlab-license-bridge' ), 0 );
        }

        $started  = microtime( true );
        $per_page = max( 1, min( 500, (int) ( $settings['node_poll_per_page'] ?? 200 ) ) );
        $page     = 1;
        $errors   = [];

        $delete_stale_enabled = ! empty( $settings['node_poll_delete_stale'] );

        $remote_pairs            = [];
        $protected_pairs         = [];
        $conflicts               = [];
        $missing_identifier_items = [];
        $has_stable_identifiers  = true;
        $explicit_empty_remote   = false;

        $summary = [
            'pages'                    => 0,
            'items'                    => 0,
            'key_upserts'              => 0,
            'user_upserts'             => 0,
            'deleted_keys'             => 0,
            'deleted_users'            => 0,
            'remote_pairs_count'       => 0,
            'protected_pairs_count'    => 0,
            'skipped_legacy_keys'      => 0,
            'skipped_legacy_users'     => 0,
            'conflicts_count'          => 0,
        ];

        $error_context = [
            'http_code'     => 0,
            'url'           => '',
            'body_excerpt'  => '',
            'decoded_error' => '',
            'method'        => '',
        ];

        DSB_Cron_Logger::log( 'node_poll', 'Node poll started', [ 'manual' => $manual, 'per_page' => $per_page ] );

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

                    $processed = $this->process_item_strict( $item, $code );

                    if ( ! $processed['pair_valid'] ) {
                        $has_stable_identifiers = false;
                        if ( count( $missing_identifier_items ) < 3 ) {
                            $missing_identifier_items[] = [
                                'keys'             => array_keys( $item ),
                                'wp_user_id'       => $processed['wp_user_id'] ?? null,
                                'customer_email'   => $processed['customer_email'] ?? null,
                                'plan_slug'        => $processed['plan_slug'] ?? null,
                                'status'           => $processed['status'] ?? null,
                            ];
                        }
                    } else {
                        $remote_pairs[ $processed['pair_key'] ] = true;
                    }

                    $summary['key_upserts']  += in_array( $processed['key_result']['status'], [ 'inserted', 'updated' ], true ) ? 1 : 0;
                    $summary['user_upserts'] += in_array( $processed['user_result']['status'], [ 'inserted', 'updated' ], true ) ? 1 : 0;
                    $summary['skipped_legacy_keys']  += 'legacy' === $processed['key_result']['status'] ? 1 : 0;
                    $summary['skipped_legacy_users'] += 'legacy' === $processed['user_result']['status'] ? 1 : 0;

                    foreach ( [ 'key_result', 'user_result' ] as $result_key ) {
                        $result = $processed[ $result_key ];
                        if ( 'conflict' === ( $result['status'] ?? '' ) ) {
                            $summary['conflicts_count'] ++;
                            $local_conflict_pair = $result['conflict_local'] ?? [];
                            $local_wp            = absint( $local_conflict_pair['wp_user_id'] ?? 0 );
                            $local_sub           = isset( $local_conflict_pair['subscription_id'] ) ? sanitize_text_field( (string) $local_conflict_pair['subscription_id'] ) : '';
                            if ( $local_wp > 0 && '' !== $local_sub ) {
                                $protected_pairs[ $local_wp . '|' . $local_sub ] = true;
                            }
                            if ( count( $conflicts ) < 5 ) {
                                $conflicts[] = [
                                    'type'          => $result['conflict_type'] ?? 'unknown',
                                    'local'         => $result['conflict_local'] ?? [],
                                    'remote'        => $result['conflict_remote'] ?? [],
                                    'result_source' => $result_key,
                                ];
                            }
                        }
                    }
                }

                $total_pages = isset( $decoded['total_pages'] ) ? (int) $decoded['total_pages'] : 0;
                $has_more    = isset( $decoded['has_more'] ) ? (bool) $decoded['has_more'] : false;
                if ( empty( $items ) && 0 === (int) ( $decoded['total'] ?? $decoded['total_items'] ?? $decoded['count'] ?? 0 ) && ! $has_more ) {
                    $explicit_empty_remote = true;
                }
                $page ++;
            } while ( $items && ( $has_more || ( $total_pages && $page <= $total_pages ) || count( $items ) >= $per_page ) );
        } catch ( \Throwable $e ) {
            $errors[]                = $e->getMessage();
            $has_stable_identifiers  = false;
        }

        $summary['remote_pairs_count']    = count( $remote_pairs );
        $summary['protected_pairs_count'] = count( $protected_pairs );

        $stable_streak = (int) get_option( self::OPTION_STABLE_STREAK, 0 );

        if ( $errors || ! $has_stable_identifiers ) {
            $stable_streak = 0;
            update_option( self::OPTION_LAST_UNSTABLE, 1, false );
            update_option( self::OPTION_STABLE_STREAK, $stable_streak, false );
        } else {
            $stable_streak ++;
            update_option( self::OPTION_LAST_UNSTABLE, 0, false );
            update_option( self::OPTION_STABLE_STREAK, $stable_streak, false );
        }

        DSB_Cron_Logger::log(
            'node_poll',
            'Node poll fetch summary',
            [
                'fetched_count'                => $summary['items'],
                'pages'                        => $summary['pages'],
                'has_stable_identifiers'       => $has_stable_identifiers,
                'remote_pairs_count'           => $summary['remote_pairs_count'],
                'protected_pairs_count'        => $summary['protected_pairs_count'],
                'key_upserts'                  => $summary['key_upserts'],
                'user_upserts'                 => $summary['user_upserts'],
                'stable_streak'                => $stable_streak,
                'missing_identifier_samples'   => $missing_identifier_items,
                'errors_present'               => ! empty( $errors ),
                'delete_stale_enabled'         => $delete_stale_enabled,
                'skipped_legacy_keys'          => $summary['skipped_legacy_keys'],
                'skipped_legacy_users'         => $summary['skipped_legacy_users'],
                'conflicts_count'              => $summary['conflicts_count'],
                'conflict_samples'             => $conflicts,
                'explicit_empty_remote'        => $explicit_empty_remote,
            ]
        );

        if ( $has_stable_identifiers && $delete_stale_enabled && $stable_streak >= 2 && ! $errors ) {
            $deletion_flags = [
                'pair_deletions'        => 'skipped',
            ];

            $allow_empty_remote = $explicit_empty_remote && 0 === $summary['items'];

            $all_pairs = array_keys( array_merge( $remote_pairs, $protected_pairs ) );

            if ( $summary['remote_pairs_count'] || $allow_empty_remote ) {
                $summary['deleted_users'] += $this->db->delete_users_not_in_pairs( $all_pairs, 500, $allow_empty_remote );
                $summary['deleted_keys']  += $this->db->delete_keys_not_in_pairs( $all_pairs, 500, $allow_empty_remote );
                $deletion_flags['pair_deletions'] = 'ran';
            } else {
                $deletion_flags['pair_deletions'] = 'skipped_remote_pairs_empty';
            }

            DSB_Cron_Logger::log( 'node_poll', 'Node poll deletions executed', [
                'deleted_keys'                  => $summary['deleted_keys'],
                'deleted_users'                 => $summary['deleted_users'],
                'remote_pairs_count'            => $summary['remote_pairs_count'],
                'protected_pairs_count'         => $summary['protected_pairs_count'],
                'stable_streak'                 => $stable_streak,
                'explicit_empty_remote'         => $explicit_empty_remote,
                'deletion_flags'                => $deletion_flags,
            ] );
        } elseif ( $delete_stale_enabled ) {
            $skip_reason = $errors ? 'errors_present' : 'unstable_identifiers';
            if ( $stable_streak < 2 && ! $errors && $has_stable_identifiers ) {
                $skip_reason = 'stable_streak_below_threshold';
            }

            DSB_Cron_Logger::log( 'node_poll', 'Skipped deletions', [
                'skip_reason'                  => $skip_reason,
                'remote_pairs_count'           => $summary['remote_pairs_count'],
                'protected_pairs_count'        => $summary['protected_pairs_count'],
                'missing_identifier_samples'   => $missing_identifier_items,
                'stable_streak'                => $stable_streak,
                'explicit_empty_remote'        => $explicit_empty_remote,
            ] );
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
            $status = $this->record_status( 'error', implode( ';', $errors ), $duration );
            DSB_Cron_Logger::log( 'node_poll', 'Node poll failed', [ 'errors' => $errors, 'context' => $error_context, 'duration_ms' => $duration ] );
            DSB_Cron_Alerts::handle_job_result(
                'node_poll',
                $status['status'] ?? 'error',
                $error_excerpt,
                $settings,
                [
                    'last_run' => get_option( self::OPTION_LAST_RUN_AT ),
                    'next_run' => $this->format_next_run(),
                ]
            );
            return $status;
        }

        $this->record_last_error_context();
        $status = $this->record_status( 'ok', '', $duration );
        DSB_Cron_Logger::log( 'node_poll', 'Node poll completed', [ 'summary' => $summary, 'duration_ms' => $duration ] );
        DSB_Cron_Alerts::handle_job_result(
            'node_poll',
            $status['status'] ?? 'ok',
            '',
            $settings,
            [
                'last_run' => get_option( self::OPTION_LAST_RUN_AT ),
                'next_run' => $this->format_next_run(),
            ]
        );

        return $status;
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

    public function clear_lock(): void {
        delete_option( self::OPTION_LOCK_UNTIL );
    }

    protected function record_status( string $status, string $error = '', int $duration_ms = 0 ): array {
        update_option( self::OPTION_LAST_RUN_AT, current_time( 'mysql', true ) );
        update_option( self::OPTION_LAST_RESULT, $status );
        update_option( self::OPTION_LAST_ERROR, $error );
        update_option( self::OPTION_LAST_DURATION_MS, $duration_ms );

        return [ 'status' => $status, 'error' => $error, 'duration_ms' => $duration_ms ];
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

    protected function format_next_run(): string {
        $next = wp_next_scheduled( self::CRON_HOOK );
        return $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) : '';
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

    protected function process_item_strict( array $item, int $http_code ): array {
        $plan          = isset( $item['plan'] ) && is_array( $item['plan'] ) ? $item['plan'] : [];
        $plan_slug     = dsb_normalize_plan_slug( $plan['plan_slug'] ?? ( $item['plan_slug'] ?? '' ) );
        $subscription  = isset( $item['subscription_id'] ) ? sanitize_text_field( (string) $item['subscription_id'] ) : '';
        $external_sub  = isset( $item['external_subscription_id'] ) ? sanitize_text_field( (string) $item['external_subscription_id'] ) : '';
        $subscription_id = $subscription ?: $external_sub;
        $wp_user_id    = isset( $item['wp_user_id'] ) ? absint( $item['wp_user_id'] ) : 0;
        $customer_email = isset( $item['customer_email'] ) ? sanitize_email( $item['customer_email'] ) : sanitize_email( $item['email'] ?? '' );
        $customer_name = isset( $item['customer_name'] ) ? sanitize_text_field( $item['customer_name'] ) : null;
        $status         = isset( $item['status'] ) ? sanitize_text_field( $item['status'] ) : '';
        $subscription_status = isset( $item['subscription_status'] ) ? sanitize_text_field( $item['subscription_status'] ) : '';
        $valid_from     = $this->normalize_mysql_datetime( $item['valid_from'] ?? ( $item['valid_from_at'] ?? null ) );
        $valid_until    = $this->normalize_mysql_datetime( $item['valid_until'] ?? ( $item['valid_to'] ?? null ) );
        $node_plan_id   = isset( $plan['plan_id'] ) ? $plan['plan_id'] : ( $item['plan_id'] ?? null );

        $node_id = 0;
        if ( ! empty( $item['id'] ) ) {
            $node_id = absint( $item['id'] );
        } elseif ( ! empty( $item['api_key_id'] ) ) {
            $node_id = absint( $item['api_key_id'] );
        } elseif ( ! empty( $item['node_api_key_id'] ) ) {
            $node_id = absint( $item['node_api_key_id'] );
        }

        $node_api_key_id = $node_id;
        $order_id       = isset( $item['order_id'] ) ? absint( $item['order_id'] ) : null;
        $product_id     = isset( $item['product_id'] ) ? absint( $item['product_id'] ) : null;

        if ( ! $subscription_id && $wp_user_id && 'free' === $plan_slug ) {
            $subscription_id = 'free-' . $wp_user_id;
        }

        $status_value = strtolower( $subscription_status ?: $status );
        $is_active    = in_array( $status_value, [ 'active', 'ok' ], true );
        $missing_fields = [];
        if ( $is_active ) {
            if ( ! $subscription_id && ! $node_api_key_id ) {
                $missing_fields[] = 'subscription_id';
            }
            if ( '' === $plan_slug ) {
                $missing_fields[] = 'plan_slug';
            }
            if ( ! $valid_from && ! $valid_until ) {
                $missing_fields[] = 'validity';
            }
        }

        if ( $is_active && ! empty( $missing_fields ) ) {
            dsb_log(
                'warning',
                'Node poll active item missing required fields; skipping mirrors',
                [
                    'subscription_id' => $subscription_id,
                    'wp_user_id'      => $wp_user_id ?: null,
                    'customer_email'  => $customer_email,
                    'plan_slug'       => $plan_slug,
                    'missing_fields'  => $missing_fields,
                ]
            );

            return [
                'wp_user_id'      => $wp_user_id,
                'customer_email'  => $customer_email,
                'subscription_id' => $subscription_id,
                'node_api_key_id' => $node_api_key_id,
                'order_id'        => $order_id,
                'product_id'      => $product_id,
                'plan_slug'       => $plan_slug,
                'status'          => $subscription_status ?: ( $status ?: null ),
                'valid_from'      => $valid_from,
                'valid_until'     => $valid_until,
                'pair_key'        => null,
                'pair_valid'      => false,
                'key_result'      => [ 'status' => 'skipped' ],
                'user_result'     => [ 'status' => 'skipped' ],
            ];
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

        $key_result = $this->db->upsert_key_strict(
            [
                'subscription_id'     => $subscription_id,
                'customer_email'      => $customer_email,
                'customer_name'       => $customer_name,
                'wp_user_id'          => $wp_user_id ?: null,
                'plan_slug'           => $plan_slug,
                'status'              => $status ?: 'unknown',
                'subscription_status' => $subscription_status ?: null,
                'key_prefix'          => $key_prefix,
                'key_last4'           => $key_last4,
                'valid_from'          => $valid_from,
                'valid_until'         => $valid_until,
                'node_plan_id'        => $node_plan_id,
                'node_api_key_id'     => $node_api_key_id ?: null,
                'last_action'         => 'node_poll',
                'last_http_code'      => $http_code,
                'last_error'          => null,
            ]
        );

        $user_result = $this->db->upsert_user(
            [
                'wp_user_id'          => $wp_user_id ?: 0,
                'customer_email'      => $customer_email,
                'subscription_id'     => $subscription_id,
                'order_id'            => $order_id,
                'product_id'          => $product_id,
                'plan_slug'           => $plan_slug,
                'status'              => $subscription_status ?: ( $status ?: null ),
                'valid_from'          => $valid_from,
                'valid_until'         => $valid_until,
                'node_api_key_id'     => $node_api_key_id ?: null,
                'source'              => 'node_poll',
                'last_sync_at'        => current_time( 'mysql', true ),
            ]
        );

        $pair_valid = $wp_user_id > 0 && '' !== $subscription_id;

        return [
            'wp_user_id'      => $wp_user_id,
            'customer_email'  => $customer_email,
            'subscription_id' => $subscription_id,
            'node_api_key_id' => $node_api_key_id,
            'order_id'        => $order_id,
            'product_id'      => $product_id,
            'plan_slug'       => $plan_slug,
            'status'          => $subscription_status ?: ( $status ?: null ),
            'valid_from'      => $valid_from,
            'valid_until'     => $valid_until,
            'pair_key'        => $pair_valid ? $wp_user_id . '|' . $subscription_id : null,
            'pair_valid'      => $pair_valid,
            'key_result'      => $key_result,
            'user_result'     => $user_result,
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
