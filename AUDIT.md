# AUDIT — Sistema Maestro de Isabel Fuentes

**Date:** 2026-06-12 · **Auditor:** Claude (Fable 5) · **Scope:** full repo
`isabelinsurance-design/Code-` (marketing component for LUNA).

This is a **client-side single-page marketing app** (no backend in this repo),
plus a reference Python Telegram bot and handoff docs. The audit reflects that
reality: most "real" risk is XSS-in-browser and the trust model of a
bring-your-own-key client app, not server CVEs.

---

## 1. Architecture map

```
index.html ............ SOURCE OF TRUTH. Single-page app (~3,730 lines).
  ├─ <style> ........... all CSS, design tokens as :root vars
  ├─ <body> ............ topbar + collapsible sidebar + <main> with N modules
  └─ <script> .......... ONE inline IIFE-free script (~69KB, 72 functions)
        state: apiKey, leads[], calendarItems{}, memoria{4 layers}
        Anthropic calls: callClaude / callClaudeRaw / callClaudeWebSearch
        Athena patterns: capture-by-default, COACHES roster, orchestrator,
                         Radar (web_search + COS lens), health/gaps, compliance,
                         audit log
tools/ (18 files) ...... full standalone dashboards, each with an injected
                         "ISABEL UNIFIED" fetch interceptor (shared key + auth)
isabel-sistema-completo-UNICO.html .. GENERATED build (1.4MB): index.html with
                         openTool() swapped to blob-URLs + all 18 tools embedded
                         as TOOL_DATA. This is what deploys to LUNA.
bot/ ................... reference Python Telegram bot (NOT deployed; Bluehost
                         can't run it). README, requirements, .env.example.
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
| Browser app | Anthropic API | `claude-sonnet-4-20250514`, `web_search_20250305` tool |
| Bot | `python-telegram-bot>=21.0`, `anthropic>=0.34.0` | **Unpinned** (`>=`), see M-3 |

No lockfile, no SBOM, no dependabot. Acceptable for the browser app (zero deps);
weak for the bot.

---

## 3. API endpoints

This repo exposes **no endpoints** (no server). It *consumes* one:
`POST https://api.anthropic.com/v1/messages`, called from three wrappers
(`callClaude`, `callClaudeRaw`, `callClaudeWebSearch`) and from each of the 18
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
- **No CI/CD**, no automated tests, no linter in the repo.
- **The build script is NOT committed** (see H-3) — it lives only in the Claude
  Code workflow. This is the single biggest maintainability risk.

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

**H-2 — `importDataFromFile` trusts arbitrary file contents.**
Restore reads a user-chosen JSON and writes every `isabel_*` key verbatim
(only the API key is skipped). A malicious/edited backup can inject scripted
strings that later render through the unescaped sinks in H-1, or poison
`isabel_intel_runs`/memoria. Low likelihood (user must open a hostile file) but
combines with H-1 into account-key theft.
*Fix:* validate shape per-key, escape on render (H-1 fix covers most), and
confirm the file looks like a known backup (`version`, `exportedAt`).

### 🟠 MEDIUM

**M-1 — AI / web-search output rendered as HTML.**
`setOutput()` injects model text via `innerHTML`. Today prompts request plain
text/markdown, but `callClaudeWebSearch` returns content derived from **live web
pages**; a crafted page could surface `<script>`/`<img onerror>` into the Radar
output. Not classic XSS (no eval) but `<img onerror>` fires.
*Fix:* render AI text as `textContent`, or sanitize, or a tiny markdown renderer
that escapes HTML.

**M-2 — `postMessage` uses `'*'` target and unauthenticated receipt.**
Shell broadcasts the API key to iframes with `targetWindow.postMessage(msg,'*')`
and tools accept `ISABEL_API_KEY` from any origin. In the bundled blob/iframe
model this is contained, but if a tool ever loads third-party content, the key
could leak to/from another frame.
*Fix:* pass an explicit target origin and check `event.origin` on receipt.

**M-3 — Bot deps unpinned, no chat allowlist.**
`requirements.txt` uses `>=` (non-reproducible builds). `bot/bot.py` answers
**any** Telegram user who finds the bot — no `ISABEL_CHAT_ID` allowlist — so
anyone can spend Isabel's Anthropic budget. It's reference code, but the
`MERGE` runbook tells Sammy to deploy from it.
*Fix:* pin versions; add an allowed-chat-id check before calling Claude.

**M-4 — API key stored in plaintext localStorage, no scoping guidance.**
Standard for BYO-key client apps, but there's no note telling Isabel to use a
key with a spend cap. Combined with H-1, a key with no cap is a real money risk.
*Fix:* document "set a monthly limit in console.anthropic.com"; surface it in
the key input helptext.

### 🟡 LOW

**L-1 — Calendar is not persisted.** `calendarItems` is in-memory only; edits
vanish on reload (every other store persists). Inconsistent + data loss.
*Fix:* add `isabel_calendar` localStorage key, save in `addCalItem`.

**L-2 — No schema/version guard on `localStorage` reads.** A future shape change
silently breaks older saved data. *Fix:* version the stores, migrate on load.

**L-3 — Model ID hardcoded in 8+ places** (`claude-sonnet-4-20250514` in shell +
each tool). Model upgrades require a find/replace across files. *Fix:* single
`const MODEL` in the shell; tools read from the interceptor.

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
| 2 | Render AI/web-search output as text or sanitized markdown | 🟠 M-1 | **M** (~2 h) | ☐ |
| 3 | Validate + shape-check `importDataFromFile` | 🔴 H-2 | **S** (~45 min) | ✅ **DONE** |
| 4 | Pin bot deps + add `ISABEL_CHAT_ID` allowlist | 🟠 M-3 | **S** (~30 min) | ☐ |
| 5 | `postMessage` explicit origin + `event.origin` check | 🟠 M-2 | **M** (~1.5 h) | ☐ |
| 6 | Persist calendar to localStorage | 🟡 L-1 | **S** (~20 min) | ✅ **DONE** |
| 7 | Commit the UNICO build script (`build.py`) to the repo | (maintainability) | **S** (~30 min) | ✅ **DONE** |
| 8 | Add a tiny smoke test + (optional) GitHub Action | (maintainability) | **M** (~2-3 h) | ☐ |
| 9 | Single `const MODEL` + key-cap helptext | 🟡 L-3/M-4 | **S** (~30 min) | ☐ |
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

### Remaining (recommended order for a second pass)
- **Task 4** (S) — bot allowlist + pinned deps. Important once Sammy actually
  deploys the bot.
- **Task 9** (S) — single `const MODEL` and a "set a spend cap" note next to
  the API-key input. Quick wins.
- **Task 2** (M) — sanitize AI/web-search HTML output.
- **Task 5** (M) — postMessage origin checks.
- **Task 10** (M) — localStorage schema versioning + migrations.
- **Task 8** (M) — port the audit test into a checked-in smoke test.

---

## 8. Biggest non-security risk: the build script

The thing that keeps the two-file model honest (`index.html` → `UNICO`) is a
~30-line Python script that is **not in the repo**. If this Claude Code workflow
goes away, no one can regenerate the deployable file from source without
reverse-engineering it from CLAUDE.md. **Commit it (Task 7) before anything
else** — it's a one-S-effort fix that de-risks the whole maintenance story.
