# Plan Sync to Node

- Triggered from Settings tab by **Sync Plans** button.
- Iterates selected `dsb_plan_products` (or inferred subscription products) and builds payload per product via `DSB_Admin::get_plan_payload_for_product()` including:
  - `plan_slug`, `plan_name`, `product_id`, `price`, `billing_period`, `billing_interval`, `description`.
  - Limits and feature flags from product meta: quotas, file limits, timeout, allow_* booleans, `is_free`.
- Sends payloads to `/internal/wp-sync/plan` using `DSB_Client::sync_plan()`; status stored in option `dsb_plan_sync` with timestamp and per-product results.
- When logging enabled, each sync is recorded as `plan_sync` in `davix_bridge_logs`.
