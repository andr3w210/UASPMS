# Database Recovery

This project already contains a working baseline database dump that can recreate the system schema and starter data.

## Current database settings

- Database: `spamsdb`
- Host: `127.0.0.1`
- User: `root`
- Password: blank

These values are defined in [constants.php](/f:/xampp/htdocs/UASPMS/spams/app/config/constants.php).

## Recovery baseline

The saved baseline dump is:

- [spamsdb_recovery_baseline_2026-03-26.sql](/f:/xampp/htdocs/UASPMS/database/backups/spamsdb_recovery_baseline_2026-03-26.sql)

This baseline contains:

- Full schema for the recovered system
- Seed/master data needed by the app
- A working `admin` user

This baseline does **not** contain your old operational records. The current database has no purchase orders, receivings, issuances, distributions, returns, or disposals yet.

## One-command restore

Run this from PowerShell:

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\restore_database.ps1
```

The restore script will:

- Drop and recreate `spamsdb`
- Import the saved baseline dump

Script path:

- [restore_database.ps1](/f:/xampp/htdocs/UASPMS/scripts/restore_database.ps1)

## Default login after restore

- Username: `admin`
- Password: `password`

Change the password immediately after logging in.

## Alternative importer already in the project

If you want to rebuild from the individual SQL files instead of the baseline dump, you can also run:

```powershell
& 'F:\xampp\php\php.exe' .\spams\scripts\import_database.php
```

The baseline dump is the safer option because it was created from a database that already loads successfully in this environment.
