<?php
namespace Davix\SubscriptionBridge;

defined( 'ABSPATH' ) || exit;

class DSB_User_Purger {
    /** @var DSB_DB */
    protected static $db;
    /** @var DSB_Purge_Worker */
    protected static $worker;

    public static function register( DSB_DB $db, DSB_Purge_Worker $worker ): void {
        self::$db     = $db;
        self::$worker = $worker;

        add_action( 'delete_user', __NAMESPACE__ . '\\dsb_handle_delete_user_pre', 10, 2 );
        add_action( 'deleted_user', __NAMESPACE__ . '\\dsb_handle_deleted_user_post', 10, 2 );
        add_action( 'remove_user_from_blog', __NAMESPACE__ . '\\dsb_handle_delete_user_pre', 10, 2 );
    }

    public static function handle_pre( int $user_id, $reassign = null ): void {
        if ( ! $user_id || ! self::$db || ! self::$worker ) {
            return;
        }

        $user  = get_userdata( $user_id );
        $email = $user ? $user->user_email : null;

        $identities       = self::$db->get_identities_for_wp_user_id( $user_id );
        $emails           = $identities['emails'] ?? [];
        $subscription_ids = $identities['subscription_ids'] ?? [];

        if ( $email ) {
            $emails[] = $email;
        }

        $emails           = array_values( array_filter( array_unique( $emails ) ) );
        $subscription_ids = array_values( array_filter( array_unique( $subscription_ids ) ) );

        $subscription_id = $subscription_ids ? reset( $subscription_ids ) : null;

        self::$db->enqueue_purge_job(
            [
                'wp_user_id'       => $user_id,
                'customer_email'   => $emails[0] ?? null,
                'subscription_id'  => $subscription_id,
                'subscription_ids' => $subscription_ids,
                'reason'           => 'wp_user_deleted',
            ]
        );

        self::$worker->run_once();
    }

    public static function handle_post( int $user_id, $reassign = null ): void {
        if ( ! $user_id || ! self::$db ) {
            return;
        }
        return;
    }
}

function dsb_handle_delete_user_pre( $user_id, $reassign = null ): void {
    DSB_User_Purger::handle_pre( (int) $user_id, $reassign );
}

function dsb_handle_deleted_user_post( $user_id, $reassign = null ): void {
    DSB_User_Purger::handle_post( (int) $user_id, $reassign );
}
