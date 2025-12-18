# AJAX Endpoints

## Admin
- `dsb_search_users`: search users by term/email; returns `{id,text,email}`.
- `dsb_search_subscriptions`: search subscription posts; returns `{id,text,email,status}`.
- `dsb_search_orders`: search WooCommerce orders by ID/email; returns `{id,text,email,status}`.
- `dsb_js_log`: accepts `message`, `level`, `context`; rate limited and gated by nonce/capability; writes to file log.

## Dashboard (frontend)
- `dsb_dashboard_summary`: fetch user summary from Node.
- `dsb_dashboard_usage`: fetch usage history.
- `dsb_dashboard_rotate`: rotate key.
- `dsb_dashboard_toggle`: enable/disable key.

Responses are JSON with `success` flag and payload; errors may include sanitized `debug` metadata (URL, method, http code, body excerpt, error message).
