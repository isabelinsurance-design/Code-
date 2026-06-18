# Tempo

A self-hosted, Time Doctor–style **time & computer-activity tracker**.

It answers the question *"what was actually done on the computer?"* — which
apps and websites were used, how long, when the person was idle, and
screenshots — plus a manual start/stop timer and project timesheets.

```
tempo/
├── dashboard/index.html     ← the UI (timer, timesheet, projects, reports,
│                              computer-activity report, team)
├── server/tempo_server.py   ← collector + API + serves the dashboard (stdlib only)
└── agent/tempo_agent.py     ← the desktop tracker that runs on each machine
```

## Why a desktop agent?

A web page is sandboxed — it **cannot** see other apps, the URLs you visit in
other tabs, your idle time, or your screen. That's an OS security boundary.
Time Doctor, Hubstaff, ActivTrak, etc. all solve it the only way possible: a
small program installed on the computer. **Tempo Agent** is that program.

The agent uses **no required pip packages**:

| OS      | active app / window / URL              | idle time           |
|---------|----------------------------------------|---------------------|
| Windows | `ctypes` (user32/psapi)                | `GetLastInputInfo`  |
| macOS   | `osascript` (AppleScript)              | `ioreg`             |
| Linux   | `xdotool`                              | `xprintidle`        |

Screenshots are **optional** and only happen if `mss` or `Pillow` is installed
(`pip install mss`). Everything else works without them.

## Quick start

**1. Start the server** (also serves the dashboard):

```bash
cd tempo/server
python3 tempo_server.py            # http://localhost:8787
```

Open **http://localhost:8787** → go to **Computer Activity**. It will say the
agent isn't reporting yet (you'll see demo data).

**2. Run the agent** on the computer you want to track:

```bash
cd tempo/agent
python3 tempo_agent.py
# point it elsewhere if the server isn't local:
TEMPO_SERVER=http://192.168.1.20:8787 TEMPO_USER=isabel python3 tempo_agent.py
```

Within a minute the dashboard flips to **🟢 Live data** and fills with real
apps, sites, idle time and screenshots.

> Linux needs `xdotool` (and `xprintidle` for idle): `sudo apt install xdotool xprintidle`.

## Configuration

**Agent** — edit the `CONFIG` block at the top of `tempo_agent.py` (or use env vars):

| setting | default | meaning |
|---|---|---|
| `TEMPO_SERVER` | `http://localhost:8787` | where to send samples |
| `TEMPO_USER` | machine hostname | label for this person/computer |
| `SAMPLE_INTERVAL` | `30`s | how often activity is sampled |
| `SCREENSHOT_INTERVAL` | `600`s | seconds between screenshots (`0` = off) |
| `IDLE_THRESHOLD` | `60`s | no input for this long = "idle/away" |
| `ENABLE_SCREENSHOTS` | `True` | needs `mss`/`Pillow` to actually capture |

**Productivity rules** — edit `server/categories.json` (auto-created on first run).
Any app/title/URL containing a keyword is tagged `productive`, `neutral`, or
`distracting`. Changing the rules re-classifies history instantly (it's applied
at read time).

## How it works

```
[ Tempo Agent ] --POST /api/ingest--> [ Tempo Server ] --SQLite (tempo.db)
   active app/url/idle/shot                  |
                                  classify + aggregate per day
                                             |
[ Dashboard ] <--GET /api/activity?day=N-----'
```

The dashboard reads from one place (`GET /api/activity`). When the server has
agent data it shows it live; otherwise it falls back to demo data so the UI is
never empty.

## Wiring to a CRM

Two clean hooks:

- **Activity → CRM:** in the agent's `post()`, also POST samples to your CRM API.
- **Time entries → CRM:** the dashboard timer stores entries per project; forward
  them to your CRM as time logs per client/contact (the data is in `localStorage`
  under `timetrack.v1`, ready to sync).

## ⚠️ Privacy & consent

Monitoring employees' computers — especially **screenshots** and app/website
logging — carries legal and ethical obligations that vary by country and U.S.
state (notice, consent, data handling). **Notify the people being tracked and
get consent before deploying.** Turn screenshots off (`SCREENSHOT_INTERVAL = 0`)
if you only need time/app totals. This tool is for transparent, consented
workforce tracking — not covert surveillance.
