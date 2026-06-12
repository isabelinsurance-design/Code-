# CLAUDE.md — Sistema Maestro de Isabel Fuentes

Project memory for future sessions. Read this first; you usually won't need to read every file.

## What this is

A Spanish-language, single-page **Medicare marketing system** for **Isabel Fuentes**, a
bilingual Medicare insurance agent in **Southern California** (Los Angeles, Orange County,
Inland Empire) serving the **Hispanic 60+ market**.

**The real goal (why this exists):** generate **leads** cheaply, convert them into enrolled
**members**, and make Isabel *the* recognized name for Medicare in the Latino community.
Everything in the system serves that funnel: Strangers → Followers → Leads → Members.

Isabel is **non-technical**. Keep explanations simple, in plain language, and prefer giving
her finished files over technical steps.

## Files

| File | Purpose |
|------|---------|
| `index.html` | **The shell / source of truth.** Single-page app. Edit THIS. Loads tools from `tools/` via iframe `src`. |
| `tools/` (19 files) | Full standalone tool dashboards. Each has an **injected shared-key fetch interceptor** (search `ISABEL UNIFIED`). |
| `isabel-sistema-completo-UNICO.html` | **GENERATED build** — all 19 tools embedded as blob URLs so Isabel can open ONE file in Chrome with no `tools/` folder. **Do not hand-edit.** This is the file she actually uses. |
| `bot/` | Telegram bot (Python). Same `ISABEL_SYSTEM` prompt as the web app. Deployable to Railway/Replit/Render. See `bot/README.md`. |
| `serve.sh` | Local web server helper (`python3 -m http.server`). |

## Build step — IMPORTANT

After **any** change to `index.html` or `tools/`, regenerate the single-file
build by running:

```
python3 build.py
```

`build.py` (committed in the repo root) reads `index.html` + every file in
`tools/`, replaces the iframe `openTool()` function with a blob-URL version,
embeds the tools as `const TOOL_DATA = {...}` (escaping `</` → `<\/` and
`<!--` → `<\!--`), and writes `isabel-sistema-completo-UNICO.html`. **Do not
edit the UNICO file by hand** — any change made directly there will be
overwritten on the next build.

Then **verify in a real browser** with Playwright (installed globally at
`/opt/node22/lib/node_modules/playwright`) + `python3 -m http.server`. Always
send the rebuilt UNICO file to Isabel after changes.

## Architecture

- **Shell** = the "Maestro": top sidebar section `EMPEZAR AQUÍ` (Plan de Acción [default],
  Identidad de Marca, Plantillas de Posts), built-in quick modules (Dashboard, Cerebro IA,
  Meta Ads, Viral, FB Live, Calendario, Intel, Compliance, CRM, Métricas), and
  `HERRAMIENTAS COMPLETAS` (the 18 full tools opened inside an `#mod-tool` iframe).
- **AI calls** hit `https://api.anthropic.com/v1/messages`, model `claude-sonnet-4-20250514`.
  Required headers: `x-api-key`, `anthropic-version: 2023-06-01`,
  `anthropic-dangerous-direct-browser-access: true`.
- **Shared API key:** entered once (top-right), saved to localStorage, broadcast to tool
  iframes via `postMessage`. Tools read it via their injected interceptor (which also adds
  the auth headers — the originals shipped with NO auth headers and didn't work in a browser).

### localStorage keys
- `isabel_anthropic_key` — the Anthropic API key (shared across shell + all tools)
- `isabel_crm_leads` — CRM leads (JSON array)
- `isabel_plan_progress` — Plan de Acción checkbox state
- `isabel_memoria_hechos` / `_personas` / `_tareas` / `_compromisos` — layered memory (Athena-style)
- `isabel_intel_runs` — Radar run history; each entry includes the structured `snapshot` data used at run time so the next run can self-grade against it
- `isabel_audit_log` — last 500 user-action events `{ts,event,details}` (debounced 30s per event key) used by `getUsageStats(daysBack)` to feed the Radar's "uso del sistema" snapshot
- `isabel_calendar` — weekly calendar items (sanitized on load)
- `isabel_t65_leads` / `isabel_t65_spend` — T65 DIY lead tracker (written by `tools/t65-lead-machine.html`, which runs same-origin so it shares the shell's localStorage; included in backup via `BACKUP_SCHEMA`)

### Athena patterns adopted
The `🧠 Memoria` tab uses **layered memory** (separate stores for facts about
Isabel, people she mentions, tasks she owns, and commitments others made to her).
**Capture-by-default**: `askCerebro` fires `captureFromMessage()` in parallel with
the main answer — a second Claude call that extracts structured items from
Isabel's message and auto-saves them to the right layer. The Equipo IA agents
each have their own voice block (signature phrases + forbidden words). Every AI
response must close with one concrete action prefixed with `✅ Tu próxima acción:`
(baked into `ISABEL_SYSTEM`).

**Trust score + gaps** live on top of Plan de Acción: `computeHealth()` returns
a 0-100 number from plan progress, lead count + recency, memory depth, and
pending tareas/compromisos; `computeGaps()` returns up to 6 prioritized
attention items. Both re-render on every state-changing call
(`updatePlanProgress`, `updateCRMStats`, `addMemoria`/`removeMemoria`/
`toggleMemDone`, `checkApiKey`). **Compliance gating**: `callClaude` runs
`checkCompliance()` on every AI response against `CMS_FLAGS` regexes (no
absolute superlatives, no guarantees, no negative carrier comparisons, etc.)
and inserts a `.cms-banner` next to the output if anything matches.

**Radar** (`🔭 Radar`): `runIntel()` calls Anthropic with the
`web_search_20250305` tool enabled (`max_uses: 6`). The prompt puts the
**Chief of Staff section FIRST** with heavy weight, market intel after as
supporting context.

The COS section is structured: resumen ejecutivo → **self-grade vs last
week** (comparing against `prev.snapshot`) → ≥5 things working → ≥5 things
not working → **brechas en uso del sistema** (which tabs / tools Isabel hasn't
touched, where workflows are slow) → 5 operational changes for this week →
**1 suggested change to the system itself** → ✅ next action.

State plumbing: `buildSnapshotData()` returns a structured object with plan %,
leads + recency, memoria layers, gaps, AI calls by feature, unused tabs from
the last 14 days, and standalone tools used this week. It feeds both
`buildSelfStateSnapshot()` (the prompt string) and `saveIntelRun(text,
snapshotData)` (so each saved run carries a structured snapshot the next run
self-grades against). The audit data comes from `logEvent()` calls wired into
`showModule`, `openTool`, `callClaude`, `addLead`, `addMemoria`. PHP cron
version (Mon 6am, same structure) is in `PARA-LUNA-TEAM.md`.

**Multi-coach orchestrator** (`🧭 Pregunta Inteligente`, Athena Section 5): a
shared `COACHES` roster (id, icon, name, desc, voice) is the canonical source
of coach personalities; both `runEquipoIA` and `runOrchestrator` use it.
`runOrchestrator()` does three Claude calls: a routing call (decides 1-3
coaches), parallel calls to the chosen coaches via `Promise.all`, and a
synthesizer call that integrates the voices into one answer with the
mandatory "✅ Tu próxima acción" closing. Individual coach voices are kept
visible under a `<details>` block. Still not built (need server-side, see
`PARA-LUNA-TEAM.md` for PHP blueprint): briefings cron, drafts queue, signals
nightly job, WhatsApp.

## Hard rules / conventions

- **NEVER show fake/placeholder data.** The original design shipped invented metrics
  (247 leads, 89 enrollments, $4.20 CPL, etc.); these were removed and must not return.
  Show real values or `0`/`—`. The dashboard lead count comes from the real CRM.
- **Spanish UI**, warm and clear tone. Avoid jargon and fine print.
- **No documentation files** unless asked. **No emojis in code** unless they're part of the UI copy.

## Brand identity

- Name: **Medicare with Isabel**. Symbol: **butterfly 🦋** (transformation, care, peace of mind).
- Agent: Isabel Fuentes · Insurance Agent · **CA Lic #0D96598** · **+1 (310) 270-0626** ·
  withisabelfuentes.com
- Palette: Azul Mariposa `#3D8FD6` · Navy Confianza `#333A4D` · Azul Cielo `#A9D4F0` ·
  Fondo Suave `#EAF4FB` · Gris Texto `#5C6270` · Durazno Cálido `#F2A977` (CTAs only).
- Fonts (Canva): Great Vibes (script accent), Poppins (headings), Open Sans (body).
- Positioning: *"La agente que te explica Medicare claro, en tu idioma — sin letra chiquita."*

## Deployment target

This system is **a component for LUNA on Bluehost** (Isabel's larger marketing
platform), NOT a standalone Railway service. Athena (her personal Chief of
Staff) is the only thing on Railway; LUNA stays on Bluehost.

The HTML/JS files deploy as static files on Bluehost. The `bot/` Python code
does NOT fit Bluehost (shared PHP hosting can't run long-polling Python). The
bot is reference code for one of three paths: (a) absorb into Athena on
Railway, (b) re-implement as PHP webhook on Bluehost, (c) deploy separately on
Railway/Replit. See `PARA-LUNA-TEAM.md` for handoff details.

## Two places, two jobs (don't confuse them)

This repo (`isabelinsurance-design/Code-`) and the LUNA deployment are **both
kept** — they do different jobs:

| | **This repo (GitHub)** | **LUNA (Bluehost)** |
|---|---|---|
| Role | Development workshop / source of truth | Production deployment |
| What lives here | `index.html` (editable), `tools/`, all docs, full git history | One built `isabel-sistema-completo-UNICO.html` + nav link |
| Who touches it | Devs (or AI agents) making changes | Isabel using it day to day |
| Edit cycle | Edit `index.html` here → rebuild UNICO → push commit → upload to LUNA | Receive the built UNICO, replace the old file |

**Rule:** all edits go in this repo first. After editing, regenerate the
single-file UNICO (see "Build step" above) and Sammy uploads the new UNICO
to LUNA. Never edit the file directly on LUNA — those changes are lost the
next time we deploy.

This repo is also Isabel's **backup**: if LUNA's server has a problem, every
version of the system since day one is in git history here.

The only future scenario where this repo could be archived is if LUNA's own
repo eventually absorbs `index.html` + `tools/` as a sub-folder (e.g.,
`luna-repo/agents/marketing/`) and the build pipeline runs there. Until that
day: keep both.

## Merge runbook

The step-by-step runbook for absorbing this system into LUNA as an "agent"
lives in `MERGE-TO-LUNA.md`. Four phases: (1) drop-in static, (2) MySQL data
sync, (3) PHP cron briefings + weekly Radar, (4) register as agent in LUNA's
orchestrator. The PHP skeletons referenced by phases 2-3 are in
`PARA-LUNA-TEAM.md`.

## Git

- Work on branch `main`. Commit with clear messages and push after completing changes.
