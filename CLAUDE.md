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
| `tools/` (18 files) | Full standalone tool dashboards. Each has an **injected shared-key fetch interceptor** (search `ISABEL UNIFIED`). |
| `isabel-sistema-completo-UNICO.html` | **GENERATED build** — all 18 tools embedded as blob URLs so Isabel can open ONE file in Chrome with no `tools/` folder. **Do not hand-edit.** This is the file she actually uses. |
| `serve.sh` | Local web server helper (`python3 -m http.server`). |

## Build step — IMPORTANT

After **any** change to `index.html` or `tools/`, regenerate the single-file build.
It is produced by a small Python script that:
1. Reads `index.html` + every file in `tools/`.
2. Replaces the iframe `openTool()` function with a blob-URL version.
3. Embeds tools as `const TOOL_DATA = {...}`, escaping `</` → `<\/` and `<!--` → `<\!--`.
4. Writes `isabel-sistema-completo-UNICO.html`.

Then **verify in a real browser** with Playwright (installed globally at
`/opt/node22/lib/node_modules/playwright`) + `python3 -m http.server`. Always send the
rebuilt UNICO file to Isabel after changes.

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

## Git

- Work on branch `main`. Commit with clear messages and push after completing changes.
