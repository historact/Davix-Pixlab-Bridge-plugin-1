<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Provision_Worker {
    const CRON_HOOK     = 'dsb_provision_queue_event';
    const SCHEDULE_KEY  = 'dsb_every_minute';
    const MAX_ATTEMPTS  = 10;
    const LOCK_MINUTES  = 1;
    const LEASE_SECONDS = 120;
    const BATCH_SIZE    = 20;
    const OPTION_LOCK_UNTIL   = 'dsb_provision_lock_until';
    const OPTION_LAST_RUN_AT  = 'dsb_provision_last_run_at';
    const OPTION_LAST_RUN_TS  = 'dsb_provision_last_run_ts';
    const OPTION_LAST_RESULT  = 'dsb_provision_last_result';
    const OPTION_LAST_ERROR   = 'dsb_provision_last_error';
    const OPTION_LAST_DURATION_MS = 'dsb_provision_last_duration_ms';
    const OPTION_LAST_PROCESSED = 'dsb_provision_last_processed_count';

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
                'interval' => MINUTE_IN_SECONDS,
                'display'  => __( 'Every minute', 'pixlab-license-bridge' ),
            ];
        }

        return $schedules;
    }

    public function maybe_schedule(): void {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE_KEY, self::CRON_HOOK );
            dsb_log( 'info', 'cron.schedule', [ 'event' => 'cron.schedule', 'job' => 'provision_worker' ] );
        }
    }

    public function unschedule(): void {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    public function run( bool $manual = false, ?int $limit = null ): array {
        DSB_Cron_Logger::log( 'provision_worker', 'Provision worker stage: lock.acquire', [ 'manual' => $manual ] );
        if ( ! $this->acquire_lock( self::LOCK_MINUTES ) ) {
            return $this->record_status( 'skipped_locked', __( 'Provision worker locked', 'pixlab-license-bridge' ), 0, 0 );
        }

        $batch_size  = max( 1, min( 100, (int) ( $limit ?? self::BATCH_SIZE ) ) );
        $processed   = 0;
        $result      = 'ok';
        $error_msg   = '';
        $started     = microtime( true );
        $claim_token = bin2hex( random_bytes( 16 ) );

        DSB_Cron_Logger::log( 'provision_worker', 'Provision worker started', [ 'manual' => $manual, 'batch_size' => $batch_size ] );

        try {
            DSB_Cron_Logger::log( 'provision_worker', 'Provision worker stage: queue.fetch', [ 'batch_size' => $batch_size ] );
            $jobs = $this->db->claim_pending_provision_jobs( $batch_size, $claim_token, self::LEASE_SECONDS );
            DSB_Cron_Logger::log( 'provision_worker', 'Provision worker stage: queue.fetch.result', [ 'count' => count( $jobs ) ] );
            foreach ( $jobs as $job ) {
                DSB_Cron_Logger::log( 'provision_worker', 'Provision worker stage: item.process', [ 'job_id' => $job['id'] ?? 0 ] );
                $job_ok = $this->process_job( $job );
                $processed ++;
                if ( ! $job_ok ) {
                    $result = 'error';
                    $error_msg = $error_msg ?: __( 'Provisioning failed', 'pixlab-license-bridge' );
                }
            }
        } catch ( \Throwable $e ) {
            $result     = 'error';
            $error_msg  = $e->getMessage();
            dsb_log( 'error', 'Provision worker failed', [ 'error' => $error_msg ] );
        } finally {
            $duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
            $this->release_lock();
            DSB_Cron_Logger::log( 'provision_worker', 'Provision worker stage: lock.release', [] );
            $status = $this->record_status( $result, $error_msg, $duration_ms, $processed );
            DSB_Cron_Logger::log( 'provision_worker', 'Provision worker finished', [ 'status' => $result, 'duration_ms' => $duration_ms, 'processed' => $processed ] );

            $settings = $this->client->get_settings();
            DSB_Cron_Alerts::handle_job_result(
                'provision_worker',
                $status['status'] ?? $result,
                $error_msg,
                $settings,
                [
                    'last_run' => get_option( self::OPTION_LAST_RUN_AT ),
                    'next_run' => $this->format_next_run(),
                ]
            );

            dsb_log( 'info', 'cron.run.finish', [
                'event' => 'cron.run.finish',
                'job'   => 'provision_worker',
                'status' => $status['status'] ?? $result,
                'processed' => $processed,
                'duration_ms' => $duration_ms,
            ] );

            return $status;
        }
    }

    public function run_once(): array {
        return $this->run( true );
    }

    protected function process_job( array $job ): bool {
        $job_id = (int) ( $job['id'] ?? 0 );
        if ( ! $job_id ) {
            return true;
        }

        $payload = json_decode( $job['payload'] ?? '', true );
        if ( ! is_array( $payload ) ) {
            $this->db->mark_provision_job_error( $job, 'invalid_payload', self::MAX_ATTEMPTS, $this->get_retry_delay_seconds( 1 ) );
            dsb_log( 'error', 'Provision job payload invalid', [ 'job_id' => $job_id ] );
            return false;
        }

        $this->validate_plan_mapping_for_payload( $payload );

        DSB_Cron_Logger::log( 'provision_worker', 'Provision worker stage: pixlab.call', [ 'job_id' => $job_id, 'event' => $payload['event'] ?? '' ] );
        $result  = $this->client->send_event( $payload );
        $success = ! empty( $result['success'] );
        $error   = $this->resolve_error_message( $result );

        if ( $success ) {
            DSB_Cron_Logger::log( 'provision_worker', 'Provision worker stage: db.update', [ 'job_id' => $job_id, 'status' => 'done' ] );
            $this->db->mark_provision_job_done( $job_id );
            dsb_log( 'info', 'Provision job completed', [ 'job_id' => $job_id, 'event' => $payload['event'] ?? '' ] );
            return true;
        }

        $attempts = (int) ( $job['attempts'] ?? 0 );
        $delay    = $this->get_retry_delay_seconds( $attempts + 1 );
        DSB_Cron_Logger::log( 'provision_worker', 'Provision worker stage: db.update', [ 'job_id' => $job_id, 'status' => 'error', 'attempt' => $attempts + 1 ] );
        $this->db->mark_provision_job_error( $job, $error, self::MAX_ATTEMPTS, $delay );
        dsb_log( 'error', 'Provision job failed', [ 'job_id' => $job_id, 'attempt' => $attempts + 1, 'error' => $error ] );

        return false;
    }

    protected function resolve_error_message( array $result ): string {
        if ( isset( $result['validation_error'] ) && $result['validation_error'] ) {
            return (string) $result['validation_error'];
        }

        $response = $result['response'] ?? null;
        if ( is_wp_error( $response ) ) {
            return $response->get_error_message();
        }

        $decoded = $result['decoded'] ?? null;
        if ( is_array( $decoded ) && isset( $decoded['status'] ) ) {
            return (string) $decoded['status'];
        }

        return 'unexpected_response';
    }

    protected function get_retry_delay_seconds( int $attempt ): int {
        $attempt = max( 1, $attempt );
        $base    = 60;
        $delay   = $base * ( 2 ** ( $attempt - 1 ) );
        return (int) min( 3600, $delay );
    }

    public function clear_lock(): void {
        delete_option( self::OPTION_LOCK_UNTIL );
    }

    public function get_last_status(): array {
        return [
            'last_run_at'      => get_option( self::OPTION_LAST_RUN_AT, '' ),
            'last_run_ts'      => (int) get_option( self::OPTION_LAST_RUN_TS, 0 ),
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
        update_option( self::OPTION_LAST_RUN_TS, time() );
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

    protected function validate_plan_mapping_for_payload( array $payload ): void {
        $settings = $this->client->get_settings();
        if ( empty( $settings['alerts_enable_license_validation'] ) ) {
            return;
        }

        $subscription_id = isset( $payload['subscription_id'] ) ? sanitize_text_field( (string) $payload['subscription_id'] ) : '';
        if ( 0 !== strpos( $subscription_id, 'pmpro-' ) ) {
            return;
        }

        $level_id = isset( $payload['product_id'] ) ? absint( $payload['product_id'] ) : 0;
        if ( $level_id <= 0 ) {
            return;
        }

        $expected_plan = $this->client->plan_slug_for_level( $level_id );
        $observed_plan = isset( $payload['plan_slug'] ) ? dsb_normalize_plan_slug( $payload['plan_slug'] ) : '';

        if ( ! $expected_plan || $expected_plan !== $observed_plan ) {
            DSB_Cron_Alerts::trigger_generic_alert(
                'license.plan_mapping_mismatch',
                __( 'License Plan Mapping Mismatch', 'pixlab-license-bridge' ),
                [
                    'level_id'     => $level_id,
                    'subscription' => $subscription_id,
                    'expected_plan' => $expected_plan ?: '(missing)',
                    'observed_plan' => $observed_plan ?: '(missing)',
                ],
                'error'
            );
        } else {
            DSB_Cron_Alerts::trigger_generic_recovery(
                'license.plan_mapping_mismatch',
                __( 'License Plan Mapping Mismatch', 'pixlab-license-bridge' ),
                [
                    'level_id'     => $level_id,
                    'subscription' => $subscription_id,
                    'expected_plan' => $expected_plan,
                    'observed_plan' => $observed_plan,
                ]
            );
        }
    }
}

/*
Manual verification checklist:
1) Trigger PMPro checkout while Node is slow/down; confirm checkout completes and a provision job is queued.
2) Verify worker retries with exponential backoff (1m, 2m, 4m...) and stops after 10 attempts.
3) Ensure dashboard shows "Provisioning…" while a job is queued, and "Provisioning failed" after max attempts.
4) Confirm DB logs store masked excerpts (no tokens/keys visible).
5) Save node_base_url with http/private/localhost; confirm it is rejected and requests blocked.
6) Force repeated provisioning failures and confirm alert notifications fire, then recover on success.
*/
