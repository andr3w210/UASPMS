# Request rate limits

Limits are tracked per client IP (`REMOTE_ADDR`) and action key in
`rate_limit_attempts`. When the configured database table is unavailable,
sensitive and billable actions fail closed. Apply `database/121_rate_limit_attempts.sql`
before enabling the application.

| Route / action | Limit | Response when exceeded |
|---|---:|---|
| `auth/login.php` POST | 5 / 15 minutes | HTTP 429; login form displays “Too many login attempts from your IP address. Please try again in N minute(s).” Includes `Retry-After` in seconds. |
| `auth/forgot_password.php` POST | 5 / 15 minutes | HTTP 429; form displays “Too many requests from your IP address. Please try again in N minute(s).” Includes `Retry-After`. |
| `auth/reset_password.php` POST | 5 / 15 minutes | HTTP 429; form displays “Too many requests from your IP address. Please try again in N minute(s).” Includes `Retry-After`. |
| `modules/users/index.php`, `action=save`, new account (`id=0`) | 10 / 60 minutes | HTTP 429; page displays “Account creation limit reached. Try again later.” Includes `Retry-After`. There is no public self-service signup route; this is administrator-created account enrollment. |
| `modules/users/index.php`, `action=reset_password` | 5 / 15 minutes | HTTP 429; page displays “Password reset limit reached. Try again later.” Includes `Retry-After`. |
| `modules/purchase_orders/scan_proxy.php` POST (Gemini API) | 10 / 60 minutes | HTTP 429 JSON: `{"error":"PO scan limit reached. Try again later.","retry_after":SECONDS}` plus `Retry-After`. |
| `modules/settings/purchase_order_notifications.php`, `action=send_overdue_sms` (UniSMS/iTexMo) | 3 / 60 minutes | HTTP 429; page displays “SMS send limit reached. Try again later.” Includes `Retry-After`. |
| `modules/settings/purchase_order_notifications.php`, `action=send_email_test` (SMTP) | 2 / 60 minutes | HTTP 429; page displays “Email send limit reached. Try again later.” Includes `Retry-After`. |

The forgot-password endpoint sends a reset email and is covered by its 5 / 15 minute
limit. Scheduled notification scripts are CLI-only; these request limits apply to the
web routes that initiate paid or account-sensitive operations.
