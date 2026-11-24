@echo off
rem Daily backup health check ("RentalApp Backup Health Check").
rem Runs scripts/check-backup-freshness.sh, logs it, and pops a warning on failure.
setlocal

set "BACKUP_DIR=%USERPROFILE%\Documents\rental-app-backups"
set "LOG=%BACKUP_DIR%\backup-health.log"
set "BASH=C:\Program Files\Git\bin\bash.exe"
set "SCRIPT=/c/laragon/www/rental-app/scripts/check-backup-freshness.sh"

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

if not exist "%BASH%" (
  >>"%LOG%" echo [%DATE% %TIME%] error: bash not found at "%BASH%"
  exit /b 1
)
if not exist "C:\laragon\www\rental-app\scripts\check-backup-freshness.sh" (
  >>"%LOG%" echo [%DATE% %TIME%] error: freshness script not found
  exit /b 1
)

>>"%LOG%" echo [%DATE% %TIME%] checking backup freshness
"%BASH%" -lc "%SCRIPT%">>"%LOG%" 2>&1
set "RC=%ERRORLEVEL%"
if not "%RC%"=="0" (
  powershell -NoProfile -Command "(New-Object -ComObject WScript.Shell).Popup('Rental-app backups need attention: the newest dump is stale or the nightly task failed. Details in %LOG%',25,'Backup problem',48)" >nul 2>&1
)
>>"%LOG%" echo [%DATE% %TIME%] exit code %RC%
exit /b %RC%
