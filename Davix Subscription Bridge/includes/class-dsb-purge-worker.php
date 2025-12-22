<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Purge_Worker {
    const CRON_HOOK     = 'dsb_purge_queue_event';
    const SCHEDULE_KEY  = 'dsb_every_five_minutes';
    const MAX_ATTEMPTS  = 10;
    const OPTION_LOCK_UNTIL   = 'dsb_purge_lock_until';
    const OPTION_LAST_RUN_AT  = 'dsb_purge_last_run_at';
    const OPTION_LAST_RESULT  = 'dsb_purge_last_result';
    const OPTION_LAST_ERROR   = 'dsb_purge_last_error';
    const OPTION_LAST_DURATION_MS = 'dsb_purge_last_duration_ms';
    const OPTION_LAST_PROCESSED = 'dsb_purge_last_processed_count';

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
        if ( ! isset( $schedules[ self::SCHEDULE_KEY ] ) ) {
            $schedules[ self::SCHEDULE_KEY ] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 minutes', 'davix-sub-bridge' ),
            ];
        }

        return $schedules;
    }

    public function maybe_schedule(): void {
        $settings = $this->client->get_settings();

        if ( empty( $settings['enable_purge_worker'] ) ) {
            $this->unschedule();
            return;
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE_KEY, self::CRON_HOOK );
        }
    }

    public function unschedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public function run( bool $manual = false, ?int $limit = null ): array {
        $settings = $this->client->get_settings();

        if ( empty( $settings['enable_purge_worker'] ) ) {
            $this->unschedule();
            return $this->record_status( 'skipped_disabled', '', 0, 0 );
        }

        $lock_minutes = max( 1, min( 120, (int) ( $settings['purge_lock_minutes'] ?? 10 ) ) );
        if ( ! $this->acquire_lock( $lock_minutes ) ) {
            return $this->record_status( 'skipped_locked', __( 'Purge worker locked', 'davix-sub-bridge' ), 0, 0 );
        }

        $lease_minutes  = max( 1, min( 120, (int) ( $settings['purge_lease_minutes'] ?? 15 ) ) );
        $batch_size     = max( 1, min( 100, (int) ( $limit ?? ( $settings['purge_batch_size'] ?? 20 ) ) ) );
        $processed      = 0;
        $result         = 'ok';
        $error_message  = '';
        $started        = microtime( true );
        $claim_token    = bin2hex( random_bytes( 16 ) );

        DSB_Cron_Logger::log( 'purge_worker', 'Purge worker started', [ 'manual' => $manual, 'batch_size' => $batch_size ] );

        try {
            $jobs = $this->db->claim_pending_purge_jobs( $batch_size, $claim_token, $lease_minutes * MINUTE_IN_SECONDS );
            foreach ( $jobs as $job ) {
                $this->process_job( $job );
                $processed ++;
            }
        } catch ( \Throwable $e ) {
            $result        = 'error';
            $error_message = $e->getMessage();
            dsb_log( 'error', 'Purge worker failed', [ 'error' => $error_message ] );
        } finally {
            $duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
            $this->release_lock();
            $status = $this->record_status( $result, $error_message, $duration_ms, $processed );
            DSB_Cron_Logger::log( 'purge_worker', 'Purge worker finished', [ 'status' => $result, 'duration_ms' => $duration_ms, 'processed' => $processed ] );
            DSB_Cron_Alerts::handle_job_result(
                'purge_worker',
                $status['status'] ?? $result,
                $error_message,
                $settings,
                [
                    'last_run' => get_option( self::OPTION_LAST_RUN_AT ),
                    'next_run' => $this->format_next_run(),
                ]
            );

            return $status;
        }
    }

    public function run_once(): array {
        return $this->run( true );
    }

    protected function process_job( array $job ): void {
        $job_id  = (int) ( $job['id'] ?? 0 );
        if ( ! $job_id ) {
            return;
        }

        $attempt    = (int) ( $job['attempts'] ?? 0 );
        $wp_user_id = isset( $job['wp_user_id'] ) ? absint( $job['wp_user_id'] ) : 0;
        $emails     = [];
        $subs       = [];

        if ( ! empty( $job['customer_email'] ) ) {
            $emails[] = sanitize_email( $job['customer_email'] );
        }

        if ( ! empty( $job['subscription_id'] ) ) {
            $subs[] = sanitize_text_field( (string) $job['subscription_id'] );
        }

        $identities = $wp_user_id ? $this->db->get_identities_for_wp_user_id( $wp_user_id ) : [ 'emails' => [], 'subscription_ids' => [] ];
        $emails     = array_values( array_filter( array_unique( array_merge( $emails, $identities['emails'] ?? [] ) ) ) );
        $subs       = array_values( array_filter( array_unique( array_merge( $subs, $identities['subscription_ids'] ?? [] ) ) ) );

        $payload = [ 'reason' => sanitize_key( $job['reason'] ?? 'manual' ) ];

        if ( $wp_user_id ) {
            $payload['wp_user_id'] = $wp_user_id;
        }

        if ( $emails ) {
            $payload['customer_email'] = $emails[0];
        }

        if ( $subs ) {
            $payload['subscription_ids'] = $subs;
        }

        $response     = $this->client->purge_user_on_node( $payload );
        $response_obj = $response['response'] ?? null;
        $code         = (int) ( $response['code'] ?? 0 );
        $decoded      = $response['decoded'] ?? null;

        $status_value = is_array( $decoded ) && isset( $decoded['status'] ) ? strtolower( (string) $decoded['status'] ) : '';
        $status_ok = $code >= 200 && $code < 300 && ( ! is_array( $decoded ) || ! isset( $decoded['status'] ) || in_array( $status_value, [ 'ok', 'active', 'disabled' ], true ) );
        $error     = '';

        if ( ! $status_ok ) {
            if ( is_wp_error( $response_obj ) ) {
                $error = $response_obj->get_error_message();
            } elseif ( is_array( $decoded ) && isset( $decoded['status'] ) ) {
                $error = (string) $decoded['status'];
            } else {
                $error = 'unexpected_response';
            }
        }

        $log_data = [
            'event'           => 'purge',
            'customer_email'  => $emails[0] ?? null,
            'subscription_id' => $subs[0] ?? null,
            'response_action' => $job['reason'] ?? '',
            'http_code'       => $code,
            'error_excerpt'   => $status_ok ? 'ok' : $error,
        ];
        $this->db->log_event( $log_data );

        if ( $status_ok ) {
            $this->db->delete_user_rows_local( $wp_user_id, $emails, $subs );
            $this->db->mark_job_done( $job_id );
            dsb_log( 'info', 'Purge job completed', [ 'job_id' => $job_id, 'code' => $code, 'wp_user_id' => $wp_user_id ?: null ] );
        } else {
            $this->db->mark_job_error( $job, $error, self::MAX_ATTEMPTS );
            dsb_log( 'error', 'Purge job failed', [ 'job_id' => $job_id, 'code' => $code, 'error' => $error, 'attempt' => $attempt ] );
        }
    }

    public function clear_lock(): void {
        delete_option( self::OPTION_LOCK_UNTIL );
    }

    public function get_last_status(): array {
        return [
            'last_run_at'      => get_option( self::OPTION_LAST_RUN_AT, '' ),
            'last_result'      => get_option( self::OPTION_LAST_RESULT, '' ),
            'last_error'       => get_option( self::OPTION_LAST_ERROR, '' ),
            'last_duration_ms' => (int) get_option( self::OPTION_LAST_DURATION_MS, 0 ),
            'last_processed'   => (int) get_option( self::OPTION_LAST_PROCESSED, 0 ),
            'lock_until'       => (int) get_option( self::OPTION_LOCK_UNTIL, 0 ),
        ];
    }

    protected function acquire_lock( int $minutes ): bool {
        $lock_until = (int) get_option( self::OPTION_LOCK_UNTIL );
        if ( $lock_until > time() ) {
            return false;
        }

        return update_option( self::OPTION_LOCK_UNTIL, time() + ( $minutes * MINUTE_IN_SECONDS ) );
    }

    protected function release_lock(): void {
        delete_option( self::OPTION_LOCK_UNTIL );
    }

    protected function record_status( string $result, string $error, int $duration_ms, int $processed ): array {
        update_option( self::OPTION_LAST_RUN_AT, current_time( 'mysql', true ) );
        update_option( self::OPTION_LAST_RESULT, $result );
        update_option( self::OPTION_LAST_ERROR, wp_strip_all_tags( $error ) );
        update_option( self::OPTION_LAST_DURATION_MS, $duration_ms );
        update_option( self::OPTION_LAST_PROCESSED, $processed );

        return [
            'status'    => $result,
            'error'     => $error,
            'processed' => $processed,
            'duration'  => $duration_ms,
        ];
    }

    protected function format_next_run(): string {
        $next = wp_next_scheduled( self::CRON_HOOK );
        return $next ? gmdate( 'Y-m-d H:i:s', (int) $next ) : '';
    }
}
