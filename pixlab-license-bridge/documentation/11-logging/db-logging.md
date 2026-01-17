# DB Logging

- Controlled by setting `enable_logging`.
- `DSB_DB::log_event()` writes to `davix_bridge_logs` with sanitized fields; trims to 200 rows by deleting oldest IDs.
- Entries record event names (e.g., subscription lifecycle, plan sync, errors), customer email, plan slug, subscription/order IDs, response action, HTTP code, and error excerpts.
- Logs shown in admin Logs tab; not exposed to frontend.
