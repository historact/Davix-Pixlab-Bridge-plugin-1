<?php
/**
 * Cleanup for PixLab License Bridge.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

defined( 'DSB_PLUGIN_FILE' ) || define( 'DSB_PLUGIN_FILE', __FILE__ );
defined( 'DSB_PLUGIN_DIR' ) || define( 'DSB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once __DIR__ . '/includes/class-dsb-db.php';
require_once __DIR__ . '/includes/class-dsb-client.php';
require_once __DIR__ . '/includes/class-dsb-logger.php';
require_once __DIR__ . '/includes/class-dsb-cron-logger.php';
require_once __DIR__ . '/includes/class-dsb-cron-alerts.php';
require_once __DIR__ . '/includes/class-dsb-resync.php';
require_once __DIR__ . '/includes/class-dsb-node-poll.php';
require_once __DIR__ . '/includes/class-dsb-purge-worker.php';
require_once __DIR__ . '/includes/class-dsb-provision-worker.php';
require_once __DIR__ . '/includes/class-dsb-plugin.php';

\Davix\SubscriptionBridge\DSB_Plugin::full_uninstall_cleanup();
