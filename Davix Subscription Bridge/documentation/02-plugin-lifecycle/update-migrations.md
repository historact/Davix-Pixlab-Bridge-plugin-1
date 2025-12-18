# Update & Migrations

- Current schema version: `1.4.0` stored in option `dsb_db_version`.
- `DSB_DB::migrate()` runs on init; if stored version differs, tables are recreated and `includes/migrations/upgrade-1.4.0.php` runs for purge queue adjustments.
- After migrations, the option is updated to the current version.
