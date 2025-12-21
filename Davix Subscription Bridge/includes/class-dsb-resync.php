<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Resync {
    const CRON_HOOK            = 'dsb_daily_resync_event';
    const OPTION_LOCK_UNTIL    = 'dsb_resync_lock_until';
    const OPTION_LAST_RUN_AT   = 'dsb_resync_last_run_at';
    const OPTION_LAST_RESULT   = 'dsb_resync_last_result';
    const OPTION_LAST_ERROR    = 'dsb_resync_last_error';
    const OPTION_LAST_DURATION = 'dsb_resync_last_duration_ms';

    /** @var DSB_Client */
    protected $client;
    /** @var DSB_DB */
    protected $db;

    public function __construct( DSB_Client $client, DSB_DB $db ) {
        $this->client = $client;
        $this->db     = $db;
    }

    public function init(): void {
        add_action( self::CRON_HOOK, [ $this, 'handle_cron' ] );
        add_action( 'init', [ $this, 'maybe_schedule_cron' ] );
    }

    public function handle_cron(): void {
        $this->run();
    }

    public function maybe_schedule_cron(): void {
        $settings  = $this->client->get_settings();
        $scheduled = wp_next_scheduled( self::CRON_HOOK );

        if ( empty( $settings['enable_daily_resync'] ) ) {
            $this->unschedule();
            return;
        }

        if ( ! $scheduled ) {
            $timestamp = $this->next_run_timestamp( (int) ( $settings['resync_run_hour'] ?? 3 ) );
            wp_schedule_event( $timestamp, 'daily', self::CRON_HOOK );
        }
    }

    public function run( bool $is_manual = false ): array {
        $settings = $this->client->get_settings();

        if ( ! $is_manual && empty( $settings['enable_daily_resync'] ) ) {
            return $this->record_status( 'skipped', __( 'Daily resync disabled', 'davix-sub-bridge' ) );
        }

        $lock_minutes = max( 5, (int) ( $settings['resync_lock_minutes'] ?? 30 ) );
        if ( ! $this->acquire_lock( $lock_minutes ) ) {
            return [ 'status' => 'locked', 'processed' => 0, 'skipped' => 0 ];
        }

        $processed = 0;
        $skipped   = 0;
        $result    = 'ok';
        $error     = '';
        $started   = microtime( true );

        DSB_Cron_Logger::log( 'resync', 'Daily resync started', [ 'manual' => $is_manual ] );

        try {
            $memberships = $this->client->fetch_pmpro_memberships_all();

            if ( is_wp_error( $memberships ) ) {
                $result = 'error';
                $error  = $memberships->get_error_message();
            } elseif ( ! is_array( $memberships ) ) {
                $result = 'error';
                $error  = __( 'Unexpected membership payload', 'davix-sub-bridge' );
            } else {
                $batch_size      = max( 20, min( 500, (int) ( $settings['resync_batch_size'] ?? 100 ) ) );
                $active_user_ids = [];
                foreach ( array_chunk( $memberships, $batch_size ) as $chunk ) {
                    foreach ( $chunk as $item ) {
                        $ok = $this->process_membership_item( $item, $settings, $active_user_ids );
                        if ( $ok ) {
                            $processed ++;
                        } else {
                            $skipped ++;
                        }
                    }
                }

                if ( ! empty( $settings['resync_disable_non_active'] ) ) {
                    $processed += $this->process_missing_memberships( $active_user_ids );
                }
            }
        } catch ( \Throwable $e ) {
            $result = 'error';
            $error  = $e->getMessage();
            dsb_log( 'error', 'Resync failed', [ 'message' => $e->getMessage() ] );
        } finally {
            $this->release_lock();
            $duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
            $status      = $this->record_status( $result, $error, $duration_ms );
            DSB_Cron_Logger::log( 'resync', 'Daily resync finished', [ 'status' => $result, 'duration_ms' => $duration_ms, 'processed' => $processed, 'skipped' => $skipped ] );
            DSB_Cron_Alerts::handle_job_result(
                'resync',
                $status['status'] ?? $result,
                $error,
                $settings,
                [
                    'last_run' => get_option( self::OPTION_LAST_RUN_AT ),
                    'next_run' => $this->format_next_run( $settings ),
                ]
            );
        }

        return [ 'status' => $result, 'processed' => $processed, 'skipped' => $skipped, 'error' => $error ];
    }

    public function get_last_status(): array {
        return [
            'last_run_at' => get_option( self::OPTION_LAST_RUN_AT ),
            'last_result' => get_option( self::OPTION_LAST_RESULT ),
            'last_error'  => get_option( self::OPTION_LAST_ERROR ),
            'last_duration_ms' => (int) get_option( self::OPTION_LAST_DURATION, 0 ),
            'lock_until'       => (int) get_option( self::OPTION_LOCK_UNTIL, 0 ),
        ];
    }

    protected function process_membership_item( $item, array $settings, array &$active_user_ids ): bool {
        $user_id  = isset( $item['user_id'] ) ? absint( $item['user_id'] ) : 0;
        $level_id = isset( $item['level_id'] ) ? absint( $item['level_id'] ) : 0;
        $status   = isset( $item['status'] ) ? strtolower( (string) $item['status'] ) : 'active';

        if ( $user_id <= 0 || $level_id <= 0 ) {
            return false;
        }

        $user = get_userdata( $user_id );
        $email = $user instanceof \WP_User ? $user->user_email : '';
        $name  = '';
        if ( $user instanceof \WP_User ) {
            $name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
            if ( ! $name ) {
                $name = $user->display_name ?? '';
            }
        }

        if ( 'active' === $status ) {
            $active_user_ids[] = $user_id;
        }

        $plan_slug = $this->client->plan_slug_for_level( $level_id );
        $end_ts    = isset( $item['end_ts'] ) ? (int) $item['end_ts'] : 0;
        $is_lifetime = $end_ts <= 0;
        $valid_from = ! empty( $item['startdate'] ) ? DSB_Util::to_iso_utc( $item['startdate'] ) : null;
        $valid_from_ts = $valid_from ? strtotime( $valid_from ) : time();
        $valid_until = null;

        if ( $is_lifetime ) {
            $valid_until = null;
        } else {
            $valid_until = $end_ts > 0 ? DSB_Util::to_iso_utc( $end_ts ) : DSB_Util::to_iso_utc( self::compute_fallback_valid_until_ts( $level_id, $valid_from_ts ) );
        }

        $event = 'active' === $status ? 'active' : 'cancelled';
        if ( 'active' !== $status && empty( $settings['resync_disable_non_active'] ) ) {
            return true;
        }

        if ( 'active' === $event && ! $plan_slug ) {
            dsb_log( 'warning', 'Resync skip send_event: plan missing for PMPro level', [ 'user_id' => $user_id, 'level_id' => $level_id ] );
            return true;
        }

        $payload = [
            'event'               => $event,
            'wp_user_id'          => $user_id,
            'customer_email'      => $email ? strtolower( sanitize_email( $email ) ) : '',
            'customer_name'       => $name ? sanitize_text_field( $name ) : '',
            'subscription_id'     => 'pmpro-' . $user_id . '-' . $level_id,
            'product_id'          => $level_id,
            'plan_slug'           => $plan_slug,
            'subscription_status' => $event === 'active' ? 'active' : $status,
            'pmpro_is_lifetime'   => $is_lifetime,
        ];

        if ( $valid_from ) {
            $payload['valid_from'] = $valid_from;
        }

        if ( $valid_until ) {
            $payload['valid_until'] = $valid_until;
        }

        $this->client->send_event( $payload );
        dsb_log(
            'debug',
            'PMPro resync payload validity prepared',
            [
                'event'             => $event,
                'user_id'           => $user_id,
                'level_id'          => $level_id,
                'pmpro_is_lifetime' => $is_lifetime,
                'valid_from'        => $payload['valid_from'] ?? null,
                'valid_until'       => $payload['valid_until'] ?? null,
            ]
        );
        return true;
    }

    protected function process_missing_memberships( array $active_user_ids ): int {
        $active_user_ids = array_values( array_filter( array_map( 'absint', array_unique( $active_user_ids ) ) ) );
        $tracked_users   = $this->db->get_tracked_user_ids();
        $processed       = 0;

        foreach ( $tracked_users as $user_id ) {
            $user_id = absint( $user_id );
            if ( $user_id <= 0 || in_array( $user_id, $active_user_ids, true ) ) {
                continue;
            }

            $user  = get_userdata( $user_id );
            $email = $user instanceof \WP_User ? $user->user_email : '';

            $payload = [
                'event'               => 'cancelled',
                'wp_user_id'          => $user_id,
                'customer_email'      => $email ? strtolower( sanitize_email( $email ) ) : '',
                'subscription_id'     => 'pmpro-' . $user_id . '-0',
                'subscription_status' => 'cancelled',
            ];

            $this->client->send_event( $payload );
            $processed ++;
        }

        return $processed;
    }

    protected function map_status_to_event( string $status ): string {
        $status = strtolower( $status );
        $inactive_map = [
            'cancelled'       => 'cancelled',
            'canceled'        => 'cancelled',
            'expired'         => 'expired',
            'paused'          => 'paused',
            'on-hold'         => 'paused',
            'payment_failed'  => 'payment_failed',
            'payment-failed'  => 'payment_failed',
            'disabled'        => 'disabled',
        ];

        if ( isset( $inactive_map[ $status ] ) ) {
            return $inactive_map[ $status ];
        }

        $active_statuses = [ 'active', 'processing', 'completed', 'renewed', 'reactivated' ];
        if ( in_array( $status, $active_statuses, true ) ) {
            return 'active';
        }

        return $status ? 'active' : '';
    }

    protected static function compute_fallback_valid_until_ts( int $level_id, int $validFromTs ): int {
        $level_meta = is_numeric( $level_id ) && $level_id > 0 ? get_option( 'dsb_level_meta_' . $level_id, [] ) : [];
        $period     = is_array( $level_meta ) && ! empty( $level_meta['billing_period'] ) ? strtolower( (string) $level_meta['billing_period'] ) : 'monthly';

        if ( 'year' === $period || 'yearly' === $period ) {
            return strtotime( '+1 year', $validFromTs ) ?: ( $validFromTs + ( 365 * DAY_IN_SECONDS ) );
        }

        return strtotime( '+1 month', $validFromTs ) ?: ( $validFromTs + ( 30 * DAY_IN_SECONDS ) );
    }

    protected function resolve_plan_slug( int $product_id, \WC_Order $order, int $subscription_id ): string {
        $plans = $this->client->get_product_plans();
        if ( $product_id && isset( $plans[ $product_id ] ) ) {
            return dsb_normalize_plan_slug( $plans[ $product_id ] );
        }

        $plan_slug = (string) $order->get_meta( '_dsb_plan_slug', true );

        if ( ! $plan_slug && $subscription_id ) {
            $plan_slug = (string) get_post_meta( $subscription_id, '_dsb_plan_slug', true );
            if ( ! $plan_slug ) {
                $plan_slug = (string) get_post_meta( $subscription_id, 'wps_sfw_plan_slug', true );
            }
        }

        return $plan_slug ? dsb_normalize_plan_slug( $plan_slug ) : '';
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

    protected function acquire_lock( int $minutes ): bool {
        $lock_until = (int) get_option( self::OPTION_LOCK_UNTIL );
        if ( $lock_until > time() ) {
            return false;
        }

        $expires = time() + ( $minutes * 60 );
        update_option( self::OPTION_LOCK_UNTIL, $expires, false );
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
        update_option( self::OPTION_LAST_DURATION, $duration_ms );

        return [ 'status' => $status, 'error' => $error, 'duration_ms' => $duration_ms ];
    }

    protected function next_run_timestamp( int $hour ): int {
        try {
            $tz     = wp_timezone();
            $now    = new \DateTimeImmutable( 'now', $tz );
            $target = $now->setTime( $hour, 0, 0 );
            if ( $target->getTimestamp() <= $now->getTimestamp() ) {
                $target = $target->modify( '+1 day' );
            }
            return $target->getTimestamp();
        } catch ( \Throwable $e ) {
            return time() + DAY_IN_SECONDS;
        }
    }

    protected function unschedule(): void {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );
        while ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
            $timestamp = wp_next_scheduled( self::CRON_HOOK );
        }
    }

    protected function format_next_run( array $settings ): string {
        $scheduled = wp_next_scheduled( self::CRON_HOOK );
        if ( $scheduled ) {
            return gmdate( 'Y-m-d H:i:s', (int) $scheduled );
        }

        $hour = isset( $settings['resync_run_hour'] ) ? (int) $settings['resync_run_hour'] : 3;
        return gmdate( 'Y-m-d H:i:s', $this->next_run_timestamp( $hour ) );
    }
}
