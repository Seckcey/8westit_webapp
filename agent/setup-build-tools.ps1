<#
  setup-build-tools.ps1 — one-time setup of everything needed to build the agent MSI.

  Run this ONCE on a new Windows machine (double-click setup-build-tools.bat).
  It installs: .NET SDK 8, the nuget.org package source, and WiX 5 (+ Util extension).
  Safe to re-run — it skips anything already present.
#>
$ErrorActionPreference = "Continue"

function Step($msg) { Write-Host "==> $msg" -ForegroundColor Cyan }

Step ".NET SDK 8 (via winget)"
if (Get-Command dotnet -ErrorAction SilentlyContinue) {
  Write-Host "    dotnet already present ($((dotnet --version)))" -ForegroundColor Gray
} else {
  winget install --id Microsoft.DotNet.SDK.8 -e --silent --accept-package-agreements --accept-source-agreements --disable-interactivity
}

# Pick up the freshly-installed tools in THIS session.
$env:Path = [Environment]::GetEnvironmentVariable("Path","Machine") + ";" +
            [Environment]::GetEnvironmentVariable("Path","User") + ";$env:USERPROFILE\.dotnet\tools"

if (-not (Get-Command dotnet -ErrorAction SilentlyContinue)) {
  Write-Host "    .NET SDK isn't on PATH yet. Close this window, open a NEW one, and run setup again." -ForegroundColor Yellow
  Read-Host "    Press Enter to close"; exit 1
}

Step "NuGet package source (nuget.org)"
$sources = (dotnet nuget list source) 2>$null
if ($sources -match "api\.nuget\.org") {
  Write-Host "    nuget.org already configured" -ForegroundColor Gray
} else {
  dotnet nuget add source https://api.nuget.org/v3/index.json -n nuget.org
}

Step "WiX Toolset 5 (free; v6/v7 require a paid EULA)"
dotnet tool install --global wix --version 5.0.2 2>$null
if ($LASTEXITCODE -ne 0) {
  # Already installed (or different version) — make sure it's 5.0.2.
  dotnet tool update --global wix --version 5.0.2 2>$null
}

Step "WiX Util extension"
wix extension add -g WixToolset.Util.wixext/5.0.2 2>$null

Write-Host ""
Write-Host "  Setup complete." -ForegroundColor Green
Write-Host "  dotnet: $((dotnet --version 2>$null))" -ForegroundColor White
Write-Host "  wix:    $((wix --version 2>$null))" -ForegroundColor White
Write-Host ""
Write-Host "  You can now build installers with make-installer.bat." -ForegroundColor Green
Read-Host "  Press Enter to close"
