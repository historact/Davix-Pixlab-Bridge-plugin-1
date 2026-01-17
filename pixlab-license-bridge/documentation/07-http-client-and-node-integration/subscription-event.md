# /internal/subscription/event

- **Method**: POST
- **Headers**: `x-davix-bridge-token` (bridge token), `Content-Type: application/json`.
- **Payload**: built in `DSB_Events::build_payload()`: `event`, `subscription_id`, `order_id`, `customer_email`, `customer_name`, `plan_slug`, `wp_user_id`, `product_id`, `subscription_status`, `valid_from`, `valid_until`, optional `external_reference`.
- **Response Handling** (`DSB_Client::send_event`):
  - Success when HTTP 2xx and body JSON with status/action markers or key markers.
  - Upserts `davix_bridge_keys` with status (`active`/`disabled`), key prefix/last4, node plan ID, validity dates, last action/code.
  - Upserts `davix_bridge_user` on activated/renewed/reactivated events.
  - Logs DB event when logging enabled.
  - On error, logs error excerpt and records key row with `status=error` and last_http_code/error.
