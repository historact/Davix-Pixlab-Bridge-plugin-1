# /internal/user/key/rotate

- **Method**: POST
- **Payload**: identity (email/subscription/order) from dashboard AJAX `dsb_dashboard_rotate`.
- **Response Handling**: Returns new key parts and optional plaintext key for modal display; status codes and body excerpts are returned in JSON for error display. Rotations are rate-limited (60s per user) client-side.
