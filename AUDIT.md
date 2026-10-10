# AUDIT — Sistema Maestro de Isabel Fuentes

**Date:** 2026-06-12 (round 1) · **Round 2:** 2026-10-10 — see §9 · **Auditor:** Claude · **Scope:** full repo
`isabelinsurance-design/Code-` (marketing component for LUNA).

> **Status at round 2:** every HIGH and MEDIUM finding is fixed and covered by the
> test-suite; only L-2 (storage versioning) is still open. §1–§8 keep the original
> round-1 text; each finding carries a *Status* line, and §9 lists what changed.

This is a **client-side single-page marketing app** (no backend in this repo),
plus a reference Python Telegram bot and handoff docs. The audit reflects that
reality: most "real" risk is XSS-in-browser and the trust model of a
bring-your-own-key client app, not server CVEs.

---

## 1. Architecture map

```
index.html ............ SOURCE OF TRUTH. Single-page app (~5,300 lines, ~320 KB).
  ├─ <style> ........... all CSS, design tokens as :root vars
  ├─ <body> ............ topbar + collapsible sidebar + <main> with N modules
  └─ <script> .......... ONE inline IIFE-free script (~69KB, 72 functions)
        state: apiKey, leads[], calendarItems{}, memoria{4 layers}
        Anthropic calls: ONE core — aiRequest → aiStreamOnce (SSE, tiers, fallbacks);
                         callClaude / callClaudeRaw are thin wrappers
        Athena patterns: capture-by-default, COACHES roster, orchestrator,
                         Radar (web_search + COS lens), health/gaps, compliance,
                         audit log
tools/ (20 files) ...... full standalone dashboards, each with an injected
                         "ISABEL UNIFIED" fetch interceptor (shared key + auth); the
                         interceptor's source of truth is tools-interceptor.js (inject.py syncs it)
isabel-sistema-completo-UNICO.html .. GENERATED build (~1.8MB): index.html with
                         openTool() swapped to blob-URLs + all 20 tools embedded
                         as TOOL_DATA. This is what deploys to LUNA.
bot/ ................... reference Python Telegram bot (NOT deployed; Bluehost
                         can't run it). README, pinned requirements, .env.example,
                         offline tests (test_bot.py), isabel_system.txt (generated).
tests/ ................. Playwright browser tests + a fake Anthropic server (node tests/run.cjs)
docs ................... CLAUDE.md, MERGE-TO-LUNA.md, PARA-LUNA-TEAM.md,
                         PHASE-1-QUICKSTART.md, VERIFY-MERGE.md
serve.sh ............... local `python3 -m http.server` helper
```

**Data flow:** browser ⇄ Anthropic API directly (`x-api-key` from a
user-pasted key in localStorage). Shell broadcasts the key to tool iframes via
`postMessage`. No server, no DB in this repo — persistence is `localStorage`.
LUNA (Bluehost, separate repo) is the production host; MySQL/PHP/cron live
there per the blueprints in `PARA-LUNA-TEAM.md` (Phases 2-4, not yet built).

---

## 2. Dependencies

| Surface | Dependency | Notes |
|---|---|---|
| Browser app | **none** (vanilla JS/CSS/HTML) | No framework, no build chain, no npm. Good for longevity. |
| Browser app | Google Fonts (CDN) | External `<link>`; fails closed (system fonts) if offline. |
| Browser app | Anthropic API | tiered models `claude-haiku-5-5` / `claude-sonnet-5-5` / `claude-opus-5-5` with a fallback chain, `web_search_20250305` tool (was `claude-sonnet-4-20250514`, **retired 2026-06-15**) |
| Bot | `python-telegram-bot==22.8`, `anthropic==1.13.0` | Pinned (round 2, M-3) |

No lockfile, no SBOM, no dependabot. Acceptable for the browser app (zero deps);
the bot is now pinned to exact versions.

---

## 3. API endpoints

This repo exposes **no endpoints** (no server). It *consumes* one:
`POST https://api.anthropic.com/v1/messages`, called from one core
(`aiRequest`, wrapped by `callClaude` / `callClaudeRaw`) and from each of the 20
tools' injected interceptor. The PHP endpoints (`/api/mkt/*`) and crons exist
only as **blueprints** in `PARA-LUNA-TEAM.md` for LUNA to implement.

---

## 4. Database patterns

No database. Client state is `localStorage`, keys (all `isabel_*`):

| Key | Holds |
|---|---|
| `isabel_anthropic_key` | the API key (plaintext) |
| `isabel_crm_leads` | CRM leads array |
| `isabel_plan_progress` | plan checkbox state |
| `isabel_memoria_{hechos,personas,tareas,compromisos}` | layered memory |
| `isabel_intel_runs` | Radar history + structured snapshots |
| `isabel_audit_log` | last 500 usage events |
| `isabel_tools_section_open` | sidebar UI pref |

No schema validation on read (`JSON.parse` then trusted). `importDataFromFile`
writes arbitrary `isabel_*` keys from an uploaded file (see H-2).

---

## 5. Deployment config

- **Production:** single `isabel-sistema-completo-UNICO.html` uploaded to LUNA
  on Bluehost as a static file + one nav link. Verified **UNICO is currently in
  sync** with `index.html` (rebuilt and byte-compared during this audit).
- **No CI/CD** (no GitHub Action yet); tests are run by hand: `python3 build.py && node tests/run.cjs`
  (≈315 browser checks against both builds) and `python bot/test_bot.py`.
- **`build.py` is committed** (task 7) and also syncs the tool interceptor and the bot prompt.

---

## 6. Findings by severity

### 🔴 HIGH

**H-1 — Stored XSS in CRM leads and calendar items.**
`renderLeads()` (index.html ~2820) and `renderCalendar()` (~2687) interpolate
`l.name`, `l.phone`, `l.zone`, `l.notes`, `item.text` straight into
`innerHTML` with **no escaping**. A lead name like
`<img src=x onerror=alert(document.cookie)>` executes. Same code path renders
in the Memoria tab via `escapeHtml` (good) but CRM + calendar were never
migrated. Because the API key lives in `localStorage`, an XSS here can exfiltrate
it. Real trigger: Isabel pastes a lead name/notes copied from a Facebook comment
containing markup.
*Fix:* wrap every interpolated field in the existing `escapeHtml()`.
*Status (2026-10-10): ✅ fixed in round 1; `tests/security.cjs` injects `<img onerror>` payloads into leads, calendar and memory and checks nothing fires.*

**H-2 — `importDataFromFile` trusts arbitrary file contents.**
Restore reads a user-chosen JSON and writes every `isabel_*` key verbatim
(only the API key is skipped). A malicious/edited backup can inject scripted
strings that later render through the unescaped sinks in H-1, or poison
`isabel_intel_runs`/memoria. Low likelihood (user must open a hostile file) but
combines with H-1 into account-key theft.
*Fix:* validate shape per-key, escape on render (H-1 fix covers most), and
confirm the file looks like a known backup (`version`, `exportedAt`).
*Status (2026-10-10): ✅ fixed in round 1 (`BACKUP_SCHEMA` per-key shape checks; unknown keys dropped, API key can't be overwritten). Round 2 added the new settings/AEP/T65 keys to the schema.*

### 🟠 MEDIUM

**M-1 — AI / web-search output rendered as HTML.**
`setOutput()` injects model text via `innerHTML`. Today prompts request plain
text/markdown, but `callClaudeWebSearch` returns content derived from **live web
pages**; a crafted page could surface `<script>`/`<img onerror>` into the Radar
output. Not classic XSS (no eval) but `<img onerror>` fires.
*Fix:* render AI text as `textContent`, or sanitize, or a tiny markdown renderer
that escapes HTML.
*Status (2026-10-10): ✅ fixed in round 2 — `mdToHtml()` escapes ALL HTML before adding formatting, and web-search sources only render `http(s)` links.*

**M-2 — `postMessage` uses `'*'` target and unauthenticated receipt.**
Shell broadcasts the API key to iframes with `targetWindow.postMessage(msg,'*')`
and tools accept `ISABEL_API_KEY` from any origin. In the bundled blob/iframe
model this is contained, but if a tool ever loads third-party content, the key
could leak to/from another frame.
*Fix:* pass an explicit target origin and check `event.origin` on receipt.
*Status (2026-10-10): ✅ fixed in round 2 — the shell posts to its own origin and only answers key requests from its own tool frames; the interceptor checks `event.origin`.*

**M-3 — Bot deps unpinned, no chat allowlist.**
`requirements.txt` uses `>=` (non-reproducible builds). `bot/bot.py` answers
**any** Telegram user who finds the bot — no `ISABEL_CHAT_ID` allowlist — so
anyone can spend Isabel's Anthropic budget. It's reference code, but the
`MERGE` runbook tells Sammy to deploy from it.
*Fix:* pin versions; add an allowed-chat-id check before calling Claude.
*Status (2026-10-10): ✅ fixed in round 2 — pinned versions + `ALLOWED_CHAT_IDS` (the bot ignores everyone else; `/start` only shows a stranger their chat id). Covered by `bot/test_bot.py`.*

**M-4 — API key stored in plaintext localStorage, no scoping guidance.**
Standard for BYO-key client apps, but there's no note telling Isabel to use a
key with a spend cap. Combined with H-1, a key with no cap is a real money risk.
*Fix:* document "set a monthly limit in console.anthropic.com"; surface it in
the key input helptext.
*Status (2026-10-10): ✅ fixed in round 2 — tip in ⚙️ Ajustes, plus a monthly call/token/spend estimate there.*

### 🟡 LOW

**L-1 — Calendar is not persisted.** `calendarItems` is in-memory only; edits
vanish on reload (every other store persists). Inconsistent + data loss.
*Fix:* add `isabel_calendar` localStorage key, save in `addCalItem`.
*Status (2026-10-10): ✅ fixed in round 1.*

**L-2 — No schema/version guard on `localStorage` reads.** A future shape change
silently breaks older saved data. *Fix:* version the stores, migrate on load.
*Status (2026-10-10): ☐ still open (task 10).*

**L-3 — Model ID hardcoded in 8+ places** (`claude-sonnet-4-20250514` in shell +
each tool). Model upgrades require a find/replace across files. *Fix:* single
`const MODEL` in the shell; tools read from the interceptor.
*Status (2026-10-10): ✅ fixed in round 2 — `MODEL_PRESETS` + `modelChain(tier)` in the shell; the chosen chat model is broadcast to the tools, whose interceptor rewrites stale IDs. A model that answers "not found" is skipped automatically.*

**L-4 — No `rel="noopener"` audit / external links.** Minor; the "abrir en
pestaña nueva" link already has it, but tool HTML wasn't audited.

**L-5 — `bot/__pycache__` was committed once** (now gitignored). Confirm it's
gone from history if the repo is ever made public.

### ℹ️ INFO / good practices observed

- ✅ No fake/placeholder metrics (explicitly removed; honest `0`/`—`).
- ✅ Memoria tab correctly uses `escapeHtml` — the pattern exists, just not
  applied everywhere (H-1).
- ✅ CMS compliance gating on every AI output (regex red-flags).
- ✅ Backup export **excludes** the API key — thoughtful.
- ✅ UNICO build verified in sync with source at audit time.
- ✅ Capture-by-default JSON parsing is wrapped in try/catch with regex extract.

---

## 7. Prioritized task list (with effort)

| # | Task | Severity | Effort | Status |
|---|---|---|---|---|
| 1 | Escape `escapeHtml()` on all CRM + calendar render fields | 🔴 H-1 | **S** (~30 min) | ✅ **DONE** |
| 2 | Render AI/web-search output as text or sanitized markdown | 🟠 M-1 | **M** (~2 h) | ✅ **DONE** (round 2) |
| 3 | Validate + shape-check `importDataFromFile` | 🔴 H-2 | **S** (~45 min) | ✅ **DONE** |
| 4 | Pin bot deps + add `ISABEL_CHAT_ID` allowlist | 🟠 M-3 | **S** (~30 min) | ✅ **DONE** (round 2) |
| 5 | `postMessage` explicit origin + `event.origin` check | 🟠 M-2 | **M** (~1.5 h) | ✅ **DONE** (round 2) |
| 6 | Persist calendar to localStorage | 🟡 L-1 | **S** (~20 min) | ✅ **DONE** |
| 7 | Commit the UNICO build script (`build.py`) to the repo | (maintainability) | **S** (~30 min) | ✅ **DONE** |
| 8 | Add a tiny smoke test + (optional) GitHub Action | (maintainability) | **M** (~2-3 h) | ✅ **DONE** (tests, round 2) · GitHub Action not added |
| 9 | Single `const MODEL` + key-cap helptext | 🟡 L-3/M-4 | **S** (~30 min) | ✅ **DONE** (round 2) |
| 10 | Version + migrate localStorage stores | 🟡 L-2 | **M** (~2 h) | ☐ |

**Effort key:** S ≤ 1h · M = 1-3h · L > 3h.

### First-pass status — DONE (commit 2026-06-12)
Tasks **1, 3, 6, 7** are complete. 28/28 verification tests pass on both
`index.html` and `isabel-sistema-completo-UNICO.html`, including direct XSS
payload attempts (`<img onerror=…>` injected via lead name/notes and calendar
text — neither fires), malicious-backup restore (unknown keys dropped,
wrong-type values rejected, API key cannot be overwritten, extra fields
stripped from items), calendar persistence across reload, and the bug build.py
exposed: the previous UNICO was missing the `logEvent('tool', file)` audit
line, which is now baked into the canonical build.

### Remaining
- **Task 10** (M) — localStorage schema versioning + migrations (L-2).
- **GitHub Action** (S) — run `python3 build.py && node tests/run.cjs` and `python bot/test_bot.py` on every push, and fail if `isabel-sistema-completo-UNICO.html` is out of date.

---

## 8. Biggest non-security risk (updated)

Round 1 flagged the missing build script; that is fixed (`build.py`, committed).
The biggest risk now is **silent model retirement**. `claude-sonnet-4-20250514`
was retired on 2026-06-15 and every AI button stopped working for ~4 months
without anyone noticing, because the old code swallowed API errors. Mitigations
now in place:

- model fallback chains per tier (a model that answers "not found" is skipped);
- plain-Spanish error messages that name the real cause (key, balance, model, rate limit);
- ⚙️ Ajustes → "Probar mi conexión" tests the key and the models in one click;
- the bot reads `ANTHROPIC_MODEL` from the environment and explains a missing model;
- `CLAUDE.md` links the official deprecation page and lists the current IDs.

Still worth doing: put the deprecation page on a calendar reminder (next known date:
`claude-haiku-4-5-20251001` retires no sooner than **2026-10-15**; the app already
prefers `claude-haiku-5-5`).

---

## 9. Round 2 (2026-10-10)

**Why:** the app had not been touched for four months; the model it called was
retired, and AEP opens 2026-10-15.

**Fixed / added**
- New AI core (one place for headers, streaming, tiers, effort, token limits that leave room for thinking, fallbacks, usage counters, web search with sources and `pause_turn`).
- Context on every call: today's date, AEP phase, today's calendar, optional Memoria (never CRM phone numbers).
- Compliance content corrected to CMS plan-year 2027 (no 48-hour SOA wait; TPMO disclaimer before benefits; no SHIP line) in the system prompt, the regex gate (`cmsRegexIssues`), the AEP calendar, `tools/t65-lead-machine.html`, the bot and the designer Excel.
- Two broken tools repaired (Centro de Comando: duplicate `let`; Sistema Maestro v2: progress label removed by an `innerHTML` rewrite). Interceptor v2 in all 20 tools.
- New features: AEP "Hoy" card (creates today's piece with AI), Revisor de Piezas (AI vision pre-check of designer pieces and competitor ads), voice dictation, ⚙️ Ajustes (quality level, memory switch, TPMO numbers, voice language, usage estimate), weekly calendar CSV export.
- Telegram bot rewritten (async, current model + effort, allowlist, pinned deps, message splitting, friendly errors, same system prompt as the app via `build.py`).
- LUNA handoff doc: PHP samples now use current models, skip `thinking` blocks, continue `pause_turn`, send Telegram by POST in plain text.
- A screenshot review of the new screens found radio buttons and checkboxes stretched to full width in ⚙️ Ajustes and the Revisor (the global `input{width:100%}` rule); fixed, with a layout regression test in `tests/features.cjs`.
- Tests: `tests/` (aep, ai, security, smoke, features — ≈315 checks, run against `index.html` and the UNICO build) and `bot/test_bot.py` (12 checks).

**Verification:** all of the above run green on both builds; the PHP helper
functions were exercised against a fake API server (thinking skipped, `pause_turn`
continued, sources collected, Telegram text split without cutting characters).

**Not verified (needs real credentials):** a live Anthropic call and a live Telegram
session. Isabel can check the first with ⚙️ Ajustes → "Probar mi conexión".
