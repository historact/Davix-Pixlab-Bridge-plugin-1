# /internal/admin/plans

- **Method**: GET
- **Headers**: `x-davix-bridge-token`.
- **Usage**: Admin Settings tab fetches available Node plans for display/diagnostics via `DSB_Client::fetch_plans()`.
- **Response Handling**: Raw response passed back to UI; errors surfaced as admin notices.
