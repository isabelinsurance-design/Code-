@echo off
REM Tempo Agent installer (double-click me).
REM Runs install.ps1 with an execution-policy bypass scoped to this process only.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1"
echo.
pause
