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
        $valid_from = gmdate( 'c' );

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
            'valid_from'          => $valid_from,
        ];

        $end_ts       = self::get_pmpro_end_ts( $user_id, $level_id );
        $is_lifetime  = self::is_pmpro_lifetime( $user_id, $level_id, $end_ts );
        $payload['pmpro_is_lifetime'] = $is_lifetime;

        $payload['valid_until'] = $is_lifetime
            ? null
            : (
                $end_ts > 0
                    ? gmdate( 'c', $end_ts )
                    : gmdate( 'c', self::compute_fallback_valid_until_ts( $level_id, strtotime( $valid_from ) ?: time() ) )
            );

        dsb_log(
            'debug',
            'PMPro payload validity prepared',
            [
                'event'              => 'activated',
                'user_id'            => $user_id,
                'level_id'           => $level_id,
                'pmpro_is_lifetime'  => $is_lifetime,
                'valid_from'         => $payload['valid_from'],
                'valid_until'        => $payload['valid_until'],
            ]
        );

        if ( ! $plan_slug ) {
            dsb_log( 'error', 'PMPro event skipped: missing plan_slug', [ 'event' => 'activated', 'user_id' => $user_id, 'level_id' => $level_id ] );
            return;
        }
        $payload['event_id'] = DSB_Util::event_id_from_payload( $payload );
        self::dispatch_provision_payload( $payload, 'pmpro_after_checkout' );
    }

    public static function handle_change_membership_level( $level_id, $user_id ): void {
        $user_id = absint( $user_id );
        $level_id = absint( $level_id );

        if ( $level_id <= 0 ) {
            $membership = self::get_latest_membership_row( $user_id );
            $now_ts     = time();
            $end_ts     = isset( $membership['end_ts'] ) ? (int) $membership['end_ts'] : 0;
            $prev_level = isset( $membership['level_id'] ) ? absint( $membership['level_id'] ) : 0;
            $is_lifetime = $end_ts <= 0;
            $has_remaining = $is_lifetime || $end_ts > $now_ts;

            if ( $has_remaining && $prev_level > 0 ) {
                $plan_slug = self::$client->plan_slug_for_level( $prev_level );
                $payload   = [
                    'event'               => 'cancelled',
                    'wp_user_id'          => $user_id,
                    'customer_email'      => self::user_email( $user_id ),
                    'customer_name'       => self::user_name( $user_id ),
                    'subscription_id'     => self::subscription_id( $user_id, $prev_level ),
                    'product_id'          => $prev_level,
                    'plan_slug'           => $plan_slug,
                    'subscription_status' => 'cancelled',
                    'pmpro_is_lifetime'   => $is_lifetime,
                ];

                if ( ! empty( $membership['startdate'] ) ) {
                    $payload['valid_from'] = DSB_Util::to_iso_utc( $membership['startdate'] );
                }

                if ( ! $is_lifetime && $end_ts > 0 ) {
                    $payload['valid_until'] = gmdate( 'c', $end_ts );
                }

                dsb_log( 'debug', 'PMPro cancellation with remaining time', [
                    'user_id'     => $user_id,
                    'level_id'    => $prev_level,
                    'end_ts'      => $end_ts,
                    'plan_slug'   => $plan_slug,
                    'valid_until' => $payload['valid_until'] ?? null,
                ] );

                $payload['event_id'] = DSB_Util::event_id_from_payload( $payload );
                self::dispatch_provision_payload( $payload, 'pmpro_change_membership_level_cancelled' );
                return;
            }

            $payload = [
                'event'               => 'expired',
                'wp_user_id'          => $user_id,
                'customer_email'      => self::user_email( $user_id ),
                'subscription_id'     => self::subscription_id( $user_id, 0 ),
            'subscription_status' => 'expired',
        ];
        $payload['event_id'] = DSB_Util::event_id_from_payload( $payload );
        self::dispatch_provision_payload( $payload, 'pmpro_change_membership_level_expired' );
        return;
        }

        $plan_slug = self::$client->plan_slug_for_level( $level_id );
        $valid_from = gmdate( 'c' );
        $payload   = [
            'event'               => 'active',
            'wp_user_id'          => $user_id,
            'customer_email'      => self::user_email( $user_id ),
            'customer_name'       => self::user_name( $user_id ),
            'subscription_id'     => self::subscription_id( $user_id, $level_id ),
            'product_id'          => $level_id,
            'plan_slug'           => $plan_slug,
            'subscription_status' => 'active',
            'valid_from'          => $valid_from,
        ];

        $end_ts       = self::get_pmpro_end_ts( $user_id, $level_id );
        $is_lifetime  = self::is_pmpro_lifetime( $user_id, $level_id, $end_ts );
        $payload['pmpro_is_lifetime'] = $is_lifetime;

        $payload['valid_until'] = $is_lifetime
            ? null
            : (
                $end_ts > 0
                    ? gmdate( 'c', $end_ts )
                    : gmdate( 'c', self::compute_fallback_valid_until_ts( $level_id, strtotime( $valid_from ) ?: time() ) )
            );

        dsb_log(
            'debug',
            'PMPro payload validity prepared',
            [
                'event'              => 'active',
                'user_id'            => $user_id,
                'level_id'           => $level_id,
                'pmpro_is_lifetime'  => $is_lifetime,
                'valid_from'         => $payload['valid_from'],
                'valid_until'        => $payload['valid_until'],
            ]
        );

        if ( ! $plan_slug ) {
            dsb_log( 'error', 'PMPro event skipped: missing plan_slug', [ 'event' => 'active', 'user_id' => $user_id, 'level_id' => $level_id ] );
            return;
        }
        $payload['event_id'] = DSB_Util::event_id_from_payload( $payload );
        self::dispatch_provision_payload( $payload, 'pmpro_change_membership_level_active' );
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

        $valid_from = 'renewed' === $event ? gmdate( 'c' ) : null;
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

        if ( $valid_from ) {
            $payload['valid_from'] = $valid_from;
        }

        if ( in_array( $event, [ 'activated', 'active', 'renewed', 'reactivated' ], true ) ) {
            $end_ts      = self::get_pmpro_end_ts( $user_id, $level_id );
            $is_lifetime = self::is_pmpro_lifetime( $user_id, $level_id, $end_ts );
            $payload['pmpro_is_lifetime'] = $is_lifetime;

            $valid_from_ts          = $valid_from ? strtotime( $valid_from ) : time();
            $payload['valid_until'] = $is_lifetime
                ? null
                : (
                    $end_ts > 0
                        ? gmdate( 'c', $end_ts )
                        : gmdate( 'c', self::compute_fallback_valid_until_ts( $level_id, $valid_from_ts ?: time() ) )
                );

            dsb_log(
                'debug',
                'PMPro payload validity prepared',
                [
                    'event'              => $event,
                    'user_id'            => $user_id,
                    'level_id'           => $level_id,
                    'pmpro_is_lifetime'  => $is_lifetime,
                    'valid_from'         => $payload['valid_from'] ?? null,
                    'valid_until'        => $payload['valid_until'] ?? null,
                ]
            );
        } else {
            $payload['valid_until'] = null;
        }

        if ( in_array( $event, [ 'activated', 'active', 'renewed', 'reactivated' ], true ) && empty( $payload['plan_slug'] ) ) {
            dsb_log( 'error', 'PMPro event skipped: missing plan_slug', [ 'event' => $event, 'user_id' => $user_id, 'level_id' => $level_id ] );
            return;
        }

        $payload['event_id'] = DSB_Util::event_id_from_payload( $payload );
        self::dispatch_provision_payload( $payload, 'pmpro_payment_event' );
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

    private static function get_pmpro_end_ts( int $user_id, int $level_id ): int {
        if ( ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            return 0;
        }

        $level = pmpro_getMembershipLevelForUser( $user_id );
        if ( empty( $level ) || ! isset( $level->id ) ) {
            return 0;
        }

        if ( $level_id > 0 && (int) $level->id !== (int) $level_id ) {
            return 0;
        }

        if ( ! empty( $level->enddate ) ) {
            if ( is_numeric( $level->enddate ) && (int) $level->enddate > 0 ) {
                return (int) $level->enddate;
            }

            if ( is_string( $level->enddate ) ) {
                $ts = strtotime( $level->enddate );
                return $ts && $ts > 0 ? $ts : 0;
            }
        }

        return 0;
    }

    private static function is_pmpro_lifetime( int $user_id, int $level_id, int $endTs ): bool {
        return $endTs <= 0;
    }

    private static function compute_fallback_valid_until_ts( int $level_id, int $validFromTs ): int {
        $level_meta = is_numeric( $level_id ) && $level_id > 0 ? get_option( 'dsb_level_meta_' . $level_id, [] ) : [];
        $period     = is_array( $level_meta ) && ! empty( $level_meta['billing_period'] ) ? strtolower( (string) $level_meta['billing_period'] ) : 'monthly';

        if ( 'year' === $period || 'yearly' === $period ) {
            return strtotime( '+1 year', $validFromTs ) ?: ( $validFromTs + ( 365 * DAY_IN_SECONDS ) );
        }

        return strtotime( '+1 month', $validFromTs ) ?: ( $validFromTs + ( 30 * DAY_IN_SECONDS ) );
    }

    private static function get_latest_membership_row( int $user_id ): ?array {
        global $wpdb;

        $table = $wpdb->prefix . 'pmpro_memberships_users';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) {
            return null;
        }

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY startdate DESC, id DESC LIMIT 1", $user_id ), ARRAY_A );
        if ( ! $row ) {
            return null;
        }

        $end_ts = 0;
        if ( isset( $row['enddate'] ) ) {
            if ( is_numeric( $row['enddate'] ) ) {
                $end_ts = (int) $row['enddate'];
            } elseif ( is_string( $row['enddate'] ) && '' !== $row['enddate'] ) {
                $parsed = strtotime( $row['enddate'] );
                $end_ts = $parsed && $parsed > 0 ? $parsed : 0;
            }
        }

        return [
            'level_id'  => isset( $row['membership_id'] ) ? absint( $row['membership_id'] ) : 0,
            'startdate' => $row['startdate'] ?? null,
            'end_ts'    => $end_ts,
        ];
    }

    public static function build_active_payload_for_user( int $user_id, ?int $level_id = null ): ?array {
        $user_id = absint( $user_id );
        $level_id = $level_id ? absint( $level_id ) : 0;

        if ( $user_id <= 0 || ! function_exists( 'pmpro_getMembershipLevelForUser' ) ) {
            return null;
        }

        $level = pmpro_getMembershipLevelForUser( $user_id );
        if ( empty( $level ) || empty( $level->id ) ) {
            dsb_log( 'warning', 'Self-heal skipped: no PMPro level found', [ 'user_id' => $user_id ] );
            return null;
        }

        $resolved_level_id = $level_id > 0 ? $level_id : absint( $level->id );
        if ( $resolved_level_id <= 0 ) {
            return null;
        }

        $plan_slug = self::$client ? self::$client->plan_slug_for_level( $resolved_level_id ) : '';
        if ( ! $plan_slug ) {
            dsb_log( 'warning', 'Self-heal skipped: missing plan slug', [ 'user_id' => $user_id, 'level_id' => $resolved_level_id ] );
            return null;
        }

        $valid_from = gmdate( 'c' );
        $end_ts      = self::get_pmpro_end_ts( $user_id, $resolved_level_id );
        $is_lifetime = self::is_pmpro_lifetime( $user_id, $resolved_level_id, $end_ts );
        $valid_from_ts = strtotime( $valid_from ) ?: time();

        $payload = [
            'event'               => 'active',
            'wp_user_id'          => $user_id,
            'customer_email'      => self::user_email( $user_id ),
            'customer_name'       => self::user_name( $user_id ),
            'subscription_id'     => self::subscription_id( $user_id, $resolved_level_id ),
            'product_id'          => $resolved_level_id,
            'plan_slug'           => $plan_slug,
            'subscription_status' => 'active',
            'valid_from'          => $valid_from,
            'pmpro_is_lifetime'   => $is_lifetime,
        ];

        $payload['valid_until'] = $is_lifetime
            ? null
            : (
                $end_ts > 0
                    ? gmdate( 'c', $end_ts )
                    : gmdate( 'c', self::compute_fallback_valid_until_ts( $resolved_level_id, $valid_from_ts ) )
            );

        $payload['event_id'] = DSB_Util::event_id_from_payload( $payload );

        return $payload;
    }

    public static function dispatch_provision_payload( array $payload, string $context = '' ): array {
        if ( empty( $payload['event_id'] ) ) {
            $payload['event_id'] = DSB_Util::event_id_from_payload( $payload );
        }

        $result  = self::$client ? self::$client->send_event( $payload ) : [ 'success' => false, 'code' => 0 ];
        $success = ! empty( $result['success'] );

        $response_body = '';
        if ( isset( $result['response'] ) && ! is_wp_error( $result['response'] ) ) {
            $response_body = wp_remote_retrieve_body( $result['response'] );
        } elseif ( isset( $result['response'] ) && is_wp_error( $result['response'] ) ) {
            $response_body = $result['response']->get_error_message();
        }

        $log_context = [
            'context'      => $context,
            'event'        => $payload['event'] ?? '',
            'wp_user_id'   => $payload['wp_user_id'] ?? null,
            'plan_slug'    => $payload['plan_slug'] ?? '',
            'level_id'     => $payload['product_id'] ?? null,
            'endpoint'     => '/internal/subscription/event',
            'http_code'    => $result['code'] ?? null,
            'response'     => $response_body ? dsb_mask_string( substr( (string) $response_body, 0, 500 ) ) : '',
            'result'       => $result['decoded']['status'] ?? null,
        ];

        if ( $success ) {
            dsb_log( 'info', 'Provisioning request sent', $log_context );
            return $result;
        }

        if ( self::$db ) {
            self::$db->enqueue_provision_job( $payload );
        }

        dsb_log( 'warning', 'Provisioning request failed; queued for retry', $log_context + [ 'queued' => true ] );

        return $result;
    }
}
