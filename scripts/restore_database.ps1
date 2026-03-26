param(
    [string]$MySqlExe = "F:\xampp\mysql\bin\mysql.exe",
    [string]$User = "root",
    [string]$Password = "",
    [string]$DumpFile = ""
)

$ErrorActionPreference = "Stop"

$Database = "spamsdb"

$projectRoot = Split-Path -Parent $PSScriptRoot

if ([string]::IsNullOrWhiteSpace($DumpFile)) {
    $DumpFile = Join-Path $projectRoot "database\backups\spamsdb_recovery_baseline_2026-03-26.sql"
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
Write-Host "Default recovered admin login:"
Write-Host "  Username: admin"
Write-Host "  Password: password"
