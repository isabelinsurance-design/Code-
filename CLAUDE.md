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
| `tools/` (20 files) | Full standalone tool dashboards. Each has an **injected shared-key fetch interceptor** (search `ISABEL UNIFIED`). |
| `isabel-sistema-completo-UNICO.html` | **GENERATED build** — all 20 tools embedded as blob URLs so Isabel can open ONE file in Chrome with no `tools/` folder. **Do not hand-edit.** This is the file she actually uses. |
| `bot/` | Telegram bot (Python, async). Same `ISABEL_SYSTEM` prompt as the web app (`bot/isabel_system.txt`, **generated** by `build.py`). Only answers chats in `ALLOWED_CHAT_IDS`. Offline tests: `python bot/test_bot.py`. Deployable to Railway/Replit/Render. See `bot/README.md`. |
| `tools-interceptor.js` | Source of truth for the script injected into every tool (shared key, headers, current model, strips thinking blocks). Edit this, never the tools. |
| `inject.py` | Syncs `tools-interceptor.js` into every `tools/*.html`. `build.py` runs it first. |
| `build.py` | Regenerates the UNICO file (and `bot/isabel_system.txt`). See "Build step". |
| `agent/` | The Marketing employee. `empleado.cjs` compiles the routine prompts (`compilar`) and has `hoy` / `revisar` helpers; `TRABAJO-DIARIO.md` / `RADAR-SEMANAL.md` are the job texts; `config.json` holds Isabel's Live time and TPMO numbers; `generado/` is the exact text installed in the routines. These are the agent's instructions, not docs. See "Marketing employee". |
| `ads/` | The Facebook/Instagram ad pack (AEP 2026). `anuncios.json` is the single source of truth (9 angles: image text, 2 headlines, description, texts A/B, dates); `render.cjs` draws the 18 PNGs (feed 1080x1350 + stories 1080x1920) with the brand fonts in `fuentes/`; `libro.py` builds the 9-sheet Excel. Outputs go to `ads/salida/` (git-ignored, regenerate). See "Facebook ads". |
| `tests/` | Browser tests (Playwright + a fake Anthropic server): `aep`, `ads`, `ai`, `security`, `smoke`, `features`, `agent`. `node tests/run.cjs` runs them all (≈400 checks). |
| `AUDIT.md` | Security/architecture audit with task status. |
| `serve.sh` | Local web server helper (`python3 -m http.server`). |

## Build step — IMPORTANT

After **any** change to `index.html` or `tools/`, regenerate the single-file
build by running:

```
python3 build.py
```

`build.py` (committed in the repo root) first syncs the interceptor into the tools (`inject.py`) and
writes `bot/isabel_system.txt` from the `ISABEL_SYSTEM` prompt in `index.html`, then reads `index.html` + every file in
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
  `HERRAMIENTAS COMPLETAS` (the 20 full tools opened inside an `#mod-tool` iframe).
- **AI calls** go through ONE core in `index.html` (`// ─── AI CORE ───`): `aiRequest()` →
  `aiStreamOnce()`. Required headers: `x-api-key`, `anthropic-version: 2023-06-01`,
  `anthropic-dangerous-direct-browser-access: true`. Answers stream (SSE) and render through
  `mdToHtml()` (escapes ALL html first — never use `innerHTML` with AI text directly).
- **Models are tiered, not hard-coded:** `MODEL_PRESETS` (Económico / Equilibrado / Máximo, picked in ⚙️ Ajustes)
  map the tiers `fast` (extract/route → haiku-5-5), `chat` (writing → sonnet-5-5) and `deep`
  (Radar, reviews → opus-5-5) to a fallback chain; a model that answers "not found" is skipped and
  the next one is used. `output_config.effort` is set per tier. `claude-sonnet-4-20250514` was
  RETIRED on 15 Jun 2026 (that silently broke every AI button for 4 months) and `claude-haiku-4-5-20251001`
  retires no sooner than 15 Oct 2026 — check https://platform.claude.com/docs/en/about-claude/model-deprecations
  before assuming an ID still works. Current IDs: claude-fable-5-1, claude-opus-5-5, claude-sonnet-5-5, claude-haiku-5-5.
- **Context on every call:** `composeSystem()` appends `buildContextBlock()` (today's date, AEP phase, today's
  calendar items, and — if enabled in settings — Memoria facts/people/tasks; never CRM phone numbers).
  Pass `noContext:true` for extraction/routing calls.
- **Web search** (`webSearchTool()`, `web_search_20250305`, Los Angeles location) streams the queries
  into the status line, handles `pause_turn`, shows sources under the answer (Anthropic requires
  citations to be shown) and falls back to no-search if the account has it disabled.
- **Thinking:** current models think by default and thinking counts toward `max_tokens`, so tiers use large
  limits. Never send `temperature`/`top_p`/`top_k` or an assistant prefill (400 errors).
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
- `isabel_settings` — AI quality preset, memory toggle, TPMO org/plan numbers (⚙️ Ajustes)
- `isabel_usage` — monthly call/token counters for the spend estimate; `isabel_chat_model` — the model the tools use
- `isabel_calendar` — weekly calendar items (sanitized on load)
- `isabel_aep_checks` / `isabel_aep_actuals` — Calendario AEP pre-AEP checklist and weekly real-application counts (semáforo)
- `isabel_aep_drafts` — pieces written by the AEP "Hoy" card (`aepGenerate`), keyed by calendar item, so they survive a reload
- `isabel_aep_maestro` — state of `tools/estrategia-aep-2026.html` (the team's Meta 300 strategy doc)
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

**Calendario AEP** (`📅 Calendario AEP`, first in EMPEZAR AQUÍ): built from the
team's "Estrategia Integral AEP 2026 — Meta 300" (`tools/estrategia-aep-2026.html`,
ported from branch `claude/aep-2026-estrategia-produccion-e8ulza`). `AEP_PERIODS`
holds the plan (pre-AEP Oct 1-14, Semanas 1-7, Cierre Dec 3-7, Post), each with
cumulative meta (39/week → 300), campaigns, Facebook items, team actions, and a
CMS note. `aepItemsFor(ymd)` derives each day's items; post-AEP uses fixed dated
items only (ads must stop after Dec 7). `downloadAepIcs()` exports an .ics.
Between Oct 1 and Dec 7 the app opens on this tab. Dates are hardcoded to 2026 —
update `AEP_PERIODS`/`AEP_MILESTONES` for AEP 2027.

**Release 2 features (Oct 2026):**
- **AEP "Hoy" card** (top of `📅 Calendario AEP`): `renderAepToday()` lists today's calendar items and
  `aepGenerate()` / `aepGenerateAd()` write the piece with AI (`buildAepPrompt`, per-kind specs in `AEP_SPECS`;
  every draft gets `aepDisclaimerBlock()` = TPMO text from `tpmoText()` + license line, and the CMS regex gate).
  Drafts are saved in `isabel_aep_drafts`. The Live time is a setting (`aepLiveHM`) because Isabel's Oct 15
  appointments (9am–2pm) can clash with a 12pm Live; the `.ics` export uses it too.
- **Revisor de Piezas** (`🛡️`, under INTELIGENCIA): she drops/pastes a designer piece or a competitor ad;
  `fileToImageBlock()` shrinks it to a 1568 px JPEG, `runRevisor()` sends it to a vision-capable model with
  `REV_SYSTEM`, and the answer starts with `VEREDICTO: VERDE|AMARILLO|ROJO` (publish / minor edits / don't
  publish) which is parsed into a colored badge.
- **Voice dictation:** 🎤 buttons (`toggleMic`, `initMics`) use the browser's SpeechRecognition; language is
  `voiceLang` in ⚙️ Ajustes (es-US default). The buttons are hidden where the browser lacks the API (use Chrome).

**Marketing employee (scheduled routines, Oct 2026 pilot):** two Claude Code routines (a fresh cloud session per run;
managed in the Claude app under Routines or with the `*_trigger` tools) do the work without Isabel opening the app.
They only **draft**: she approves and publishes. Nothing goes out, no lead is contacted, no connectors.
- **Borradores diarios de Marketing** (`trig_011D2QqKZsZJawpRbquWpnjs`): Mon–Sat, 6:16 am Pacific, Oct–Dec. Writes the day's
  Reel/Live/post drafts from the calendar with Isabel's voice and CMS rules, adds the TPMO and license disclaimers, lists
  the team's tasks and tomorrow's preview.
- **Radar semanal de Marketing** (`trig_01DeKgkogVtytuzQsJQwaA6S`): Mondays, 5:51 am Pacific, Oct–Dec. Market only (the app's
  Chief-of-Staff part needs browser data a routine cannot see). Web search.
- **A routine cannot open this repo.** Its session starts with no repository and no MCP tools (no `add_repo`; GitHub
  answers 403); the first test runs failed for exactly that reason. So each routine's prompt is **self-contained**: the job
  text (`agent/TRABAJO-DIARIO.md`, `agent/RADAR-SEMANAL.md`) plus, for the daily one, the voice and CMS rules, per-type
  specs, phases, the whole calendar (12 Oct–31 Dec), disclaimers and CMS alerts, all read from `index.html` by
  `node agent/empleado.cjs compilar` into `agent/generado/prompt-*.txt` (committed; `tests/agent.cjs` fails if stale).
- **To change what the employee does:** edit `agent/*.md`, `agent/config.json` (her Live time and TPMO numbers live in her
  browser, so until they are filled in the drafts show `[hora]` and `[número…]`) or `index.html` (calendar, voices, specs);
  run `node agent/empleado.cjs compilar`; paste the generated text into the routine with `update_trigger(prompt=…)`.
  `hoy` and `revisar` are developer helpers for spot-checks.
- **Notifications:** a run only notifies Isabel (push + email, "⚡ <routine> — routine completed" from
  `no-reply-claude@mail.anthropic.com`) when the job itself calls the `PushNotification` tool; the email text is that one line
  (≤200 chars) with "Open session" / "Manage routine" links. Both job texts therefore end with an explicit PushNotification
  step (the tool is deferred: `ToolSearch select:PushNotification`). The full drafts are the run's final message, in the
  session in the app. The email can arrive 1–2 minutes after the run.
- **Reading a run:** `get_session` shows only status and token counts, and `REVIEW_READY` does NOT mean the job worked (the
  failed first tests looked fine there). To see what a run did, have it send a diagnostic line through PushNotification and
  read the email; to test a drafting day without waiting, create a temporary routine whose prompt fixes HOY to a date
  (`fire_trigger`'s `text` is unreliable: the daily job ignored it) and delete it afterwards.
- Runs use Isabel's Claude plan usage and fail when the limit is reached (one of her older routines failed that way on 9 Oct 2026).
- Routine sessions must never commit or push (see Git below).

**Facebook ads (Oct 2026):** Claude can write, design and check the ads but CANNOT log in to Meta, publish or spend; a person
(Isabel, Sammy or her designer) uploads them (~10 min per campaign, sheet "Cómo subirlo"). Zapier's Facebook apps only post organic
content, receive leads and manage audiences, none creates paid ads. What the pack decided (and why):
- **Meta treats insurance ads as "Financial products and services" (special ad category, required for US advertisers since Jan 2025;
  read in third-party summaries, Meta's own page was blocked: confirm in Ads Manager).** Under it there is NO age, gender or ZIP
  targeting, no lookalikes and a 15-mile minimum radius, so the old "Spanish · LA/OC/IE · 64+" plan became: radius by city (LA,
  Santa Ana, San Bernardino/Riverside), Spanish language, and the Spanish Medicare creative does the targeting. Release 3
  (10 Oct) fixed the app to match: `AEP_SPECS.ad` (no 64+, no "qué tiene hoy"), the 📢 Meta Ads Studio (targeting, budgets and
  library; the old ones promised $2.50–5 CPL, "70–100 leads for $300", age 62–67, interests, lookalikes and "gratis" examples
  with invented scores, all against the NEVER-fake-data rule), the `lead` coach voice ("es tuya gratis" → gone), the Radar year
  (2027) and the post-AEP 📞/📊 items (`aepKindOf` → null). `ISABEL_SYSTEM` now carries the Meta personal-attributes rule.
- **Meta "personal attributes" policy:** copy never asserts or implies the viewer's age, health, money or origin ("¿Cumples 65?",
  "¿Tomas medicinas?", "Si tienes Medi-Cal" are out); it talks about the topic ("Medicare para quienes están por cumplir 65").
- **Instant form asks only name, phone, ZIP + two multiple-choice questions** (topic, call or text). No Medicare number, birth date,
  health, medicines, doctor, current plan or income (Meta treats them as sensitive). Consent text covers calls and automated texts.
- Generic ads only (no plan names or figures). Plan-specific ideas live in the sheet "Planes (con aprobación)": NOT publishable until
  the carrier/FMO approves and Isabel is contracted for 2027; UHC/AARP data is excluded (agent-use-only grid). Figures are verified
  against her comparison workbook by `python3 ads/libro.py out.xlsx --planes <her xlsx>`.
- Every primary text ends with the license line + the TPMO notice; the images carry a short footer (license, phone, not affiliated,
  "no ofrecemos todos los planes", Medicare.gov / 1-800-MEDICARE). The two TPMO numbers are inputs in the Excel ("Empieza aquí"):
  texts live one line per row so they copy without quotes; the notice fills itself with `CONCATENATE`.
- Cadence follows `AEP_PERIODS`: 3 ads launch 14 Oct (carta de cambios, español claro, primer Medicare), then one per week (22 Oct
  medicinas, 29 Oct beneficios, 5 Nov doctores, 12 Nov referidos, 19 Nov familias, 30 Nov últimos días); all end 7 Dec, check 8 Dec.
- The daily routine's Tuesday `[ad]` job follows the pack: `node agent/empleado.cjs compilar` appends an "ANUNCIOS DE META" section built from `ads/anuncios.json` (which ad to switch on this week, 2 new texts to test, the Monday tracking reminder).
- To change copy: edit `ads/anuncios.json`, then `node ads/render.cjs --out ads/salida/imagenes` and
  `python3 ads/libro.py ads/salida/Anuncios-Facebook-AEP-2026.xlsx --planes <her xlsx>`; `node tests/ads.cjs` checks limits, CMS
  alerts, Meta wording, the legal text against the app, image sizes and the workbook. The scratch tooling used to eyeball the Excel
  (HyperFormula for formulas, an HTML render) is not kept; LibreOffice Calc is not installed in this container.

## Testing

`python3 build.py && node tests/run.cjs` — runs `aep`, `ai`, `security`, `smoke`, `features` (≈315 checks) against both
`index.html` and the UNICO build (`features` covers the Hoy card, Revisor and voice), plus `agent` (≈40 checks on `agent/`, including that `agent/generado` is in sync with `index.html`) and `ads` (≈40 checks on `ads/` and on the ad guidance inside `index.html`). A fake Anthropic server streams real SSE events, so streaming, web search,
`pause_turn`, fallbacks and errors are all exercised. Add a test with every feature.
The bot has its own offline tests: `python bot/test_bot.py` (needs `pip install -r bot/requirements.txt`).

## Compliance facts (CMS plan year 2027 — AEP 2026)

Researched 9–10 Oct 2026 (also in the Athena repo's HANDOFF_ISABEL.md). Do NOT reintroduce the old rules:
- **No 48-hour SOA wait** (CY2027 final rule). SOA is still required BEFORE talking about specific plans;
  some carriers/FMOs still ask for the wait → always "confirm with the FMO".
- **TPMO disclaimer goes BEFORE any benefit is discussed** (not "first minute") and no longer mentions SHIPs.
- Marketing of 2027 plans from 1 Oct; applications only from 15 Oct. Marketing-call recordings: 6 years.
These live in `ISABEL_SYSTEM`, `CMS_FLAGS`, the AEP calendar notes and `tools/t65-lead-machine.html`.

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
- Exception: the scheduled Marketing-employee routines only read the repo and never commit or push.
