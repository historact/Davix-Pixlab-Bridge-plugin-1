<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Purge_Worker {
    const CRON_HOOK     = 'dsb_purge_queue_event';
    const SCHEDULE_KEY  = 'dsb_every_five_minutes';
    const MAX_ATTEMPTS  = 10;

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
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::SCHEDULE_KEY, self::CRON_HOOK );
        }
    }

    public function run(): void {
        $this->process_queue();
    }

    public function run_once(): void {
        $this->process_queue( 5 );
    }

    protected function process_queue( int $limit = 20 ): void {
        $jobs = $this->db->fetch_pending_purge_jobs( $limit );
        foreach ( $jobs as $job ) {
            $this->process_job( $job );
        }
    }

    protected function process_job( array $job ): void {
        $job_id  = (int) ( $job['id'] ?? 0 );
        $attempt = (int) ( $job['attempts'] ?? 0 ) + 1;
        if ( ! $job_id ) {
            return;
        }

        $this->db->mark_job_processing( $job_id );

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

        $this->db->delete_user_rows_local( $wp_user_id, $emails, $subs );

        $status_ok = $code >= 200 && $code < 300 && ( ! is_array( $decoded ) || ! isset( $decoded['status'] ) || 'ok' === $decoded['status'] );
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
            $this->db->mark_job_done( $job_id );
            dsb_log( 'info', 'Purge job completed', [ 'job_id' => $job_id, 'code' => $code, 'wp_user_id' => $wp_user_id ?: null ] );
        } else {
            $this->db->mark_job_error( $job_id, $error, $attempt, self::MAX_ATTEMPTS );
            dsb_log( 'error', 'Purge job failed', [ 'job_id' => $job_id, 'code' => $code, 'error' => $error, 'attempt' => $attempt ] );
        }
    }
}
