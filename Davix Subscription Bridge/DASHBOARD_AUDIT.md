# Davix Pixlab Dashboard Audit

## A) Shortcodes & hooks
- `includes/class-dsb-dashboard.php` registers **only** `[PIXLAB_DASHBOARD]` via `add_shortcode( 'PIXLAB_DASHBOARD', [ $this, 'render' ] )` inside `DSB_Dashboard::init()`. The render method outputs the dashboard markup and enqueues assets when the shortcode is present. No other shortcodes exist (confirmed via repo search).
- Frontend assets are conditionally enqueued: `DSB_Dashboard::maybe_enqueue_assets()` runs on `wp_enqueue_scripts`, checks `is_singular()` and `has_shortcode( $post->post_content, 'PIXLAB_DASHBOARD' )`, then calls `enqueue_assets()`. `enqueue_assets()` registers/enqueues `assets/css/dsb-dashboard.css`, `assets/js/chart.min.js`, and `assets/js/dsb-dashboard.js` with localized data (ajaxUrl, nonce, defaultRange, isAdmin, strings, colors). Uses static guard to avoid duplicate enqueue.

## B) AJAX endpoints (WordPress)
Handlers are registered in `includes/class-dsb-dashboard-ajax.php` within `DSB_Dashboard_Ajax::init()`:
- `wp_ajax_dsb_dashboard_summary` → `summary()`
  - Params: `nonce` (POST); identity resolved server-side (subscription_id/order_id/customer_email).
  - Nonce: `dsb_dashboard_nonce` verified via `wp_verify_nonce`.
  - Capability: logged-in requirement enforced in `validate_request()`; no additional capability.
  - Response: passes through Node response; expects `{ status: 'ok', plan, key, usage, billing, per_endpoint, history? }`. Errors send `{status:'error', message, debug?}` with HTTP 4xx/5xx.
- `wp_ajax_dsb_dashboard_usage` → `usage()`
  - Params: `nonce`, `range` (hourly|daily|monthly|billing_period; defaults daily). Identity derived in PHP. Adds `window` payload derived by `get_window_for_range()`.
  - Nonce/capability: same as summary (logged-in + nonce).
  - Response: Node response returned unchanged when status ok; includes `usage`, `billing`, `per_endpoint`, `history`/`labels`/`series` expected by JS.
- `wp_ajax_dsb_dashboard_rotate` → `rotate_key()`
  - Params: `nonce`; identity from PHP.
  - Nonce/capability: same checks.
  - Response: expects Node to return `{status:'ok', key?, key_prefix, key_last4}`; forwarded directly on success.
- `wp_ajax_dsb_dashboard_toggle` → `toggle_key()`
  - Params: `nonce`, `enabled` (truthy string for enable, empty for disable). Identity from PHP.
  - Nonce/capability: same checks.
  - Response: Node response forwarded on success.

For all four handlers:
- `start_response()` sets JSON headers and disables cache.
- `respond_from_result()` maps WP errors to HTTP 500, Node non-ok/HTTP!=200 to HTTP max(400,code); optionally attaches `debug` payload (url/method/http/body/error) when `DSB_DASH_DIAG` or current user can manage_options.
- Exceptions invoke `handle_exception()` returning `{status:'error', message:'Something went wrong.'}` and debug when enabled.

## C) Node calls from PHP
- HTTP client in `includes/class-dsb-client.php`.
- Base URL option key: `dsb_settings['node_base_url']` (trimmed with `rtrim( '/', base )`). `build_url()` concatenates `base . '/' . ltrim( $path, '/' )` and appends query args when provided.
- Headers: every request uses `x-davix-bridge-token` set to `dsb_settings['bridge_token']`; POST adds `Content-Type: application/json` with JSON-encoded body.
- Timeout: 15 seconds (`request()` args).
- URL/path strings used by dashboard:
  - `user_summary()` → `POST /internal/user/summary`
  - `user_usage()` → `POST /internal/user/usage` (payload merges identity + `range` + optional `window`)
  - `user_rotate()` → `POST /internal/user/key/rotate`
  - `user_toggle()` → `POST /internal/user/key/toggle`
- `post_internal()` wraps `request()` then calls `prepare_response()` adding `url` and `method` to result.

## D) Identifiers logic
- Identity resolution in `dsb_pixlab_get_identity()` (same file as AJAX handlers):
  - `customer_email`: current user’s email when logged-in; sanitized.
  - `order_id`: last WooCommerce order for user ID; falls back to latest order by billing_email when user missing; uses `wc_get_orders` limited to 1 sorted DESC.
  - `subscription_id`: extracted from the same most-recent order meta keys (checked in order): `wps_sfw_subscription_id`, `subscription_id`, `_subscription_id`.
- Returned identity array keys: `subscription_id`, `order_id`, `customer_email`. These are sent directly to Node in dashboard calls. No `wp_order_id` is sent.
- Body construction per endpoint:
  - Summary: identity only.
  - Usage: identity plus `range` and `window` (days/hours/months/periods based on range).
  - Rotate: identity only.
  - Toggle: identity plus boolean `enabled` flag derived from POST truthiness.

## E) Frontend JS mapping (`assets/js/dsb-dashboard.js`)
- Calls `admin-ajax.php` via `post()` helper sending action + nonce (+ payload):
  - `dsb_dashboard_summary` → `fetchSummary()` → `renderSummary()`.
  - `dsb_dashboard_usage` → `fetchUsage(range)` → `applyUsage()` + `renderHistory()`.
  - `dsb_dashboard_rotate` → `handleRotate()` (confirms) → expects `{status:'ok', key?, key_prefix, key_last4}`; shows modal with `key` when provided; also updates masked display using prefix/last4.
  - `dsb_dashboard_toggle` → `handleToggle()`; sends `enabled: '1'` to enable or `''` to disable; on success flips UI state and re-fetches summary/usage.
- Success criteria: `handleResponse()` requires `json.status === 'ok'`; otherwise throws `Error(message)` and logs `json.debug` to console when `isAdmin`.
- Summary mapping (`renderSummary`):
  - Reads `res.plan` (`name`, `limit`), `res.key` (`key_prefix`, `key_last4`, `created_at`, `status`, `enabled`), `res.usage` (`total_calls_used`, `total_calls_limit`, `percent`, etc.), `res.billing` (`period`, `start`, `end`), `res.per_endpoint` (`h2i_calls`, `image_calls`, `pdf_calls`, `tools_calls`).
  - Displays masked key (`prefix••••last4`), created date shown verbatim (`Created ${created_at}`), and status badge text `Active`/`Disabled` based on key status/enabled.
- Usage mapping (`applyUsage`):
  - Derives `used` from `usage.total_calls_used` or `usage.used`; `limit` from `usage.total_calls_limit` or `usage.limit`; percent from `usage.percent` or computed `(used/limit)`.
  - Renders “Used Calls: {used} / {limit}” (if limit present) and percent text; progress bar width uses percent or used capped at 100.
  - Billing window uses `billing.period` for hint and `billing.start`–`billing.end` for range label.
  - Per-endpoint counters read `{h2i_calls, image_calls, pdf_calls, tools_calls}` from `per_endpoint` object.
- History mapping (`renderHistory`): expects `labels` array and `series` object with `h2i`, `image`, `pdf`, `tools` arrays; uses Chart.js stacked bar; legend built from dataset colors.

### Potential mismatches / instability points
- Toggle payload uses boolean cast of string POST param; Node requirement “enable/disable” action strings is not enforced—PHP sends `{enabled: true|false}` while JS sends `'1'`/''.
- `applyUsage` expects `usage.total_calls_used`/`total_calls_limit` or percent; any deviation in Node naming could yield 0/NaN and default to zero, causing perceived “0 usage”.
- `renderSummary` shows key created_at as returned (no YYYY/MM/DD formatting) and assumes `per_endpoint` keys match `*_calls`; missing keys render as “0 calls”.
- Identity fallback relies on the most recent order; wrong order meta could send incorrect or null subscription_id/order_id leading to Node misses/zeros.

## F) Existing debugging/logging
- PHP: `DSB_Dashboard_Ajax::respond_from_result()` appends debug payload (url/method/http/body/error) for admins or when `DSB_DASH_DIAG` defined. Errors logged via `error_log('[DSB_DASH] …')` with token masking. Exceptions include file/line in debug payload when allowed.
- JS: Admins see console warnings for summary errors and console errors for usage errors; `handleResponse` throws with message and logs `json.debug` to console when provided.

## G) Minimal safe change plan (no code changes yet)
- Normalize identifier resolution and payloads to ensure Node receives correct `subscription_id`, `order_id`, and `customer_email` (e.g., handle multiple orders/subscriptions, avoid nulls).
- Align toggle/rotate payloads with Node expectations (explicit `action: 'enable'|'disable'` or adjusted boolean handling) and enforce clear success/error messaging.
- Standardize usage payload/response mapping: document expected Node keys or adapt JS to support returned shape; format billing window and created date (YYYY/MM/DD) server-side or client-side; ensure per-endpoint counters map directly to Node fields.
- Confirm shortcode uniqueness and asset enqueue guard remain; consider adding explicit failure UI for not-logged-in users and handling ajax 4xx/5xx gracefully.
- Add diagnostic logging of identity + Node payload (sanitized) on dashboard calls to trace cases where usage returns zeros; keep token masking and respect logging settings.
