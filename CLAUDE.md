# Athena — Project Context

## What this is
**Athena** — a personal coaching app for Isabel Fuentes (isabel.insurance@gmail.com). A static HTML app (`app/todoisabel.html`) plus a WhatsApp server (`server/`) that acts as an autonomous chief-of-staff via AI coaches. ("Athena" is the user-facing brand and the name of the chief-of-staff coach; the file is still `todoisabel.html` and the repo is `Code-`.)

## Structure
- `app/todoisabel.html` — the entire front-end (single file)
- `server/src/` — WhatsApp server (Node.js ESM)
  - `agents.js` — all coach personas and system prompts
  - `directora.js` — runs the main Athena agent
  - `tools.js` — tools Athena can use (email, Sami messages, memory)
  - `briefing.js` — morning proactive briefing
  - `memory.js` — conversation history + Isabel wiki

## The coaches system
Each coach has a stable `id` (never change these) and a display `name`.

| id | name | role |
|---|---|---|
| directora | Athena | Chief of Staff |
| carmen | Chef Carmen | Nutrition |
| rivera | Coach Rivera | Strength / fitness |
| sofia | Dra. Sofía | Hormones / wellness |
| luna | Beauty Luna | Skin / beauty |
| valentina | Estilo Valentina | Style |
| maria | María Medicare | Medicare / clients |
| elena | CFO Elena | Finances |
| alma | Mente Alma | Mindset |
| rosa | Casa Rosa | Home / organizing |
| camila | Decor Camila | Interior design |
| marisol | Brand Marisol | Brand / marketing |
| lucia | Voz Lucía | Voice / speaking |
| catalina | Viajes Catalina | Travel / lifestyle |
| beatriz | Network Beatriz | Networking / PR |
| esperanza | Guía Esperanza | Faith / spiritual |
| victoria | Visión Victoria | Vision / goals |

**Rule:** `id` fields are used for routing throughout the app — never rename them. Only `name` fields are user-facing.

## Isabel
- 53 years old, Medicare agent in SoCal (SCAN, Anthem, Humana, Alignment, LA Care, Health Net, Molina, UHC)
- Website: withisabelfuentes.com
- Home gym: Tonal + pilates ball. Shops at Sprouts.
- Goal: 168 lbs (from 178), 110g protein/day, 80oz water, workout 4x/week
- Human assistant: Sami

## Running the app
```bash
python3 -m http.server 7788 --directory app
# open http://localhost:7788/todoisabel.html
```

## Dev branch
`claude/sleepy-darwin-P4k2z`

## Isabel's philosophy (baked into every coach)
All coaches reason from Isabel's own framework, condensed from her book "Más completa, no más perfecta." It's defined as `ISABEL_FILOSOFIA` and interpolated into every system prompt:
- **Server**: `server/src/agents.js` — exported constant, injected after `${ISABEL_BASE}` in all 8 prompts.
- **App**: `app/todoisabel.html` — `const ISABEL_FILOSOFIA` declared just before `const AGENTS`, interpolated at the end of every coach's `system:` template literal (17 coaches).

The block covers: the 3 categories (urgente/importante/mantenimiento), the 4-step system (capturar → clasificar → ejecutar → revisar), the 13 áreas de vida, the non-negotiable rules ("máx 3 prioridades/día", "volver no es empezar de cero", "no todo es mío", descanso-in-structure, growth from curiosity). If you edit the philosophy, edit it in both files so they stay in sync.

## Key rules
- Never change coach `id` fields
- Server is Node.js ESM (`"type": "module"`)
- App is a single HTML file — no build step
- Spanglish is intentional throughout
- Keep `ISABEL_FILOSOFIA` synced between app and server
