<#
.SYNOPSIS
    Build lab_client.exe — a single self-contained binary for the lab machines.

.DESCRIPTION
    Freezes the Python interpreter, mss, Pillow and pywin32 into one .exe so the lab
    PCs need no Python installation at all.

    This is the deployment that matters. Legacy committed a 30 MB python-3.14.3
    installer to git and ran it on every lab PC, silently modifying that machine's
    Python install and PATH, then pip-installed unpinned dependencies from PyPI at
    install time. The result was a lab where each machine could be running different
    capture code from its neighbour, and a 30 MB binary in version control.

    Here the interpreter is a build artefact rather than something installed onto
    student machines, and requirements.txt is pinned so a rebuild is reproducible.

.PARAMETER OutputPath
    Where to put the .exe. Default: .\dist\lab_client.exe

.EXAMPLE
    .\build.ps1

.EXAMPLE
    .\build.ps1 -OutputPath ..\..\public\lab-client.exe
#>

[CmdletBinding()]
param(
    [Parameter()]
    [string] $OutputPath
)

$ErrorActionPreference = 'Stop'

# Resolved here rather than as a param default on purpose. In an advanced script -
# anything with [CmdletBinding()] - $PSScriptRoot is still empty while the param block
# is evaluated, so a default of "$PSScriptRoot\dist\lab_client.exe" expanded to
# "\dist\lab_client.exe" and PyInstaller quietly built into C:\dist, leaving the real
# dist folder holding a stale .exe. Verified:
#
#     [CmdletBinding()] param([string]$O = "$PSScriptRoot\x")  ->  '\x'
#     param([string]$O = "$PSScriptRoot\x")                     ->  'C:\...\x'
#
# So the parameter stays empty and is filled in where $PSScriptRoot actually works.
if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $PSScriptRoot 'dist\lab_client.exe'
} elseif (-not [System.IO.Path]::IsPathRooted($OutputPath)) {
    # Relative paths are taken as relative to the script, not the caller's shell,
    # which for this script is the only thing anyone means.
    $OutputPath = Join-Path $PSScriptRoot $OutputPath
}

Write-Host 'Building the KobenLabs Lab Client...' -ForegroundColor Cyan

# A virtual environment keeps mss, Pillow and PyInstaller out of the machine's
# Python. A lab build box should not be left with the lab's dependencies on its
# global interpreter.
if (-not (Test-Path -LiteralPath "$PSScriptRoot\.venv")) {
    Write-Host 'Creating a virtual environment...' -ForegroundColor DarkGray
    python -m venv "$PSScriptRoot\.venv"
}

$venvPython = "$PSScriptRoot\.venv\Scripts\python.exe"

if (-not (Test-Path -LiteralPath $venvPython)) {
    throw "The virtual environment is missing its interpreter at $venvPython."
}

& $venvPython -m pip install --quiet --upgrade pip
& $venvPython -m pip install --quiet -r "$PSScriptRoot\requirements.txt"

if ($LASTEXITCODE -ne 0) {
    throw 'Installing dependencies failed. Check that the pinned versions are available for this Python version.'
}

Write-Host 'Checking the client imports cleanly...' -ForegroundColor DarkGray
& $venvPython -c "import lab_client; print('lab_client', lab_client.VERSION)"
& $venvPython -c "import mss, PIL, win32service; print('dependencies OK')"

if ($LASTEXITCODE -ne 0) {
    throw 'Import check failed. Build on Windows — mss and pywin32 have no other implementation here.'
}

$outputDirectory = Split-Path -Parent $OutputPath

# Belt and braces against the bug above coming back: never write to a bare drive root,
# and never silently accept an unresolved path.
if ([string]::IsNullOrWhiteSpace($outputDirectory) -or
    $outputDirectory -match '^[A-Za-z]:\\?$') {
    throw "Refusing to build into '$outputDirectory' - that is a drive root, not a dist folder. Pass -OutputPath with a full folder."
}

New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null

# --onefile, --noconsole
#
#   --noconsole  This is what makes the client invisible. pythonw semantics: no
#                window, no taskbar entry, nothing for a student to notice or close.
#                Also the reason the bootloader is runw.exe, whose PE subsystem is 2
#                (GUI) rather than 3 (console).
#
# Deliberately NOT --uac-admin, which was the earlier setting here. The client runs in
# the signed-in user's own session and captures that session's desktop, so it needs no
# elevation at all — and a permanently-elevated binary would mean a UAC prompt
# whenever it is started by hand, plus an avoidable reason for endpoint protection to
# flag it. The *installer* still needs administrator, because it registers a scheduled
# task and writes to Program Files; that is install-client.ps1's job, not the client's.
#
# --noupx because UPX-packed binaries trip some endpoint protection heuristics, and a
# file that gets quarantined on a lab machine is worse than one that is a few hundred
# KB larger.
& $venvPython -m PyInstaller `
    --noconsole `
    --onefile `
    --noupx `
    --clean `
    --name lab_client `
    --distpath $outputDirectory `
    --workpath "$PSScriptRoot\build" `
    --specpath "$PSScriptRoot\build" `
    --hidden-import mss.windows `
    --hidden-import win32timezone `
    "$PSScriptRoot\lab_client.py"

if ($LASTEXITCODE -ne 0) {
    throw "PyInstaller failed with exit code $LASTEXITCODE."
}

$built = Join-Path $outputDirectory 'lab_client.exe'

if (-not (Test-Path -LiteralPath $built)) {
    throw "PyInstaller reported success but $built is not there."
}

$sizeMb = [Math]::Round((Get-Item -LiteralPath $built).Length / 1MB, 1)

# ---------------------------------------------------------------------
# Refresh KobenLabs-Setup - the folder that gets copied onto lab PCs
# ---------------------------------------------------------------------
# This exists because the alternative was worse: handing someone three loose files and
# asking which one to run. Two files looked equally runnable, and getting it wrong means
# double-clicking a program that quietly exits because it has no code yet.
#
# Generated here rather than kept by hand, so it can never be a stale copy of the
# installer - which is the failure this replaces. An out-of-date setup folder would
# quietly install last month's client, and the symptom would be a bug that was already
# fixed in source.
$setupFolder = Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) 'KobenLabs-Setup'

New-Item -ItemType Directory -Path $setupFolder -Force | Out-Null

# The uninstaller travels with the installer. If a machine misbehaves you are standing
# at that machine holding the setup folder, and having to find the source tree on the
# server to undo it is exactly the wrong time to need a second location.
foreach ($file in @('install.cmd', 'install-client.ps1', 'uninstall.cmd', 'uninstall-client.ps1')) {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot $file) -Destination $setupFolder -Force
}

Copy-Item -LiteralPath $built -Destination $setupFolder -Force

# Bake the server address into the folder, read from the app's own APP_URL.
#
# One source of truth for the site's name. Renaming it is an APP_URL change and a rebuild;
# nothing in the installer or the docs carries a second copy to fall out of step, and no
# one types a hostname on thirty machines.
$envFile = Join-Path (Split-Path -Parent (Split-Path -Parent $PSScriptRoot)) '.env'

if (Test-Path -LiteralPath $envFile) {
    $appUrl = (Select-String -Path $envFile -Pattern '^APP_URL=(.+)$' |
        Select-Object -First 1).Matches.Groups[1].Value.Trim().Trim('"')

    if ($appUrl) {
        $serverFile = Join-Path $setupFolder 'server.json'
        # Written with the .NET API and no BOM, matching how the installer writes config.json.
        [System.IO.File]::WriteAllText(
            $serverFile,
            ('{ "url": "' + $appUrl + '", "_comment": "Written by build.ps1 from the app''s APP_URL. Edit and re-run build.ps1 to change it." }'),
            (New-Object System.Text.UTF8Encoding $false)
        )

        Write-Host "Server address baked in: $appUrl" -ForegroundColor Green
    } else {
        Write-Host 'APP_URL was not set in .env; the installer will ask for the address.' -ForegroundColor Yellow
    }
}

Write-Host ''
Write-Host "Setup folder refreshed: $setupFolder" -ForegroundColor Green
Get-ChildItem -LiteralPath $setupFolder |
    ForEach-Object { Write-Host ("    {0,-22} {1,6:N1} MB" -f $_.Name, ($_.Length / 1MB)) }

Write-Host ''
Write-Host 'To install on a lab PC:' -ForegroundColor Cyan
Write-Host '    copy the KobenLabs-Setup folder across, then double-click install.cmd'
Write-Host '    It asks for the server IP and the one-time code for that machine.'
Write-Host ''
Write-Host 'To remove it again (needs an elevated PowerShell):' -ForegroundColor Cyan
Write-Host '    .\uninstall-client.ps1 -Purge'