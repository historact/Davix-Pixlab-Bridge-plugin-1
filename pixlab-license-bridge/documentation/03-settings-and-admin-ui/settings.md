# Settings

All settings stored in option `dsb_settings` and managed in **PixLab License → Settings**.

| UI Label | Option Key | Default | Sanitization | Behavior |
| --- | --- | --- | --- | --- |
| Node Base URL | `node_base_url` | empty | `esc_url_raw` | Base URL for all Node endpoints. Required for HTTP calls. |
| Bridge Token | `bridge_token` | empty | `sanitize_text_field` | Sent as `x-davix-bridge-token` header on every request. Masked in UI. |
| Enable logging | `enable_logging` | 1 | Checkbox -> int | Allows DB event logging and some UI notices. |
| Enable debug logging | `debug_enabled` | 0 | Checkbox -> int | Controls file logging via `dsb_get_log_settings`. |
| Debug level | `debug_level` | `info` | `sanitize_key` w/ allowed values | Sets minimum level for file logs. |
| Debug retention (days) | `debug_retention_days` | 7 | `max(1,int)` | File log pruning window. |
| Delete data on uninstall | `delete_data` | 0 | Checkbox -> int | Mirrors into `dsb_delete_on_uninstall` to drop tables/options. |
| Allow provisioning without references | `allow_provision_without_refs` | 0 | Checkbox -> int | Lets manual provisioning proceed without subscription/order IDs. |
| Enable daily resync | `enable_daily_resync` | 1 | Checkbox -> int | Toggles scheduled WPS resync cron. |
| Resync batch size | `resync_batch_size` | 100 | `max(20,min(500,int))` | Controls per-run rows sent during resync. |
| Resync lock minutes | `resync_lock_minutes` | 30 | `max(5,int)` | Lease length to avoid concurrent resync. |
| Resync run hour | `resync_run_hour` | 3 | `0-23` int | Daily schedule hour. |
| Disable non-active during resync | `resync_disable_non_active` | 1 | Checkbox -> int | Tells resync to disable users not marked active. |
| WPS REST consumer secret | `wps_rest_consumer_secret` | empty | `sanitize_text_field` | Required for pulling WPS subscriptions via REST during resync. |
| Enable Node poll sync | `enable_node_poll_sync` | 0 | Checkbox -> int | Enables Node export polling cron. |
| Node poll interval (minutes) | `node_poll_interval_minutes` | 10 | `max(5,min(60,int))` | Custom cron schedule period. |
| Node poll per page | `node_poll_per_page` | 200 | `max(1,min(500,int))` | Page size for Node export pulls. |
| Node poll delete stale | `node_poll_delete_stale` | 1 | Checkbox -> int | Deletes local rows not present in Node export. |
| Node poll lock minutes | `node_poll_lock_minutes` | 10 | `max(1,int)` | Lock duration to avoid overlap. |
| Enable purge worker | `enable_purge_worker` | 1 | Checkbox -> int | Enables purge queue processing cron. |
| Purge lock minutes | `purge_lock_minutes` | 10 | `max(1,min(120,int))` | Lease window for purge jobs. |
| Purge lease minutes | `purge_lease_minutes` | 15 | `max(1,min(240,int))` | Lock duration for claimed jobs. |
| Purge batch size | `purge_batch_size` | 20 | `max(1,min(100,int))` | Number of queued purge jobs to process per run. |
| Plan sync product picker | `plan_products` (option `dsb_plan_products`) | empty | `absint` array | Selected product IDs eligible for plan sync; slugs stored to post meta. |
| Product → Plan mapping table | `product_plans` (option `dsb_product_plans`) | empty | `absint` keys + sanitized slugs | Determines which orders trigger event forwarding and plan slugs used in payloads. |

Settings also include dashboard style and label keys (see style file).
