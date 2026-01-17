# Keys UI

- **Keys** tab uses `DSB_Keys_Table` to paginate `davix_bridge_keys` (20 per page). Columns: subscription ID, status, plan slug, customer email/name, key prefix/last4, valid_from/until, last action/code/error.
- Row actions link back with nonces for rotate/disable operations handled by `DSB_Admin::handle_actions()` which call `DSB_Client::rotate_key()` or `disable_key()`.
- Manual provisioning form posts customer email, plan slug, and subscription/order IDs to `/internal/admin/key/provision` via `DSB_Client::provision_key()`. Requires references unless `allow_provision_without_refs` is enabled.
- Notices reflect Node responses; successful actions update mirrored key table via response handling in `DSB_Client::send_event()` or direct UI handling.
