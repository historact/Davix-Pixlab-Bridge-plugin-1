# Extending Safely

- Add new settings via `DSB_Client::get_settings()` defaults and `save_settings()` sanitization to ensure persistence and uninstall cleanup.
- When adding new Node endpoints, reuse `DSB_Client::request()`/`post_internal()` to preserve auth headers and logging behavior.
- Extend payloads through `DSB_Events::build_payload()` and mirror handling in `DSB_Client::send_event()` so database mirrors stay consistent.
- To adjust dashboard data, update normalization in `DSB_Dashboard_Ajax` and corresponding frontend JS expectations.
- Avoid modifying runtime hooks or behavior per requirements; use actions/filters added by WordPress to supplement features.
