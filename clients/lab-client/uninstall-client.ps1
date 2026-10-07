<#
.SYNOPSIS
    Remove the KobenLabs Lab Client from this machine.

.DESCRIPTION
    Unregisters the scheduled task and optionally deletes the program directory.

    Uses the ScheduledTasks cmdlets and process matching in PowerShell rather than
    the legacy uninstaller's `wmic process where "CommandLine like '%screen_agent.py%'"`
    plus `taskkill`. wmic was removed from Windows in recent builds, so on a current
    machine that uninstaller silently did nothing — leaving the agent running and its
    credential sitting on disk.

    Revoke the credential on the server too. Uninstalling does not invalidate a token
    that was already copied elsewhere:

        php artisan pcs:revoke-client "LAB-01"

.PARAMETER Purge
    Delete the install directory, including the stored token and log.

.EXAMPLE
    .\uninstall-client.ps1 -Purge
#>

[CmdletBinding()]
param(
    [Parameter()]
    [switch] $Purge
)

$ErrorActionPreference = 'Stop'

$TaskName = 'KobenLabs Lab Client'
$InstallPath = 'C:\Program Files\KobenLabs\Client'

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)

if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this from an elevated PowerShell prompt.'
}

$task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

if ($null -ne $task) {
    Write-Host "Stopping '$TaskName'..." -ForegroundColor Cyan
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Start-Sleep -Seconds 2

    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false

    Write-Host 'Task removed.' -ForegroundColor Green
}
else {
    Write-Host 'No task found.' -ForegroundColor Yellow
}

# Kill anything still running. Task Scheduler does not always stop the child process
# promptly, and a surviving client would keep uploading frames for a machine that is
# meant to be decommissioned.
$running = Get-CimInstance Win32_Process -Filter "Name = 'pythonw.exe' OR Name = 'lab_client.exe'" -ErrorAction SilentlyContinue |
    Where-Object { $_.CommandLine -like '*lab_client*' -or $_.ExecutablePath -like '*KobenLabs*' }

foreach ($process in $running) {
    Write-Host "Killing leftover process $($process.ProcessId)..." -ForegroundColor DarkGray
    Stop-Process -Id $process.ProcessId -Force -ErrorAction SilentlyContinue
}

if ($Purge) {
    if (Test-Path -LiteralPath $InstallPath) {
        # The directory ACL is locked to SYSTEM and Administrators, which is right while
        # the client runs but leaves nobody able to clean it up afterwards without
        # taking ownership back.
        Write-Host 'Taking ownership of the install directory to delete it...' -ForegroundColor DarkGray

        & takeown.exe /F $InstallPath /R /D Y | Out-Null
        & icacls.exe $InstallPath /grant '*F-1-5-32-544:(OI)(CI)F' /T /C | Out-Null

        Remove-Item -LiteralPath $InstallPath -Recurse -Force

        Write-Host 'Deleted the install directory, including the stored token.' -ForegroundColor Green
    }
}
else {
    Write-Host ''
    Write-Host "The install directory was left in place: $InstallPath" -ForegroundColor Yellow
    Write-Host 'Re-run with -Purge to delete it and the stored token.'
}

Write-Host ''
Write-Host 'Revoke the credential on the server as well:' -ForegroundColor Yellow
Write-Host '    php artisan pcs:revoke-client "LAB-01"'