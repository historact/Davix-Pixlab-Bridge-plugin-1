# Common Issues

- **Missing subscription ID**: orders without subscription meta trigger retries and `subscription_missing` logs. Ensure WPS hooks fire and mapping includes products.
- **Plan not mapped**: events skipped with `plan_missing` notice; set plan slug on product or mapping table.
- **Node unreachable or invalid token**: HTTP errors recorded; test connection from Settings and verify `node_base_url`/`bridge_token`.
- **Expiry not reflected**: if `_dsb_valid_until` absent, confirm WPS expiry filter runs and backfill action `dsb_backfill_valid_until_for_subscription` executes.
- **Node poll/resync disabled**: ensure toggles enabled and cron running; check lock options to see if a run is already in progress.
- **Purge queue stuck**: review purge worker status options and last errors; adjust lock/lease minutes if jobs remain pending.
