# Security Hardening Update - 2026-04-06

## Scope
This update documents security and maintainability hardening applied to purchase order OCR flow, upload handling, and related access control.

## Changes Applied

### 1) Purchase Order OCR proxy hardening
- Enforced authentication and authorization on PO scan proxy.
- Updated access control to role-based guard:
  - `Administrator`
  - `Supply Officer`
- Removed temporary authentication bypass logic.
- Removed debug request/response log writing from proxy runtime path.
- Removed return of raw model output on parse failures.
- Changed AI API key transport from URL query string to request header (`x-goog-api-key`).

### 2) Purchase Order module role consistency
- Standardized role guard for key PO endpoints so list/view/create/edit/scan all use the same role scope.

### 3) Upload directory hardening
- Added root uploads access rules to block script execution.
- Added deny rules for risky/sensitive extensions (including `.log` and `.txt`) in root uploads.
- Retained existing hardening in `spams/uploads/.htaccess` for app-scoped uploads.

### 4) Sensitive artifact cleanup
- Removed scan proxy debug logs from upload folders.
- Removed temporary text artifact used during scan debugging.

### 5) Repository hygiene
- Added `.gitignore` entries for:
  - environment file (`.env`)
  - upload logs/text artifacts
  - temporary helper files used during debugging

### 6) Maintainability improvements
- Reformatted compressed module files into readable multi-line structure without behavior changes:
  - `mode_of_procurements`
  - `unit_of_measures`

## Operational Notes
- PHP CLI lint was not executed in terminal due to missing `php` in PATH in this environment.
- VS Code diagnostics reported no errors in modified files.

## Recommended Follow-up
- Ensure production Apache/Nginx virtual host blocks direct access to non-public roots.
- Add periodic cleanup job for old uploads/log artifacts.
- Add a lightweight security checklist for future feature merges touching auth/upload/AI proxy logic.
