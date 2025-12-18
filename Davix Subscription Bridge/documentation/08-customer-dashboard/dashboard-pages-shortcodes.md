# Dashboard Pages & Shortcodes

- Shortcode `[PIXLAB_DASHBOARD]` renders the customer dashboard when user is logged in; otherwise displays login-required message from label settings.
- Assets enqueued: `assets/css/dsb-dashboard.css`, `assets/js/chart.min.js`, `assets/js/dsb-dashboard.js` with localized config (AJAX URLs, nonce `dsb_dashboard_nonce`, labels, style colors, default range).
- Template output built in `DSB_Dashboard::render_dashboard()` includes sections for plan/key summary, usage meters, history chart, and modal for key rotation.
