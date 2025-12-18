# Installation and Requirements

## Dependencies
- WordPress 6.0+ and PHP 7.4+ (per plugin header).
- WooCommerce active plus WPSwings Subscriptions for WooCommerce (dependency check in `DSB_Plugin::dependencies_met`).
- Node service reachable at the configured base URL with a valid bridge token (`x-davix-bridge-token`).

## Plugin Setup
1. Install and activate WooCommerce and the WPSwings subscription extension.
2. Activate Davix Subscription Bridge; activation runs DB migrations and fails if dependencies are missing.
3. Visit **Davix Bridge → Settings** to configure:
   - Node Base URL.
   - Bridge token.
   - Optional WPSwings REST consumer secret for resync.
   - Enable logging/diagnostics and cron sync features (daily resync, Node poll, purge worker).
4. Map products to plan slugs and trigger plan sync if needed.

## Roles and Capabilities
- Admin UI pages require `manage_options`.
- AJAX dashboard endpoints require logged-in users; admin-only diagnostics are gated by capability checks or debug flags.

## Server Considerations
- Outbound HTTP from WordPress to the Node base URL (15s timeout) must be allowed.
- File logging writes to `wp-content/uploads/davix-bridge-logs/` and needs filesystem permissions.
- Cron must be functional for resync, polling, and purge worker schedules.
