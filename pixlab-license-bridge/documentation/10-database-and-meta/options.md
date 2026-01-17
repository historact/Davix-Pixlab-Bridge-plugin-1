# Options

- `dsb_settings`: main configuration (Node URL/token, logging toggles, resync/poll/purge settings, style and label overrides).
- `dsb_product_plans`: product/variation ID → plan slug mapping.
- `dsb_plan_products`: list of product IDs selected for plan sync.
- `dsb_plan_sync`: last plan sync status summary.
- `pixlab_license_db_version`: schema version string.
- `dsb_delete_on_uninstall`: boolean flag controlling cleanup.
- Resync options: `dsb_resync_lock_until`, `dsb_resync_last_run_at`, `dsb_resync_last_result`, `dsb_resync_last_error`.
- Node poll options: `dsb_node_poll_lock_until`, `dsb_node_poll_last_run_at`, `dsb_node_poll_last_result`, `dsb_node_poll_last_error`, `dsb_node_poll_last_http_code`, `dsb_node_poll_last_url`, `dsb_node_poll_last_body_excerpt`.
- Purge worker options: `dsb_purge_lock_until`, `dsb_purge_last_run_at`, `dsb_purge_last_result`, `dsb_purge_last_error`, `dsb_purge_last_duration_ms`, `dsb_purge_last_processed_count`.
