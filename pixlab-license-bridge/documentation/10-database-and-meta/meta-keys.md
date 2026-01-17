# Meta Keys

## Orders / Subscriptions
- `_dsb_subscription_id`: stored on order when subscription ID known.
- `_dsb_subid_retry_count`, `_dsb_subid_retry_lock`: retry counters/locks.
- `_dsb_last_sent_event`: last lifecycle event sent.
- `_dsb_valid_until`: ISO8601 expiry captured.
- `_dsb_valid_until_backfilled`: marker that backfill was attempted.
- `_dsb_event_sent_activated`, `_dsb_event_sent_activated_with_valid_until`: markers preventing duplicate sends.
- `_dsb_wps_valid_until`, `_dsb_wps_valid_until_source`, `_dsb_wps_valid_until_captured_at`: WPS expiry capture.

## Products/Variations
- `_dsb_plan_slug`, `_dsb_monthly_quota_files`, `_dsb_max_files_per_request`, `_dsb_max_total_upload_mb`, `_dsb_max_dimension_px`, `_dsb_timeout_seconds`, `_dsb_allow_h2i`, `_dsb_allow_image`, `_dsb_allow_pdf`, `_dsb_allow_tools`, `_dsb_is_free`.

## Users (WP user meta)
- None specific; identity derived from core fields. Free-user provisioning uses wp_user_id to relate to mirrored tables.
