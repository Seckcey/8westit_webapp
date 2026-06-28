<#
  Build-Installer.ps1 — interactive one-step MSI builder.

  Double-click make-installer.bat (or run this script). It asks for the
  enrollment key you copied from the portal (Agents & Keys), then builds:
     dist\EightWestAgent.msi   and   dist\install.bat
#>
$ErrorActionPreference = "Stop"

Write-Host ""
Write-Host "  8 West IT - Agent Installer Builder" -ForegroundColor Cyan
Write-Host "  -----------------------------------" -ForegroundColor Cyan
Write-Host "  1) In the portal: Agents & Keys -> Generate -> Copy the key" -ForegroundColor Gray
Write-Host ""

$key = Read-Host "  Paste the enrollment key"
$key = ($key -replace '\s','').Trim()
if ($key -notmatch '^[A-Fa-f0-9]{64}$') {
    Write-Host "  That doesn't look like a 64-character key. Aborting." -ForegroundColor Red
    Read-Host "  Press Enter to close"; exit 1
}

$portal = Read-Host "  Portal URL [press Enter for https://support.8westit.com]"
if (-not $portal) { $portal = "https://support.8westit.com" }

& "$PSScriptRoot\build-agent.ps1" -EnrollKey $key -PortalUrl $portal

Write-Host ""
Write-Host "  DONE." -ForegroundColor Green
Write-Host "  Your installer is here:" -ForegroundColor Green
Write-Host "    $PSScriptRoot\dist\EightWestAgent.msi" -ForegroundColor White
Write-Host "    $PSScriptRoot\dist\install.bat   (run as Administrator on the client PC)" -ForegroundColor White
Write-Host ""
Read-Host "  Press Enter to close"
