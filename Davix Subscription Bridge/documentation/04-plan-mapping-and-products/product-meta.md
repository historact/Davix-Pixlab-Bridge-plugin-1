# Product Meta

Stored on products/variations via the **Davix Plan Limits** tab and during plan sync:
- `_dsb_plan_slug`: optional override for plan slug.
- `_dsb_monthly_quota_files`: integer monthly quota.
- `_dsb_max_files_per_request`: integer limit per request.
- `_dsb_max_total_upload_mb`: integer max upload size.
- `_dsb_max_dimension_px`: integer pixel dimension cap.
- `_dsb_timeout_seconds`: integer request timeout.
- `_dsb_allow_h2i`, `_dsb_allow_image`, `_dsb_allow_pdf`, `_dsb_allow_tools`: feature flags (checkbox values).
- `_dsb_is_free`: mark plan as free.

Defaults are inferred from product slug/pricing; values are sanitized in `DSB_Admin::save_plan_limits_meta()`.
