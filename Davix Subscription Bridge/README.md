# Davix Subscription Bridge

A production-focused bridge between WooCommerce + WPSwings **Subscriptions for WooCommerce** and the Davix Node.js licensing API.

## Requirements
- WordPress 6.0+
- PHP 7.4+
- WooCommerce active
- WPSwings "Subscriptions for WooCommerce" active

## Installation
1. Upload the plugin folder to `wp-content/plugins/davix-subscription-bridge/`.
2. Activate in **Plugins**. If dependencies are missing the plugin will show an admin notice and deactivate.
3. In **Davix Bridge → Settings** configure the Node base URL and bridge token. Enable logging if desired.
4. Under **Plan Mapping**, map WooCommerce product IDs (or variations) to Pixlab `plan_slug` values.
5. Click **Test Connection**, then place a subscription order to verify the flow.

## Database tables
Created on activation:
- `wp_davix_bridge_logs` – last 200 bridge events (when logging is enabled).
- `wp_davix_bridge_keys` – mirrored key metadata (prefix/last4 only).

## How it works
- Hooks WPSwings subscription lifecycle events: `wps_sfw_after_renewal_payment`, `wps_sfw_expire_subscription_scheduler`, and `wps_sfw_subscription_cancel`, plus WooCommerce fallbacks (`woocommerce_checkout_order_processed`, `woocommerce_order_status_changed`).
- Derives `plan_slug` from the configured product → plan mapping. If no mapping exists for a subscription line item, the event is skipped and logged for review.
- Sends events to `/internal/subscription/event` using the configured `x-davix-bridge-token` header with a 15s timeout. Responses update the mirrored key metadata without storing plaintext keys.
- Admin UI exposes Settings, Plan Mapping, Keys (list/disable/rotate/provision via new Node admin endpoints), and Logs tabs.

## Uninstall
If **Delete data on uninstall** is checked in settings, removing the plugin will drop the custom tables and options via `uninstall.php`.
