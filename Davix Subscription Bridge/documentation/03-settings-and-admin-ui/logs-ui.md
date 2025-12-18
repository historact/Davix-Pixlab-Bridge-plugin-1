# Logs UI

- **Logs** tab lists up to 200 recent entries from `davix_bridge_logs` via `DSB_DB::get_logs()`; shows event, email, subscription/order IDs, response action, HTTP code, error excerpt, and timestamp.
- **Download log** posts to `admin_post_dsb_download_log` and returns the latest file log (if debugging enabled) with nonce/capability checks.
- **Clear log** posts to `admin_post_dsb_clear_log` to delete file logs.
- Logging enablement is governed by `enable_logging` (DB log) and `debug_enabled` (file log); both toggled in settings.
