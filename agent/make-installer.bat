@echo off
REM Double-click me to build a client installer (.msi).
REM Prompts for the enrollment key from the portal, then builds dist\EightWestAgent.msi
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0Build-Installer.ps1"
