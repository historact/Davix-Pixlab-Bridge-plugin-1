# Uninstall

- Uninstall hook: `DSB_Plugin::uninstall()` plus `uninstall.php` fallback.
- Removal is gated by the `dsb_delete_on_uninstall` option. When truthy:
  - Drops custom tables (`davix_bridge_logs`, `davix_bridge_keys`, `davix_bridge_user`, `davix_bridge_purge_queue`).
  - Clears scheduled hooks for purge worker, Node poll, and resync.
  - Deletes options: `dsb_settings`, `dsb_product_plans`, `dsb_plan_products`, `dsb_plan_sync`, resync/node-poll/purge lock and status options, `dsb_db_version`, and uninstall flag itself.
- If the flag is falsey, data and schedules are retained.
