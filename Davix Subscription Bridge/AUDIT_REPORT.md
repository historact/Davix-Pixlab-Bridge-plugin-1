# Davix Subscription Bridge – Technical Audit

## 1) Inventory (files & purposes)
- **Davix Subscription Bridge.php** – Loader defining constants, includes core classes, registers activation/uninstall hooks, and boots the plugin on `plugins_loaded`.
- **includes/class-dsb-plugin.php** – Service container wiring DB, client, admin UI, and event listeners; checks WooCommerce + WPSwings dependencies; activation creates tables; uninstall cleans options when enabled.
- **includes/class-dsb-db.php** – Database layer defining options and creating/dropping `davix_bridge_logs` and `davix_bridge_keys`; provides log insertion, key upsert/find, and pagination helpers.
- **includes/class-dsb-client.php** – Node API client + settings storage/masking; saves mappings, builds authenticated HTTP requests, sends subscription events, syncs plans, and proxies admin key/plan endpoints.
- **includes/class-dsb-admin.php** – Admin menu/tabs (Settings, Plan Mapping, Keys, Logs), AJAX search handlers, product plan meta panels, manual key provisioning, plan sync, notices.
- **includes/class-dsb-events.php** – Hooks WooCommerce/WPSwings subscription lifecycle, builds payloads, validates mappings, and forwards events to Node with logging.
- **includes/class-dsb-keys-table.php** – `WP_List_Table` wrapper (currently unused in UI) for listing keys with action links.
- **assets/js/dsb-admin.js** – Enhances select boxes (Select2/SelectWoo) for AJAX searches and plan dropdowns on admin screens.
- **node/key-service.js** – Helper for Node side: generate/repair keys, activate/provision/update/disable using DB/pool adapters.
- **node/internal-admin-routes.js** – Express router for protected admin endpoints (keys list/provision/disable/rotate, plan list, WP plan sync) secured by bridge token header.
- **README.md / readme.txt** – Plugin docs/changelog.
- **uninstall.php** – Removes plugin data when the delete-on-uninstall option is enabled.

## 2) Core flow (events → payload → HTTP → persistence)
1. **Woo/WPS events fire** (checkout, order status, renewal, cancel, expire) captured in `DSB_Events::handle_*` hooks. Payload built with subscription/order/email/plan via `build_payload`.
2. **Validation**: ensures subscription ID, customer email, and plan slug exist; otherwise logs `subscription_missing`, `customer_missing`, or `plan_missing` via `DSB_DB::log_event` and aborts. Settings determine mapped plans list.
3. **HTTP send**: `DSB_Client::send_event` posts payload to Node `internal/subscription/event` with `x-davix-bridge-token` header, 15s timeout. Response decoded and status checked.
4. **Logging**: If logging enabled, event row stored in `davix_bridge_logs` (capped at 200, oldest purged).
5. **Key mirror**: Successful responses upsert `davix_bridge_keys` with status, prefix/last4, plan, and HTTP/action metadata; failures (when logging on) mark status `error` with last_error/http_code.

## 3) Hook map (WP/Woo/WPS)
- `plugins_loaded` → instantiates plugin services if dependencies present.
- `wps_sfw_after_renewal_payment` → `handle_wps_renewal`: sends `renewed` with subscription/order refs.
- `wps_sfw_expire_subscription_scheduler` → `handle_wps_expire`: sends `expired`.
- `wps_sfw_subscription_cancel` → `handle_wps_cancel`: sends `cancelled`.
- `woocommerce_checkout_order_processed` → `handle_checkout`: sends `activated` (or `activated_pending_subscription_id` when missing) if order has mapped product.
- `woocommerce_order_status_changed` → `handle_order_status_change`: maps status to `activated`/`cancelled`/`payment_failed`/`expired` and sends when subscription ID present; logs otherwise.
- Product admin: `woocommerce_product_data_tabs`, `woocommerce_product_data_panels`, `woocommerce_admin_process_product_object`, `save_post_product`, `woocommerce_update_product` used to expose/save plan limit meta and trigger plan sync discovery.
- Admin/AJAX: `admin_menu`, `admin_init`, `admin_enqueue_scripts`, `wp_ajax_dsb_search_users`, `wp_ajax_dsb_search_subscriptions`, `wp_ajax_dsb_search_orders` manage UI and search endpoints.

## 4) Admin UI map
- **Settings tab** (option `dsb_settings`): Node base URL, bridge token (masked display), enable logging, delete data on uninstall, allow manual provisioning without refs, plan product selector + per-product plan slug override; also “Test Connection” and “Sync Plans to Node” actions storing sync summary in `dsb_plan_sync`. Options `dsb_product_plans` and `dsb_plan_products` hold mappings/selection.
- **Plan Mapping tab**: simple table to save product ID → plan slug pairs via `save_settings` subset; reuses `dsb_product_plans` option.
- **Keys tab**: fetches Node `/internal/admin/keys` list (pagination/search) and shows local mirror rows with rotate/disable forms posting to Node `/internal/admin/key/{rotate|disable}`; manual provision form calling `/internal/admin/key/provision` allowing customer/subscription/order/plan inputs (AJAX select2 searches). Honors setting to allow provisioning without subscription/order refs.
- **Logs tab**: displays `davix_bridge_logs` table rows (time, event, subscription, order, email, response, HTTP, error). Logging toggle controls writes.

## 5) Data storage
- **Tables**: `wp_davix_bridge_logs` (id, event, customer_email, plan_slug, subscription_id, order_id, response_action, http_code, error_excerpt, created_at). `wp_davix_bridge_keys` (id, subscription_id UNIQUE, customer_email, plan_slug, status, key_prefix, key_last4, node_plan_id, last_action, last_http_code, last_error, timestamps).
- **WP options**: `dsb_settings`, `dsb_product_plans`, `dsb_plan_products`, `dsb_plan_sync`, `dsb_delete_on_uninstall`. Product meta keys: `_dsb_plan_slug`, `_dsb_monthly_quota_files`, `_dsb_max_files_per_request`, `_dsb_max_total_upload_mb`, `_dsb_max_dimension_px`, `_dsb_timeout_seconds`, `_dsb_allow_h2i`, `_dsb_allow_image`, `_dsb_allow_pdf`, `_dsb_allow_tools`, `_dsb_is_free`. Subscription meta fallback `_dsb_plan_slug`, `wps_sfw_plan_slug` read during payload build.

## 6) Node endpoints used (via DSB_Client)
- `GET internal/subscription/debug` (test connection).
- `POST internal/subscription/event` – lifecycle event payload: event, customer_email, plan_slug, subscription_id, order_id. Expects JSON `{status, action?, key?, key_prefix?, key_last4?, plan_id?}`.
- Admin endpoints: `GET internal/admin/keys` (page/per_page/search query) returning `{status, items, total, page, per_page}`; `POST internal/admin/key/provision` with customer_email, plan_slug, subscription_id?, order_id? returning `{status:'ok', action, key?, key_prefix, key_last4}`; `POST internal/admin/key/disable` payload subscription_id or customer_email returning action + affected; `POST internal/admin/key/rotate` payload subscription_id or customer_email returning rotated key prefix/last4 (key may be present).
- Plan endpoints: `GET internal/admin/plans` for list; `POST internal/wp-sync/plan` payload from Woo product (plan_slug, name, billing_period, limits flags/quotas, description, wp_product_id) returning `{status:'ok', action?, plan_slug}`.

## 7) My Account / shortcode integration
- No shortcodes, REST routes, or WooCommerce My Account endpoints are registered in the plugin; search found none. Best insertion: add a WooCommerce “My Account” endpoint and/or shortcode to show a user dashboard with key info and usage, backed by Node admin/user endpoint calls, and gated to logged-in customers.

## 8) Security review
- **Bridge token** stored in option `dsb_settings` and sent as `x-davix-bridge-token`; UI masks token via `masked_token()`, but plaintext is stored unencrypted in options. Requests are blocked if missing token.
- **Admin capability**: most admin actions gated by `manage_options`; AJAX search handlers verify nonce `dsb_admin_ajax` and capability. Settings/forms use nonces `dsb_save_settings`, `dsb_plans_nonce`, `dsb_manual_nonce`, `dsb_key_action_nonce`, `dsb_sync_plans`, `dsb_test_connection`.
- **Sanitization**: inputs generally sanitized (emails, plan slugs, IDs). Manual provisioning enforces email+plan and optionally subscription/order unless setting allows bypass. Missing nonce on per-row rotate/disable forms would block action handling; forms include nonce fields.
- **Key handling**: WordPress never stores plaintext keys from Node responses; only prefix/last4/plan/status plus HTTP metadata recorded. Node layer checks header token against environment variable before any admin route and wraps pool services via `enhanceKeysService`.

## 9) What we need to add (user dashboard readiness)
Goal: surface API key/usage in customer-facing area with rotate capability.
- Show current user’s API key prefix + last4 (never full hash or key).
- Display monthly usage + per-endpoint usage (would require new Node endpoint or reuse keys service if it tracks usage).
- Provide a Rotate button with confirmation, rate limiting, and success message.

### Proposed touch points
- Add WooCommerce “My Account” endpoint (e.g., `/my-account/api-usage`) or shortcode to embed in a page. Use Node admin/user-safe endpoint to fetch key metadata + usage for the logged-in user.
- Frontend template should leverage existing settings for Node base URL/token (server-to-server calls via `DSB_Client`) or new user-facing endpoint without exposing token client-side; display prefix/last4 and usage metrics; include rotate action posting to WP that triggers `DSB_Client::rotate_key` for the user’s subscription/email, with nonce + capability check and throttling per user.

Files likely to change for implementation: `includes/class-dsb-client.php` (user-facing fetch/rotate helpers), `includes/class-dsb-plugin.php` (register new endpoint/shortcode), new template/PHP file for My Account display, `assets/js/` for confirmation/rate-limit UI, possibly `includes/class-dsb-admin.php` if settings needed for user dashboard, and Node routes (`node/internal-admin-routes.js` or new user route) if usage endpoint absent.
