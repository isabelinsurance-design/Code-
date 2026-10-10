@echo off
REM ============================================================================
REM  Build a standalone TempoAgent.exe  (run ONCE on a Windows machine with
REM  Python 3 installed). The resulting .exe needs no Python on the target PCs.
REM  After it finishes, ship the whole "windows" folder + TempoAgent.exe and
REM  run install.bat on each computer.
REM ============================================================================
setlocal
cd /d "%~dp0"

echo Installing build dependencies (PyInstaller + screenshot libs)...
python -m pip install --upgrade pyinstaller mss pillow || (
  echo.
  echo ERROR: Python 3 / pip not found. Install Python from https://python.org
  echo        and tick "Add python.exe to PATH", then re-run this script.
  pause & exit /b 1
)

echo.
echo Building TempoAgent.exe ...
pyinstaller --onefile --noconsole --clean --name TempoAgent ^
  --hidden-import mss --hidden-import mss.windows ^
  --hidden-import PIL --hidden-import PIL.ImageGrab ^
  "..\tempo_agent.py"

if exist "dist\TempoAgent.exe" (
  copy /Y "dist\TempoAgent.exe" "TempoAgent.exe" >nul
  rmdir /S /Q build dist 2>nul
  del /Q TempoAgent.spec 2>nul
  echo.
  echo Done -^> %~dp0TempoAgent.exe
  echo Now run install.bat on each computer to track.
) else (
  echo.
  echo Build failed. See the PyInstaller output above.
)
echo.
pause
