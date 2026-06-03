# Manual de Athena

Qué hace, cuándo, cómo y por qué.

Última actualización: 3 de junio 2026 · Rama: `claude/sleepy-darwin-P4k2z`

---

## Qué es Athena

**Athena** es la AI Chief of Staff de Isabel Fuentes. Vive en dos lugares:

- **WhatsApp** — chat directo conversacional. Es el canal default.
- **PWA web** (`/app/`) — interfaz visual con dashboard, search, editores estructurados. Para sesiones más largas.

Detrás de cámaras es un proceso Node.js corriendo en Railway 24/7. Es **el cerebro central** que orquesta 17 coaches especialistas, lee/escribe el CRM (LUNA), maneja calendar/email/SMS, y opera proactiva en horarios fijos.

**Modelo:** Claude Opus 4.8 para Athena (orchestrator), Sonnet 4.6 para los 16 coaches especialistas + llamadas de voz, Haiku 4.5 para clasificaciones baratas (triage email, review tone, summaries de llamada).

---

## Lo que Athena hace SOLA (cron jobs proactivos)

Estos corren sin que Isabel pida nada. Algunos respetan quiet hours (9pm–7am), otros son críticos y pasan por encima.

| Cuándo | Qué | Por qué | Override env |
|---|---|---|---|
| **6:30am diario** | Morning briefing — señales + gaps + Top 3 | Que Isabel arranque el día con claridad sin tener que pensar | `MORNING_BRIEFING_CRON` |
| **5:00am diario** | Email triage — clasifica y arma borradores | Cuando Isabel se levanta, los emails ya están procesados | `EMAIL_TRIAGE_CRON` |
| **11:00am diario** | Trend scan (6 lentes incluyendo Chief of Staff) | Detectar virales / mejoras al sistema antes que ella pregunte | `TREND_SCAN_CRON` |
| **12:00pm diario** | Research digest — rota temas activos | Le ahorra ~2h/día de scroll de noticias del sector | `RESEARCH_DIGEST_CRON` |
| **6:00pm lun-vie** | Closing loop — qué cerramos hoy | Patrón Elite EA SOP — anchor de cierre laboral | `CLOSING_LOOP_CRON` |
| **6:00pm viernes** | Rapport semanal — pide peso/medidas/foto/sentires | Continuidad real para Sofía/Rivera/Carmen | `WEEKLY_RAPPORT_CRON` |
| **6:00pm domingo** | Weekly review | Mirar semana completa, planear la siguiente | `WEEKLY_REVIEW_CRON` |
| **8:00pm domingo** | Self-grade — Athena se evalúa 0-100 + propone 1 cambio | Auto-mejora honesta, no copy externo | `SELF_GRADE_CRON` |
| **9:00pm diario** | Evening check-in — 3 wins + 1 para mañana | Cierre cálido + captura de progreso | `EVENING_CHECKIN_CRON` |
| **2:00am diario** | Reflexión nocturna — dreaming 4-pasos | Destila el día → wiki + entidades + señales | `NIGHTLY_REFLECT_CRON` |
| **Cada hora 7am-9pm** | Task tick — trabajo silencioso en cola | Avanza lo de Athena sin que ella pida | `TASK_TICK_CRON` |
| **Cada 2h 8am-8pm** | Commitment chase — persigue promesas vencidas | Que terceros cumplan sin que Isabel ande recordando | `COMMITMENT_CHASE_CRON` |
| **Cada 5 min 7am-9pm** | Calendar tick — pre-meeting briefs 15 min antes | Llegar preparada a cada junta. Si título tiene `[LLAMA]` → además te llama por teléfono | `CAL_TICK_CRON` |
| **Cada hora** | Backup tar.gz → R2 + rotation local | Memoria persistente — nada se pierde por redeploy | `BACKUP_CRON` |
| **Cada hora** | Audio GC — borra MP3s >24h | Limpieza | (interno) |

**Trade-off filosófico:** Athena prefiere errar en el lado de molestar poco. **Cap diario:** 1 briefing + 3 mensajes unsolicited / día. Solo emergencias críticas (legal vencido, error de pago) rompen el cap.

---

## Las 6 lentes del Radar (Trends scout)

11am todos los días Athena escanea **6 lentes** con Sonnet + web_search en paralelo. Cada hit recibe score 1-10. Si alguno ≥ 8 → ping proactivo.

| # | Lente | Qué busca | Items max |
|---|---|---|---|
| 1 | ⚙️ **Chief of Staff** (META — primera, más peso) | 60% análisis interno de uso propio + 40% trends externos de AI assistants / executive systems | 5 |
| 2 | 🔥 Medicare & Insurance | CMS news, carrier shifts, AEP/OEP changes | 3 |
| 3 | 🔥 Brand & Content | Latina creators trending, Reels formulas, YouTube growth | 3 |
| 4 | 🔥 Health 50+ / Perimenopausia | Estudios breakthrough, HRT research, longevidad | 3 |
| 5 | 🔥 Productividad / Solopreneur | AI tools breakthrough, chief-of-staff frameworks | 3 |
| 6 | 🔥 Wealth & Personal Finance | Real estate post-50, wealth building tactics | 3 |

**La lente META hace algo distinto:** lee `activity.json` últimos 7 días, detecta tools no usadas, coaches no consultadas, workflow friction, error rate, delta vs sem prev, último self-grade no implementado. Su prompt PROHIBE traer hits que sean solo "trend afuera" sin conexión a un patrón propio.

---

## Self-grade semanal

Domingo 8pm. Athena se califica. **Score total 0-100** = suma de 5 subscores 0-20:

| Subscore | Mide | Cómo |
|---|---|---|
| **Response** | Error rate | 20 - (errores/total × 100). Min 0 |
| **Coverage** | % de tools known usadas | Lineal hasta 50% = 20 |
| **Engagement** | Volumen de tool calls | Lineal hasta 200/sem = 20 |
| **Proactive** | % de mensajes proactivos respondidos en 1h | Lineal |
| **Team** | Tareas atrasadas | 20 - (atrasadas × 2). Min 0 |

Después genera **UNA propuesta concreta de cambio** con Sonnet (formato Patrón→Cambio→Dueño→Métrica).

Si baja >5 pts o ≤ 60 → ping. Si sube → silencioso pero queda en `/insights`.

---

## Los 17 coaches

Athena es la #1. Las otras 16 son especialistas que ella consulta vía `consultar_especialistas` (parallel fan-out + opcional huddle mode round 2). Cada una con su filosofía y modelo Sonnet 4.6.

| id | Nombre | Dominio |
|---|---|---|
| `directora` | Athena | Chief of Staff |
| `carmen` | Chef Carmen | Nutrición |
| `rivera` | Coach Rivera | Strength / fitness |
| `sofia` | Dra. Sofía | Hormonas / wellness |
| `luna` | Beauty Luna | Skin / beauty |
| `valentina` | Estilo Valentina | Style |
| `pilar` | Pilar Medicare | Medicare / clientes (única con acceso a LUNA) |
| `elena` | CFO Elena | Finanzas |
| `alma` | Mente Alma | Mindset |
| `rosa` | Casa Rosa | Home / organizing |
| `camila` | Decor Camila | Interior design |
| `marisol` | Brand Marisol | Brand / marketing |
| `lucia` | Voz Lucía | Voice / speaking |
| `catalina` | Viajes Catalina | Travel / lifestyle |
| `beatriz` | Network Beatriz | Networking / PR |
| `esperanza` | Guía Esperanza | Faith / spiritual |
| `victoria` | Visión Victoria | Vision / goals |

**Coaches NO incluidas todavía en la NAV de chat pero existen en el código:** `dolores` (cuidadora), `paloma` (intimidad).

---

## Memoria por coach (Phases A-D)

Cada coach especialista tiene **3 capas de memoria** distintas:

### A) Hilo conversacional (`coach_threads/<id>.json`)
- Cada mensaje que Isabel ↔ coach intercambian, persistido entre sesiones
- Cap 60 turnos por coach (~30 idas y vueltas)
- Carga cuando se abre Chat en PWA Y cuando Athena consulta vía WhatsApp (Phase D)

### B) Plan vigente (`coach_plans/<id>.json`)
- Recomendaciones activas estructuradas: `{ id, text, status: active|paused|done, ts_created, ts_updated }`
- La coach lo ACTUALIZA SOLA con tool `coach_plan_agregar` durante la conversación
- Isabel también puede editar manualmente en `/plans`

### C) Expediente (`coach_notes/<id>.json`)
- Blob markdown único — hechos estables que la coach SABE de Isabel (labs, intolerancias, PRs, lesiones, etc.)
- Coach lo reescribe con `coach_notes_actualizar` cuando aprende algo nuevo importante
- Cap 8000 caracteres

### D) Cross-channel write (Phase D)
- Las 16 coaches especialistas (excepto Pilar — sus datos viven en LUNA) reciben coach_plan_tools AUNQUE Isabel las consulte por WhatsApp
- Dispatcher scoped al coach_id — Sofía no toca el plan de Carmen

**Pilar es especial:** acceso a las 14 tools `luna_*` que tocan el CRM MySQL en Bluehost. Sus "planes" son tickets/citas en LUNA.

---

## Capture-by-default — la regla #1

Cuando Isabel verbaliza algo que podría perderse, Athena lo guarda **automáticamente sin pedir permiso**:

| Si Isabel dice... | Athena llama... |
|---|---|
| Hecho/preferencia sobre ella misma | `recordar` |
| Hecho sobre otra persona | `entidad_anotar` |
| Cosa que ELLA hará | `crear_tarea(responsable=isabel)` |
| Cosa que Athena investigará | `crear_tarea(responsable=athena)` |
| Cosa que Sami ejecutará | `crear_tarea(responsable=sami)` |
| Promesa que otro le hizo | `comprometer_entrega` |
| Nuevo cliente Medicare | Pilar → `luna_crear_miembro` |
| Compliance Medicare (SOA, MBI, etc.) | tool específica de LUNA |
| Voz reflexiva ("qué día tan agotador") | `journal_entrada` (Phase: voice→journal automático) |

Si después dice *"no la guardes / olvídala"* → `olvidar`.

---

## Safety rails

| Mecanismo | Qué hace |
|---|---|
| **Confirmation gate** | Todo mensaje a TERCEROS (email, SMS a cliente) entra a `outbound_queue.json`. No se manda hasta que Isabel diga "envía" o "sí". |
| **Outbound review** | Antes de mandar, corre 5 checks deterministicos (medical advice sin disclaimer, claims financieras, plan details sin SOA firmada = violation CMS, vocab prohibido, length) + 1 tone check con Haiku. |
| **Sami bypass review** | Mensajes a Sami pasan por review pero NO por gate (Sami es human in the loop). Si review marca `alto`, BLOQUEA el send. |
| **Quiet hours** | 9pm–7am — mensajes proactivos bloqueados EXCEPTO pre-meeting briefs críticos. |
| **Daily cap** | 1 briefing + 3 unsolicited / día. |
| **Twilio signature** | Validación en `/whatsapp`, `/voice/incoming`, `/voice/status` cuando `TWILIO_REQUIRE_SIGNATURE=true`. |
| **Idempotencia** | Por `MessageSid` (TTL 24h). Twilio retries no doble-disparan tools. |
| **Rate limit** | 30 req/min/IP en webhook WhatsApp. |
| **Voice tool blocklist** | Durante llamadas live, Athena NO puede `enviar_email`, `enviar_sms`, `mensaje_a_sami` sin confirmación post-call. |
| **Audit log PII-redacted** | Cada tool call queda en `activity.json` con phone/email/SSN/MBI redactados. Last 500. |
| **Backups encrypted at rest** | tar.gz → Cloudflare R2 cada hora con rotation local 24. |

---

## El flujo de voz

```
Isabel → voz a WhatsApp
  ↓ Twilio media URL
  ↓ Whisper transcribe (OpenAI)
  ↓ Athena lee transcripción
    - Si tono reflexivo → journal_entrada PRIMERO, después contesta
    - Si tono operacional → ejecuta normal
  ↓ Athena responde (Opus)
  ↓ TTS (OpenAI nova OR ElevenLabs voz clonada)
  ↓ MP3 servido en /audio/<id>.mp3 (24h TTL)
  ↓ Twilio envía como voice note
  ↓ Isabel escucha
```

**Latencia típica:** 8-15 segundos round-trip.

---

## El flujo de calendar

```
Cron cada 5 min mira Google Calendar:
  ↓ ¿Hay evento empezando en 10-20 min Y no lo recordó antes?
    NO → skip
    SÍ → continúa
  ↓ Construye contexto: asistentes (lookup en entities), compromisos, AARs recientes
  ↓ Sonnet sintetiza brief de 80-120 palabras
  ↓ Manda WhatsApp
  ↓ Si título contiene [LLAMA] | [CALL] | 🔔:
       → ADEMÁS placeOutboundCall (Twilio Voice) al ISABEL_VOICE_PHONE
       → motivo = título del evento
  ↓ Marca evento.id como ya recordado (mapa en memoria, gc cada hora)
```

---

## El bridge a LUNA

LUNA es el CRM del negocio Medicare (PHP + MySQL en Bluehost). Athena **NO** lo administra directamente. Solo Pilar lo toca, vía 14 tools `luna_*` que se inyectan dinámicamente cuando `consultar_especialistas` la llama.

```
Athena WhatsApp → tool consultar_especialistas(especialista='pilar', ...)
   ↓
Pilar (Sonnet 4.6) recibe wikiContext + LUNA_TOOL_DEFINITIONS + plan/notes propios
   ↓
Pilar llama luna_buscar_miembro, luna_crear_ticket, etc.
   ↓
luna_client.js hace HTTP a https://withisabelfuentes.com/luna/luna_api.php
   con header X-LUNA-Key: <LUNA_API_KEY>
   ↓
luna_api.php valida header, bypassa session, ejecuta como Isabel admin (user_id=6)
   ↓
MySQL query
   ↓
Respuesta JSON → Pilar
   ↓
Pilar sintetiza → Athena → Isabel
```

**Excepción infraestructura:** durante llamadas telefónicas (voice.js), Athena llama `luna_client` directamente para identificar al caller ANTES de la conversación. Esto NO le da acceso conversacional a LUNA — solo lookup de teléfono.

---

## Mejora (la 5ª lente reflexiva)

La lente Chief of Staff funciona distinto:

```
1. Lee activity.json últimos 7 días
2. Computa: tools no usadas (de KNOWN_TOOLS ~40), coaches no consultadas (de 16),
   workflow friction (misma tool 5+ veces seguidas), error rate, delta vs sem prev,
   último self-grade y si fue implementado, tareas atrasadas por dueño, señales activas
3. Lee último self-grade del archivo self_grades.json
4. Construye snapshot interno (~10 líneas estructuradas)
5. Pasa a Sonnet con prompt 60% interno / 40% externo
6. Sonnet usa web_search SOLO para validar 1-2 propuestas con cómo otros resuelven
7. Output: 5 items, cada uno con titulo (cambio propuesto) + summary (patrón + resolución) +
   razon_isabel (acción concreta con dueño) + url (opcional) + score (impacto vs esfuerzo)
8. Si algún hit score ≥ 8 → ping proactivo (priorizada antes que los hits de dominio)
```

---

## Lo que tiene Isabel a la mano

### En WhatsApp
Chat normal con Athena. Mandar texto, voz, foto, PDF. Athena entiende todo.

### Slash commands en WhatsApp (Isabel o Sami)
- `/help` — lista completa
- `/briefing`, `/evening`, `/weekly`, `/rapport`, `/triage`, `/reflect`, `/chase`, `/research`, `/scan` — disparar crons manualmente
- `/grade` — correr self-grade ahora
- `/mejora` — digest Chief of Staff (propuestas + último grade)
- `/gaps`, `/signals`, `/agenda`, `/clientes`, `/pendientes`, `/historial`, `/compromisos`, `/skills`, `/tareas`, `/huecos`, `/luna`, `/reading`, `/trends` — consultas
- `/envía [id?]`, `/descartar` — manejar cola de borradores
- `/backup` — snapshot manual
- `/revisar <texto>` — Athena revisa antes de mandar (CMS / Medicare claims)
- `/eod <nombre> <reporte>` — equipo reporta fin de día
- `/sabado` — saturday brief especial

### En la PWA `/app/`
18 páginas: Hoy, Buscar, Coaches, Trends, Goals, Insights, Personas, Planes, Journal, Rapport, Reading, Brand, Calendar, Aprueba, Tareas, Wiki, Actividad, Configura. Plus el FAB de Quick-add flotante (+) en cualquier página.

### Voz / llamadas
- Voz inbound (notas de voz por WhatsApp) → Whisper transcribe
- Voz outbound — Athena responde en voz cuando Isabel manda voz
- Llamadas telefónicas — `llamar_cliente` tool + `[LLAMA]` en calendar event

---

## Almacenamiento — qué vive dónde

Todo en `server/data/` (volumen persistente en Railway, gitignored, backup horario a R2):

```
isabel_wiki.json         Long-term notes sobre Isabel
conversation.json        WhatsApp history (last 40)
season.json              Foco actual de Isabel
activity.json            Audit log (last 500, PII redacted)
outbound_queue.json      Drafts pendientes "envía"
proactive_counter.json   Cap diario tracker
tasks.json               Cola 3-owner (athena/isabel/sami)
commitments.json         Promesas de terceros a Isabel
crm.json                 LEGACY — vacío (CRM real vive en LUNA)
entities.json            Personas que Athena conoce
signals.json             Señales computadas nocturno
journal.json             Entradas reflexivas
habits.json              Peso, agua, proteína, workouts, sueño
rapport.json             Snapshots semanales del cuerpo
reading_list.json        Pocket interno
trends.json              Hits del Radar
self_grades.json         Calificaciones semanales de Athena
coach_threads/<id>.json  Hilos conversacionales por coach
coach_plans/<id>.json    Planes vigentes por coach
coach_notes/<id>.json    Expedientes markdown por coach
skills/<slug>.json       Playbooks Phase 10
audio/<id>.mp3           TTS output (24h TTL)
backups/                 tar.gz snapshots (24h local + R2)
```

---

## Cambio = qué tocar

Cuando aparece la propuesta de mejorar algo:

| Tipo de cambio | Dueño | Cómo |
|---|---|---|
| Prompt de Athena | Claude (yo) | Editar `server/src/agents.js` |
| Prompt de coach especialista | Claude (yo) | Editar `server/src/agents.js` SPECIALISTS[id].system |
| Nuevo tool / endpoint | Claude (yo) | `server/src/tools.js` + dispatcher + opcionalmente PWA |
| Cron nuevo / cambio horario | Claude (yo) | `server/src/index.js` scheduleCron |
| Env var (key, número, etc.) | Sami | Railway → Variables → Update |
| Compra de servicio (Twilio number, ElevenLabs) | Sami | Provisión + paste en Railway |
| Cambio en el contenido de Sofía (regla médica nueva) | Athena vía coach_notes_actualizar | Conversar con Sofía y ella lo guarda |
| Plan de coach (recomendación específica) | Coach vía coach_plan_agregar | Conversar con esa coach |

---

## Estado actual de capacidades

Conteo aproximado al cierre:

- **49 tools** en el directora level + **14 tools `luna_*`** para Pilar
- **6 lentes** del Radar (5 dominio + 1 META)
- **17 coaches** con memoria persistente (10 con UI en PWA via Coaches directory)
- **~30 endpoints REST** en `/api/*`
- **17 crons** programados
- **~30 slash commands** disponibles
- **18 páginas PWA**

---

## Conocer este manual

Atalena debe READ este manual al inicio de sesiones nuevas para saber qué puede hacer.
Sami debe READ esto al onboarding y cuando hay dudas operacionales.
Claude (en sesiones futuras de coding) actualiza este manual cuando agrega features.

**Source of truth:** `MANUAL_ATHENA.md` en la raíz del repo.
