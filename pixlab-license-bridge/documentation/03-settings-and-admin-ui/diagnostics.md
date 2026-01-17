# Diagnostics

- **Test Connection** button on Settings tab calls `DSB_Client::test_connection()` against `/internal/subscription/debug`; response code and body shown as admin notice.
- **Diagnostics: Request Log** fetches `/internal/admin/diagnostics/request-log` via `DSB_Client::fetch_request_log_diagnostics()`; displayed when available for admins.
- JS logger: admin pages enqueue inline script when debug enabled to POST `dsb_js_log` AJAX with nonce `dsb_js_log`, rate limited to 30/minute per user.
