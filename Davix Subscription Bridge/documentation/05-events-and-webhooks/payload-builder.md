# Payload Builder

`DSB_Events::build_payload()` constructs the body for `/internal/subscription/event`:
- `event`: mapped lifecycle action.
- `subscription_id`: required; derived from order meta `_dsb_subscription_id`, WPS subscription IDs, or passed argument.
- `order_id`: WooCommerce order ID when available.
- `customer_email`: from order billing email; falls back to associated user email or post meta.
- `customer_name`: concatenated billing first/last names.
- `wp_user_id`: user assigned to order/subscription.
- `plan_slug`: resolved via mapping/meta (see plan mapping doc); normalized with `dsb_normalize_plan_slug`.
- `product_id`: ID of product that matched mapping.
- `subscription_status`: order or subscription status where available.
- Validity dates: `valid_from` from order creation date; `valid_until` from captured meta (`_dsb_valid_until` or WPS filter capture).
- `external_reference`: order ID is added when subscription ID missing.

Validation: payload must include subscription ID (unless `allow_provision_without_refs` for manual provisioning) and plan slug. Missing data results in admin notices and scheduled retries.
