param(
    [string]$MySqlExe = "",
    [string]$Database = "",
    [string]$User = "",
    [string]$Password = "",
    [string]$DumpFile = "",
    [switch]$ConfirmDestructiveRestore
)

$ErrorActionPreference = "Stop"

$projectRoot = Split-Path -Parent $PSScriptRoot

function Get-UaspmsEnvFileValues {
    param([string]$Path)

    $values = @{}
    if (-not (Test-Path -LiteralPath $Path)) {
        return $values
    }

    Get-Content -LiteralPath $Path | ForEach-Object {
        $line = [string]$_
        $trimmed = $line.Trim()
        if ([string]::IsNullOrWhiteSpace($trimmed) -or $trimmed.StartsWith('#') -or -not $trimmed.Contains('=')) {
            return
        }

        $parts = $trimmed.Split('=', 2)
        $name = $parts[0].Trim()
        $value = $parts[1].Trim()
        if ([string]::IsNullOrWhiteSpace($name)) {
            return
        }

        if (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'"))) {
            $value = $value.Substring(1, $value.Length - 2)
        }

        $values[$name] = $value
    }

    return $values
}

if (-not $ConfirmDestructiveRestore) {
    throw "Refusing destructive restore. Re-run with -ConfirmDestructiveRestore after verifying backup and target database."
}

$envValues = Get-UaspmsEnvFileValues -Path (Join-Path $projectRoot ".env")
if ($envValues.Count -eq 0) {
    $envValues = Get-UaspmsEnvFileValues -Path (Join-Path $projectRoot "spams\.env")
}

if ([string]::IsNullOrWhiteSpace($Database)) { $Database = if ($envValues.ContainsKey('DB_NAME')) { $envValues['DB_NAME'] } else { "spamsdb" } }
if ([string]::IsNullOrWhiteSpace($User) -and $envValues.ContainsKey('DB_USER')) { $User = $envValues['DB_USER'] }
if ($Password -eq "" -and $envValues.ContainsKey('DB_PASS')) { $Password = $envValues['DB_PASS'] }
if ([string]::IsNullOrWhiteSpace($User)) {
    throw "Database user is required. Set DB_USER in .env or pass -User explicitly."
}

if ([string]::IsNullOrWhiteSpace($DumpFile)) {
    $DumpFile = Join-Path $projectRoot "database\backups\spamsdb_recovery_baseline_2026-03-26.sql"
}

if ([string]::IsNullOrWhiteSpace($MySqlExe) -or -not (Test-Path -LiteralPath $MySqlExe)) {
    $candidates = @(
        "C:\xampp\mysql\bin\mysql.exe",
        "F:\xampp\mysql\bin\mysql.exe"
    )
    foreach ($candidate in $candidates) {
        if (Test-Path -LiteralPath $candidate) {
            $MySqlExe = $candidate
            break
        }
    }
}

if (-not (Test-Path -LiteralPath $MySqlExe)) {
    throw "mysql.exe not found at: $MySqlExe"
}

if (-not (Test-Path -LiteralPath $DumpFile)) {
    throw "Dump file not found at: $DumpFile"
}

$passwordArg = if ($Password -ne "") { "--password=$Password" } else { "--password=" }

Write-Host "Restoring database [$Database] from:"
Write-Host "  $DumpFile"

$backupScript = Join-Path $projectRoot "scripts\backup_database.ps1"
if (-not (Test-Path -LiteralPath $backupScript)) {
    throw "Pre-restore backup script not found: $backupScript"
}

Write-Host "Creating pre-restore backup before destructive restore..."
& powershell -NoProfile -ExecutionPolicy Bypass -File $backupScript -KeepDays 30
if ($LASTEXITCODE -ne 0) {
    throw "Pre-restore backup failed. Restore aborted."
}

& $MySqlExe "--user=$User" $passwordArg "-e" "DROP DATABASE IF EXISTS $Database; CREATE DATABASE $Database CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"
if ($LASTEXITCODE -ne 0) {
    throw "Failed to recreate database [$Database]."
}

Get-Content -LiteralPath $DumpFile | & $MySqlExe "--user=$User" $passwordArg $Database
if ($LASTEXITCODE -ne 0) {
    throw "Failed to import dump into [$Database]."
}

Write-Host ""
Write-Host "Restore complete."
Write-Host "Verify active administrator accounts and force password reset before production use."
