@echo off
REM Wrapper to run UASPMS backup script with photos
"%SystemRoot%\system32\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "C:\xampp\htdocs\UASPMS\scripts\backup_database.ps1" -KeepDays 30 -OutputDir "C:\Users\SPMU-Andrew\OneDrive - University of Antique\UASPMS-Backups" -IncludePhotos -PhotosDir "C:\xampp\htdocs\UASPMS\spams\uploads\assets"
