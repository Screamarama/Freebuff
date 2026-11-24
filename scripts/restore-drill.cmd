@echo off
rem Monthly restore drill ("RentalApp Restore Drill").
rem Restores the newest dump into a scratch database and logs the comparison.
setlocal

set "BACKUP_DIR=%USERPROFILE%\Documents\rental-app-backups"
set "LOG=%BACKUP_DIR%\restore-drill.log"
set "BASH=C:\Program Files\Git\bin\bash.exe"
set "SCRIPT=/c/laragon/www/rental-app/scripts/restore-drill.sh"

if not exist "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

if not exist "%BASH%" (
  >>"%LOG%" echo [%DATE% %TIME%] error: bash not found at "%BASH%"
  exit /b 1
)
if not exist "C:\laragon\www\rental-app\scripts\restore-drill.sh" (
  >>"%LOG%" echo [%DATE% %TIME%] error: drill script not found
  exit /b 1
)

>>"%LOG%" echo [%DATE% %TIME%] starting restore drill
"%BASH%" -lc "%SCRIPT%">>"%LOG%" 2>&1
set "RC=%ERRORLEVEL%"
if not "%RC%"=="0" (
  powershell -NoProfile -Command "(New-Object -ComObject WScript.Shell).Popup('Restore drill failed: the newest dump did not restore cleanly. Details in %LOG%',25,'Restore drill',48)" >nul 2>&1
)
>>"%LOG%" echo [%DATE% %TIME%] exit code %RC%
exit /b %RC%
