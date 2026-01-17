# Compatibility

- Requires WooCommerce and WPSwings Subscriptions; plugin halts initialization if absent.
- Product metadata uses WooCommerce helper functions (`woocommerce_wp_text_input`, etc.); ensure WooCommerce admin assets are available when extending.
- Resync uses WPS REST endpoint `/wp-json/wsp-route/v1/wsp-view-subscription` with consumer secret; verify version compatibility when upgrading WPS plugin.
- Cron relies on WP-Cron; for real CRON environments ensure events `dsb_resync_daily_event`, `dsb_node_poll_sync_event`, and `dsb_purge_worker_event` remain scheduled.
