# Checkout Impact

- Event sending occurs during checkout and subscription hooks with 15s HTTP timeout. Failures do not block order creation but log errors and schedule retries.
- When Node is down, manual plan sync and key operations show admin notices; retries/backfill may queue until connectivity returns.
- `allow_provision_without_refs` can be used for manual provisioning when subscription/order references are missing, but normal checkout still requires mapped products to trigger sends.
