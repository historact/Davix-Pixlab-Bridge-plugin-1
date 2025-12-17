<?php
/**
 * Cleanup for Davix Subscription Bridge.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-dsb-db.php';
require_once __DIR__ . '/includes/class-dsb-client.php';
require_once __DIR__ . '/includes/class-dsb-resync.php';

if ( get_option( \Davix\SubscriptionBridge\DSB_DB::OPTION_DELETE_ON_UNINSTALL ) ) {
    $db = new \Davix\SubscriptionBridge\DSB_DB( $GLOBALS['wpdb'] );
    $db->drop_tables();
    delete_option( \Davix\SubscriptionBridge\DSB_DB::OPTION_DELETE_ON_UNINSTALL );
    delete_option( \Davix\SubscriptionBridge\DSB_DB::OPTION_DB_VERSION );
    delete_option( \Davix\SubscriptionBridge\DSB_Client::OPTION_SETTINGS );
    delete_option( \Davix\SubscriptionBridge\DSB_Client::OPTION_PRODUCT_PLANS );
    delete_option( \Davix\SubscriptionBridge\DSB_Client::OPTION_PLAN_PRODUCTS );
    delete_option( \Davix\SubscriptionBridge\DSB_Client::OPTION_PLAN_SYNC );
    delete_option( \Davix\SubscriptionBridge\DSB_Resync::OPTION_LOCK_UNTIL );
    delete_option( \Davix\SubscriptionBridge\DSB_Resync::OPTION_LAST_RUN_AT );
    delete_option( \Davix\SubscriptionBridge\DSB_Resync::OPTION_LAST_RESULT );
    delete_option( \Davix\SubscriptionBridge\DSB_Resync::OPTION_LAST_ERROR );
}
