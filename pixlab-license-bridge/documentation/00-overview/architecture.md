# Architecture

PixLab License Bridge synchronizes WooCommerce + WPSwings subscription lifecycle data with the Davix Node service.

## Components
- **Bootstrap**: `pixlab-license-bridge.php` defines constants and instantiates `DSB_Plugin` on `plugins_loaded`.
- **Core services**: `DSB_Plugin` wires database access (`DSB_DB`), HTTP client (`DSB_Client`), event forwarding (`DSB_Events`), resync (`DSB_Resync`), Node poller (`DSB_Node_Poll`), purge worker (`DSB_Purge_Worker`), admin UI (`DSB_Admin`), customer dashboard shortcode (`DSB_Dashboard`), and AJAX controller (`DSB_Dashboard_Ajax`).
- **Database**: custom tables mirror key metadata, event logs, user truth rows, and purge queue entries.
- **Node integration**: all outbound requests target the Node base URL with `x-davix-bridge-token` authentication. Payloads cover subscription events, plan sync, admin key management, and user dashboard queries.
- **Frontend dashboard**: `[PIXLAB_DASHBOARD]` shortcode renders usage, key status, and controls using AJAX calls into the Node service.
- **Admin tools**: settings/forms for connectivity, plan mapping, plan sync, logs, manual key operations, resync triggers, diagnostics, and purge monitoring.

## Data Flow
1. **Checkout / subscription lifecycle**: WooCommerce + WPSwings hooks invoke `DSB_Events`, which builds payloads (subscription ID, customer email, plan slug, order/product context, validity dates) and POSTs `/internal/subscription/event` via `DSB_Client`.
2. **Node response handling**: `DSB_Client::send_event()` records results into `davix_bridge_keys` and truth tables, logs outcomes when enabled, and retains validity dates.
3. **Plan synchronization**: Admin triggers sync; `DSB_Admin::sync_plans_to_node()` builds plan payloads from product meta and sends `/internal/wp-sync/plan`, storing status in `dsb_plan_sync`.
4. **Periodic reconciliation**: `DSB_Resync` and `DSB_Node_Poll` scheduled events pull data from WPSwings REST or Node exports to refresh local tables; `DSB_Purge_Worker` clears Node users/keys queued by triggers.
5. **Customer dashboard**: AJAX endpoints derive identity from the logged-in user, call Node `/internal/user/*` endpoints, and render summary, usage charts, and key controls.
6. **Logging & diagnostics**: File logging (opt-in) plus DB logs capture actions; admin diagnostics fetch Node request log excerpts.
