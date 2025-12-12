<?php
/**
 * Plugin Name: Davix Pixlab Bridge
 * Plugin URI: https://pixlab.davix.dev
 * Description: Sync WooCommerce plans and license keys to the Davix Pixlab Node.js API.
 * Version: 1.0.0
 * Author: Davix
 * License: GPL2+
 * Text Domain: davix-pixlab-bridge
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DPB_VERSION' ) ) {
    define( 'DPB_VERSION', '1.0.0' );
}

if ( ! defined( 'DPB_PLUGIN_FILE' ) ) {
    define( 'DPB_PLUGIN_FILE', __FILE__ );
}

if ( ! defined( 'DPB_PLUGIN_DIR' ) ) {
    define( 'DPB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'DPB_PLUGIN_URL' ) ) {
    define( 'DPB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

require_once DPB_PLUGIN_DIR . 'includes/class-dpb-plugin.php';
require_once DPB_PLUGIN_DIR . 'includes/class-dpb-client.php';
require_once DPB_PLUGIN_DIR . 'includes/class-dpb-admin-settings.php';
require_once DPB_PLUGIN_DIR . 'includes/class-dpb-sync.php';

add_action(
    'plugins_loaded',
    function () {
        // Ensure WooCommerce and LMFWC are loaded before registering hooks.
        if ( ! class_exists( 'WooCommerce' ) || ! defined( 'LMFWC_VERSION' ) ) {
            return;
        }

        \dpb\DPB_Plugin::instance();
    },
    20
);