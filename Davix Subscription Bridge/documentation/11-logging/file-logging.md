# File Logging

- Controlled by `debug_enabled`, `debug_level`, and `debug_retention_days` in settings.
- Log directory: `wp-content/uploads/davix-bridge-logs/`; created with index.php and .htaccess to block listing.
- Files named `dsb-YYYY-MM-DD.log`; fallback `dsb.log` supported.
- Entries include timestamp, level, message, and JSON context with user/page info; secrets masked via `dsb_mask_secrets`.
- Pruning removes files older than retention window.
- JS logs accepted via `dsb_js_log` AJAX, rate limited by transient key per user/minute.
