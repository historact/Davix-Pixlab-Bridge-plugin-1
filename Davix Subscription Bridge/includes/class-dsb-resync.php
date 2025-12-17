<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_Resync {
    const CRON_HOOK            = 'dsb_daily_resync_event';
    const OPTION_LOCK_UNTIL    = 'dsb_resync_lock_until';
    const OPTION_LAST_RUN_AT   = 'dsb_resync_last_run_at';
    const OPTION_LAST_RESULT   = 'dsb_resync_last_result';
    const OPTION_LAST_ERROR    = 'dsb_resync_last_error';

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

        try {
            $subscriptions = $this->client->fetch_wps_subscriptions_all();

            if ( is_wp_error( $subscriptions ) ) {
                $result = 'error';
                $error  = $subscriptions->get_error_message();
            } elseif ( ! is_array( $subscriptions ) ) {
                $result = 'error';
                $error  = __( 'Unexpected subscription payload', 'davix-sub-bridge' );
            } else {
                $batch_size = max( 20, min( 500, (int) ( $settings['resync_batch_size'] ?? 100 ) ) );
                foreach ( array_chunk( $subscriptions, $batch_size ) as $chunk ) {
                    foreach ( $chunk as $item ) {
                        $ok = $this->process_subscription_item( $item, $settings );
                        if ( $ok ) {
                            $processed ++;
                        } else {
                            $skipped ++;
                        }
                    }
                }
            }
        } catch ( \Throwable $e ) {
            $result = 'error';
            $error  = $e->getMessage();
            dsb_log( 'error', 'Resync failed', [ 'message' => $e->getMessage() ] );
        } finally {
            $this->release_lock();
            $this->record_status( $result, $error );
        }

        return [ 'status' => $result, 'processed' => $processed, 'skipped' => $skipped, 'error' => $error ];
    }

    public function get_last_status(): array {
        return [
            'last_run_at' => get_option( self::OPTION_LAST_RUN_AT ),
            'last_result' => get_option( self::OPTION_LAST_RESULT ),
            'last_error'  => get_option( self::OPTION_LAST_ERROR ),
        ];
    }

    protected function process_subscription_item( $item, array $settings ): bool {
        $subscription_id = isset( $item['subscription_id'] ) ? absint( $item['subscription_id'] ) : ( isset( $item['id'] ) ? absint( $item['id'] ) : 0 );
        $order_id        = isset( $item['parent_order_id'] ) ? absint( $item['parent_order_id'] ) : ( isset( $item['order_id'] ) ? absint( $item['order_id'] ) : 0 );
        $status          = isset( $item['status'] ) ? strtolower( (string) $item['status'] ) : '';

        if ( ! $order_id ) {
            dsb_log( 'warning', 'Resync skipped: missing parent order', [ 'subscription_id' => $subscription_id ?: null ] );
            return false;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order instanceof \WC_Order ) {
            dsb_log( 'warning', 'Resync skipped: order not found', [ 'order_id' => $order_id, 'subscription_id' => $subscription_id ?: null ] );
            return false;
        }

        $wp_user_id = (int) $order->get_user_id();
        if ( $wp_user_id <= 0 ) {
            dsb_log( 'warning', 'Resync skipped: user missing on order', [ 'order_id' => $order_id, 'subscription_id' => $subscription_id ?: null ] );
            return false;
        }

        $customer_email = $order->get_billing_email();
        $product_id     = 0;

        foreach ( $order->get_items() as $item_obj ) {
            $product_id = $item_obj->get_variation_id() ?: $item_obj->get_product_id();
            if ( $product_id ) {
                break;
            }
        }

        $plan_slug = $this->resolve_plan_slug( $product_id, $order, $subscription_id );

        $valid_from  = $order->get_date_created() instanceof \WC_DateTime ? $order->get_date_created()->getTimestamp() : null;
        $valid_until = $this->normalize_mysql_datetime( $item['subscriptions_expiry_date'] ?? ( $item['expiry_date'] ?? null ) );

        if ( ! $valid_until ) {
            $valid_until = $this->normalize_mysql_datetime( get_post_meta( $subscription_id, '_dsb_valid_until', true ) ?: get_post_meta( $subscription_id, '_dsb_wps_valid_until', true ) );
        }

        if ( ! $valid_until ) {
            $valid_until = $this->normalize_mysql_datetime( $order->get_meta( DSB_Events::ORDER_META_VALID_UNTIL ) );
        }

        $this->db->upsert_user(
            [
                'wp_user_id'      => $wp_user_id,
                'customer_email'  => $customer_email,
                'subscription_id' => $subscription_id ?: null,
                'order_id'        => $order_id,
                'product_id'      => $product_id ?: null,
                'plan_slug'       => $plan_slug,
                'status'          => $status ?: null,
                'valid_from'      => $valid_from ? gmdate( 'Y-m-d H:i:s', $valid_from ) : null,
                'valid_until'     => $valid_until,
                'source'          => 'wps_rest',
                'last_sync_at'    => current_time( 'mysql', true ),
            ]
        );

        $event = $this->map_status_to_event( $status );
        if ( ! $event ) {
            return true;
        }

        $inactive_events = [ 'cancelled', 'expired', 'paused', 'payment_failed', 'disabled' ];
        if ( in_array( $event, $inactive_events, true ) && empty( $settings['resync_disable_non_active'] ) ) {
            return true;
        }

        if ( in_array( $event, [ 'active', 'activated', 'renewed', 'reactivated' ], true ) && ! $plan_slug ) {
            dsb_log( 'warning', 'Resync skip send_event: plan missing', [ 'order_id' => $order_id, 'subscription_id' => $subscription_id ?: null ] );
            return true;
        }

        $payload = [
            'event'               => $event,
            'wp_user_id'          => $wp_user_id,
            'customer_email'      => $customer_email,
            'subscription_id'     => $subscription_id ? (string) $subscription_id : '',
            'order_id'            => (string) $order_id,
            'plan_slug'           => $plan_slug,
            'subscription_status' => $status,
            'product_id'          => $product_id ?: null,
        ];

        if ( $valid_from ) {
            $payload['valid_from'] = DSB_Util::to_iso_utc( $valid_from );
        }

        if ( $valid_until ) {
            $payload['valid_until'] = DSB_Util::to_iso_utc( $valid_until );
        }

        $this->client->send_event( $payload );

        return true;
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

    protected function record_status( string $status, string $error = '' ): array {
        update_option( self::OPTION_LAST_RUN_AT, current_time( 'mysql', true ) );
        update_option( self::OPTION_LAST_RESULT, $status );
        update_option( self::OPTION_LAST_ERROR, $error );

        return [ 'status' => $status, 'error' => $error ];
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
}
