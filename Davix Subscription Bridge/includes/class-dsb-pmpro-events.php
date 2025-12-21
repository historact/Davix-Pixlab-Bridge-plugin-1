<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_PMPro_Events {
    /** @var DSB_Client */
    protected static $client;
    /** @var DSB_DB */
    protected static $db;

    public static function init( DSB_Client $client, DSB_DB $db ): void {
        self::$client = $client;
        self::$db     = $db;

        add_action( 'pmpro_after_checkout', [ __CLASS__, 'handle_after_checkout' ], 10, 2 );
        add_action( 'pmpro_after_change_membership_level', [ __CLASS__, 'handle_change_membership_level' ], 10, 2 );
        add_action( 'pmpro_subscription_payment_completed', [ __CLASS__, 'handle_payment_completed' ], 10, 1 );
        add_action( 'pmpro_subscription_payment_failed', [ __CLASS__, 'handle_payment_failed' ], 10, 1 );
    }

    public static function handle_after_checkout( $user_id, $morder ): void {
        $user_id = absint( $user_id );
        if ( $user_id <= 0 ) {
            return;
        }

        $level_id = self::resolve_level_id_from_order( $morder, $user_id );
        $plan_slug = $level_id ? self::$client->plan_slug_for_level( $level_id ) : '';

        $payload = [
            'event'               => 'activated',
            'wp_user_id'          => $user_id,
            'customer_email'      => self::user_email( $user_id ),
            'customer_name'       => self::user_name( $user_id ),
            'subscription_id'     => self::subscription_id( $user_id, $level_id ),
            'order_id'            => isset( $morder->id ) ? (string) $morder->id : '',
            'product_id'          => $level_id ?: null,
            'plan_slug'           => $plan_slug,
            'subscription_status' => 'active',
            'valid_from'          => gmdate( 'c' ),
        ];

        $valid_until = self::level_valid_until( $user_id );
        $payload['valid_until'] = self::ensure_valid_until( 'activated', $user_id, $level_id, $payload['valid_from'], $valid_until );

        if ( ! $plan_slug ) {
            dsb_log( 'error', 'PMPro event skipped: missing plan_slug', [ 'event' => 'activated', 'user_id' => $user_id, 'level_id' => $level_id ] );
            return;
        }
        self::$client->send_event( $payload );
    }

    public static function handle_change_membership_level( $level_id, $user_id ): void {
        $user_id = absint( $user_id );
        $level_id = absint( $level_id );

        if ( $level_id <= 0 ) {
            $payload = [
                'event'               => 'cancelled',
                'wp_user_id'          => $user_id,
                'customer_email'      => self::user_email( $user_id ),
                'subscription_id'     => self::subscription_id( $user_id, 0 ),
                'subscription_status' => 'cancelled',
            ];
            self::$client->send_event( $payload );
            return;
        }

        $plan_slug = self::$client->plan_slug_for_level( $level_id );
        $payload   = [
            'event'               => 'active',
            'wp_user_id'          => $user_id,
            'customer_email'      => self::user_email( $user_id ),
            'customer_name'       => self::user_name( $user_id ),
            'subscription_id'     => self::subscription_id( $user_id, $level_id ),
            'product_id'          => $level_id,
            'plan_slug'           => $plan_slug,
            'subscription_status' => 'active',
            'valid_from'          => gmdate( 'c' ),
        ];

        $valid_until = self::level_valid_until( $user_id );
        $payload['valid_until'] = self::ensure_valid_until( 'active', $user_id, $level_id, $payload['valid_from'], $valid_until );

        if ( ! $plan_slug ) {
            dsb_log( 'error', 'PMPro event skipped: missing plan_slug', [ 'event' => 'active', 'user_id' => $user_id, 'level_id' => $level_id ] );
            return;
        }
        self::$client->send_event( $payload );
    }

    public static function handle_payment_completed( $morder ): void {
        self::handle_payment_event( $morder, 'renewed' );
    }

    public static function handle_payment_failed( $morder ): void {
        self::handle_payment_event( $morder, 'payment_failed' );
    }

    protected static function handle_payment_event( $morder, string $event ): void {
        $user_id  = isset( $morder->user_id ) ? absint( $morder->user_id ) : 0;
        $level_id = self::resolve_level_id_from_order( $morder, $user_id );

        if ( $user_id <= 0 ) {
            return;
        }

        $payload = [
            'event'               => $event,
            'wp_user_id'          => $user_id,
            'customer_email'      => self::user_email( $user_id ),
            'customer_name'       => self::user_name( $user_id ),
            'subscription_id'     => self::subscription_id( $user_id, $level_id ),
            'order_id'            => isset( $morder->id ) ? (string) $morder->id : '',
            'product_id'          => $level_id ?: null,
            'subscription_status' => 'active',
        ];

        if ( $level_id ) {
            $payload['plan_slug'] = self::$client->plan_slug_for_level( $level_id );
        }

        $valid_until = self::level_valid_until( $user_id );
        $payload['valid_until'] = self::ensure_valid_until( $event, $user_id, $level_id, $payload['valid_from'] ?? null, $valid_until );

        if ( in_array( $event, [ 'activated', 'active', 'renewed', 'reactivated' ], true ) && empty( $payload['plan_slug'] ) ) {
            dsb_log( 'error', 'PMPro event skipped: missing plan_slug', [ 'event' => $event, 'user_id' => $user_id, 'level_id' => $level_id ] );
            return;
        }

        self::$client->send_event( $payload );
    }

    protected static function subscription_id( int $user_id, int $level_id ): string {
        return 'pmpro-' . $user_id . '-' . $level_id;
    }

    protected static function user_email( int $user_id ): string {
        $user = get_userdata( $user_id );
        $email = $user instanceof \WP_User ? $user->user_email : '';
        return $email ? strtolower( sanitize_email( $email ) ) : '';
    }

    protected static function user_name( int $user_id ): string {
        $user = get_userdata( $user_id );
        if ( ! $user instanceof \WP_User ) {
            return '';
        }

        $name = trim( (string) $user->first_name . ' ' . (string) $user->last_name );
        if ( ! $name ) {
            $name = $user->display_name ?? '';
        }

        return $name ? sanitize_text_field( $name ) : '';
    }

    protected static function level_valid_until( int $user_id ): ?string {
        if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            return null;
        }

        $level = pmpro_getMembershipLevelForUser( $user_id );
        if ( empty( $level ) || empty( $level->enddate ) ) {
            return null;
        }

        if ( is_numeric( $level->enddate ) ) {
            return gmdate( 'c', (int) $level->enddate );
        }

        try {
            $dt = new \DateTimeImmutable( is_string( $level->enddate ) ? $level->enddate : '' );
            return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'c' );
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    protected static function resolve_level_id_from_order( $morder, int $user_id ): int {
        if ( isset( $morder->membership_id ) && $morder->membership_id ) {
            return (int) $morder->membership_id;
        }

        if ( isset( $morder->membership_level ) && is_object( $morder->membership_level ) && isset( $morder->membership_level->id ) ) {
            return (int) $morder->membership_level->id;
        }

        if ( function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            $level = pmpro_getMembershipLevelForUser( $user_id );
            if ( $level && isset( $level->id ) ) {
                return (int) $level->id;
            }
        }

        return 0;
    }

    protected static function ensure_valid_until( string $event, int $user_id, int $level_id, ?string $valid_from_iso, ?string $valid_until_iso ): ?string {
        $activation_events = [ 'activated', 'active', 'renewed', 'reactivated' ];
        if ( ! in_array( strtolower( $event ), $activation_events, true ) ) {
            return $valid_until_iso ?: null;
        }

        $now          = time();
        $valid_from_ts = $valid_from_iso ? strtotime( $valid_from_iso ) : $now;
        $candidate    = $valid_until_iso;

        if ( ! $candidate ) {
            $level_meta  = is_numeric( $level_id ) && $level_id > 0 ? get_option( 'dsb_level_meta_' . $level_id, [] ) : [];
            $period      = is_array( $level_meta ) && ! empty( $level_meta['billing_period'] ) ? strtolower( (string) $level_meta['billing_period'] ) : 'monthly';
            $days_to_add = ( 'year' === $period || 'yearly' === $period ) ? 366 : 31;
            $candidate   = gmdate( 'c', $now + ( $days_to_add * DAY_IN_SECONDS ) );
        }

        $valid_until_ts = strtotime( $candidate );
        if ( $valid_until_ts && $valid_until_ts <= $valid_from_ts ) {
            $valid_until_ts = $valid_from_ts + ( 31 * DAY_IN_SECONDS );
            $candidate      = gmdate( 'c', $valid_until_ts );
        }

        return $candidate ?: null;
    }
}
