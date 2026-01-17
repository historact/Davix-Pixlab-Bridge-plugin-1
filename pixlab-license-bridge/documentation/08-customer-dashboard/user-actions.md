# User Actions

Dashboard interactions handled via AJAX (`DSB_Dashboard_Ajax`):
- **View summary**: `dsb_dashboard_summary` posts identity to `/internal/user/summary`; returns plan and key status.
- **View usage**: `dsb_dashboard_usage` posts identity + range to `/internal/user/usage`; returns chart-ready history.
- **Rotate key**: `dsb_dashboard_rotate` posts identity to `/internal/user/key/rotate`; rate limited to once per 60 seconds; modal shows plaintext key when returned.
- **Toggle key**: `dsb_dashboard_toggle` posts identity + enabled flag to `/internal/user/key/toggle` to disable/enable keys.

Identity is derived from logged-in user email and latest order/subscription meta via `dsb_pixlab_get_identity()`.
