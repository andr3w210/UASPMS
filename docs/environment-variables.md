# Environment variables

The PHP application loads its `.env` from `spams/.env`. The database restore
script also accepts a project-root `.env` (and falls back to `spams/.env`).
The root `.env.example` is the template for both locations. Process environment
values take precedence over values in the file.

## PHP application settings

| Variable | Purpose | Reaches the browser? |
| --- | --- | --- |
| `DB_HOST` | Primary MySQL server | No |
| `DB_NAME` | Primary database | Yes, on the Administrator-only Database Tools page |
| `DB_USER` | Primary MySQL account | No |
| `DB_PASS` | Primary MySQL password | No |
| `DB_ADMIN_USER` | Separate maintenance account for database creation and destructive CLI restore; not used by the web app | No |
| `DB_ADMIN_PASS` | Password for the separate maintenance account | No |
| `TRIP_DB_HOST` | Trip database server; defaults to `DB_HOST` | No |
| `TRIP_DB_NAME` | Trip database name | No |
| `TRIP_DB_USER` | Trip database account; defaults to `DB_USER` | No |
| `TRIP_DB_PASS` | Trip database password; defaults to `DB_PASS` | No |
| `APP_NAME` | Application/report name | Yes, as page and report text |
| `APP_URL` | Public application URL | Yes, where application links are rendered |
| `BASE_URL` | Public application path prefix | Yes, in rendered links and the keep-alive URL |
| `TIMEZONE` | PHP default timezone | No; it affects server-side date/time formatting |
| `LOW_STOCK_THRESHOLD` | Dashboard low-stock cutoff | Yes, shown in the dashboard threshold badge |
| `ITEXMO_API_CODE` | iTexMo account code | No |
| `ITEXMO_API_PASSWORD` | iTexMo credential | No |
| `ITEXMO_API_URL` | iTexMo service endpoint | No |
| `SMS_PROVIDER` | Selects `unisms` or `itexmo` | No |
| `UNISMS_API_SECRET` | UniSMS credential | No |
| `UNISMS_SENDER_ID` | UniSMS sender identifier | No |
| `UNISMS_API_URL` | UniSMS service endpoint | No |
| `MAIL_HOST` | SMTP server | No |
| `MAIL_PORT` | SMTP port | No |
| `MAIL_ENCRYPTION` | SMTP transport encryption | No |
| `MAIL_USERNAME` | SMTP account | No |
| `MAIL_PASSWORD` | SMTP credential | No |
| `MAIL_FROM` | Email sender address | No |
| `MAIL_FROM_NAME` | Email sender display name | No |
| `UPLOAD_DEBUG_LOG` | Enables temporary upload/request diagnostics | No |
| `SPAMS_CACHE_ENABLED` | Enables or disables the runtime cache | No |
| `SPAMS_CACHE_TTL_SECONDS` | Runtime cache lifetime | No |
| `UASPMS_CA_CERT_PATH` | Server path to the optional LAN CA certificate | No; an Administrator can download the certificate through the authenticated settings page |
| `GEMINI_API_KEY` | Credential for PO image extraction | No; used by the authenticated `scan_proxy.php` server endpoint |
| `OPENAI_API_KEY` | Reserved AI integration credential; currently unused | No |
| `ANTHROPIC_API_KEY` | Reserved AI integration credential; currently unused | No |

The PO scan page sends the uploaded image to the PHP proxy; the Gemini key is
sent from PHP to Google in a request header and is not embedded in JavaScript or
returned in the proxy response. SMTP and SMS integrations likewise make
provider requests from PHP.

## CLI / host process settings

| Variable | Consumer | Reaches the browser? |
| --- | --- | --- |
| `ONEDRIVE_TENANT_ID` | Backup-upload PowerShell script | No |
| `ONEDRIVE_CLIENT_ID` | Backup-upload PowerShell script | No |
| `ONEDRIVE_CLIENT_SECRET` | Backup-upload PowerShell script; treat as a secret | No |
| `ONEDRIVE_DRIVE_ID` | Backup-upload PowerShell script | No |
| `PATH` | Database Tools uses it to find command-line programs | No |
| `SystemRoot` | Database Tools uses it to locate PowerShell on Windows | No |

The scripts in `spams/scripts/` are CLI utilities. Apache denies HTTP access
to that directory, and each PHP utility also checks that it is running from
the CLI before loading application configuration. Run them from the command
line. Database creation and destructive restore additionally require
`DB_ADMIN_USER` / `DB_ADMIN_PASS`; those credentials do not fall back to the
regular application account. Standard web-server process variables such as
`HTTP_HOST` and `REMOTE_ADDR` are request metadata, not application
configuration variables.
