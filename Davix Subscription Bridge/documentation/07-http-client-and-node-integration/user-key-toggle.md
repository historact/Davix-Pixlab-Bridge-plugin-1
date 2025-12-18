# /internal/user/key/toggle

- **Method**: POST
- **Payload**: identity + `action` (`enable` or `disable`) based on dashboard toggle state.
- **Response Handling**: Dashboard updates button/labels based on `enabled` flag in JSON; errors include HTTP code/body excerpt for admin debugging.
