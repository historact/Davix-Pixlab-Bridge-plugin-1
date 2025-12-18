# Scheduled Hooks

| Hook | Schedule | Purpose |
| --- | --- | --- |
| `dsb_retry_provision_order` | ad-hoc with backoff | Retry sending subscription events when IDs/plan missing. |
| `dsb_backfill_valid_until_for_subscription` | ad-hoc | Send updated `activated` with valid_until after expiry captured. |
| `dsb_resync_daily_event` (inside `DSB_Resync`) | daily at configured hour | Pull WPS subscriptions via REST to refresh local truth and send Node events. |
| `dsb_node_poll_sync_event` | custom interval `dsb_node_poll_interval` (5–60 min) | Fetch Node export pages to upsert keys/users and optionally delete stale rows. |
| `dsb_purge_worker_event` | interval derived from settings (default hourly) | Processes purge queue rows to call Node `/internal/user/purge` and clean local tables. |
