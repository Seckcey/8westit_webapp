@echo off
REM Run me ONCE on a new PC to install the build tools (.NET SDK 8 + WiX 5).
REM Right-click -> Run as administrator (winget may need it).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup-build-tools.ps1"
