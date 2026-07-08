# Database Recovery

This project already contains a working baseline database dump that can recreate the system schema and starter data.

## Current database settings

The application reads database settings from the project `.env` file, falling back to `spams/.env` when needed. Do not rely on hard-coded `root` or blank-password values for production recovery.

## Recovery baseline

The saved baseline dump is:

- [spamsdb_recovery_baseline_2026-03-26.sql](/f:/xampp/htdocs/UASPMS/database/backups/spamsdb_recovery_baseline_2026-03-26.sql)

This baseline is an emergency recovery seed only. It contains:

- Full schema for the recovered system
- Seed/master data needed by the app
- Administrator records that must be reviewed and reset before user access is enabled

This baseline does **not** contain current operational records. Before using it, confirm that restoring it is the intended recovery path for the target environment.

## One-command restore

Run this from PowerShell only after confirming the target database and backup location:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\restore_database.ps1 -ConfirmDestructiveRestore
```

The restore script will:

- Create a pre-restore backup using `scripts/backup_database.ps1`
- Drop and recreate `spamsdb`
- Import the saved baseline dump

Script path:

- `scripts/restore_database.ps1`

## Administrator access after restore

Do not use or publish default credentials for production. After any restore, verify the active administrator accounts in the restored database and force a password reset before enabling user access.

## Alternative importer already in the project

If you want to rebuild from the individual SQL files instead of the baseline dump, you can also run:

```powershell
& 'F:\xampp\php\php.exe' .\spams\scripts\import_database.php
```

The baseline dump is the safer option because it was created from a database that already loads successfully in this environment.
