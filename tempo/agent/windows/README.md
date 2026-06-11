# Tempo Agent — Windows

Auto-start the Tempo desktop tracker on a Windows PC. Installs per-user
(**no administrator rights needed**) and starts at every logon, running
silently in the background (no console window).

## Two ways to deploy

### A. PCs that have Python 3 (simplest)
1. Make sure the **Tempo Server** is running somewhere reachable
   (`python tempo_server.py` on the manager's PC — note its address, e.g.
   `http://192.168.1.20:8787`).
2. Copy this `windows` folder (plus `..\tempo_agent.py`) to the PC.
3. Double-click **`install.bat`**. It asks for the server URL, a label for the
   user, and whether to capture screenshots — then installs and starts.

### B. PCs without Python (ship a single .exe)
1. On any one Windows machine **with** Python, run **`build_exe.bat`**.
   It produces `TempoAgent.exe` here (no Python required to *run* it).
2. Copy the `windows` folder **including `TempoAgent.exe`** to each PC and
   double-click **`install.bat`**. It will use the `.exe` automatically.

## What the installer does
- Copies the agent to `%LOCALAPPDATA%\Tempo`
- Writes `tempo_config.json` (server URL, user label, screenshot on/off)
- Adds a per-user logon entry under
  `HKCU\Software\Microsoft\Windows\CurrentVersion\Run` named **TempoAgent**
- Starts the agent hidden, right away

## Verify it's running
- Open the dashboard (`http://<server>:8787`) → **Computer Activity** → it
  should flip to **🟢 Live data** within a minute.
- Or check the log: `%LOCALAPPDATA%\Tempo\tempo_agent.log`
- Or Task Manager → Details → `TempoAgent.exe` (or `pythonw.exe`).

## Change settings later
Edit `%LOCALAPPDATA%\Tempo\tempo_config.json` and have the user log off/on
(or end the task and re-run it).

## Remove it
Double-click **`uninstall.bat`** — stops the agent, removes the auto-start, and
deletes the folder.

## ⚠️ Consent
Screenshot/app/website monitoring has legal notice-and-consent requirements
that vary by state/country. Tell the people being tracked and get consent
first. Set `enable_screenshots` to `false` in the config (or answer "n" at
install) if you only want time/app totals.
