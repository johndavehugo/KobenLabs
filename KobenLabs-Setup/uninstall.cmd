@echo off
rem ============================================================================
rem  KobenLabs Lab Client - uninstaller launcher
rem ============================================================================
rem
rem  Double-click THIS file to remove the Lab Client from this machine.
rem
rem  It does two things and then gets out of the way:
rem
rem    1. Asks Windows for permission to run as administrator. Removing a scheduled
rem       task and deleting from Program Files both need it. You should not have to
rem       know that, hence this wrapper - you get one "Do you want to allow..."
rem       prompt and nothing else.
rem
rem    2. Runs uninstall-client.ps1, which stops the task, unregisters it, and
rem       deletes the program.
rem
rem  Kept here so that standing at a misbehaving lab PC, the way out is in the same
rem  folder as the way in. Needing a second location on the server at that moment is
rem  exactly when it is least convenient.
rem
rem  Add -Purge to also delete the code and log, so the machine forgets its identity
rem  completely:
rem
rem      uninstall.cmd -Purge
rem ============================================================================

setlocal

set "SCRIPT_DIR=%~dp0"
set "PS_SCRIPT=%SCRIPT_DIR%uninstall-client.ps1"

if not exist "%PS_SCRIPT%" (
    echo.
    echo ERROR: uninstall-client.ps1 was not found next to this file.
    echo.
    echo Keep uninstall.cmd and uninstall-client.ps1 in the same folder.
    echo.
    pause
    exit /b 1
)

rem Already elevated? net session is the cheapest reliable test and does not need
rem PowerShell to answer.
net session >nul 2>&1
if errorlevel 1 (
    echo.
    echo Requesting administrator permission to remove the Lab Client...
    echo.

    if "%~1"=="" (
        powershell -NoProfile -ExecutionPolicy Bypass -Command ^
            "Start-Process -FilePath '%~f0' -Verb RunAs"
    ) else (
        powershell -NoProfile -ExecutionPolicy Bypass -Command ^
            "Start-Process -FilePath '%~f0' -ArgumentList '%*' -Verb RunAs"
    )

    exit /b
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SCRIPT%" %*
set "EXIT_CODE=%ERRORLEVEL%"

echo.
if "%EXIT_CODE%"=="0" (
    echo The Lab Client has been removed from this machine.
) else (
    echo Removal did not finish. The message above says why.
)
pause >nul

exit /b %EXIT_CODE%