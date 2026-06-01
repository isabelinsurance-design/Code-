# Athena — Project Context

## What this is

**Athena** is a personal AI chief-of-staff for **Isabel Fuentes** (`isabel.insurance@gmail.com`) — a 53-year-old licensed Medicare insurance agent in Southern California with ~60–70 active clients.

She lives in two places:

- **`app/todoisabel.html`** — a single-file static HTML app (no build step) Isabel can open in a browser to chat with any of the 17 coaches directly.
- **`server/`** — a Node.js ESM WhatsApp server that runs Athena autonomously. This is the production deployment surface. It owns proactive briefings, memory, the CRM, voice calls, email, and everything else listed below.

"Athena" is the user-facing brand AND the name of the chief-of-staff coach. The HTML file is still `todoisabel.html` and the repo is still `Code-` — legacy naming preserved on purpose.

---

## Isabel — the user

- 53 years old, SoCal
- **Licensed Medicare agent** for: SCAN, Anthem, Humana, Alignment, LA Care, Health Net, Molina, UHC
- Website: `withisabelfuentes.com`
- **Spanglish first.** Spanish dominant with English code-switching is the native register.
- **Author** of *Más completa, no más perfecta* — her philosophy is baked into every coach (see `ISABEL_FILOSOFIA`)
- **Home gym:** Tonal + pilates ball. Shops at Sprouts.
- **Health goals:** 168 lbs (from 178), 110 g protein / day, 80 oz water, workout 4×/week
- **Human assistant:** Sami (handles non-AI tasks Athena delegates to)
- **Time zone:** `America/Los_Angeles`

---

## Architecture — three systems, one universe

Athena does NOT own the Medicare CRM. There are three systems that share data:

- **Athena** (this repo) — Isabel's personal chief of staff. WhatsApp + voice. Node.js on Railway. Owns: wiki, season, tasks, commitments, entities, signals, skills, drafts queue.
- **LUNA** (separate repo, Bluehost) — the team's workspace. PHP + MySQL. Skarleth, Arlette, Samia, and Isabel use it from a browser. Owns: miembros (clients), pólizas, SOAs, tickets, citas, actividad log, comisiones, metas.
- **Sistema Maestro IA** (being absorbed into LUNA) — marketing/content tool. Will retire as a standalone.

**Pilar Medicare is the ONLY bridge between Athena and LUNA.** Athena's directora does NOT have direct access to LUNA. The 14 `luna_*` tools live in `luna_tools.js` and are injected dynamically when `consultar_especialistas` is called with `especialista='pilar'`. Anyone else asking — including Athena herself — gets denied access at the architecture level (the tools simply aren't in their tool set). This enforces the principle "LUNA is a separate system; only Pilar knows how to talk to it."

Pilar reads/writes LUNA via `luna_api.php` using shared-secret auth (X-Athena-Key header). For Medicare-client operations Athena MUST delegate to Pilar via `consultar_especialistas`. Athena no longer has its own CRM — `data/crm.json` is empty/legacy.

**Infrastructure exception:** `voice.js` calls `luna_client` directly during phone calls to identify the caller before the conversation starts (latency-sensitive lookup). This is infrastructure, not conversational — the directora does not gain LUNA access through it.

## What's been built

### Phase 1 — original Athena
Static HTML app with the 17 coaches as direct conversation partners.

### Phase 2 — Athena chief-of-staff
WhatsApp server with parallel coach consultation (Anthropic multi-agent pattern). One Opus orchestrator (Athena) consults Sonnet workers in parallel via `consultar_especialistas`.

### Phase 2.5 — trust + safety
Confirmation gates on every outbound message (drafts queue → `envía` to flush). Audit trail of every tool call (`historial`). Quiet hours 9pm–7am. Daily proactive cap (1 briefing + 3 unsolicited).

### Phase 3 — multimodal + memory
Voice notes inbound (Whisper). Photos inbound (Anthropic vision). Built-in `web_search` tool. Layered memory (wiki + season + autonomous task queue with 3 owners: athena / isabel / sami). Model tiers (Opus orchestrator, Sonnet specialists).

### Phase 4 — capture + accountability + CRM
Universal capture rule baked into the prompt: when Isabel says anything she might lose, Athena captures by default. Accountability tracker for promises **others** made to Isabel (auto-nudges via SMS/email/WhatsApp). Built-in lightweight Medicare CRM. Nextiva SMS connector scaffold.

### Phase 5 — voice out + Instagram
TTS-1 voice replies (OpenAI). Athena replies in voice when Isabel sends voice. Instagram Graph API read-only (DMs / comments / stats).

### Phase 5.5 — production hardening (ops)
Twilio signature validation enforced. Idempotency by `MessageSid` (Twilio retries can't double-fire tools). Rate limit on the webhook. PII redaction in the audit log (phone / email / SSN / MBI). Hourly tar.gz backups with rotation + direct upload to S3-compatible storage (Cloudflare R2). Bumped prompt-cache TTL to 1h (Anthropic dropped the default to 5m in Feb 2026).

### Phase 6 — entity memory + Medicare compliance + signals + dreaming
**Entity memory:** every person Isabel mentions becomes a typed entity (`client / lead / family / team / vendor / broker / doctor / friend / other`) with notes + aliases + salience + optional CRM link. Resolves "Pilar" / "Pilar Hernández" / "Mari" to one record.

**Medicare compliance fields** added to every client: SOA status + 10-year retention, MBI verification, TCPA consent, AEP touchpoint log (for the CMS 12-month rule), drug list, provider directory, call recording URL. Derived helpers: `t65Info` (ICEP window auto-computed from DOB), `isAepNow` (Oct 15 – Dec 7 banner), `clientsNeedingAnnualTouch`, `clientsWithMbiPending`, `clientsWithSoaIssue`, `t65Pipeline`.

**Signals:** nightly cron computes threshold (no peso 5+d), pattern (mood keywords), state (CRM counters), and calendar (AEP active) signals. Briefing prompt consumes them ranked by severity.

**Dreaming:** nightly 2am reflection is now 4-step (extract → entities → consolidate contradictions → compute signals).

### Phase 7 — voice cloning + PDFs + Gmail IDLE + AEP digest
Multi-provider TTS: OpenAI default, **ElevenLabs** for voice cloning (set `ELEVENLABS_VOICE_ID` after recording 5 min of Isabel in Spanglish). PDF document support inbound (Anthropic native). Gmail IMAP IDLE for instant email reactions (VIP / urgent → WhatsApp ping; Medicare client → queue for morning triage). AEP-aware briefing: during Oct 15 – Dec 7 (or 30 days prior), morning brief includes a `web_search` Medicare digest hint.

### Phase 8 — phone calls
**Inbound + outbound voice via Twilio Programmable Voice + ConversationRelay.** Clients call Isabel's Twilio number → Athena answers in Isabel's cloned voice (Polly Lupe-Neural fallback) → looks up the caller in the CRM → has the conversation → records (CMS-compliant 10y retention auto-set). Post-call: Haiku summarizes the live transcript, attaches as a `cliente_touchpoint`, saves the recording URL on the client, drops a note into the morning briefing context.

`llamar_cliente` tool lets Athena place outbound calls on Isabel's behalf. Voice mode uses Sonnet 4.6 (faster than Opus, lower latency), shorter `max_tokens`, max 3 tool rounds, and a tool blocklist (no `enviar_email` / `enviar_sms` / `mensaje_a_sami` mid-call).

### Phase 9 — known unknowns + outbound review hooks
**Known unknowns** (Garry Tan gbrain pattern): instead of waiting to be asked, Athena surfaces what's MISSING. `gaps_overview` ranks compliance blockers (no MBI verified, no SOA, no TCPA, no 12-month touchpoint) above operational gaps (no email, no renewal date) above nice-to-haves. Morning briefing opens with the single most painful gap as a proposal.

**Outbound review hooks** (Boris Cherny PostToolUse pattern): every outbound message (email / SMS / Sami) runs through `reviewOutbound` before flushing. 5 deterministic checks (medical advice without disclaimer, financial advice / guaranteed returns, plan details to a client without signed SOA = CMS violation, forbidden vocab clashing with the filosofía, length) + 1 Haiku tone check ("does this sound like Isabel?"). For Sami messages (which send immediately), `alto` flags BLOCK the send. For drafts, flags surface so Isabel sees them before saying `envía`.

### Phase 10 — skills
Athena can grow her own playbooks. When she notices a recurring pattern, she proposes a draft skill (`skill_proponer`) — a markdown body that orchestrates existing tools. Isabel approves (`skill_aprobar`) before it can execute. Next time the pattern hits, `skill_invocar(nombre, inputs)` runs the playbook as a sub-conversation. Skills can't introduce new code or new tools — they only reuse what Athena already has. Versioning + retire workflow lets Isabel iterate without losing history.

### Phase 11 — calendar WRITE + free-slots
Athena creates / moves / cancels Google Calendar events on Isabel's behalf. `buscar_huecos` finds real free slots respecting working hours, days of week, and a buffer between meetings (15min default). `crear_cita` guards against conflicts by default — fails with conflict details if the slot overlaps something, unless `permitir_conflicto=true`.

### Phase 13 — Medicare workflow pack + dashboard rebrand
Six Medicare workflow skills (AEP outreach, intake, 12-month check-in, renewal followup, SOA chase, plan comparison) seeded as drafts via `medicare_pack_seed`. All skill bodies use `luna_*` tools — they read/write to LUNA's MySQL, not local data. Operational dashboard at `/dashboard` rebranded to lino cálido palette matching `todoisabel.html`, with KPI hero + tasks/commitments/skills/activity panels.

### Phase 13.5 — LUNA bridge + local CRM retired
Athena no longer maintains a parallel CRM. The 14 `luna_*` tools talk to LUNA's PHP API:
- Reads: `luna_buscar_miembro`, `luna_expediente_miembro`, `luna_briefing_completo`, `luna_pipeline_resumen`, `luna_t65_alertas`, `luna_hot_leads`, `luna_compliance_pendiente`, `luna_actividad_reciente`, `luna_carriers_breakdown`, `luna_today_appointments`
- Writes: `luna_agregar_nota`, `luna_registrar_actividad`, `luna_crear_miembro`, `luna_crear_ticket`, `luna_crear_cita`

24 legacy CRM tools removed from Athena's exposed surface: `crear_cliente`, `actualizar_cliente`, `nota_cliente`, `buscar_cliente`, `expediente_cliente`, `lista_clientes`, `clientes_descuidados`, `proximas_renovaciones`, `proximos_cumples`, `cliente_soa_firmar`, `cliente_mbi_estado`, `cliente_tcpa`, `cliente_touchpoint`, `cliente_medicamento_agregar`, `cliente_medicamento_quitar`, `cliente_doctor_agregar`, `cliente_grabacion`, `compliance_*` (3), `pipeline_t65`, `gaps_overview`, `gaps_de_cliente`, `crm_auditar`.

`voice.js` refactored to look up callers in LUNA via `luna_searchMember` and register call recordings + post-call summaries via `luna_addMemberNote` / `luna_registrar_actividad`.

**Soft retire:** `crm.js`, `gaps.js`, `auditor.js` still exist as internal helpers used by `briefing.js` (`isAepNow`), `signals.js`, and `hooks.js` (`findClient`). These read from the now-empty local CRM and silently return zeros. To be fully refactored to LUNA in a future iteration.

`/luna [ping]` slash command lets Sami spot-check connectivity. `/auditar` retired with a friendly message.

Required env vars on Railway: `LUNA_BASE_URL`, `LUNA_API_KEY`. Required PHP patch on Bluehost: at top of `luna_api.php`, accept `HTTP_X_ATHENA_KEY` header matching `LUNA_INTERNAL_KEY` env var to bypass session and treat as Isabel-admin. Patch snippet in `server/src/luna_client.js` comment block.

---

## File-by-file architecture (server)

```
server/
├─ package.json                    Node 20+, ESM
├─ src/
│  ├─ index.js                     Express server, all HTTP/WS endpoints, cron scheduling
│  ├─ claude.js                    Anthropic SDK client
│  ├─ agents.js                    The 17 coach personas + ISABEL_FILOSOFIA + ISABEL_BASE
│  ├─ directora.js                 Athena's main run loop (Opus 4.8 + tools + memory context)
│  ├─ tools.js                     49 tools — definitions + dispatcher (directora-level)
│  ├─ luna_tools.js                14 luna_* tools — Pilar-only via consultar_especialistas
│  ├─ whatsapp.js                  Twilio WhatsApp send (supports media + voice notes out)
│  ├─ email.js                     Gmail IMAP + SMTP (nodemailer + imapflow)
│  ├─ transcribe.js                Whisper voice-note transcription
│  ├─ tts.js                       Multi-provider TTS (OpenAI default, ElevenLabs)
│  │
│  ├─ memory.js                    Wiki + season + outbound queue + activity log + context builder
│  ├─ tasks.js                     3-owner task queue (athena/isabel/sami) + hourly tick
│  ├─ commitments.js               Promises OTHERS made to Isabel + auto-nudge chase cron
│  ├─ crm.js                       Built-in Medicare CRM with compliance fields
│  ├─ entities.js                  Per-person memory (canonical name + aliases + salience)
│  ├─ signals.js                   Nightly threshold/pattern/state/calendar signals
│  ├─ gaps.js                      Known-unknowns — what's missing across clients/entities/commits
│  ├─ skills.js                    Playbook authoring + lifecycle (draft → active → retired)
│  │
│  ├─ briefing.js                  6:30am proactive briefing (signals + gaps + AEP digest)
│  ├─ proactive.js                 Evening check-in, weekly review, nightly reflection
│  ├─ triage.js                    5am Gmail batch triage with draft generation
│  ├─ inbox_idle.js                Persistent IMAP IDLE — instant email reactions
│  │
│  ├─ voice.js                     Phone calls in/out via ConversationRelay + Sonnet 4.6
│  ├─ calendar.js                  Google Calendar (read scaffold + pre-meeting brief tick)
│  ├─ nextiva.js                   Nextiva SMS visibility (read-only)
│  ├─ instagram.js                 Instagram Graph API (read-only)
│  │
│  ├─ security.js                  Signature validation, idempotency, rate limit, PII redaction
│  ├─ backup.js                    Hourly tar.gz snapshots + R2/S3 upload + local rotation
│  └─ hooks.js                     PostToolUse outbound review (medical/financial/SOA/tone)
│
└─ data/                           Runtime state (gitignored). Backed up hourly to R2.
   ├─ isabel_wiki.json             Long-term notes about Isabel
   ├─ conversation.json            Recent WhatsApp turns (~40)
   ├─ season.json                  What Isabel is focused on this season
   ├─ activity.json                Audit trail of every tool call (last 500, PII-redacted)
   ├─ outbound_queue.json          Drafts pending "envía"
   ├─ proactive_counter.json       Per-day cap counter
   ├─ tasks.json                   3-owner task queue
   ├─ commitments.json             Third-party promises to Isabel
   ├─ crm.json                     Clients
   ├─ entities.json                People-by-name memory
   ├─ signals.json                 Last computed signals (refreshed nightly)
   ├─ skills/<slug>.json           One file per skill
   └─ audio/<id>.mp3               TTS output, auto-deleted >24h
```

---

## The 17 coaches

Each coach has a stable `id` used for routing throughout the app — **never rename them**. The `name` field is user-facing.

| id | name | role |
|---|---|---|
| `directora` | Athena | Chief of Staff (orchestrator, only one that runs server-side autonomously) |
| `carmen` | Chef Carmen | Nutrition |
| `rivera` | Coach Rivera | Strength / fitness |
| `sofia` | Dra. Sofía | Hormones / wellness |
| `luna` | Beauty Luna | Skin / beauty |
| `valentina` | Estilo Valentina | Style |
| `pilar` | Pilar Medicare | Medicare / clients |
| `elena` | CFO Elena | Finances |
| `alma` | Mente Alma | Mindset |
| `rosa` | Casa Rosa | Home / organizing |
| `camila` | Decor Camila | Interior design |
| `marisol` | Brand Marisol | Brand / marketing |
| `lucia` | Voz Lucía | Voice / speaking |
| `catalina` | Viajes Catalina | Travel / lifestyle |
| `beatriz` | Network Beatriz | Networking / PR |
| `esperanza` | Guía Esperanza | Faith / spiritual |
| `victoria` | Visión Victoria | Vision / goals |

Athena consults the others in parallel via the `consultar_especialistas` tool when the request spans multiple domains. Each specialist runs on Sonnet 4.6.

---

## ISABEL_FILOSOFIA — the source of Athena's voice

All 17 coaches reason from Isabel's framework, condensed from *Más completa, no más perfecta*. Defined as a constant called `ISABEL_FILOSOFIA`:

- **Server:** `server/src/agents.js` — exported constant, injected after `${ISABEL_BASE}` in all 8 server prompts.
- **App:** `app/todoisabel.html` — `const ISABEL_FILOSOFIA` declared just before `const AGENTS`, interpolated at the end of every coach's `system:` template literal (all 17 coaches).

The block covers:

- The **3 categories**: urgente / importante / mantenimiento
- The **4-step system**: capturar → clasificar → ejecutar → revisar
- The **13 áreas de vida**
- Non-negotiables: "máx 3 prioridades/día," "volver no es empezar de cero," "no todo es mío," descanso-in-structure, growth from curiosity

**Both files must stay in sync byte-for-byte.** As of the last audit they're both 1,704 chars. Edit one, edit the other.

---

## The 49 tools at the directora level (plus 14 Pilar-only)

### Memory & priorities
`recordar`, `olvidar`, `que_recuerdas`, `actualizar_temporada`, `consultar_temporada`, `historial`

### Tasks (3-owner queue)
`crear_tarea`, `mis_tareas`, `completar_tarea`, `cancelar_tarea`, `actualizar_tarea`

### Commitments (others' promises to Isabel)
`comprometer_entrega`, `mis_compromisos`, `marcar_cumplido`, `marcar_fallido`

### Entities (per-person memory)
`entidad_anotar`, `entidad_buscar`, `entidad_expediente`, `entidad_vincular_cliente`, `entidad_fusionar`

### Pilar-only LUNA tools (no expuestas a la directora)
Estas 14 tools viven en `luna_tools.js`. Solo Pilar las recibe cuando es consultada via `consultar_especialistas`. La directora (Athena) NO las ve en su `toolDefinitions`.
Reads: `luna_buscar_miembro`, `luna_expediente_miembro`, `luna_briefing_completo`, `luna_pipeline_resumen`, `luna_t65_alertas`, `luna_hot_leads`, `luna_compliance_pendiente`, `luna_actividad_reciente`, `luna_carriers_breakdown`
Writes: `luna_agregar_nota`, `luna_registrar_actividad`, `luna_crear_miembro`, `luna_crear_ticket`, `luna_crear_cita`

### Signals
`señales_de_hoy`

### Communications outbound (all review-gated)
`enviar_email`, `enviar_sms`, `mensaje_a_sami`, `confirmar_envio`, `descartar_envio`, `revisar_emails`

### Phone calls
`llamar_cliente` (outbound; inbound is automatic via `/voice/incoming` webhook). Caller lookup + post-call touchpoint via LUNA.

### Calendar (Google, full write)
`proximos_eventos`, `detalles_cita`, `crear_cita`, `reagendar_cita`, `cancelar_cita`, `buscar_huecos`

### External read-only
`web_search` (Anthropic built-in), `nextiva_pendientes`, `nextiva_actividad`, `ig_dms_pendientes`, `ig_comentarios_pendientes`, `ig_actividad`, `ig_stats`

### Coach consultation
`consultar_especialistas` (parallel fan-out to specialist coaches)

### Skills (Phase 10)
`skill_proponer`, `skill_aprobar`, `skill_retirar`, `skill_eliminar`, `skills_lista`, `skill_ver`, `skill_invocar`

---

## Cron jobs (10 in process)

| Label | Default cron | What it does |
|---|---|---|
| `briefing` | `30 6 * * *` | Morning briefing to WhatsApp — signals → gaps → priorities → Top 3 question |
| `evening` | `0 21 * * *` | Evening check-in — 3 wins + 1 for tomorrow |
| `weekly` | `0 18 * * 0` | Sunday weekly review |
| `reflect` | `0 2 * * *` | Nightly "dreaming" — extract / entities / consolidate / compute signals |
| `triage` | `0 5 * * *` | Gmail batch triage with draft generation |
| `tasks` | `0 7-21 * * *` | Hourly task tick (Athena's silent work + reminders) |
| `chase` | `0 8-20/2 * * *` | Commitment chase — nudges overdue promises, alerts Isabel |
| `audio_gc` | `0 * * * *` | Delete MP3s older than 24h |
| `backup` | `15 * * * *` | tar.gz snapshot of `data/` + R2 upload + local rotation |
| `security_gc` | `5 * * * *` | Prune seen-SID map + rate-limit hits |

`cal` (5-min calendar pre-meeting tick) registers only if Google Calendar is configured.

---

## HTTP / WebSocket endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/` | Liveness banner |
| `GET` | `/health` | JSON `{ok, time}` |
| `GET` | `/audio/<id>.mp3` | Static serving of generated TTS files |
| `POST` | `/whatsapp` | Twilio WhatsApp inbound webhook — rate-limited + signature-validated + idempotency-checked |
| `POST` | `/voice/incoming` | Twilio voice inbound — returns TwiML opening ConversationRelay |
| `POST` | `/voice/status` | Twilio voice lifecycle webhook (recording-available) |
| `WSS` | `/voice/relay` | ConversationRelay ↔ Athena bridge |

All Twilio endpoints validate `X-Twilio-Signature` when `TWILIO_REQUIRE_SIGNATURE=true` (default in prod).

---

## Layered memory (everything Athena sees per turn)

In order, surfaced in the persistent context block:

1. **Temporada actual** — 1–2 sentence focus block (`season.json`)
2. **Wiki notas** — last 25 long-term notes about Isabel (`isabel_wiki.json`)
3. **Tareas activas** — by owner (`tasks.json`)
4. **Compromisos pendientes** — third-party promises (`commitments.json`)
5. **CRM snapshot** — counts + compliance counts (mbi pending, soa missing, no-touchpoint-12m) + AEP banner
6. **Entidades** — top 12 most-recent people with highest-salience note
7. **Señales activas** — last computed (nightly), sorted by severity
8. **Known-unknowns summary** — 1 line of gap counts + top missing fields
9. **Skills activas** — 1-line per active playbook
10. **Borradores pendientes** — drafts awaiting "envía"

Plus the chat history (last ~40 turns).

System prompt cached with `ttl: '1h'`. Wiki/memory block cached with default 5m TTL since it mutates more often.

---

## Capture-by-default rule (Athena's most important behavior)

Built into Athena's prompt. When Isabel verbalizes anything she might lose, Athena captures **automatically without asking permission**:

- Hecho/preferencia/contexto **sobre Isabel** → `recordar`
- Hecho **sobre otra persona** → `entidad_anotar`
- Cosa que **ella** tiene que hacer → `crear_tarea(responsable='isabel')`
- Cosa que **Athena** va a investigar → `crear_tarea(responsable='athena')`
- Cosa que **Sami** va a ejecutar → `crear_tarea(responsable='sami')`
- Promesa que otra persona le hizo → `comprometer_entrega`
- Nuevo cliente Medicare → `crear_cliente`
- Compliance Medicare (SOA / MBI / TCPA / touchpoint / drug / doctor / recording) → tool específico, no nota libre

If Isabel later says *"no la guardes / olvídala"* → `olvidar`.

---

## Safety rails

- **Confirmation gate** on every third-party message (email, SMS to client) — drafts in `outbound_queue.json` until Isabel says `envía` / `sí`.
- **Sami messages bypass the gate** (Sami is human-in-the-loop) but go through the outbound review hook.
- **Outbound review hook** (`hooks.js`) — every outbound runs through 5 deterministic checks + 1 Haiku tone check before flush. Catches medical/financial advice, plan details without signed SOA (CMS violation), forbidden vocab, length.
- **Audit log** (`activity.json`) of every tool call, PII-redacted (phone / email / SSN / MBI). Last 500 entries. Exposed via `historial`.
- **Quiet hours** 9pm–7am — proactive messages blocked except critical pre-meeting briefs.
- **Daily cap** — 1 briefing + 3 unsolicited / day.
- **Twilio signature validation** enforced on `/whatsapp`, `/voice/incoming`, `/voice/status`.
- **Idempotency** by `MessageSid` (24h TTL) — Twilio retries can't double-fire tools.
- **Rate limit** 30 req/min/IP — leaked URL fizzles fast.
- **Voice tool blocklist** — during live calls, Athena can't send email / SMS / Sami messages without explicit Isabel confirmation post-call.
- **Skills can't introduce code** — they orchestrate existing tools only. All compliance gates still apply.

---

## Environment variables

### Required
```bash
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...                # Whisper + TTS-1 default
PUBLIC_URL=https://athena.example.com # Twilio reaches /audio + /voice + /whatsapp here

# Twilio
TWILIO_ACCOUNT_SID=AC...
TWILIO_AUTH_TOKEN=...
TWILIO_WHATSAPP_FROM=whatsapp:+1...
TWILIO_REQUIRE_SIGNATURE=true        # ALWAYS true in prod

# Isabel
ISABEL_WHATSAPP=whatsapp:+1...
ISABEL_NAME=Isabel
TIMEZONE=America/Los_Angeles

# Gmail
GMAIL_USER=isabel.insurance@gmail.com
GMAIL_APP_PASSWORD=...               # 16-char app password
SAMI_EMAIL=...
SAMI_WHATSAPP=whatsapp:+1...
```

### Optional — voice
```bash
TTS_PROVIDER=openai                  # or elevenlabs
TTS_MODEL=tts-1                      # or tts-1-hd
TTS_VOICE=nova
TTS_MAX_CHARS=1200
ELEVENLABS_API_KEY=...
ELEVENLABS_VOICE_ID=...               # Isabel's cloned voice
ELEVENLABS_MODEL=eleven_flash_v2_5
VOICE_MODEL=claude-sonnet-4-6         # phone call brain
VOICE_MAX_TOKENS=200
VOICE_MAX_ROUNDS=3
TWILIO_VOICE_FROM=+1...
TWILIO_SMS_FROM=+1...
```

### Optional — integrations
```bash
GOOGLE_CALENDAR_CLIENT_ID=...
GOOGLE_CALENDAR_CLIENT_SECRET=...
GOOGLE_CALENDAR_REFRESH_TOKEN=...
NEXTIVA_API_KEY=...
NEXTIVA_ACCOUNT_ID=...
IG_ACCESS_TOKEN=...                   # Instagram Business/Creator
IG_USER_ID=...
INBOX_IDLE=true                       # Gmail event-driven (default on if Gmail creds set)
```

### Optional — backups + ops
```bash
BACKUP_S3_ENDPOINT=https://<acct>.r2.cloudflarestorage.com
BACKUP_S3_BUCKET=athena-backups
BACKUP_S3_REGION=auto
BACKUP_S3_ACCESS_KEY_ID=...
BACKUP_S3_SECRET_ACCESS_KEY=...
BACKUP_S3_PREFIX=athena
BACKUP_KEEP_LOCAL=24
BACKUP_CRON=15 * * * *
BACKUP_SYNC_CMD=                      # optional shell hook (rclone / restic / scp)
RATE_LIMIT_PER_MIN=30
```

### Optional — cron overrides
```bash
MORNING_BRIEFING_CRON=30 6 * * *
EVENING_CHECKIN_CRON=0 21 * * *
WEEKLY_REVIEW_CRON=0 18 * * 0
NIGHTLY_REFLECT_CRON=0 2 * * *
EMAIL_TRIAGE_CRON=0 5 * * *
TASK_TICK_CRON=0 7-21 * * *
COMMITMENT_CHASE_CRON=0 8-20/2 * * *
CAL_TICK_CRON=*/5 7-21 * * *
TRIAGE_EMAIL_LIMIT=25
```

---

## Running

### Front-end app (local)
```bash
python3 -m http.server 7788 --directory app
# open http://localhost:7788/todoisabel.html
```

### Server (local dev — Twilio signature off, no WhatsApp)
```bash
cd server
npm install
cp .env.example .env  # populate the required vars
TWILIO_REQUIRE_SIGNATURE=false npm run dev
```

### Server (production)
See **`DEPLOY.md`** at the repo root. Target: Railway Hobby ($5/mo) with persistent volumes for `data/` and `backups/`. Total monthly cost estimate including Anthropic + OpenAI + Twilio + R2 is ~$22–50.

---

## Dev branch

`claude/sleepy-darwin-P4k2z`

All work happens on this branch. Sami deploys from here.

---

## Hard rules (don't break)

- **Never change coach `id` fields** — they're used for routing throughout the app.
- **Spanglish is intentional** — don't "fix" code-switching to Spanish-only or English-only.
- **`ISABEL_FILOSOFIA` must stay byte-for-byte synced** between `app/todoisabel.html` and `server/src/agents.js`.
- **Server is Node.js ESM** (`"type": "module"`) — use `import` not `require`.
- **App is a single HTML file** — no build step, no bundler, no npm in the app.
- **JSON files in `data/` are the source of truth** — back them up before any structural change. Schema migrations need to be additive.
- **Outbound to third parties is gated.** Email and client SMS go through the drafts queue. Sami messages go through the review hook. Voice calls block sensitive tools mid-call.
- **Capture by default.** Athena's #1 job is memory — when in doubt, capture. Isabel will `olvidar` if it's wrong.
- **All client data is HIPAA-adjacent.** PII redaction is on by default in logs. Backups are encrypted at rest in R2.
- **Skills can't introduce new code.** They orchestrate existing tools only. Every action they take still passes through the same compliance gates.

---

## What's deliberately deferred

(Real gaps vs the current Lindy / Martin / Gemini Spark / OpenAI Pulse landscape, but not blockers for Isabel today.)

- **Calendar WRITE** — Google Calendar is read-only scaffold. Booking/rescheduling on Isabel's behalf is Phase 11.
- **Meeting capture** — Zoom / Meet bot that transcribes + summarizes + acts on meetings. Phase 12.
- **Multi-carrier quote / e-app integration** — SunFire / MedicareCENTER. Needs Isabel's API credentials.
- **MCP client** — would give Athena Canva / OpenTable / Instacart / Zapier-class integrations for free. Not urgent.
- **Live observability dashboard** — small SQLite log + web view of every tool call (IndyDevDan's pattern). Half-day add.
- **Slash command library for Sami** — wrap the 10 crons as on-demand commands (Cole Medin's pattern).
- **Anthropic Skills reformulation** — restructure the 17 coaches as filesystem Skills for progressive disclosure / cheaper context. Big refactor.
- **WhatsApp Business Calling for live conversations** (vs current async voice notes + ConversationRelay phone calls) — Twilio API exists, integration is non-trivial.

---

## Stack summary

- **Brain:** Anthropic Claude — Opus 4.8 for Athena (orchestrator), Sonnet 4.6 for specialists + voice calls, Haiku 4.5 for cheap classifications (inbox triage, tone review, call summaries)
- **Voice in:** OpenAI Whisper
- **Voice out:** OpenAI TTS-1 (default) or ElevenLabs Flash v2.5 (with cloning)
- **Vision in:** Anthropic native (images + PDFs)
- **Web search:** Anthropic built-in `web_search`
- **Messaging:** Twilio WhatsApp + SMS
- **Voice calls:** Twilio Programmable Voice + ConversationRelay
- **Email:** Gmail IMAP (read + IDLE) + Gmail SMTP via nodemailer (send)
- **Calendar:** Google Calendar API (`googleapis`)
- **Storage:** JSON files on disk (gitignored, backed up to R2)
- **Hosting target:** Railway Hobby with persistent volumes
- **Process model:** single Node.js process, in-process cron jobs, single WebSocket server attached to the HTTP server
