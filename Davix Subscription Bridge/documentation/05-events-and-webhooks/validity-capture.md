# Validity Capture

- WPS expiry filter `wps_sfw_susbcription_end_date` intercepted by `DSB_Events::dsb_capture_wps_expiry()`.
- Captured expiry normalized to UTC and stored as:
  - Subscription meta `_dsb_wps_valid_until`, `_dsb_wps_valid_until_source`, `_dsb_wps_valid_until_captured_at`, `_dsb_valid_until` (ISO string).
  - Parent order meta `_dsb_wps_valid_until` and `_dsb_valid_until` when applicable.
- Helper `persist_valid_until()` persists dates into mirrored tables and triggers backfill action `dsb_backfill_valid_until_for_subscription` when missing.
- Validity is reused to avoid re-sending `activated` events without end dates (guards via `_dsb_event_sent_activated_with_valid_until` meta).
