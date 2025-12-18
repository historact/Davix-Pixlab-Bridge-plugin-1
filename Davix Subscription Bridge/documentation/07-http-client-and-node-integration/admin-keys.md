# Admin Key Endpoints

## /internal/admin/keys (GET)
- Parameters: `page`, `per_page`, `search` query args.
- Used by Keys tab pagination.

## /internal/admin/key/provision (POST)
- Payload: `customer_email`, `plan_slug`, optional `subscription_id`, `order_id`.
- Used by manual provisioning form and free-user registration.

## /internal/admin/key/disable (POST)
- Payload: `subscription_id` or `customer_email` (or both) to disable keys.

## /internal/admin/key/rotate (POST)
- Payload: identity (subscription/email). Returns key prefix/last4 and optional plaintext key.

All calls include `x-davix-bridge-token` and are handled via `DSB_Client::request()`. Responses update mirrored key table and surface notices in admin UI.
