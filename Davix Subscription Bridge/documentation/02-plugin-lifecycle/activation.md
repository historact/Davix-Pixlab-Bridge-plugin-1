# Activation

- Activation hook: `DSB_Plugin::activate()`.
- Dependency check: WooCommerce and WPSwings Subscriptions must be active; otherwise plugin deactivates itself and shows an admin error.
- Database migrations: `DSB_DB::migrate()` creates/updates custom tables via `dbDelta` and installs purge triggers when needed.
- No additional data seeded on activation beyond schema creation.
