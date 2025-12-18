# Style and Label Controls

Style and label overrides are stored alongside `dsb_settings` using keys defined in `DSB_Client::get_style_defaults()` and `get_label_defaults()`.

## Style Keys
Backgrounds, borders, text, buttons, inputs, badges, progress bars, and table colors can be overridden. Defaults use dark palette (e.g., `style_dashboard_bg` `#0f172a`, button/background/border colors set to the cyan accent, progress fill `#22c55e`). Values are sanitized with `sanitize_text_field` and empty values fall back to defaults.

## Label Keys
UI labels for the dashboard (plan name, usage headings, endpoint legends, buttons like "Disable Key", "Regenerate Key", loading and empty-state text) can be overridden per key. Empty inputs fall back to localized defaults. Sanitized via `sanitize_text_field`.

## Usage
- Admin **Style** tab renders color pickers and text boxes for these keys; saved through `DSB_Client::save_settings()`.
- Values are localized to the dashboard script (`DSB_Dashboard::enqueue_assets`) and used when rendering shortcode output and AJAX responses.
