@echo off
rem ============================================================================
rem  KobenLabs Lab Client - installer launcher
rem ============================================================================
rem
rem  Double-click THIS file. It does two things and then gets out of the way:
rem
rem    1. Asks Windows for permission to run as administrator, if it has not
rem       already been granted. The Lab Client has to register a scheduled task
rem       and write to Program Files, so there is no way around it — but you
rem       should not have to know that, hence this wrapper. You get one
rem       "Do you want to allow..." prompt and nothing else.
rem
rem    2. Runs install-client.ps1, which does the real work and asks you for
rem       your server address and one-time code.
rem
rem  Legacy's install.bat checked for admin rights and then printed "Right-click
rem  install.bat and select Run as administrator" — making the user do the job
rem  this file does for them.
rem
rem  Keep this file in the same folder as install-client.ps1.
rem ============================================================================

setlocal

set "SCRIPT_DIR=%~dp0"
set "PS_SCRIPT=%SCRIPT_DIR%install-client.ps1"

if not exist "%PS_SCRIPT%" (
    echo.
    echo ERROR: install-client.ps1 was not found next to this file.
    echo.
    echo Keep install.cmd and install-client.ps1 in the same folder.
    echo.
    pause
    exit /b 1
)

rem Already elevated? net session is the cheapest reliable test and does not need
rem PowerShell to answer, which matters because this runs before we trust
rem anything else on the machine.
net session >nul 2>&1
if errorlevel 1 (
    rem Re-launch ourselves with elevation, carrying any arguments through so
    rem `-ServerUrl ... -Token ...` still works when someone does use them.
    echo.
    echo Requesting administrator permission to install the Lab Client...
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

rem ExecutionPolicy Bypass is deliberate and scoped to this one invocation. The
rem script is local, shipped by the lab administrator, and blocks nobody's
rem machine-wide policy - unlike the legacy approach of telling staff to edit
rem PowerShell's execution policy by hand.
powershell -NoProfile -ExecutionPolicy Bypass -File "%PS_SCRIPT%" %*
set "EXIT_CODE=%ERRORLEVEL%"

echo.
if "%EXIT_CODE%"=="0" (
    echo Press any key to close this window.
) else (
    echo Installation did not finish. The message above says why.
)
pause >nul

exit /b %EXIT_CODE%