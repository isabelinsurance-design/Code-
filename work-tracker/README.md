# NetConnect Work Tracker

See who is working, on what, and for how long, without watching cameras or RemotePC.
No per-employee subscription: a Chrome extension on each computer, a small server, and a live dashboard.

```
work-tracker/
  extension/   Chrome extension (Manifest V3) installed on each employee computer
  server/      API + dashboard. Plain Node.js, no dependencies, data saved as JSON files
```

## How time is counted

- Every tab switch, window switch, or switch to another app closes the previous segment.
  Its seconds go to that site's **hostname** (`humana.com`), in 5-minute slots.
- **Idle:** no keyboard or mouse for 5 minutes. The first 5 minutes still count as active; after that it's idle.
  A **locked** computer counts as away. So a UHC page left open for an hour shows the time actually worked, not 60 minutes.
- Working in another program (phone app, Excel, PDF) counts as *Other apps (outside Chrome)*.
- **Clock in** happens automatically at the first activity of the day. The toolbar popup has
  **Lunch**, **Break**, **Clock out** and **Back to work** buttons. While at lunch or clocked out, no site names are shared.
- The extension uploads once a minute. If the server can't be reached, it keeps the data (up to about 2 days) and sends it later.
  Every upload carries a unique id, so a retry is never counted twice.
- A sleeping computer or a closed Chrome isn't counted. The dashboard shows that time as "no data".

## Privacy (built in, not optional)

Recorded: hostname, seconds, and active/idle/locked state. **Never** the full URL (carrier URLs can contain
member IDs), page contents, passwords, Medicare numbers, member data, or keystrokes. Tell employees in writing
before turning it on. Some states (for example New York, Connecticut and Delaware) require written notice
of electronic monitoring.

## 1. Run the server

Needs Node.js 18 or newer.

```bash
cd work-tracker/server
npm start            # or: node server.js
```

The first run prints two keys and saves them in `server/data/keys.json`:

```
Tracker key (paste into each extension's Settings): ab12...
Dashboard: http://localhost:8787/?key=cd34...
```

| Variable | Default | |
|---|---|---|
| `PORT` | `8787` | |
| `DATA_DIR` | `server/data` | Back this folder up. It holds all activity, categories and keys |
| `TRACKER_KEY` | generated | Key the extensions send. Write-only access |
| `ADMIN_KEY` | generated | Key for the dashboard, categories and counters |

**Where to host it:** every employee computer must be able to reach it.
- *Office only:* an always-on office PC. Use its LAN address (`http://192.168.1.20:8787`) in the extensions.
- *Remote staff:* a small cloud server or VPS behind **HTTPS** (Caddy/nginx, or a host like Render or Railway).
  It needs a **persistent disk** for `DATA_DIR`.

Open the dashboard link on the office TV and click **📺 TV mode**, or bookmark `/?key=…&tv=1`.

## 2. Install the extension on each computer

1. Copy the `extension` folder to the computer.
2. Open `chrome://extensions`, turn on **Developer mode**, click **Load unpacked**, and pick the folder.
3. The Settings page opens. Enter the employee's name as it should appear on the dashboard,
   the server address, and the tracker key. Click **Save and test connection**.
4. Pin the extension (puzzle icon → pin) so the Lunch / Break / Clock out buttons are one click away.

If someone removes or disables the extension, their card turns **🔴 Offline**.
To stop employees from removing it, force-install it with Google Admin / Chrome Enterprise policy
(`ExtensionInstallForcelist`). That requires publishing it as a private Chrome Web Store item.

## 3. Categories

Categories are set on the server, never in the extension. On the dashboard (normal mode, not TV mode):

- **Sites not categorized yet:** every site used that day that has no category, with a dropdown to assign one.
- **Sites & categories:** add or remove sites, add categories, and mark whether each one **counts as work**.
  `humana.com` also covers `www2.humana.com` and other subdomains.

Changes apply immediately, to past days too. Add the CRM's own domain to **CRM** on day one.

## API (for connecting the CRM)

All JSON. Tracker endpoints use header `X-Tracker-Key`. Admin endpoints use `X-Admin-Key` (or `?key=`).

| Endpoint | Key | Purpose |
|---|---|---|
| `POST /api/activity` | tracker | Extension upload: `{employee, version, sentAt, status, batches:[{id, items:[{date, slot, domain, state, seconds}]}]}` → `{accepted:[ids]}` |
| `GET /api/ping` | tracker | Connection test |
| `GET /api/day?date=YYYY-MM-DD` | admin | Everything the dashboard shows: live status, clock in/out, totals, categories, timeline, sites |
| `GET /api/categories` · `PUT /api/categories` | admin | `{categories:[{name, work}], domains:{"humana.com":"Carrier portals"}}` |
| `POST /api/counters` | admin | Show CRM numbers on a card: `{employee:"Arlet", date:"2026-10-07", counters:{Calls:52, Tickets:8, Appts:3}}` |

`state` is one of `active`, `idle`, `away`, `lunch`, `break`. Raw data is in `server/data/days/YYYY-MM-DD.json`
if you'd rather import it into the CRM's own database.
