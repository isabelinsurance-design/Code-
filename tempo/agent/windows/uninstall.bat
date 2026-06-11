@echo off
REM Tempo Agent uninstaller (double-click me).
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0uninstall.ps1"
echo.
pause
