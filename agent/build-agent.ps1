<#
  build-agent.ps1 — compiles the 8 West IT RMM Agent and packages the MSI.

  Prerequisites (install once on this Windows machine):
    Just run  setup-build-tools.bat  once — it installs .NET SDK 8 + WiX 5 for you.
    (Manual equivalent: winget install Microsoft.DotNet.SDK.8 ;
     dotnet tool install --global wix --version 5.0.2 ;
     wix extension add -g WixToolset.Util.wixext/5.0.2)

  Usage:
    ./build-agent.ps1                                  # builds dist\EightWestAgent.msi
    ./build-agent.ps1 -EnrollKey <64hex> -PortalUrl https://support.8westit.com
                                                       # also writes dist\install.bat with those baked in
#>
[CmdletBinding()]
param(
  [string]$EnrollKey = "",
  [string]$PortalUrl = "https://support.8westit.com",
  [string]$Configuration = "Release",
  # RustDesk client bundled into the MSI (downloaded once, then cached).
  [string]$RustDeskUrl = "https://github.com/rustdesk/rustdesk/releases/download/1.4.8/rustdesk-1.4.8-x86_64.exe"
)

$ErrorActionPreference = "Stop"
# Make the WiX global tool findable even from a fresh shell / double-click.
if (Test-Path "$env:USERPROFILE\.dotnet\tools") { $env:Path += ";$env:USERPROFILE\.dotnet\tools" }
$root    = Split-Path -Parent $MyInvocation.MyCommand.Path
$proj    = Join-Path $root "EightWestAgent\EightWestAgent.csproj"
$wxs     = Join-Path $root "installer\Product.wxs"
$binDir  = Join-Path $root "EightWestAgent\bin\$Configuration\net48"
$distDir = Join-Path $root "dist"

function Need($cmd, $hint) {
  if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) {
    throw "Required tool '$cmd' not found on PATH. $hint"
  }
}

Write-Host "==> Checking toolchain" -ForegroundColor Cyan
Need "dotnet" "Install the .NET SDK 8."
Need "wix"    "Install WiX 5: dotnet tool install --global wix --version 5.0.2"

Write-Host "==> Building agent ($Configuration)" -ForegroundColor Cyan
dotnet build $proj -c $Configuration --nologo

if (-not (Test-Path (Join-Path $binDir "EightWestAgent.exe"))) {
  throw "Build output not found at $binDir\EightWestAgent.exe"
}

New-Item -ItemType Directory -Force -Path $distDir | Out-Null

# Bundle the RustDesk client so every install is self-contained (cached after first download).
$rdLocal = Join-Path $root "installer\rustdesk-setup.exe"
if (-not (Test-Path $rdLocal)) {
  Write-Host "==> Downloading RustDesk client to bundle (one-time, ~24 MB)" -ForegroundColor Cyan
  $ProgressPreference = "SilentlyContinue"
  Invoke-WebRequest $RustDeskUrl -OutFile $rdLocal
}
Write-Host "==> Bundling RustDesk: $([math]::Round((Get-Item $rdLocal).Length/1MB,1)) MB" -ForegroundColor Gray

Write-Host "==> Packaging MSI" -ForegroundColor Cyan
$msi = Join-Path $distDir "EightWestAgent.msi"

# wix build: -b adds bind paths so <File Source="..."> resolves (agent exe + bundled installer).
# -d BundleRustDesk=1 includes the bundled client (direct builds are always self-contained).
wix build $wxs -ext WixToolset.Util.wixext -d BundleRustDesk=1 -b "$binDir" -b "$root\installer" -o $msi
if ($LASTEXITCODE -ne 0) { throw "wix build failed (exit $LASTEXITCODE) — see the WiX error above." }

Write-Host "==> Built: $msi" -ForegroundColor Green

if ($EnrollKey -ne "") {
  $bat = Join-Path $distDir "install.bat"
@"
@echo off
REM 8 West IT RMM Agent — silent install (run as Administrator)
msiexec /i "%~dp0EightWestAgent.msi" ENROLLKEY=$EnrollKey PORTAL=$PortalUrl /qn /norestart
echo Done. The agent will check in within ~1 minute.
"@ | Set-Content -Encoding ASCII $bat
  Write-Host "==> Wrote $bat (double-click as admin on a client PC)" -ForegroundColor Green
} else {
  Write-Host "Tip: install on a client with:" -ForegroundColor Yellow
  Write-Host "  msiexec /i EightWestAgent.msi ENROLLKEY=<key> PORTAL=$PortalUrl /qn" -ForegroundColor Yellow
}
