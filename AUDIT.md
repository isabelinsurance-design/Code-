# AUDIT — Athena (Code-) codebase

> Scope: `server/` (Node.js 20 ESM, the production system), `app/todoisabel.html`
> (single-file legacy UI), `app-v2/` (React/Vite PWA built into `server/public`).
> Method: static analysis + module-level review (3 parallel passes) with spot
> verification of the highest-severity findings against `file:line`.
> Date: 2026-06-10 · Branch: `claude/sleepy-darwin-P4k2z`.
> Effort key: **S** < 1h · **M** 1–4h · **L** > 4h.

---

## Executive summary

The system is feature-rich and mostly works, but it has **three structural risk
classes** that matter more than any single bug:

1. **Unsafe persistence.** Every `data/*.json` "table" is read-modify-written with
   plain `writeFileSync` and no locking or atomic rename. Concurrent crons +
   webhooks **will** silently clobber each other → data loss. (CRITICAL)
2. **Silent-empty failure pattern.** Several code paths turn an unexpected/failed
   state into a benign-looking empty result instead of an error — the LUNA
   `unwrapArrayResponse` shape-guesser, the soft-retired `crm.js` helpers feeding
   `hooks.js`/`briefing.js`, and (already hit) the team email. Compliance checks
   and briefings can no-op without anyone noticing. (CRITICAL/HIGH)
3. **Secret & boundary hygiene.** A session-signing secret silently falls back to
   a hardcoded dev value; the LUNA shared key is referenced across tracked docs
   and must be rotated; the LUNA "only-Pilar" boundary is enforced at runtime but
   not at the module level. (HIGH)

Plus heavy **documentation drift**: CLAUDE.md says 17 coaches / 10 crons; the code
has ~21 coaches / 33 crons. CLAUDE.md is no longer a reliable source of truth.

---

## 1. Architecture map

Three cooperating systems (only the first lives in this repo):

- **Athena** (this repo) — personal chief-of-staff. Node.js ESM on Railway.
  WhatsApp + voice + PWA. Orchestrator (`directora`, Opus) fans out to specialist
  coaches (Sonnet) via `consultar_especialistas`.
- **LUNA** — team Medicare CRM. PHP/MySQL on Bluehost. Separate repo/session.
  Reached only through the HTTP bridge (`luna_client.js`) with a shared-secret header.
- **Sistema Maestro IA** — legacy marketing tool, being absorbed into LUNA.

Server module groups (`server/src`, ~25.5k LOC across ~85 files):

- **Entry/orchestration:** `index.js` (HTTP + 33 crons), `directora.js` (run loop),
  `claude.js` (Anthropic client), `agents.js` (coach personas + `ISABEL_FILOSOFIA`).
- **Tools:** `tools.js` (directora tools + dispatcher), `luna_tools.js` (Pilar-only
  LUNA tools), `luna_client.js` (HTTP bridge).
- **Memory/state:** `memory.js`, `tasks.js`, `commitments.js`, `entities.js`,
  `signals.js`, `skills.js`, plus soft-retired `crm.js`, `gaps.js`, `auditor.js`.
- **Proactive:** `briefing.js`, `proactive.js`, `triage.js`, `inbox_idle.js`,
  `manager_mode.js`, `team_morning_email.js`, `birthdays_daily.js`, etc.
- **Channels:** `whatsapp.js`, `voice.js`, `email.js`, `tts.js`, `transcribe.js`,
  `calendar.js`, `nextiva.js`, `instagram.js`.
- **Ops/cross-cutting:** `security.js`, `backup.js`, `hooks.js`, `api.js`
  (PWA/REST backend), `dashboard.js`.

UI: `app/todoisabel.html` (4.1k-line single file, no build) and `app-v2/` (React
PWA, built via `postinstall` into `server/public`).

---

## 2. Code structure

| File | LOC | Note |
|---|---|---|
| `tools.js` | 3,579 | **God file** — all tool defs + one giant `dispatchTool()` switch (100+ cases) + MCP bridge. Hard to test/modify. |
| `api.js` | 1,862 | PWA/REST backend (20+ routes) + dashboard + state builders. Undocumented in CLAUDE.md. |
| `agents.js` | 1,457 | ~21 coach personas + `ISABEL_FILOSOFIA` + directora prompt. |
| `index.js` | 586 | HTTP server **and** all 33 cron registrations — split candidate. |
| `calendar.js` `skills.js` `memory.js` `luna_tools.js` `voice.js` `luna_client.js` | 480–576 | Reasonable size. |

Cohesion is decent at the module level; the two pain points are `tools.js`
(monolithic dispatch) and `index.js` (HTTP + scheduling mixed).

---

## 3. Dependencies

`server/package.json` — 12 runtime deps, all current as of mid-2026; `package-lock.json`
committed; pure-JS (no native build). `node_modules` not installed in this checkout.

- **LOW** `web-push` declared but no `import` found anywhere → dead dependency. (S)
- **LOW** All deps use `^` (incl. `@anthropic-ai/sdk ^0.40.1`, a pre-1.0 SDK where
  minor bumps can break). For a HIPAA-adjacent prod system, pin majors / commit to
  lockfile-only installs. (S)
- **LOW** `postinstall` runs `app-v2` build and swallows failure with `|| echo` →
  a broken PWA build deploys silently as "skipped". (S)

---

## 4. Data / persistence patterns

"Database" = JSON files in `server/data/` (gitignored, on a Railway volume, hourly
tar.gz → R2). Pattern everywhere: `JSON.parse(readFileSync())` → mutate →
`writeFileSync(JSON.stringify())`.

- **CRITICAL** No locking, no atomic write (`tasks.js:38`, `memory.js:54`, and the
  same shape in `commitments.js`, `entities.js`, `skills.js`). Two writers (e.g.
  `taskTick` cron + a `/whatsapp` tool call) read-modify-write the same file
  concurrently → last write wins, the other's mutation is lost. A crash mid-write
  can also leave a truncated file. **Fix:** write to temp + `fs.rename` (atomic on
  same volume), and serialize writes per file (in-process mutex or `proper-lockfile`). (M)
- **MEDIUM** Corrupt-file reads are caught and silently replaced with `[]`/`{}` in
  several modules → silent data loss with no alert. `memory.js` has a good
  `readJsonSafe`; replicate it and log/alert on parse failure. (S)
- **MEDIUM** `memory.js:468` keeps `slice(-24)` history while the comment/CLAUDE.md
  say 40 turns — context silently shorter than documented. Decide and align. (S)
- **LOW** `activity.json` capped at 500 only on the write path; a reader-then-crash
  can skip the prune. Defensive slice on read + periodic GC. (S)

---

## 5. API surface

HTTP (from `index.js` + `api.js`):

| Method | Path | Auth | Notes |
|---|---|---|---|
| GET | `/`, `/health` | none | liveness/health |
| GET | `/audio/<id>.mp3` | none | static TTS output |
| POST | `/whatsapp` | **signature + rate-limit** | correct (`twilioSignatureMiddleware`) |
| POST | `/voice/incoming`, `/voice/status` | signature **logged, not enforced** | inconsistent w/ `/whatsapp` (MEDIUM) |
| GET | `/dashboard`, `/dashboard/state` | basic auth (`DASHBOARD_PASSWORD`) | no login throttle |
| GET | `/app/*` | none | static React PWA |
| POST | `/api/login`, `/logout`, GET `/api/me` | cookie session | **no rate-limit on login** (MEDIUM) |
| POST | `/api/transcribe`, `/api/tts` | session | transcribe stream has **no timeout** (MEDIUM) |
| GET | `/api/hoy`, routines, focus, research, coach-cadence, commitments, … (20+) | session | bulk PWA endpoints |
| GET | `/api/luna/raw`, `/api/luna/health`, `/api/luna/debug-auth` | session | diag; `debug-auth` returns masked key shape only (OK) |
| WSS | `/voice/relay` | Twilio relay | live call bridge |

---

## 6. Deployment config

- Railway, `npm start` → `node src/index.js`, Node ≥ 20, persistent volume for
  `data/` + `backups/`. Details in `DEPLOY.md`. No Dockerfile/Procfile in repo.
- **MEDIUM** `TWILIO_REQUIRE_SIGNATURE` enforced on `/whatsapp` but **not** on the
  voice webhooks (only logged) — a deployer setting it `true` reasonably assumes
  voice is covered too. Enforce or document. (S)
- **MEDIUM** Rate-limit + idempotency (`security.js`) live in process memory → wiped
  on every Railway restart/deploy, so a redeploy reopens the duplicate-`MessageSid`
  and burst windows. Move to a durable store (or accept + document). (M)
- **LOW** `.env.example` references `VERIFY_TWILIO_SIGNATURE` while code reads
  `TWILIO_REQUIRE_SIGNATURE`. (S)
- **LOW** Local `backups/*.tar.gz` are unencrypted for the 24h local-retention
  window (CLAUDE.md implies "encrypted at rest"). (S)

---

## 7. Findings by severity

### CRITICAL
| # | Finding | Where | Fix |
|---|---|---|---|
| C1 | JSON state files written with no lock/atomic rename → concurrent crons+webhooks clobber data | `tasks.js:38`, `memory.js:54`, `commitments.js`, `entities.js`, `skills.js` | M |
| C2 | `gaps_overview` is invoked by the morning briefing prompt but **no such tool exists** → gap analysis (a documented core feature) silently fails | `briefing.js:217`, `gaps.js:202` vs `tools.js` (absent) | M |
| C3 | LUNA shared key referenced across tracked docs + known-exposed → **rotate now** | `*.md` (masked) + Railway/Bluehost | S |

### HIGH
| # | Finding | Where | Fix |
|---|---|---|---|
| H1 | `unwrapArrayResponse` returns `[]` whenever the response shape isn't recognized → bridge failures look like "empty," not errors (same class that emptied the team email) | `luna_client.js:171-181` | M |
| H2 | SOA/compliance review hook calls `findClient()` against the now-empty `crm.json` → it always returns `[]`, so the CMS "no plan details without signed SOA" guard is effectively a **no-op** | `hooks.js:26,190` + `crm.js` | M |
| H3 | Session-signing secret falls back to hardcoded `'dev-only-secret-NOT-for-prod'` if env unset → forgeable session cookies | `api.js:21` | S |
| H4 | LUNA auth key sent in 3 headers + 401/403 error text leaks bridge architecture into logs | `luna_client.js:68-70, 95-101` | S |

### MEDIUM
| # | Finding | Where | Fix |
|---|---|---|---|
| M1 | Voice webhooks validate signature but don't block; inconsistent with `/whatsapp` | `index.js:83-121` | S |
| M2 | Rate-limit + idempotency in RAM only → reset on every deploy | `security.js:46-100` | M |
| M3 | `/api/login` has no attempt throttle → brute-forceable | `api.js:122` | S |
| M4 | `/api/transcribe` stream has no timeout → slow client hangs handler | `api.js:154` | S |
| M5 | `cron.schedule(expr, () => fn().catch())` doesn't catch async rejections; a failed dynamic `import()` kills a cron silently | `index.js` cron registrations | S |
| M6 | Cron stampede — duplicate slots: `30 6` (briefing+birthdays), `0 18 * * 1-5` (3 jobs), `*/5 7-21` (2 jobs), `0 * * * *` (2 jobs) | `index.js` | S |
| M7 | PII redaction inconsistent: `luna_client.js` local `redactPii` misses SSN that `security.js redactPII` catches | `luna_client.js:41-47` | S |
| M8 | Dead `pilar` coach branch (`especialista==='pilar'`) — id doesn't exist in `agents.js`; masks intent of the LUNA guard | `tools.js:1954,2005` | S |
| M9 | LUNA boundary enforced only at dispatch time; any module can `import './luna_client.js'` directly (`voice.js` already does, documented) | architectural | M |
| M10 | CLAUDE.md drift: 17→~21 coaches, 10→33 crons, `api.js`/dashboard undocumented | `CLAUDE.md` | M |

### LOW
| # | Finding | Where | Fix |
|---|---|---|---|
| L1 | `web-push` dependency unused | `package.json` | S |
| L2 | Deps all `^`-ranged incl. pre-1.0 Anthropic SDK | `package.json` | S |
| L3 | `postinstall` swallows PWA build failure | `package.json` | S |
| L4 | `.env.example` uses `VERIFY_TWILIO_SIGNATURE` vs code's `TWILIO_REQUIRE_SIGNATURE` | `.env.example` | S |
| L5 | Local backups unencrypted (24h window) | `backup.js` | S |
| L6 | History `slice(-24)` vs documented 40 turns | `memory.js:468` | S |
| L7 | DST edge in `nineAmLocalInDays()` offset math | `tasks.js:65-77` | L |
| L8 | `ISABEL_FILOSOFIA` content identical app↔server but verify on every edit (only the `export` differs) | `agents.js:38`, `todoisabel.html:1179` | S |

---

## 8. Prioritized task list

**P0 — do first (data integrity, security, broken core feature)**
1. **C3 Rotate the LUNA key** (Railway `LUNA_API_KEY` → new 64-hex → Bluehost `luna_config.php` → verify `luna_diag.php`). **S**
2. **C1 Atomic + serialized JSON writes** — temp-file + `fs.rename`, per-file write mutex; apply to `tasks/commitments/entities/skills/memory`. **M**
3. **H3 Fail-hard on missing `APP_SECRET`** (throw at startup instead of dev fallback). **S**
4. **C2 Resolve `gaps_overview`** — either add a real `gaps_overview` tool wrapping `gaps.js:computeGaps()` or remove it from the briefing prompt + `buildGapsSummary` text. **M**

**P1 — silent-failure hardening**
5. **H1** Make `unwrapArrayResponse` distinguish "unrecognized shape" (warn/return error) from "empty result". **M**
6. **H2** `hooks.js` SOA check: if local CRM is empty, route the lookup through LUNA (or skip with a logged warning) so the compliance gate isn't a no-op. **M**
7. **M5** Wrap cron callbacks in `async`+try/catch and alert on dynamic-import failure. **S**
8. **Corrupt-read alerting** (data §): replicate `readJsonSafe`, log on parse failure. **S**

**P2 — endpoint & auth hardening**
9. **M1** Enforce Twilio signature on `/voice/*` (or document the exemption). **S**
10. **M3** Rate-limit `/api/login`; **M4** add stream timeout to `/api/transcribe`. **S**
11. **H4 / M7** Single-header LUNA auth + generic auth error text + route LUNA bodies through `security.js redactPII`. **S**
12. **M2** Move rate-limit/idempotency to a durable store (or document the restart reset). **M**

**P3 — structure & hygiene**
13. **M6** Add jitter / sequence guards to the duplicate cron slots. **S**
14. **M8 / M9** Remove dead `pilar` branch; add an ESLint/import guard so only `voice.js` may import `luna_client` directly. **S–M**
15. **M10** Refresh CLAUDE.md (coaches, crons, `api.js`/dashboard) — it's the onboarding source of truth. **M**
16. **L1–L6** Drop `web-push`, pin deps, fail `postinstall` loudly, fix `.env.example`, encrypt local backups, reconcile history-length. **S each**
17. **Refactor `tools.js`** dispatch into per-domain modules; split scheduling out of `index.js`. **L** (do last, behind tests)

---

### Caveats
Findings come from static review; line numbers were spot-checked for all CRITICAL
and the verifiable HIGH items (C1, C2, C3, H2, H3 confirmed against source). Some
MEDIUM/LOW locations are as-reported and worth a 2-minute confirmation before the
fix. No code was changed by this audit.
</content>
