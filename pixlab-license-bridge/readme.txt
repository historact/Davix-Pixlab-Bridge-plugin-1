=== PixLab License Bridge ===
Contributors: davix
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Version: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Bridge WooCommerce + WPSwings Subscriptions to the Pixlab Node API: forward lifecycle events, manage keys, and view logs.

== Description ==
PixLab License Bridge securely sends subscription lifecycle events from WooCommerce + WPSwings "Subscriptions for WooCommerce" to a Davix Pixlab Node.js API. It supports activation, renewals, cancellation, expiration, and payment failure signals, with on-demand key provisioning/rotation via protected Node admin endpoints.

== Installation ==
1. Upload the plugin folder to `wp-content/plugins/davix-subscription-bridge/` or install the ZIP through **Plugins → Add New**.
2. Activate the plugin. If WooCommerce or Subscriptions for WooCommerce is missing you will see an admin notice.
3. Go to **PixLab License → Settings** and set your Node Base URL and Bridge Token. Enable logging if desired.
4. Under **Plan Mapping**, map WooCommerce product IDs to Node `plan_slug` values.
5. Use **Test Connection** to verify connectivity, then place a test subscription order.

== Frequently Asked Questions ==
= What events are sent? =
Activated/purchased, renewed, cancelled, expired, payment_failed, and pending-activation (when subscription id is not yet known).

= Where are logs stored? =
The plugin stores the last 200 events in the `wp_davix_bridge_logs` table when logging is enabled.

== Changelog ==
= 1.1.0 =
* Added secure Node admin endpoints usage (keys list/provision/rotate/disable + plans).
* New admin UI tabs for settings, plan mapping, keys, and logs.
* Safer option handling, PHP 8.2 compatibility, and clearer plan mapping validation.
* Added checkout + renewal/cancel/expire hooks required by WPSwings and WooCommerce fallbacks.
* Added plan sync button to push subscription products to Node and a searchable manual key provision UI (customers, subscriptions, orders, plans).
