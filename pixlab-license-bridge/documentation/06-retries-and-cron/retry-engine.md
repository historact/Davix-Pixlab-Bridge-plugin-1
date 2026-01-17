# Retry Engine

## Order Event Retries
- Meta keys: `_dsb_subid_retry_count`, `_dsb_subid_retry_lock`, `_dsb_last_sent_event` track retry attempts and lockout windows.
- Max attempts: 10 (`DSB_Events::MAX_RETRY_ATTEMPTS`).
- `DSB_Events::schedule_retry_if_needed()` schedules `dsb_retry_provision_order` with exponential-ish backoff and stores reason.
- Retry handler rebuilds payload and re-sends when subscription ID or plan becomes available.

## Validity Backfill
- Action `dsb_backfill_valid_until_for_subscription` scheduled when expiry captured after initial send; handler re-sends `activated` with valid_until once to update Node.
