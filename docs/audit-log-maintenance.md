# Audit Log Maintenance

UASPMS stores data-change, authentication, request, and page-access entries in `audit_logs`.
Request activity routes are stored in `audit_logs.route_path` for filtering and cleanup. Older rows may still also include the route in the JSON `new_values` payload.

## Recommended Retention

- Keep `insert`, `update`, `delete`, `login`, `logout`, and `login_failed` entries long term.
- Treat `access` and `request` rows as lower-value activity logs.
- Prune known background routes regularly, especially routes that poll on a timer.

## Current Disabled Auto-Log Routes

- `modules/audit_log/index.php`
- `modules/messages/poll.php`

## Prune Activity Logs

The prune utility uses exact route matching and prefers the indexed `route_path` column when it exists.

Dry run first:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\UASPMS\tools\audits\prune_audit_activity_logs.php --days=30 --route=modules/messages/poll.php
```

Apply cleanup:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\UASPMS\tools\audits\prune_audit_activity_logs.php --days=30 --route=modules/messages/poll.php --apply
```

Use `--days=90` for conservative retention or `--days=1` when removing background noise after confirming the route is no longer useful for audit review.
