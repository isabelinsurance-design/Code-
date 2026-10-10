# NetConnect Work Tracker

A call-center workforce system: who is on a call, in the CRM, on a carrier portal, on break or idle, and what got done.
A Time Doctor-style tracker for a browser-based team, with no per-employee subscription. It has three parts:

```
work-tracker/
  extension/   Chrome extension on each employee computer: which site is in front and when, active vs idle,
               lunch/break/meeting buttons, reminders
  server/      API + dashboard (Live, Activity log, Reports, Settings). Plain Node.js, no dependencies, data saved as JSON files
  (your phone system / CRM)  sends call start/end and CRM actions to the server
```

**The usual time-tracker features:** sites and apps used, active vs idle time, breaks, a minute-by-minute activity log
per person, an **activity level %** (how much of the desk time had keyboard or mouse input), reports and timesheets
with CSV export, late / absent / long-break / not-work alerts, and reminders to the employee ("Still working?" with a
one-click **Start break**). Since the team works in Chrome, this covers almost the whole workday.

**Built for a call center on top of that:**
- Phone calls count as work even with no clicking, so nobody looks idle for talking. Calls show in the activity log
  next to the sites.
- CRM actions, after-call work, connected calls and average handle time on every card and report.
- The employee sees the same numbers as the manager, in the extension popup.
- Free and on your own server: no per-user fee, and the data stays with you.
- **No screenshots, on purpose.** They would capture member names, Medicare numbers and health data (HIPAA). Calls,
  CRM actions and the activity log show the work without them.

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

**Productive %** = working ÷ (working + not work + idle + away). Working includes calls, after-call work, meetings and
every site in a category that counts as work. Lunch and breaks are allowed time, and sites not categorized yet are neutral,
so neither counts against anyone.

The browser side uses only the hostname (`humana.com`). Time is kept in 5-minute slots and uploaded once a minute. If the
server can't be reached, the extension keeps the data and sends it later, and retries are never counted twice.
A sleeping computer or a closed Chrome shows as "unaccounted".

## Privacy (built in, not optional)

- The extension records only the hostname, when it was in front (start and end time) and the active/idle/locked state.
  **Never** full URLs or page titles (carrier URLs and titles can contain member IDs and names), page contents,
  screenshots, passwords, Medicare numbers or keystrokes.
- On Nextiva's page (NextivaONE in Chrome) the extension checks only whether a call's audio connection is open, to
  time calls. No phone numbers, names or audio. The call-history import sends only the person, start time, length,
  direction and result, never phone numbers.
- **CRM areas (sections).** For a CRM site you mark "show areas", the extension sends the first word of the path or
  hash (e.g. `leads`, `quotes`, `calendar`) so the board can show which area is open. It keeps only one word, letters
  and hyphens, 2–24 characters: anything with a digit, and every deeper segment where a record id or a person's name
  would sit, is dropped on the computer before anything is sent. This is **off by default** and sensible only for your
  own CRM, whose routes you control; if your CRM ever puts a person's name in that first path word, turn it off.
- **Activity level** counts, per 5-minute slot, only the number of seconds in which the keyboard or mouse was used.
  Never which keys, what is typed, mouse positions, the page or its contents. It's the same idea as Time Doctor's
  "activity", without recording anything.
- **Desktop app names** (optional helper): only the name of the app in front while outside Chrome — never window
  titles, document names or contents. See `native-host/`.
- The employee sees their own working time, productivity and calls in the extension popup, counted the same way as
  the dashboard.
- Member or prospect names from the phone system and CRM are shown live on the manager's dashboard
  ("CRM record open: …"). They are **not shown in TV mode** and **not saved in history**. Saved calls keep only the
  times, outcome, direction and CRM record id.
- At lunch, on break, in a meeting or clocked out, the current site is not shared.

Tell employees in writing before turning it on. Some states (for example New York, Connecticut and Delaware) require
written notice of electronic monitoring.

## The dashboard

- **Live**: one card per person with their status right now, today's working time, **productive %** and **activity %**,
  a timeline and where the time went. For a CRM with areas on, the status shows the area ("CRM work: Leads"). **Needs attention** at the top lists alerts: on a not-work site, idle or locked with no call,
  long lunch, tracker offline without clocking out, late or not clocked in, too much break or not-work time.
  📺 **TV mode** is a dark, read-only version for an office screen.
- **Activity log** (click a name): the person's whole day in order: every site that was in front, how long, its category
  and CRM area, desktop apps by name (with the helper), idle stretches, breaks, calls (with outcome and CRM record id),
  gaps where nothing was recorded, clock in and out. A **CRM areas & apps** list sums the time per area and per app.
  Use ← → to see other days.
- **Reports**: any date range up to 93 days (this week, last week, this month…): a summary per person (days, logged in,
  working, productive %, idle, lunch/break, not work, calls, average handle time, CRM actions, late arrivals, missed
  shifts), a daily timesheet per person, and the sites the team used. **⬇ Timesheet CSV** gives one row per person per
  day with clock in/out and hours as decimals, ready for payroll. **⬇ Summary CSV** gives one row per person.
- **Settings**: shift start time and work days (with per-person exceptions), the late grace period, lunch and break
  limits, alert thresholds (0 turns one off), whether employees also get reminders, the Nextiva call-history import,
  and the sites → categories table — where each category also has **show areas (CRM)** to turn on area tracking.

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

**Updating from an earlier version:** copy the new `extension` folder over the old one and click the ↻ reload icon on
the extension in `chrome://extensions`. Version 1.2 adds the minute-by-minute activity log and reminders (it asks for
the *notifications* permission). Version 1.3 notices Nextiva calls made in Chrome (it asks to run on nextiva.com).
Version 1.4 adds the activity level and CRM areas: it runs a tiny counter on pages (to measure keyboard/mouse activity,
counts only) and reads the path on CRM sites you mark "show areas", so it asks to *read and change data on sites you
visit* — it reads no page content. Days recorded before an update keep all their totals.

If someone removes or disables the extension, their card turns **🔴 Offline**, unless they're on a call.
To stop employees from removing it, force-install it with Google Admin / Chrome Enterprise policy
(`ExtensionInstallForcelist`). That requires publishing it as a private Chrome Web Store item.

## 3. Nextiva calls

Chrome can't hear phone calls by itself, so calls reach the tracker in up to three ways. All of them can be used together:
a call reported twice (for example heard in Chrome, then imported) counts once.

| How the agent calls | How the tracker finds out | Live "On call"? |
|---|---|---|
| **NextivaONE in a Chrome tab** | The extension notices automatically. Nothing to set up | ✓ |
| **Nextiva desktop app** or desk phone | Import Nextiva's call history (below) | ✗, added to the day afterwards |
| Any, once Nextiva turns on its live API | A small connector (not built yet; see "Live calls from Nextiva") | ✓ |

**NextivaONE in Chrome.** Extension 1.3 watches Nextiva's page (`*.nextiva.com`) for one thing only: whether a call's
audio connection is open. When it opens, the card turns **🟢 On call** within seconds. When the agent hangs up, the call
is saved with its start and end time, and the next 5 minutes of CRM activity count as after-call work. No phone numbers,
names or audio are read. If that ever stops working (for example, Nextiva changes its app), the extension falls back to
listening for sound: the Nextiva tab playing sound for 6+ seconds is a call, which ends after 45 seconds of silence.
For live tracking, the simplest rule for the team is **make and take calls in NextivaONE in Chrome**.

**Nextiva desktop app: import the call history.** In the Nextiva admin dashboard open **Call History**, pick the dates
(all users), and **Download CSV**. On the tracker's **Settings** tab, under **Nextiva call history**, choose the file:
- The columns are matched automatically (date/time, duration, user, direction, result). Change any that are wrong.
- Match each Nextiva user to a person on the board once; it's remembered for the next import.
- Click **Import**. The calls appear in Live, the activity logs and Reports, with talk time replacing idle time.
  Importing the same file again is safe. Phone numbers in the file are not sent to the server.

A weekly import before running payroll reports is enough. Daily is better if you watch handle time.

**Live calls from Nextiva (optional, later).** Nextiva's phone system can send live call events (start, answer, hang-up)
through Cisco BroadWorks *Xsi-Events*, but Nextiva only turns it on through your account representative, who brings in a
Solution Engineer. Nextiva's regular support doesn't handle it. Ask for: *"Xsi-Events access to receive call events for our
users in a third-party application."* Once it's on, a connector can feed those events into `POST /api/calls` below and
every call is live, including the desktop app and desk phones.

## 4. Connect other phone systems and the CRM

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

## 5. Categories

Categories are set on the server, never in the extension. On the dashboard (normal mode, not TV mode):

- **Sites not categorized yet:** every site used that day without a category, with a dropdown to assign one.
- **Sites & categories:** add or remove sites, add categories, mark whether each one **counts as work**.
  `humana.com` also covers `www2.humana.com` and other subdomains.

Add the CRM's own domain to the **CRM** category on day one. It drives *CRM work*, *CRM open* and *After-call work*.
Changes apply immediately, to past days too.

**Show areas (CRM).** Each category has a **show areas (CRM)** checkbox next to *counts as work*. Turn it on for your
own CRM to see which area each person is in (Leads, Quotes, Calendar…) on the live cards and in the activity log. The
extension sends only the first area word of the path, never record ids or names (see Privacy). Leave it off for carrier
portals and anything whose address can contain member data.

**Activity level** needs nothing to set up — it's on as soon as the extension is installed (version 1.4). The
**Activity %** on each card and report is the share of desk time (not calls, lunch or breaks) with any keyboard or
mouse input.

**Desktop apps by name.** By default, time outside Chrome shows as *another app (outside Chrome)*. Install the optional
helper in `native-host/` on each computer to see the app's name instead (Nextiva, Excel…). Apps appear in the activity
log and the *CRM areas & apps* list; add an app's name under a category if you want to mark it work or not work.

## API reference

| Endpoint | Key | Purpose |
|---|---|---|
| `POST /api/activity` | tracker | Extension upload: `{employee, version, sentAt, status, batches:[{id, items:[{date, slot, domain, state, seconds}], log:[{from, to, domain, state, section}], calls:[{id, from, to}], activity:[{date, slot, seconds}]}]}`. `section` is the CRM area or desktop app; `activity[].seconds` is the input-active seconds in that slot. `status.call` is the call going on now; the response returns `pathDomains` (CRM sites to record the area of), `me` and `nudge`. Answers with the employee's own numbers (`me`) and a reminder (`nudge`) when one is due |
| `POST /api/calls` | integration or admin | Call start / end (above). The dashboard's Nextiva import uses it with the admin key |
| `POST /api/crm-events` | integration | CRM actions (above) |
| `POST /api/counters` | integration or admin | Set counters on a card |
| `GET /api/ping` | tracker or integration | Connection test |
| `GET /api/day?date=YYYY-MM-DD` | admin | Everything the dashboard shows: live status, logged-in time, calls, after-call work, CRM, categories, timeline |
| `GET /api/person?date=YYYY-MM-DD&id=name` | admin | One person's day: the same report plus the activity log and calls in order |
| `GET /api/range?from=YYYY-MM-DD&to=YYYY-MM-DD` | admin | Reports tab: totals and daily timesheet per person, team sites (93 days max) |
| `GET /api/settings` · `PUT /api/settings` | admin | Schedule and alert rules (see the Settings tab) |
| `GET /api/categories` · `PUT /api/categories` | admin | `{categories:[{name, work}], domains:{"humana.com":"Carrier portals"}}` |

Extension `state` is one of `active`, `idle`, `away`, `lunch`, `break`, `meeting`. Raw data is in
`server/data/days/YYYY-MM-DD.json` if you'd rather import it into the CRM's own database. Each person's `log` there is a list of
`[from, to, domain, state]` (epoch milliseconds).
