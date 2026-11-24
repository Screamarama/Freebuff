@echo off
rem Nightly entry point for Task Scheduler ("RentalApp DB Backup").
rem Runs scripts/backup-db.sh and appends everything to backup.log next to the dumps.
setlocal

set "BACKUP_DIR=%USERPROFILE%\Documents\rental-app-backups"
set "LOG=%BACKUP_DIR%\backup.log"
set "BASH=C:\Program Files\Git\bin\bash.exe"
set "SCRIPT=/c/laragon/www/rental-app/scripts/backup-db.sh"

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

if not exist "%BASH%" (
  echo [%DATE% %TIME%] error: bash not found at "%BASH%">>"%LOG%"
  exit /b 1
)

if not exist "C:\laragon\www\rental-app\scripts\backup-db.sh" (
  echo [%DATE% %TIME%] error: backup script not found>>"%LOG%"
  exit /b 1
)

echo [%DATE% %TIME%] starting backup>>"%LOG%"
"%BASH%" -lc "%SCRIPT%">>"%LOG%" 2>&1
set "RC=%ERRORLEVEL%"
echo [%DATE% %TIME%] finished with exit code %RC%>>"%LOG%"
exit /b %RC%
