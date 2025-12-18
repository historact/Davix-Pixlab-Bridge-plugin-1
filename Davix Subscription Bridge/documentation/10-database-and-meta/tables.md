# Tables

## `wp_davix_bridge_logs`
- Fields: `id` (PK), `event`, `customer_email`, `plan_slug`, `subscription_id`, `order_id`, `response_action`, `http_code`, `error_excerpt`, `created_at`.
- Retention: trimmed to 200 newest entries.

## `wp_davix_bridge_keys`
- Fields: `id` (PK), `subscription_id` (unique), `customer_email`, `wp_user_id` (unique), `customer_name`, `subscription_status`, `plan_slug`, `status`, `key_prefix`, `key_last4`, `valid_from`, `valid_until`, `node_plan_id`, `last_action`, `last_http_code`, `last_error`, timestamps.
- Triggers: deletion inserts purge queue entries via `dsb_keys_after_delete`.

## `wp_davix_bridge_user`
- Fields: `id` (PK), `wp_user_id` (unique), `customer_email`, `subscription_id`, `order_id`, `product_id`, `plan_slug`, `status`, `valid_from`, `valid_until`, `source`, `last_sync_at`, timestamps.
- Triggers: deletion inserts purge queue entries via `dsb_user_after_delete`.

## `wp_davix_bridge_purge_queue`
- Fields: `id`, `wp_user_id`, `customer_email`, `subscription_id`, `reason`, `status`, `attempts`, `claim_token`, `locked_until`, `started_at`, `finished_at`, `next_run_at`, `last_error`, timestamps.
- Indexes on status, lock, identity, claim token to support worker.
