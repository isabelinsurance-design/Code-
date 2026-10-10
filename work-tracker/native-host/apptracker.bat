@echo off
rem Launches the NetConnect Work Tracker desktop-app helper. Chrome runs this.
powershell -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%~dp0apptracker.ps1"
