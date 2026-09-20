#Requires -Version 5.1
<#
.SYNOPSIS
    Build the LinkEasy Publisher Windows installer (.exe) from source.

.DESCRIPTION
    One script, no prior knowledge needed. It:

      1. checks for Node.js (and offers to install it with winget if missing)
      2. assembles the self-contained runtime: Node, Playwright, Chromium, FFmpeg
      3. verifies every bundled component actually runs
      4. builds LinkEasyFacebookPublisherSetup.exe and LinkEasyPublisherPortable.exe
      5. signs them if a certificate is available, then opens the output folder

    The end user of the produced installer still installs nothing themselves: the
    runtime travels inside the installer.

.PARAMETER SkipRuntime
    Reuse an existing windows-app\runtime folder instead of rebuilding it.

.PARAMETER PortableOnly
    Build only the portable .exe (faster; skips the NSIS installer).

.PARAMETER Certificate
    Path to a .pfx code-signing certificate. If omitted, WIN_CSC_LINK is used.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\installer\Build-Installer.ps1

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\installer\Build-Installer.ps1 -PortableOnly -SkipRuntime
#>
[CmdletBinding()]
param(
    [switch]$SkipRuntime,
    [switch]$PortableOnly,
    [string]$Certificate = $env:WIN_CSC_LINK,
    [switch]$NoPause
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'

$root = Split-Path -Parent $PSScriptRoot
$appDir = Join-Path $root 'windows-app'
$installerDir = Join-Path $root 'installer'
$distDir = Join-Path $appDir 'dist'
$logFile = Join-Path $env:TEMP ("linkeasy-build-{0:yyyyMMdd-HHmmss}.log" -f (Get-Date))

$script:step = 0

function Say([string]$message, [string]$colour = 'Gray') {
    Write-Host $message -ForegroundColor $colour
    Add-Content -Path $logFile -Value $message -Encoding UTF8
}

function Step([string]$title) {
    $script:step++
    Say ''
    Say ("[{0}] {1}" -f $script:step, $title) 'Cyan'
    Say ("-" * 60) 'DarkGray'
}

function Fail([string]$message, [string]$hint = '') {
    Say ''
    Say "FAILED: $message" 'Red'
    if ($hint) { Say "        $hint" 'Yellow' }
    Say "Full log: $logFile" 'DarkGray'
    if (-not $NoPause) { Read-Host 'Press Enter to close' | Out-Null }
    exit 1
}

function Have([string]$command) {
    return [bool](Get-Command $command -ErrorAction SilentlyContinue)
}

function Ask([string]$question) {
    if ($env:LINKEASY_ASSUME_YES -eq '1') { return $true }
    $answer = Read-Host "$question [y/N]"
    return ($answer -match '^(y|yes)$')
}

function Run([string]$label, [scriptblock]$body) {
    Say "  $label" 'DarkGray'
    & $body
    if ($LASTEXITCODE -ne $null -and $LASTEXITCODE -ne 0) {
        Fail "$label (exit code $LASTEXITCODE)"
    }
}

# ----------------------------------------------------------------- banner

try {
    Add-Content -Path $logFile -Value "LinkEasy Publisher build — $(Get-Date)" -Encoding UTF8

    Say '============================================================' 'Cyan'
    Say '  LinkEasy Publisher — Windows installer build' 'Cyan'
    Say '============================================================' 'Cyan'
    Say "Source:  $root"
    Say "Log:     $logFile"
    Say ''

    if (-not (Test-Path $appDir)) { Fail "windows-app folder not found at $appDir" }
    if (-not (Test-Path $installerDir)) { Fail "installer folder not found at $installerDir" }

    foreach ($tool in @('node', 'npm')) {
        if (-not (Have $tool)) { Fail "$tool is not installed." }
    }

# ----------------------------------------------------------------- 1. node

    Step 'Checking prerequisites'

    $nodeVersion = (& node --version).Trim()
    $npmVersion = (& npm --version).Trim()
    $major = [int]($nodeVersion.TrimStart('v').Split('.')[0])

    Say "  Node.js $nodeVersion"
    Say "  npm     $npmVersion"

    if ($major -lt 18) {
        Say "  Node $nodeVersion is too old for the build tooling (need 18+)." 'Yellow'

        if (Have 'winget') {
            if (Ask '  Install the current Node.js LTS with winget now?') {
                & winget install --id OpenJS.NodeJS.LTS --accept-source-agreements --accept-package-agreements
                Say '  Node.js was installed. Close this window, open a new PowerShell, and run the script again.' 'Yellow'
                if (-not $NoPause) { Read-Host 'Press Enter to close' | Out-Null }
                exit 0
            }
        }
        Fail "Upgrade Node.js first (https://nodejs.org/en/download, LTS 20 or newer)."
    }

    $free = (Get-PSDrive -Name ($root.Substring(0,1))).Free
    if ($free -lt 4GB) {
        Say ("  Warning: only {0:N1} GB free. The build needs about 4 GB." -f ($free / 1GB)) 'Yellow'
    }

# ------------------------------------------------------------- 2. runtime

    Step 'Bundled runtime (Node, Playwright, Chromium, FFmpeg)'

    $runtimeDir = Join-Path $appDir 'runtime'
    $runtimeReady = (Test-Path (Join-Path $runtimeDir 'manifest.json')) -and (Test-Path (Join-Path $runtimeDir 'chromium'))

    if ($SkipRuntime -and $runtimeReady) {
        Say '  Reusing the existing runtime folder (-SkipRuntime).'
    } else {
        Say '  Downloading components. This is the slow part: expect 5-15 minutes.' 'Yellow'
        Push-Location $installerDir
        Run 'Assembling runtime' { & node (Join-Path $installerDir 'build-runtime.js') --payload }
        Run 'Verifying runtime'  { & node (Join-Path $installerDir 'verify-runtime.js') }
        Pop-Location
    }

# --------------------------------------------------------- 3. dependencies

    Step 'Application dependencies'

    $modules = Join-Path $appDir 'node_modules'
    if (Test-Path (Join-Path $modules 'electron')) {
        Say '  node_modules is present; skipping npm install.'
    } else {
        Push-Location $appDir
        Say '  Installing build and runtime packages (Electron, electron-builder, Playwright)…'
        Run 'npm install' { & npm install --no-fund --no-audit }
        Pop-Location
    }

# ------------------------------------------------------------ 4. packages

    Step 'Building the .exe files'

    if ($Certificate) {
        if (-not (Test-Path $Certificate)) { Fail "Certificate not found: $Certificate" }
        $env:WIN_CSC_LINK = $Certificate
        Say "  Signing with $Certificate" 'Green'
    } else {
        Say '  No code-signing certificate configured.' 'Yellow'
        Say '  The .exe will work, but Windows SmartScreen will warn on other machines,' 'Yellow'
        Say '  and the in-app updater will refuse it. See BUILD-EXE.md for signing.' 'Yellow'
    }

    Push-Location $appDir
    if ($PortableOnly) {
        Run 'electron-builder (portable)' { & npm run dist:portable }
    } else {
        Run 'electron-builder (installer + portable)' { & npm run dist }
    }
    Pop-Location

# -------------------------------------------------------------- 5. report

    Step 'Result'

    if (-not (Test-Path $distDir)) { Fail "No dist folder was produced at $distDir" }

    $artifacts = Get-ChildItem $distDir -File | Where-Object { $_.Extension -in '.exe', '.zip' } | Sort-Object Name
    if (-not $artifacts) { Fail "No .exe was produced. Check $logFile" }

    $installer = $artifacts | Where-Object { $_.Name -match 'Setup' } | Select-Object -First 1
    $portable = $artifacts | Where-Object { $_.Name -match 'Portable' } | Select-Object -First 1

    foreach ($file in @($installer, $portable)) {
        if (-not $file) { continue }

        $signature = Get-AuthenticodeSignature $file.FullName
        $signed = ($signature.Status -eq 'Valid')

        Say ("  {0}" -f $file.Name) 'Green'
        Say ("    size:   {0:N0} MB" -f ($file.Length / 1MB))
        Say ("    folder: {0}" -f $file.DirectoryName)

        if ($signed) {
            Say "    signed: yes ($($signature.SignerCertificate.Subject))" 'Green'
        } else {
            Say "    signed: no — SmartScreen will warn on other PCs" 'Yellow'
        }
    }

    Say ''
    Say '  Next steps:' 'Cyan'
    Say '    1. Install it on the machine that will publish (or copy the portable .exe).'
    Say '    2. Launch it, enter your workspace address and sign in.'
    Say '    3. Sign in to Facebook yourself in the browser window it opens.'
    Say ''
    Say "  Build log: $logFile" 'DarkGray'

    if (Test-Path $distDir) { Start-Process explorer.exe $distDir }

    if (-not $NoPause) { Read-Host 'Press Enter to close' | Out-Null }
    exit 0
}
catch {
    Fail $_.Exception.Message
}
