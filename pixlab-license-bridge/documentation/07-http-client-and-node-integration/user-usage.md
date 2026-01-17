# /internal/user/usage

- **Method**: POST
- **Payload**: identity + `range` (`hourly`, `daily`, `monthly`, `billing_period`) and optional `window` hint (hours/days/months/periods). Built in dashboard AJAX controller.
- **Response Handling**: Expected to return history buckets; normalized to labels/series for Chart.js stacked bars in `DSB_Dashboard_Ajax::ajax_usage()`.
