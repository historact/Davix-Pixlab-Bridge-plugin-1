# /internal/wp-sync/plan

- **Method**: POST
- **Headers**: `x-davix-bridge-token`, `Content-Type: application/json`.
- **Payload**: built per product in `DSB_Admin::get_plan_payload_for_product()` with plan slug/name, billing details, price, quotas/limits, `is_free`, feature flags, description, product ID.
- **Usage**: triggered by admin plan sync; status stored in `dsb_plan_sync`. Errors surface as admin notices and optionally DB logs.
