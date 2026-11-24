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
  >>"%LOG%" echo [%DATE% %TIME%] error: bash not found at "%BASH%"
  exit /b 1
)

if not exist "C:\laragon\www\rental-app\scripts\backup-db.sh" (
  >>"%LOG%" echo [%DATE% %TIME%] error: backup script not found
  exit /b 1
)

rem Redirect first: a trailing "%RC%>>log" would be read as "0>>log" and lose the line.
rem The off-site copy runs right after the dump; a failure there fails the task.
>>"%LOG%" echo [%DATE% %TIME%] starting backup
"%BASH%" -lc "%SCRIPT% && /c/laragon/www/rental-app/scripts/backup-offsite.sh">>"%LOG%" 2>&1
set "RC=%ERRORLEVEL%"
>>"%LOG%" echo [%DATE% %TIME%] finished with exit code %RC%
exit /b %RC%
