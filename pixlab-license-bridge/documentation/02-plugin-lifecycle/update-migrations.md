# Update & Migrations

- Current schema version: `1.0.0` stored in option `pixlab_license_db_version`.
- `DSB_DB::migrate()` runs on init; if stored version differs, tables are recreated via `create_tables()`.
- After migrations, the option is updated to the current version and the legacy db version option is removed.
