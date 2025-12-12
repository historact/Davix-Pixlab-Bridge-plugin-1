<?php
/**
 * Plugin Name: Davix Subscription Bridge
 * Plugin URI: https://pixlab.davix.dev
 * Description: Sync WooCommerce + WPSwings Subscriptions with the Davix Node.js license API.
 * Version: 1.0.0
 * Author: Davix
 * License: GPL2+
 * Text Domain: davix-sub-bridge
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'DSB_VERSION', '1.0.0' );
define( 'DSB_PLUGIN_FILE', __FILE__ );
define( 'DSB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DSB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-dsb-db.php';
require_once __DIR__ . '/includes/class-dsb-client.php';
require_once __DIR__ . '/includes/class-dsb-admin.php';
require_once __DIR__ . '/includes/class-dsb-events.php';
require_once __DIR__ . '/includes/class-dsb-keys-table.php';
require_once __DIR__ . '/includes/class-dsb-plugin.php';

register_activation_hook( __FILE__, '\\Davix\\SubscriptionBridge\\DSB_Plugin::activate' );
register_uninstall_hook( __FILE__, '\\Davix\\SubscriptionBridge\\DSB_Plugin::uninstall' );

add_action( 'plugins_loaded', static function () {
    \Davix\SubscriptionBridge\DSB_Plugin::instance();
}, 20 );
