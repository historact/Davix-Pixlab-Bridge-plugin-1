# Save System Map & Cron Regression Analysis

This report documents the current admin Save flows for the Davix Subscription Bridge plugin and analyzes the Cron tab regression where options re-enable after save. No fixes were applied.

## Phase 1 — Save System Map

| Tab | Form Action + Method | Nonce Field(s) | Submit Name/Label | Input Namespace | Handler Entry | Options Updated |
| --- | -------------------- | -------------- | ----------------- | --------------- | ------------- | ---------------- |
| Settings | Current page `method="post"` | `dsb_settings_nonce` (`dsb_save_settings`) | Default `submit` via `submit_button()` | Flat keys (e.g., `node_base_url`, `delete_data`) | `handle_actions()` initial POST block → `DSB_Client::save_settings( wp_unslash( $_POST ) )` | `dsb_settings` via `update_option`; uninstall flag via `DSB_DB::OPTION_DELETE_ON_UNINSTALL` |
| Settings – Test Connection | Current page `method="post"` | `dsb_test_connection` | `dsb_test_connection` button | Flat (no settings) | `handle_actions()` `dsb_test_connection` branch | None (network test only) |
| Settings – Request Log Diagnostics | Current page `method="post"` | `dsb_request_log_diagnostics_nonce` (`dsb_request_log_diagnostics`) | `dsb_request_log_diagnostics` button | Flat | `handle_actions()` diagnostics branch | None (reads only) |
| Style | Current page `method="post"` | `dsb_settings_nonce` (`dsb_save_settings`) | Default `submit` via `submit_button()` | Flat style/label keys (e.g., `style_dashboard_bg`, `label_current_plan`) | `handle_actions()` POST block → `save_settings()` | `dsb_settings` |
| Plan Mapping (Save Changes) | Current page `method="post"` | `dsb_plans_nonce` (`dsb_save_plans`) and `dsb_settings_nonce` (`dsb_save_settings`) | `Save Changes` | Flat arrays `product_ids[]`, `plan_slugs[]`, `plan_products[]`, `dsb_plan_slug_meta[...]` | `handle_actions()` plan-mapping block (nonce OR dsb_settings_nonce) → `save_settings()` with product/plan data | `dsb_settings`, `dsb_product_plans`, `dsb_plan_products` |
| Plan Mapping (Sync Plans to Node) | `admin-post.php` `method="post"` | `dsb_sync_plans` | `dsb_sync_plans` | Hidden action + nonce | `handle_actions()` `dsb_sync_plans` branch | None (API sync only) |
| Keys – Settings | Current page `method="post"` | `dsb_settings_nonce` (`dsb_save_settings`) | `submit` labeled “Save” | Flat `allow_provision_without_refs` with hidden `0` + checkbox `1` | `handle_actions()` POST block → `save_settings()` | `dsb_settings` |
| Keys – Table actions (Rotate/Disable/Purge) | Current page `method="post"` | `dsb_key_action_nonce` (`dsb_key_action`) | `submit` (link buttons) | Flat action inputs | `handle_key_actions()` → `$this->client` remote calls | None of the options (operates on Node) |
| Keys – Manual Provision Modal | Current page `method="post"` | `dsb_manual_nonce` (`dsb_manual_key`) | Default `submit` | Flat fields (`customer_user_id`, `plan_slug`, etc.) | `handle_key_actions()` manual provisioning path | None (remote action only) |
| Logs – Enable logging | Current page `method="post"` | `dsb_settings_nonce` (`dsb_save_settings`) | `submit` labeled “Save” | Flat `enable_logging` with hidden `0` + checkbox `1` | `handle_actions()` POST block → `save_settings()` | `dsb_settings` |
| Logs – Clear DB Logs | `admin-post.php` `method="post"` | `dsb_clear_db_logs_nonce` (`dsb_clear_db_logs`) | `submit` | Action `dsb_clear_db_logs` | `handle_clear_db_logs()` | Truncates DB logs only |
| Debug | Current page `method="post"` | `dsb_settings_nonce` (`dsb_save_settings`) | `Save Debug Settings` | Flat `debug_enabled`, `debug_level`, `debug_retention_days` | `handle_actions()` POST block → `save_settings()` | `dsb_settings` |
| Debug – Download log | `admin-post.php` `method="post"` | `dsb_download_log_nonce` (`dsb_download_log`) | `submit` | Action `dsb_download_log` | `handle_download_log()` | None |
| Debug – Clear log | `admin-post.php` `method="post"` | `dsb_clear_log_nonce` (`dsb_clear_log`) | `submit` | Action `dsb_clear_log` | `handle_clear_log()` | Deletes file log only |
| Cron Jobs | Current page `method="post"` | `dsb_settings_nonce` (`dsb_save_settings`) | `Save cron settings` | Flat keys with hidden `0` + checkbox `1` (e.g., `enable_purge_worker`) and number/textarea inputs | `handle_actions()` POST block → `save_settings()` | `dsb_settings` |

## Phase 2 — Cron Jobs Tab Trace

- **Tab slug & routing**: The Cron tab uses slug `cron` in the navigation array and routing switch (`render_page()` → `render_cron_tab()` when `tab=cron`). The tab value is derived from `$_GET['tab']` defaulting to `settings`.【F:includes/class-dsb-admin.php†L329-L335】【F:includes/class-dsb-admin.php†L772-L797】
- **Save condition**: Any POST with `dsb_settings_nonce` passing `wp_verify_nonce(..., 'dsb_save_settings')` enters the main save block of `handle_actions()`, regardless of tab. No additional condition restricts Cron saves beyond the nonce. When `tab === 'cron'`, it logs “Cron settings saved” after `save_settings()`.【F:includes/class-dsb-admin.php†L378-L420】【F:includes/class-dsb-admin.php†L436-L450】
- **Ingestion path**: The Cron form posts flat keys; `handle_actions()` passes `wp_unslash( $_POST )` directly into `DSB_Client::save_settings()`. That method expects flat keys, not nested arrays, and merges provided values with existing settings/defaults. Checkboxes are normalized using `isset( $data['key'] ) ? 1 : existing`.【F:includes/class-dsb-admin.php†L378-L400】【F:includes/class-dsb-client.php†L240-L262】
- **Option writes**: `save_settings()` writes the merged array to `update_option( DSB_Client::OPTION_SETTINGS )` (dsb_settings) along with related plan options. No second write overwrites the Cron values post-save.【F:includes/class-dsb-client.php†L280-L334】

## Phase 3 — Root Cause of Cron Re-enabling

The Cron form now includes hidden inputs with value `0` for every checkbox (`enable_purge_worker`, `enable_alerts_*`, `enable_recovery_*`, `enable_cron_debug_*`, `enable_node_poll_sync`, `node_poll_delete_stale`, `enable_daily_resync`, `resync_disable_non_active`). When a checkbox is unchecked, the hidden field still submits the key with value `0`. `save_settings()` treats **any presence** of these keys as “enabled” because it sets each boolean as `isset( $data['key'] ) ? 1 : ( $existing[...] )`, ignoring the submitted value. Thus, posting an unchecked field (only the hidden `0`) forces the value to `1`, re-enabling all toggles on save.【F:includes/class-dsb-admin.php†L1976-L2105】【F:includes/class-dsb-client.php†L240-L262】

This matches pattern #2 from the checklist: hidden+checkbox posts always set the key, but the handler uses `isset` rather than evaluating the value, so every checkbox resolves to `1` after save. No double-save or default merge is overriding the result; the misinterpretation of posted checkbox values is sufficient to explain the regression.

## Phase 4 — Other Tabs Status

- **Settings tab**: Uses a hidden `delete_data` input; because `save_settings()` also uses `isset` for `delete_data`, this checkbox will always save as `1` when the form is submitted (same pattern as Cron). Other fields either read the posted value (`enable_logging`, token fields) or use presence checks without hidden fields (e.g., `debug_enabled`), so only `delete_data` is affected by this pattern.【F:includes/class-dsb-admin.php†L813-L844】【F:includes/class-dsb-client.php†L215-L236】
- **Style tab**: No hidden/checkbox interactions; flat text/color inputs align with `save_settings()` expectations. Appears OK.【F:includes/class-dsb-admin.php†L864-L1040】
- **Plan Mapping tab**: Uses dedicated block that constructs arrays and then calls `save_settings()` with explicit `product_plans`, `node_base_url`, etc., preserving existing plan products. Inputs are arrays, not checkboxes, so unaffected. Saving plan products updates `dsb_settings`, `dsb_product_plans`, and `dsb_plan_products` as intended.【F:includes/class-dsb-admin.php†L1357-L1420】【F:includes/class-dsb-admin.php†L1429-L1453】【F:includes/class-dsb-client.php†L298-L334】
- **Keys tab**: The “Allow manual provisioning without Subscription/Order” checkbox uses a hidden `0` + checkbox `1`, but `save_settings()` reads the value (`allow_provision_without_refs` uses the posted scalar), so it persists correctly. Other key actions do not touch options. OK.【F:includes/class-dsb-admin.php†L1458-L1499】【F:includes/class-dsb-client.php†L215-L224】
- **Logs tab**: `enable_logging` also posts hidden `0` + checkbox `1`, and `save_settings()` reads the value instead of `isset`, so it persists. OK.【F:includes/class-dsb-admin.php†L1639-L1674】【F:includes/class-dsb-client.php†L215-L224】
- **Debug tab**: No hidden field for `debug_enabled`; uses presence to set boolean, which matches form behavior. OK.【F:includes/class-dsb-admin.php†L1678-L1715】【F:includes/class-dsb-client.php†L203-L214】
- **Cron tab**: Broken as described (all toggles forced to `1`).【F:includes/class-dsb-admin.php†L1976-L2105】【F:includes/class-dsb-client.php†L240-L262】

## Conclusion

- **Broken**: Cron tab toggles (and similarly `delete_data` in Settings) because hidden `0` inputs collide with `isset`-based checkbox parsing in `save_settings()`, causing every checkbox to be treated as enabled.
- **OK**: Keys, Logs, Debug, Style, Plan Mapping save flows align with `save_settings()` expectations; they either use value-based parsing or non-checkbox inputs.

No diagnostic logging was added; static code tracing conclusively explains the behavior.
