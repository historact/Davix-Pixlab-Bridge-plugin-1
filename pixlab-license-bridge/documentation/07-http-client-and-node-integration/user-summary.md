# /internal/user/summary

- **Method**: POST (via `DSB_Client::user_summary` and `post_internal`).
- **Headers**: `x-davix-bridge-token`, JSON body.
- **Payload**: identity derived from logged-in user (`customer_email`, plus `subscription_id`/`order_id` when available). Dashboard AJAX passes from `dsb_pixlab_get_identity()`.
- **Response Handling**: `DSB_Dashboard_Ajax::normalize_summary_payload()` shapes Node response into plan details, key status (prefix/last4/status/created date), usage totals and per-endpoint counts, and billing window text.
