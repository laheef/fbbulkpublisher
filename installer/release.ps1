# Release script for LinkEasy Publisher.
#
#   pwsh release.ps1 -Version 1.0.1 -FeedPath \\fileserver\updates\linkeasy-publisher
#
# Steps: build the runtime and the application, sign, hash, write the update feed
# manifest, then publish. Nothing is uploaded unless the signature verifies —
# the application refuses unsigned updates, so an unsigned release would simply
# never install.

param(
    [Parameter(Mandatory = $true)][string]$Version,
    [string]$FeedPath = "",
    [string]$Certificate = $env:WIN_CSC_LINK,
    [switch]$SkipBuild
)

$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$appDir = Join-Path $root "windows-app"
$distDir = Join-Path $appDir "dist"

function Step($message) { Write-Host "`n=== $message ===" -ForegroundColor Cyan }
function Fail($message) { Write-Host "FAILED: $message" -ForegroundColor Red; exit 1 }

# ---------------------------------------------------------------- 1. version

Step "Version $Version"
$packageFile = Join-Path $appDir "package.json"
$package = Get-Content $packageFile -Raw | ConvertFrom-Json
if ($package.version -ne $Version) {
    Write-Host "Setting package version $($package.version) -> $Version"
    $package.version = $Version
    $package | ConvertTo-Json -Depth 20 | Set-Content $packageFile
}

# ---------------------------------------------------------------- 2. build

if (-not $SkipBuild) {
    Step "Bundled runtime (Node, Playwright, Chromium, FFmpeg)"
    Push-Location (Join-Path $root "installer")
    node build-runtime.js --payload
    if ($LASTEXITCODE -ne 0) { Fail "build-runtime.js failed" }
    node verify-runtime.js
    if ($LASTEXITCODE -ne 0) { Fail "runtime verification failed on this machine" }
    Pop-Location

    Step "Application packages"
    Push-Location $appDir
    if (-not (Test-Path "node_modules")) { npm install; if ($LASTEXITCODE -ne 0) { Fail "npm install failed" } }
    if ($Certificate) {
        $env:WIN_CSC_LINK = $Certificate
        Write-Host "Signing with the certificate in WIN_CSC_LINK."
    } else {
        Write-Host "WARNING: no certificate configured — the build will be unsigned and the updater will refuse it." -ForegroundColor Yellow
    }
    npm run dist
    if ($LASTEXITCODE -ne 0) { Fail "electron-builder failed" }
    Pop-Location
}

# ---------------------------------------------------------------- 3. verify

Step "Verifying artefacts"
$installer = Get-ChildItem $distDir -Filter "*Setup.exe" | Select-Object -First 1
$portable = Get-ChildItem $distDir -Filter "*Portable.exe" | Select-Object -First 1
if (-not $installer) { Fail "no installer was produced" }

foreach ($file in @($installer, $portable)) {
    if (-not $file) { continue }
    $signature = Get-AuthenticodeSignature $file.FullName
    if ($signature.Status -ne "Valid") {
        Fail "$($file.Name) is not validly signed ($($signature.Status))"
    }
    Write-Host ("{0}: {1:N1} MB, signed by {2}" -f $file.Name, ($file.Length / 1MB), $signature.SignerCertificate.Subject)
}

# ---------------------------------------------------------------- 4. hashes

Step "Hashes"
$hashes = @{}
foreach ($file in Get-ChildItem $distDir -File) {
    $hashes[$file.Name] = (Get-FileHash $file.FullName -Algorithm SHA512).Hash.ToLower()
    Write-Host ("{0}  {1}" -f $hashes[$file.Name].Substring(0, 16), $file.Name)
}

# ---------------------------------------------------------------- 5. feed

if ($FeedPath) {
    Step "Publishing to $FeedPath"
    if (-not (Test-Path $FeedPath)) { New-Item -ItemType Directory -Path $FeedPath -Force | Out-Null }

    # electron-updater reads latest.yml (NSIS) and latest-linux.yml style files.
    $latest = @"
version: $Version
files:
  - url: $($installer.Name)
    sha512: $($hashes[$installer.Name])
    size: $($installer.Length)
path: $($installer.Name)
sha512: $($hashes[$installer.Name])
releaseDate: '$(Get-Date -Format o)'
"@
    Set-Content -Path (Join-Path $distDir "latest.yml") -Value $latest -Encoding ascii

    Copy-Item (Join-Path $distDir "latest.yml") $FeedPath -Force
    foreach ($name in $hashes.Keys) {
        Copy-Item (Join-Path $distDir $name) $FeedPath -Force
    }

    Write-Host "Published $($hashes.Count) files. Clients pick the update up within their check interval."
} else {
    Write-Host "`nNo -FeedPath given; artefacts stay in $distDir" -ForegroundColor Yellow
}

Step "Done"
Write-Host "Installer : $($installer.FullName)"
if ($portable) { Write-Host "Portable  : $($portable.FullName)" }
if ($Certificate) { Write-Host "Signed    : yes" } else { Write-Host "Signed    : NO (clients will refuse this build)" -ForegroundColor Yellow }
