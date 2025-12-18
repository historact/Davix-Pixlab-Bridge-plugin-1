# Plan Mapping

- Primary mapping stored in option `dsb_product_plans` (product/variation ID → normalized plan slug) managed via the **Plan Mapping** tab.
- Additional product list for plan sync stored in `dsb_plan_products` (IDs selected for syncing to Node).
- Events require orders to contain at least one mapped product; otherwise payloads are skipped and a `plan_missing` log/admin notice is produced.
- Plan slug resolution order in `DSB_Events::build_payload()`:
  1. Mapping from `dsb_product_plans` for any product/variation in the order.
  2. Subscription/order meta `_dsb_plan_slug` or `wps_sfw_plan_slug`.
  3. Product slug fallback when no mapping exists (still normalized).
