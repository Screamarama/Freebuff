@echo off
rem Weekly GitHub backup ("RentalApp GitHub Backup").
rem Pushes the newest encrypted dump to Backups/ on Screamarama/Freebuff.
setlocal

set "BACKUP_DIR=%USERPROFILE%\Documents\rental-app-backups"
set "LOG=%BACKUP_DIR%\backup-github.log"
set "BASH=C:\Program Files\Git\bin\bash.exe"
set "SCRIPT=/c/laragon/www/rental-app/scripts/backup-github.sh"

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

if not exist "%BASH%" (
  >>"%LOG%" echo [%DATE% %TIME%] error: bash not found at "%BASH%"
  exit /b 1
)
if not exist "C:\laragon\www\rental-app\scripts\backup-github.sh" (
  >>"%LOG%" echo [%DATE% %TIME%] error: github backup script not found
  exit /b 1
)

>>"%LOG%" echo [%DATE% %TIME%] starting github backup
"%BASH%" -lc "%SCRIPT%">>"%LOG%" 2>&1
set "RC=%ERRORLEVEL%"
if not "%RC%"=="0" (
  powershell -NoProfile -Command "(New-Object -ComObject WScript.Shell).Popup('GitHub backup failed: the newest encrypted dump was not pushed to Backups/. Details in %LOG%',25,'GitHub backup',48)" >nul 2>&1
)
>>"%LOG%" echo [%DATE% %TIME%] exit code %RC%
exit /b %RC%
