# Security

- Admin screens require `manage_options`; form submissions use `check_admin_referer` or `wp_verify_nonce` where applicable.
- AJAX nonces:
  - Admin JS logging uses nonce `dsb_js_log` and capability check.
  - Admin search endpoints validated via nonce `dsb_admin_ajax` plus capability.
  - Dashboard AJAX uses nonce `dsb_dashboard_nonce` and requires logged-in users.
- File logging masks secrets via `dsb_mask_secrets` and rate limits JS log calls.
- Manual key operations respect `allow_provision_without_refs` and sanitize all payload fields before transmission.
