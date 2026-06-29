<#
  build-templates.ps1 — builds the two generic installer TEMPLATES that the portal
  serves (patching in each client's key on download). Run this once per agent version,
  then upload the two .msi files to the portal at  public_html/8westit/installers/.

  Output (dist\templates\):
    agent-template-full.msi   (~24 MB, RustDesk bundled — for "include remote client")
    agent-template-lite.msi   (~1 MB, agent downloads RustDesk on first run)

  Both carry a 64-char placeholder ENROLLKEY that download.php replaces per client.
#>
[CmdletBinding()]
param([string]$Configuration = "Release")

$ErrorActionPreference = "Stop"
if (Test-Path "$env:USERPROFILE\.dotnet\tools") { $env:Path += ";$env:USERPROFILE\.dotnet\tools" }
$root    = Split-Path -Parent $MyInvocation.MyCommand.Path
$proj    = Join-Path $root "EightWestAgent\EightWestAgent.csproj"
$wxs     = Join-Path $root "installer\Product.wxs"
$binDir  = Join-Path $root "EightWestAgent\bin\$Configuration\net48"
$instDir = Join-Path $root "installer"
$outDir  = Join-Path $root "dist\templates"
$rdUrl   = "https://github.com/rustdesk/rustdesk/releases/download/1.4.8/rustdesk-1.4.8-x86_64.exe"

# 64-char placeholder — MUST stay identical to PLACEHOLDER in portal/public/download.php.
$placeholder = "8WESTIT-ENROLLKEY-PLACEHOLDER-" + ("0" * (64 - "8WESTIT-ENROLLKEY-PLACEHOLDER-".Length))
if ($placeholder.Length -ne 64) { throw "Placeholder must be 64 chars (is $($placeholder.Length))." }

foreach ($cmd in @("dotnet","wix")) {
  if (-not (Get-Command $cmd -ErrorAction SilentlyContinue)) {
    throw "'$cmd' not found. Run setup-build-tools.bat first."
  }
}

Write-Host "==> Building agent ($Configuration)" -ForegroundColor Cyan
dotnet build $proj -c $Configuration --nologo
if (-not (Test-Path (Join-Path $binDir "EightWestAgent.exe"))) { throw "Agent build output missing." }

New-Item -ItemType Directory -Force -Path $outDir | Out-Null

# Ensure the bundled RustDesk client is present for the full template.
$rdLocal = Join-Path $instDir "rustdesk-setup.exe"
if (-not (Test-Path $rdLocal)) {
  Write-Host "==> Downloading RustDesk client to bundle (one-time, ~24 MB)" -ForegroundColor Cyan
  $ProgressPreference = "SilentlyContinue"
  Invoke-WebRequest $rdUrl -OutFile $rdLocal
}

$fullMsi = Join-Path $outDir "agent-template-full.msi"
$liteMsi = Join-Path $outDir "agent-template-lite.msi"

Write-Host "==> Building FULL template (RustDesk bundled)" -ForegroundColor Cyan
wix build $wxs -ext WixToolset.Util.wixext -d BundleRustDesk=1 "-d" "EnrollDefault=$placeholder" `
  -b "$binDir" -b "$instDir" -o $fullMsi

Write-Host "==> Building LITE template (no bundle)" -ForegroundColor Cyan
wix build $wxs -ext WixToolset.Util.wixext "-d" "EnrollDefault=$placeholder" `
  -b "$binDir" -b "$instDir" -o $liteMsi

# Sanity: confirm the placeholder is actually present in each MSI so the portal can patch it.
foreach ($m in @($fullMsi, $liteMsi)) {
  $bytes = [System.IO.File]::ReadAllText($m, [System.Text.Encoding]::GetEncoding(28591)) # latin1, byte-safe
  if ($bytes.IndexOf($placeholder) -lt 0) { throw "Placeholder not found in $m — patching would fail." }
  Write-Host ("    {0}  {1} MB  (placeholder OK)" -f (Split-Path $m -Leaf), [math]::Round((Get-Item $m).Length/1MB,1)) -ForegroundColor Green
}

Write-Host ""
Write-Host "==> Templates built in: $outDir" -ForegroundColor Green
Write-Host "    Upload BOTH .msi files to the portal at  public_html/8westit/installers/" -ForegroundColor Yellow
