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
3. In **Davix Bridge → Settings** configure the Node base URL and bridge token. Optional: choose plan mapping mode and resync existing subscriptions.

## Database tables
Created on activation:
- `wp_davix_bridge_logs` – last 200 bridge events.
- `wp_davix_bridge_keys` – mirrored key metadata (prefix/last4 only).

## How it works
- Listens to WPSwings subscription status hooks (`wps_sfw_subscription_status_updated`, `wps_sfw_subscription_status_changed`) and the `transition_post_status` fallback for the `wps_subscriptions` post type. Also watches WooCommerce order status changes containing the `wps_sfw_subscription_id` meta for renewals.
- Maps subscription status to Davix events (`activated`, `renewed`, `cancelled`, `expired`, `payment_failed`, `paused`, `disabled`).
- Resolves customer email and plan slug from the subscription/order context, then calls the Node endpoint `/internal/subscription/event` with the configured token. Responses update the mirrored key table without storing plaintext keys.
- Admin UI provides settings, key management (activate, deactivate, regenerate, manual create), log viewing, CSV export, test connection, and a full resync utility.

## Uninstall
If **Delete data on uninstall** is checked in settings, removing the plugin will drop the custom tables and options via `uninstall.php`.
