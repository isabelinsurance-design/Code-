# NetConnect Work Tracker

A small call-center workforce system: who is on a call, in the CRM, on a carrier portal, on break or idle, and what got done.
No per-employee subscription. It has four parts:

```
work-tracker/
  extension/   Chrome extension on each employee computer: which site is in front, active vs idle, lunch/break/meeting buttons
  server/      API + live dashboard. Plain Node.js, no dependencies, data saved as JSON files
  (your phone system / CRM)  sends call start/end and CRM actions to the server
```

## How time is counted

The day follows the call-center model **Available → On call → After-call work → Available**, plus Break, Lunch,
Meeting/training, Idle, Away and Offline.

| Rule | Counts as |
|---|---|
| A call is live (from the phone system) | **On call**, even with no clicking |
| CRM activity in the 5 min after a call ends | **After-call work** (notes, scheduling, status changes) |
| CRM in front, keyboard/mouse in the last 5 min | **CRM work** |
| An approved site or app in front, keyboard/mouse in the last 5 min | Its category (Carrier portals, Email/Calendar…) |
| No input anywhere for 5+ min, no call | **Idle** · locked computer = **Away** |
| Lunch / Break / Meeting / Clock out button | Those, until **Back to work** |

**Nothing is counted twice.** During a call, the phone system wins. The call's minutes replace whatever the browser saw in
those minutes, idle time first. A 13-minute call with the CRM open is 13 minutes of work, not 26, and nobody is marked
idle for talking instead of clicking. Calls that overlap (transfers, two lines) count once.

**CRM open vs CRM work.** *CRM open* is every minute the CRM tab was in front. *CRM work* is the part with real activity
(input within 5 minutes), excluding call and after-call time. Both appear on each card, along with the number of CRM actions.

The browser side uses only the hostname (`humana.com`). Time is kept in 5-minute slots and uploaded once a minute. If the
server can't be reached, the extension keeps the data and sends it later, and retries are never counted twice.
A sleeping computer or a closed Chrome shows as "unaccounted".

## Privacy (built in, not optional)

- The extension records only the hostname, seconds and active/idle/locked state. **Never** full URLs (carrier URLs
  can contain member IDs), page contents, passwords, Medicare numbers or keystrokes.
- Member or prospect names from the phone system and CRM are shown live on the manager's dashboard
  ("CRM record open: …"). They are **not shown in TV mode** and **not saved in history**. Saved calls keep only the
  times, outcome, direction and CRM record id.
- At lunch, on break, in a meeting or clocked out, the current site is not shared.

Tell employees in writing before turning it on. Some states (for example New York, Connecticut and Delaware) require
written notice of electronic monitoring.

## 1. Run the server

Needs Node.js 18 or newer.

```bash
cd work-tracker/server
TZ=America/New_York npm start      # use the office's time zone
```

The first run prints three keys and saves them in `server/data/keys.json`:

```
Tracker key (paste into each extension's Settings): ab12...
Integration key (phone system / CRM): ef56...
Dashboard: http://localhost:8787/?key=cd34...
```

| Variable | Default | |
|---|---|---|
| `TZ` | server's | **Set it to the office time zone.** Calls are filed by local day and slot |
| `PORT` | `8787` | |
| `DATA_DIR` | `server/data` | Back this folder up. It holds all activity, categories and keys |
| `AFTER_CALL_MINUTES` | `5` | Wrap-up window after each call |
| `TRACKER_KEY` | generated | Extensions. Write-only |
| `INTEGRATION_KEY` | generated | Phone system / CRM: calls, CRM actions, counters. Write-only |
| `ADMIN_KEY` | generated | Dashboard and categories |

**Where to host it:** every employee computer, and the phone system/CRM, must reach it.
- *Office only:* an always-on office PC. Use its LAN address (`http://192.168.1.20:8787`).
- *Remote staff or a cloud phone system (Twilio):* a small cloud server or VPS behind **HTTPS** (Caddy/nginx, or a host
  like Render or Railway). It needs a **persistent disk** for `DATA_DIR`.

Open the dashboard link on the office TV and click **📺 TV mode**, or bookmark `/?key=…&tv=1`.

## 2. Install the extension on each computer

1. Copy the `extension` folder to the computer.
2. Open `chrome://extensions`, turn on **Developer mode**, click **Load unpacked**, and pick the folder.
3. The Settings page opens. Enter the employee's name **exactly as the phone system and CRM will send it**, the server
   address, and the tracker key. Click **Save and test connection**. (Names match case-insensitively.)
4. Pin the extension (puzzle icon → pin) so the Lunch / Break / Meeting / Clock out buttons are one click away.

If someone removes or disables the extension, their card turns **🔴 Offline**, unless they're on a call.
To stop employees from removing it, force-install it with Google Admin / Chrome Enterprise policy
(`ExtensionInstallForcelist`). That requires publishing it as a private Chrome Web Store item.

## 3. Connect the phone system and CRM

Send JSON with header `X-Integration-Key: <integration key>`. Webhooks that can't set headers can add
`?key=<integration key>` to the URL instead. Times can be ISO strings, epoch milliseconds or epoch seconds.

**Calls.** Post when a call starts (no `endedAt`), and again when it ends. Or post only once at the end.
Re-posting the same `callId` updates the call; it never duplicates it.

```http
POST /api/calls
{ "employee": "Arlet", "callId": "CA8f2…", "startedAt": "2026-10-07T10:14:00-04:00",
  "endedAt": "2026-10-07T10:27:00-04:00",          // or "durationSeconds": 780, or omit while live
  "outcome": "connected",                           // connected | no-answer | voicemail | busy | failed (Twilio's "completed" etc. understood)
  "direction": "outbound",                          // optional
  "contact": "Carmen Heredia",                      // optional, live screen only, never saved
  "recordId": "CRM-4411" }                          // optional
```

With Twilio, call this from the CRM's status-callback handler using Twilio's `CallSid`, `CallStatus`, `CallDuration`
and `Direction`. Post on `in-progress` (no end) and on `completed`/`no-answer`/`busy`.

**CRM actions.** Post whenever someone opens a record, adds a note, completes a ticket, changes a status, schedules
an appointment… Add `"count"` to add 1 to that counter on the employee's card.

```http
POST /api/crm-events
{ "employee": "Suri", "action": "appointment_scheduled", "record": "Carmen Heredia",
  "recordId": "CRM-4411", "eventId": "evt_123", "count": "Appointments" }
```

`eventId` is optional, but send it if your CRM retries, so a retry isn't counted twice. Both endpoints also accept a
list: `{"calls": [...]}` / `{"events": [...]}`.

**Counters** (set a number directly, e.g. from a nightly report):
`POST /api/counters` `{ "employee": "Arlet", "date": "2026-10-07", "counters": { "Applications": 2 } }`

## 4. Categories

Categories are set on the server, never in the extension. On the dashboard (normal mode, not TV mode):

- **Sites not categorized yet:** every site used that day without a category, with a dropdown to assign one.
- **Sites & categories:** add or remove sites, add categories, mark whether each one **counts as work**.
  `humana.com` also covers `www2.humana.com` and other subdomains.

Add the CRM's own domain to the **CRM** category on day one. It drives *CRM work*, *CRM open* and *After-call work*.
Changes apply immediately, to past days too.

## API reference

| Endpoint | Key | Purpose |
|---|---|---|
| `POST /api/activity` | tracker | Extension upload: `{employee, version, sentAt, status, batches:[{id, items:[{date, slot, domain, state, seconds}]}]}` |
| `POST /api/calls` | integration | Call start / end (above) |
| `POST /api/crm-events` | integration | CRM actions (above) |
| `POST /api/counters` | integration or admin | Set counters on a card |
| `GET /api/ping` | tracker or integration | Connection test |
| `GET /api/day?date=YYYY-MM-DD` | admin | Everything the dashboard shows: live status, logged-in time, calls, after-call work, CRM, categories, timeline |
| `GET /api/categories` · `PUT /api/categories` | admin | `{categories:[{name, work}], domains:{"humana.com":"Carrier portals"}}` |

Extension `state` is one of `active`, `idle`, `away`, `lunch`, `break`, `meeting`. Raw data is in
`server/data/days/YYYY-MM-DD.json` if you'd rather import it into the CRM's own database.
