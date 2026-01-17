# Styling System

- Dashboard HTML is wrapped with inline CSS variables derived from style settings (`style_*` keys). `DSB_Dashboard::get_style_vars()` outputs `--dsb-*` variables consumed by `assets/css/dsb-dashboard.css`.
- Colors/fonts are overridable via admin Style tab; defaults provide dark theme with cyan accents and green progress fill.
- Labels localized into JS reflect overrides to ensure text matches configured values.
