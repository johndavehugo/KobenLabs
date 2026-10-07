<#
.SYNOPSIS
    Install the KobenLabs Lab Client on a lab PC.

.DESCRIPTION
    Registers the client as a scheduled task that starts at every logon and runs
    windowless, so it is invisible to whoever is sitting at the machine.

    Intended to be run by double-clicking install.cmd, which handles the
    administrator prompt. Everything here can also be answered on screen rather
    than passed as arguments, so an administrator setting up thirty machines does
    not have to memorise a PowerShell invocation.

    Why a scheduled task and not a Windows Service, since that was the one
    genuinely hard-won decision here:

        A Service runs in session 0, which has no interactive desktop. mss
        captures a black frame there, permanently. A service-based client starts
        cleanly, reports itself as healthy, and shows nothing but black screens on
        every tile - with no error anywhere to explain why.

    Legacy's task-based model was therefore correct, and only its *settings* were
    wrong: bound to one %USERNAME%, triggered on logon alone, with no restart
    policy. Those three are fixed below.

.PARAMETER ServerUrl
    Base URL of the KobenLabs server, no trailing slash.
    Omit it to be asked.

.PARAMETER Token
    The one-time code from the admin panel's Enrol client button.
    Omit it to be asked.

.PARAMETER InstallPath
    Where to put the client. Default: C:\Program Files\KobenLabs\Client

.PARAMETER Executable
    Path to a built lab_client.exe. When supplied the .exe is used instead of the
    Python script, which is what you want in production - no Python on the lab
    machines at all.

.PARAMETER Force
    Replace an existing installation. Without it, an already-installed machine is
    left alone rather than silently re-enrolled.

.EXAMPLE
    Double-click install.cmd.

.EXAMPLE
    .\install-client.ps1 -ServerUrl http://192.168.1.10 -Token abc123...
#>

[CmdletBinding()]
param(
    [string] $ServerUrl,

    [string] $Token,

    [string] $InstallPath = 'C:\Program Files\KobenLabs\Client',

    [string] $Executable,

    [switch] $Force
)

$ErrorActionPreference = 'Stop'

$TaskName = 'KobenLabs Lab Client'
$ScriptRoot = Split-Path -Parent $MyInvocation.MyCommand.Path

# Pick the bundled program up automatically.
#
# This is the whole point of shipping the standalone .exe in the setup folder: someone
# copying the folder onto a lab PC should not have to know that it exists, let alone
# pass -Executable to reach it. Requiring a flag meant the two ways of installing looked
# equally official, and asking which one to use was a question nobody could answer
# without caring about the difference.
#
# A .exe next to this script wins over the Python route, because it is the only route
# that needs nothing already installed on the machine. -Executable still overrides it,
# for anyone building from source or pointing at a build in another folder.
if (-not $Executable) {
    $bundled = Join-Path $ScriptRoot 'lab_client.exe'

    if (Test-Path -LiteralPath $bundled) {
        $Executable = $bundled
    }
}

function Write-Step {
    param([string] $Message)
    Write-Host ''
    Write-Host "== $Message" -ForegroundColor Cyan
}

function Write-Failure {
    param([string] $Message)
    Write-Host ''
    Write-Host "ERROR: $Message" -ForegroundColor Red
}

function Get-PythonWindows {
    # pythonw.exe, never python.exe. It has no console window, so the client is
    # invisible at the machine. Legacy got this right too and it is worth keeping.
    $found = Get-Command pythonw.exe -ErrorAction SilentlyContinue

    if ($null -eq $found) {
        return $null
    }

    return $found.Source
}

function Get-PythonConsole {
    # python.exe alongside the pythonw.exe already located, so pip's output is visible.
    # If it cannot be found, pythonw's own directory is the only sensible guess.
    $found = Get-Command python.exe -ErrorAction SilentlyContinue

    if ($null -ne $found) {
        return $found.Source
    }

    $pythonw = Get-PythonWindows

    if ($pythonw) {
        $sibling = Join-Path (Split-Path -Parent $pythonw) 'python.exe'

        if (Test-Path -LiteralPath $sibling) {
            return $sibling
        }
    }

    return $null
}

function Test-DependenciesPresent {
    param([string] $PythonExe)

    if ([string]::IsNullOrWhiteSpace($PythonExe)) {
        return $false
    }

    # Imports the same three modules the client needs, in the same interpreter that
    # will run it. Checked here rather than by inspecting site-packages, because a
    # module can be present and broken for this interpreter.
    & $PythonExe -c 'import mss, PIL, win32gui' 2>$null

    return $LASTEXITCODE -eq 0
}

function Stop-ExistingTask {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue

    if ($null -eq $task) {
        return
    }

    Write-Host "Found an existing '$TaskName' task."

    if (-not $Force) {
        Write-Host '  This machine already has the Lab Client installed.'
        Write-Host '  Run install-client.ps1 -Force to replace it, or use'
        Write-Host '  uninstall-client.ps1 first.'

        exit 0
    }

    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

function Stop-ClientProcesses {
    # Stopping the task is not the same as the process having exited.
    #
    # Stop-ScheduledTask asks the task to end and returns straight away, and PyInstaller's
    # onefile build runs a parent that unpacks and a child that does the work - killing the
    # parent can leave the child running. Either way the client keeps its log file open, and
    # an open log makes the install directory undeletable:
    #
    #     Remove-Item ... The process cannot access the file 'lab-client.log'
    #
    # So the processes are ended explicitly, and this runs whether or not a task was
    # registered - an unregistered task can still have left a client running, which is
    # exactly the state after a failed uninstall.
    $running = Get-Process -Name 'lab_client' -ErrorAction SilentlyContinue

    if ($null -eq $running) {
        return
    }

    Write-Host '  Stopping the running client'

    $running | Stop-Process -Force -ErrorAction SilentlyContinue

    # Give the OS a moment to release the handle. Stop-Process returns once the signal is
    # delivered, not once the file handles are closed.
    for ($attempt = 0; $attempt -lt 10; $attempt++) {
        Start-Sleep -Milliseconds 500

        if ($null -eq (Get-Process -Name 'lab_client' -ErrorAction SilentlyContinue)) {
            return
        }
    }

    Write-Failure 'The running Lab Client would not stop, so it cannot be replaced.'
    Write-Host '  Close it and try again: it may be open in a Task Manager, or the'
    Write-Host '  machine may be too busy to respond.'

    exit 1
}

Write-Host ''
Write-Host '========================================' -ForegroundColor Cyan
Write-Host '  KobenLabs Lab Client' -ForegroundColor Cyan
Write-Host '========================================' -ForegroundColor Cyan

# ---------------------------------------------------------------------
# Preflight: collect every problem before changing anything
# ---------------------------------------------------------------------
# Checked as a group rather than one at a time. Checking them in sequence means
# fixing "you need administrator rights", running again, and only then discovering
# "this machine has no Python" - two round trips for one answer. Someone setting up
# a lab PC wants the whole list at once.
$problems = @()

# Administrator rights. Needed to register the scheduled task and write to Program
# Files. install.cmd normally handles this by asking Windows itself.
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)

if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    $problems += 'This needs administrator rights, to register a scheduled task.'
}

if ($Executable -and -not (Test-Path -LiteralPath $Executable)) {
    $problems += "The Lab Client program was not found at: $Executable"
}

# Python, unless the frozen .exe was supplied — that carries its own interpreter and
# every library, which is what makes it deployable to a machine with nothing on it.
if (-not $Executable) {
    $pythonw = Get-PythonWindows

    if ($null -eq $pythonw) {
        $problems += 'Python was not found on this machine.'
    } else {
        # lab_client.py and requirements.txt travel with the installer. Missing either
        # means a partial copy, and would otherwise surface much later as an
        # unhelpful pip error.
        if (-not (Test-Path -LiteralPath (Join-Path $ScriptRoot 'lab_client.py'))) {
            $problems += 'lab_client.py is missing. Keep it next to this script.'
        }

        if (-not (Test-Path -LiteralPath (Join-Path $ScriptRoot 'requirements.txt'))) {
            $problems += 'requirements.txt is missing. Keep it next to this script.'
        }
    }
}

if ($problems.Count -gt 0) {
    foreach ($problem in $problems) {
        Write-Host ''
        Write-Host "PROBLEM: $problem" -ForegroundColor Red
    }

    Write-Host ''
    Write-Host 'How to fix these:' -ForegroundColor Yellow

    if ($principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        Write-Host ''
    } else {
        Write-Host ''
        Write-Host '  Administrator rights'
        Write-Host '    Close this window and double-click install.cmd instead — it asks'
        Write-Host '    Windows for permission by itself.'
        Write-Host ''
    }

    if ($Executable -and -not (Test-Path -LiteralPath $Executable)) {
        Write-Host '  Missing program file'
        Write-Host "    Check the path you passed to -Executable."
        Write-Host ''
    }

    if (-not $Executable) {
        $pythonw = Get-PythonWindows

        if ($null -eq $pythonw) {
            Write-Host '  No Python on this machine'
            Write-Host '    Pick either:'
            Write-Host ''
            Write-Host '      a) Install Python 3.11 or newer from python.org, ticking'
            Write-Host '         "Add python.exe to PATH" during setup.'
            Write-Host ''
            Write-Host '      b) Skip Python entirely — which is the better option for a lab.'
            Write-Host '         Build the standalone program once on any machine that has'
            Write-Host '         Python:'
            Write-Host '             clients\lab-client\build.ps1'
            Write-Host '         Copy the resulting lab_client.exe here, then run:'
            Write-Host '             .\install-client.ps1 -Executable .\lab_client.exe'
            Write-Host '         That single file carries Python and every library with it,'
            Write-Host '         so the lab machine needs nothing installed.'
            Write-Host ''
        } elseif ((-not (Test-Path -LiteralPath (Join-Path $ScriptRoot 'lab_client.py'))) -or
                  (-not (Test-Path -LiteralPath (Join-Path $ScriptRoot 'requirements.txt')))) {
            Write-Host '  Missing files'
            Write-Host '    Copy the whole clients\lab-client folder, not just the installer.'
            Write-Host ''
        }
    }

    Write-Host 'Nothing has been changed on this machine.' -ForegroundColor DarkGray

    exit 1
}

# ---------------------------------------------------------------------
# Read the existing configuration, before anything is prompted for
# ---------------------------------------------------------------------
# Read here, ahead of the prompts, because its only purpose is to give them defaults.
# Reading it later - in the Installing step, which is where it used to be read - was too
# late to inform anything, and the result was that -Force wrote the old file back
# verbatim at the end and silently discarded both answers the administrator had just
# typed. A machine asked to move to a new server address was reported as installed and
# kept talking to the old one, which is the worst possible outcome: it looks like success
# and nothing about it is.
#
# Both prompts now default to what is already on the machine. Press Enter twice and
# nothing changes; type an address and only the address changes; paste a code and only
# the code changes. Nothing is overwritten behind anyone's back.
$preservedUrl = $null
$preservedToken = $null
$existingConfigPath = Join-Path $InstallPath 'config.json'

if (Test-Path -LiteralPath $existingConfigPath) {
    try {
        $existing = [System.IO.File]::ReadAllText($existingConfigPath, [System.Text.Encoding]::UTF8) |
            ConvertFrom-Json

        $preservedUrl = $existing.server_url
        $preservedToken = $existing.token
    } catch {
        $preservedUrl = $null
        $preservedToken = $null
    }
}

if ($preservedUrl) {
    Write-Step 'Existing installation'
    Write-Host "  This machine is already set up for $preservedUrl"
    Write-Host '  Press Enter at either prompt to keep what is already there.' -ForegroundColor DarkGray
}

# ---------------------------------------------------------------------
# Ask for the two things that differ per machine
# ---------------------------------------------------------------------
Write-Step 'Server details'

$bundledServerFile = Join-Path $ScriptRoot 'server.json'
$bundledUrl = $null

if (Test-Path -LiteralPath $bundledServerFile) {
    try {
        $bundled = [System.IO.File]::ReadAllText($bundledServerFile, [System.Text.Encoding]::UTF8) |
            ConvertFrom-Json

        if (-not [string]::IsNullOrWhiteSpace($bundled.url)) {
            $bundledUrl = $bundled.url
        }
    } catch {
        $bundledUrl = $null
    }
}

if ([string]::IsNullOrWhiteSpace($ServerUrl)) {
    Write-Host ''
    Write-Host '  Server address - the IP of the PC running KobenLabs.'
    Write-Host '  For example 192.168.254.103'

    # The machine's current address wins over the bundled one: it is what actually works
    # today, whereas the bundled value is only the build machine's guess.
    $defaultUrl = $preservedUrl

    if (-not $defaultUrl) {
        $defaultUrl = $bundledUrl
    }

    if ($defaultUrl) {
        Write-Host "  Press Enter to use $defaultUrl" -ForegroundColor DarkGray
    }

    Write-Host ''

    $typed = Read-Host '  Server address'

    if ([string]::IsNullOrWhiteSpace($typed)) {
        $ServerUrl = $defaultUrl
    } else {
        $ServerUrl = $typed
    }
}

if ([string]::IsNullOrWhiteSpace($ServerUrl)) {
    Write-Failure 'A server address is required.'

    exit 1
}

$ServerUrl = $ServerUrl.Trim().TrimEnd('/')

# Catch a pasted path or a bare host now, rather than letting every frame upload
# fail with a connection error for the rest of the machine's life.
if ($ServerUrl -notmatch '^https?://') {
    if ($ServerUrl -match '^[\w.-]+(:\d+)?(/.*)?$') {
# Looks like a bare host, which is the common paste.
            $ServerUrl = "http://$ServerUrl"
            # Assigned first, then reported from the variable. Reading it back out of
            # the old string instead printed "http://http://192.168.1.10", which looks
            # like a broken address and sent whoever was reading it hunting for a
            # second bug that was not there.
            Write-Host "  Assuming http://$ServerUrl" -ForegroundColor DarkGray
    } else {
        Write-Failure "'$ServerUrl' does not look like a web address."
        Write-Host '  Expected something like http://192.168.1.10'

        exit 1
    }
}

Write-Host ''
Write-Host '  One-time code - on the admin panel, open Computers, click'
Write-Host '  "Enrol client" on this machine, and copy the code it shows.'
Write-Host ''

if ([string]::IsNullOrWhiteSpace($Token)) {
    if ($preservedToken) {
        Write-Host "  Press Enter to keep this machine's existing code" -ForegroundColor DarkGray
    }

    $typedCode = Read-Host '  Code'

    if ([string]::IsNullOrWhiteSpace($typedCode)) {
        $Token = $preservedToken
    } else {
        $Token = $typedCode
    }
}

if ([string]::IsNullOrWhiteSpace($Token)) {
    Write-Failure 'The one-time code is required. Enrol the machine in the admin panel first.'

    exit 1
}

$Token = $Token.Trim()

# ---------------------------------------------------------------------
# Check the code before changing anything
# ---------------------------------------------------------------------
# Fail now, with a working installer still on disk, rather than after writing a
# scheduled task that silently retries a rejected credential forever.
Write-Step 'Checking the code'

try {
    $response = Invoke-WebRequest `
        -Uri "$ServerUrl/api/client/config" `
        -Headers @{ Authorization = "Bearer $Token" } `
        -TimeoutSec 10 `
        -UseBasicParsing `
        -ErrorAction Stop

    $payload = $response.Content | ConvertFrom-Json

    if ($payload.enabled) {
        Write-Host "  Accepted for $($payload.pc.name)" -ForegroundColor Green
    } else {
        Write-Host '  Accepted, but Live Screens is switched off on the server.' -ForegroundColor Yellow
    }
} catch {
    # PowerShell 5.1 throws WebException whose Response.StatusCode is an
    # HttpStatusCode enum, while PowerShell 7 throws HttpResponseException whose
    # StatusCode is an int. Casting to [int] handles both, and reading `.value__`
    # handles neither reliably — which would send a wrong-code failure into the
    # "could not reach the server" branch below and tell an administrator to check
    # their network when the only thing wrong was their typing.
    $status = 0

    if ($null -ne $_.Exception.Response) {
        try {
            $status = [int] $_.Exception.Response.StatusCode
        } catch {
            $status = 0
        }
    }

    if ($status -eq 401 -or $status -eq 403) {
        Write-Failure 'That code was not accepted by the server.'
        Write-Host ''
        Write-Host '  Most likely causes:'
        Write-Host '    - the code was copied incompletely'
        Write-Host '    - it belongs to a different machine'
        Write-Host '    - it was already replaced by a later Enrol client'
        Write-Host '    - Live Screens has been switched off, so nothing is accepted'

        exit 1
    }

    if ($status) {
        Write-Failure "The server answered with HTTP $status, not a code problem."
        Write-Host "  Check that $ServerUrl is the right address and reachable from here."

        exit 1
    }

    Write-Failure "Could not reach $ServerUrl"
    Write-Host ''
    Write-Host '  Check the address, and that this machine is on the same network'
    Write-Host '  as the server. Nothing has been changed yet.'

    exit 1
}

# Only now that the code has been proven is an existing installation touched.
#
# Deliberately after the check rather than before: Stop-ExistingTask() unregisters
# the scheduled task, and doing that first means a typo in the code would leave a
# working machine with its client unregistered. The machine was fine; the answer to
# the prompt was wrong.
Stop-ExistingTask

# ---------------------------------------------------------------------
# Install
# ---------------------------------------------------------------------
Write-Step 'Installing'

# $configPath was established in the Server details step, when the existing configuration
# was read. It is restated here only so the path is visible next to the deletion below.
$configPath = Join-Path $InstallPath 'config.json'

if ($Force -and $preservedToken) {
    # Checked here rather than assumed. Re-enrolling a PC issues a new code and
    # invalidates the old one, so a preserved code can be dead by the time somebody runs
    # an update. Left unchecked it produces a client that starts cleanly, authenticates
    # nothing and never reports - with "Silent" on the admin panel as the only clue.
    try {
        Invoke-WebRequest `
            -Uri "$ServerUrl/api/client/config" `
            -Headers @{ Authorization = "Bearer $preservedToken" } `
            -TimeoutSec 10 `
            -UseBasicParsing `
            -ErrorAction Stop | Out-Null

        Write-Host '  Keeping this machine''s existing code' -ForegroundColor DarkGray
    } catch {
        $existingStatus = 0

        if ($null -ne $_.Exception.Response) {
            try {
                $existingStatus = [int] $_.Exception.Response.StatusCode
            } catch {
                $existingStatus = 0
            }
        }

        if ($existingStatus -eq 401 -or $existingStatus -eq 403) {
            Write-Host '  The existing code has been replaced on the server, so paste the' -ForegroundColor Yellow
            Write-Host '  current code when asked.' -ForegroundColor Yellow
        }
    }
}

if (Test-Path -LiteralPath $InstallPath) {
    if (-not $Force -and (Test-Path -LiteralPath $configPath)) {
        Write-Failure "$InstallPath already exists. Re-run with -Force to overwrite it."

        exit 1
    }

    # A directory with no config.json is not an install, it is the debris of one: a run
    # that was interrupted, or that failed after copying the program. There is no code
    # to protect and no task to stop, so treating it as a real installation just wedges
    # the machine - the only way out is -Force, which is an update, and there is nothing
    # here to update. Clearing it is the only sensible reading, and it is what makes a
    # failed install retryable instead of needing someone to know the magic flag.
    if (-not $Force) {
        Write-Host "  Found an incomplete install from a previous run; clearing it" -ForegroundColor DarkGray
    }

    Stop-ClientProcesses

    Remove-Item -LiteralPath $InstallPath -Recurse -Force
}

New-Item -ItemType Directory -Path $InstallPath -Force | Out-Null

if ($Executable) {
    $program = Join-Path $InstallPath 'lab_client.exe'
    Copy-Item -LiteralPath $Executable -Destination $program -Force

    # The exe is already windowless, so it does not need pythonw.
    $taskCommand = "`"$program`""
} else {
    $pythonw = Get-PythonWindows
    Copy-Item -LiteralPath (Join-Path $ScriptRoot 'lab_client.py') -Destination $InstallPath -Force

    $taskCommand = "`"$pythonw`" `"$InstallPath\lab_client.py`""

    # -----------------------------------------------------------------
    # Install the capture dependencies
    # -----------------------------------------------------------------
    # The frozen .exe path needs none of this - Python, mss, Pillow and pywin32 are
    # all inside it. Running from the script does, and missing them is invisible until
    # it matters: mss and Pillow are imported *inside* capture_frame(), so the module
    # imports cleanly, the scheduled task starts happily, and then every single frame
    # raises ImportError in a retry loop while the machine shows as not reporting.
    #
    # Legacy hit exactly this: it ran `pip install` against whatever `where python`
    # found, which on a modern machine is Microsoft's Store stub rather than a real
    # interpreter.
    Write-Step 'Installing capture dependencies'

    # python.exe rather than pythonw.exe for pip: pythonw has no console, so pip's
    # output and any error go nowhere and the installer would carry on regardless.
    $pythonExe = Get-PythonConsole
    $requirements = Join-Path $ScriptRoot 'requirements.txt'

    # Checked before installing, so a machine that already has them is not made to
    # wait on a network round trip it does not need.
    if (Test-DependenciesPresent -PythonExe $pythonExe) {
        Write-Host '  Already installed'
    } else {
        Write-Host '  Installing mss, Pillow and pywin32'

        & $pythonExe -m pip install --quiet --disable-pip-version-check -r $requirements 2>&1 |
            ForEach-Object { Write-Host "    $_" }

        if ($LASTEXITCODE -ne 0) {
            Write-Failure 'The capture dependencies could not be installed.'
            Write-Host ''
            Write-Host '  This usually means the machine cannot reach PyPI, or a proxy'
            Write-Host '  needs configuring. Two ways round it:'
            Write-Host ''
            Write-Host '    - build lab_client.exe on a machine with internet'
            Write-Host '      (clients\lab-client\build.ps1) and install with'
            Write-Host '          .\install-client.ps1 -Executable .\lab_client.exe'
            Write-Host '    - or install the packages manually:'
            Write-Host '          ' + $pythonExe + ' -m pip install -r "' + $requirements + '"'

            exit 1
        }

        # Verified rather than assumed. pip can exit 0 having installed nothing useful,
        # and the failure mode this guards is a client that looks installed and reports
        # nothing.
        if (-not (Test-DependenciesPresent -PythonExe $pythonExe)) {
            Write-Failure 'The dependencies installed, but the client still cannot use them.'
            Write-Host '  Building lab_client.exe avoids this entirely:'
            Write-Host '      clients\lab-client\build.ps1'
            Write-Host '  then:'
            Write-Host '      .\install-client.ps1 -Executable .\lab_client.exe'

            exit 1
        }

        Write-Host '  Installed'
    }
}

# ---------------------------------------------------------------------
# Write the config
# ---------------------------------------------------------------------
# Written with the .NET API and a UTF8Encoding constructed with $false, because
# `Set-Content -Encoding UTF8` emits a byte-order mark on Windows PowerShell 5.1.
# A BOM at the start of the file makes json.loads() throw, and the client's only
# symptom is a refusal to start - so every machine installed through this script
# would fail identically for a reason invisible in the file itself.
New-Item -ItemType Directory -Path $InstallPath -Force | Out-Null

# Assigned here, next to the comment that explains why it matters. It was previously
# referenced without ever being created, so $encoded was $null and both WriteAllText
# calls threw "Value cannot be null. Parameter name: encoding" - after the code had
# been accepted and the install directory created, which is the worst moment for it.
$encoded = New-Object System.Text.UTF8Encoding $false

# $configPath was established in the Server details step, when the existing configuration
# was read. It is restated here only so the path is visible next to the deletion below.
$config = [ordered]@{
    server_url       = $ServerUrl
    token            = $Token
    max_width        = 1280
    quality          = 60
    interval_seconds = 1.0
}

# Always written from the values actually in play.
#
# This used to write $preserved - the old file's text - verbatim whenever -Force was
# given, on the reasonable-sounding grounds that an update should not invalidate a
# working machine's code. The problem is that "the code" was not the only thing in that
# file: the server address was in there too, so an administrator who typed a corrected
# address got it silently thrown away, and the installer went on to report success. The
# machine kept using the old address, which is why a lab full of "working" clients can
# be quietly pointed at the wrong server.
#
# Preservation now happens at the prompts, where it is visible and deliberate: Enter
# keeps what is there, anything typed replaces it. Nothing is overwritten behind
# someone's back, so what gets written is always exactly what they agreed to.
[System.IO.File]::WriteAllText($configPath, ($config | ConvertTo-Json), $encoded)

Write-Host '  Program and code written'

# ---------------------------------------------------------------------
# Lock the directory down
# ---------------------------------------------------------------------
# The code in config.json is a live credential: anyone holding it can report as
# this machine. Only SYSTEM and Administrators should be able to read it.
Write-Host '  Restricting access to the install directory'

$acl = Get-Acl -LiteralPath $InstallPath
$acl.SetAccessRuleProtection($true, $false)

foreach ($identity in @('NT AUTHORITY\SYSTEM', 'BUILTIN\Administrators')) {
    $acl.AddAccessRule(
        [System.Security.AccessControl.FileSystemAccessRule]::new(
            $identity,
            [System.Security.AccessControl.FileSystemRights]::FullControl,
            [System.Security.AccessControl.InheritanceFlags]'ContainerInherit, ObjectInherit',
            [System.Security.AccessControl.PropagationFlags]::None,
            [System.Security.AccessControl.AccessControlType]::Allow
        )
    )
}

Set-Acl -LiteralPath $InstallPath -AclObject $acl

# ---------------------------------------------------------------------
# Register the task
# ---------------------------------------------------------------------
Write-Host '  Registering it to start at every logon'

# IT runs at every logon.
#
# Legacy triggered on logon and bound the task to one %USERNAME% with
# /ru "%USERNAME%". That meant a different lab account never started it, and the
# task died with that user's session. -RunLevel Highest with the current account
# keeps it working for whoever signs in.
# -WorkingDirectory matters. A scheduled task otherwise starts in C:\Windows\System32,
# so any relative path inside the client resolves there instead of beside the exe. The
# client now anchors its own paths to its executable, and this makes the task's own
# view agree with it - belt and braces, because the failure mode is a client that
# cannot find a config file that is plainly sitting next to it.
$action = New-ScheduledTaskAction -Execute $taskCommand -WorkingDirectory $InstallPath
$trigger = New-ScheduledTaskTrigger -AtLogOn

$principal = New-ScheduledTaskPrincipal `
    -UserId ([System.Security.Principal.WindowsIdentity]::GetCurrent().Name) `
    -LogonType Interactive `
    -RunLevel Highest

# This is the setting legacy most conspicuously lacked. Without a restart policy,
# if the process exited it stayed dead until the next logon - so one network blip
# took a machine out of monitoring for the rest of the session. Combined with the
# client's own never-give-up reconnect loop, there are now two independent
# recoveries.
$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Principal $principal `
    -Settings $settings `
    -Description 'Reports this machine''s screen to KobenLabs Live Screens. Runs silently in the background.' | Out-Null

Write-Host '  Starting it now'
Start-ScheduledTask -TaskName $TaskName

# ---------------------------------------------------------------------
# Verify
# ---------------------------------------------------------------------
# Legacy finished here with "Installation Complete!" and then told the user to go
# edit config.txt. This waits and checks, because a client that is installed but
# not reporting is indistinguishable from a broken lab from the staff side.
Write-Step 'Checking it is reporting'

$logPath = Join-Path $InstallPath 'lab-client.log'
$reporting = $false

for ($attempt = 1; $attempt -le 6; $attempt++) {
    Start-Sleep -Seconds 3

    try {
        Invoke-WebRequest `
            -Uri "$ServerUrl/api/client/config" `
            -Headers @{ Authorization = "Bearer $Token" } `
            -TimeoutSec 10 `
            -UseBasicParsing `
            -ErrorAction Stop | Out-Null

        $reporting = $true
        break
    } catch {
        # Keep waiting: the client has to start, capture a first frame and post it,
        # which is a few seconds on a slow machine.
    }
}

if (-not $reporting) {
    Write-Host '  The client is installed but has not reported yet.' -ForegroundColor Yellow
    Write-Host ''
    Write-Host '  It will keep trying on its own, and this is usually fine. If it never'
    Write-Host '  appears on the admin panel, the log says why:'
    Write-Host ''
    Write-Host "    $logPath"

    exit 1
}

Write-Host '  Reporting.' -ForegroundColor Green

Write-Host ''
Write-Host '========================================' -ForegroundColor Green
Write-Host '  Installed.' -ForegroundColor Green
Write-Host '========================================' -ForegroundColor Green
Write-Host "  Program : $taskCommand"
Write-Host "  Code    : $configPath"
Write-Host "  Log     : $logPath"
Write-Host ''
Write-Host 'It starts automatically at every logon and shows nothing on screen.'
Write-Host 'Check the admin panel: the Lab Client column should say "Reporting".'

exit 0