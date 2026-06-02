try {
    $cfgPath = 'C:\xampp\htdocs\UASPMS\database\backups\auto_backup_settings.json'
    if (-not (Test-Path -LiteralPath $cfgPath)) {
        Write-Host "Settings file not found: $cfgPath"
        exit 0
    }
    $cfg = Get-Content $cfgPath -Raw | ConvertFrom-Json
    $one = $cfg.output_dir
    if ([string]::IsNullOrWhiteSpace($one)) {
        Write-Host 'No OneDrive output_dir in settings'
        exit 0
    }
    if (-not (Test-Path -LiteralPath $one)) {
        Write-Host "OneDrive output dir does not exist: $one"
        exit 0
    }

    $out = 'C:\xampp\htdocs\UASPMS\database\backups\auto'

    $latest = Get-ChildItem -Path $out -Filter '*_auto_*.sql' -File -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($latest) {
        $destSql = Join-Path $one $latest.Name
        Copy-Item -LiteralPath $latest.FullName -Destination $destSql -Force
        Write-Host "Copied SQL to OneDrive: $destSql"
    } else {
        Write-Host 'No SQL backup found to copy.'
    }

    $photos = Get-ChildItem -Path $out -Directory -ErrorAction SilentlyContinue | Where-Object { $_.Name -like 'photos_auto_*' } | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($photos) {
        $dest = Join-Path $one $photos.Name
        if (Test-Path -LiteralPath $dest) { Remove-Item -LiteralPath $dest -Recurse -Force -ErrorAction SilentlyContinue }
        Copy-Item -LiteralPath $photos.FullName -Destination $dest -Recurse -Force
        Write-Host "Copied photos to OneDrive: $dest"
    } else {
        Write-Host 'No photos folder found to copy.'
    }

} catch {
    Write-Host "Error during copy: $_"
}
