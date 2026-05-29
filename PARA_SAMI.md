# Athena — Manual para Sami

Documento para que Sami entienda qué es Athena, qué puede hacer, qué NO puede hacer, y cómo interactuar con ella.

---

## ¿Qué es Athena?

**Athena es la chief of staff de Isabel.** No es un chatbot. Es un sistema que vive en WhatsApp (y por teléfono) y que coordina TODO lo que Isabel hace: clientes Medicare, salud, casa, marca, citas, dinero, equipo.

Athena es el nombre user-facing AND el nombre de la coach principal (la jefa de las 17 coaches). El archivo HTML se llama `todoisabel.html` y el repo se llama `Code-` — naming legacy mantenido a propósito.

---

## Qué VE Athena (memoria layered)

En cada turno, Athena ve todo esto al mismo tiempo:

- **Temporada actual** — 1–2 líneas de en qué está enfocada Isabel ahora
- **Wiki notas** — notas largas sobre Isabel (sus preferencias, límites, filosofía)
- **Tareas pendientes** — de Isabel, de Athena, de Sami
- **Compromisos pendientes** — promesas que OTROS le hicieron a Isabel (con fechas)
- **CRM completo** — los ~60–70 clientes Medicare + compliance counters
- **Entidades** — cada persona que Isabel ha mencionado (con alias, salience, notas)
- **Señales activas** — alertas raras (Isabel no pesó en 5d, AEP activo, audiencia agotada)
- **Known-unknowns** — qué CAMPOS faltan en su cartera de clientes
- **Skills activas** — los playbooks aprobados
- **Borradores en cola** — los mensajes esperando "envía"
- **Los últimos ~40 turnos** de WhatsApp

System prompt cached con TTL de 1h. El bloque de memoria cached con TTL default de 5m (porque cambia más seguido).

---

## Qué PUEDE HACER Athena (72 herramientas)

### Capturar (su trabajo #1)
Cuando Isabel dice algo que podría perder, Athena lo guarda solo, sin pedir permiso:
- Nota sobre Isabel → `recordar`
- Nota sobre otra persona → `entidad_anotar`
- Tarea de Isabel / Athena / Sami → `crear_tarea`
- Promesa que alguien le hizo → `comprometer_entrega`
- Cliente nuevo → `crear_cliente`

### CRM Medicare con compliance
- Crear/actualizar/buscar clientes
- Firmar SOA (con retención 10 años automática)
- Estado MBI (pending / verified / mismatch)
- TCPA consent
- Touchpoints (12-month CMS rule)
- Drug list + provider directory
- Grabaciones de llamadas (URL + retention)
- Pipeline T65 (clientes que cumplen 65 en próximos 6m)

### Comunicaciones (TODAS pasan por review automático)
- Redactar emails (encola, espera "envía")
- Redactar SMS a clientes (encola, espera "envía")
- Mandar mensaje directo a Sami (NO se encola — Sami es human-in-the-loop)
- Confirmar/descartar envíos en cola
- Revisar inbox (triage cada 5am)

### Llamadas telefónicas
- Contestar llamadas entrantes en la voz de Isabel (Twilio + ConversationRelay)
- Hacer llamadas salientes a clientes (Athena habla por Isabel)
- Resumir cada llamada automáticamente y guardar como touchpoint

### Consultas externas
- Web search (Anthropic built-in)
- Lee Instagram (DMs, comentarios, stats)
- Lee Nextiva (SMS visibility)
- Lee Google Calendar
- Lee Gmail (IMAP IDLE — reacciones instantáneas)

### Coordinación
- `consultar_especialistas` — fan-out paralelo a las 16 otras coaches cuando una pregunta cubre varios temas
- Cada coach corre en Sonnet 4.6

### Auditoría + huecos
- `crm_auditar` — encuentra duplicados, inconsistencias, stale, huérfanos, patrones raros
- `gaps_overview` — qué CAMPOS faltan en la cartera
- `gaps_de_cliente` — qué le falta a UN cliente

### Skills (playbooks)
- Propone, aprueba, retira, invoca skills
- Los skills NO introducen código nuevo — solo orquestan tools existentes

### Cron jobs autónomos (10 en proceso)
- 6:30am — briefing matutino
- 9pm — evening check-in
- Domingo 6pm — weekly review
- 2am — reflexión nocturna (extract → entities → consolidate → signals)
- 5am — triage de Gmail
- Cada hora 7am–9pm — task tick
- Cada 2 horas 8am–8pm — chase de compromisos
- Cada hora — backup tar.gz a Cloudflare R2

---

## Qué NO PUEDE hacer Athena (rieles de seguridad)

- **NO manda emails ni SMS a clientes sin que Isabel diga "envía"**. Todo queda en draft.
- **NO manda nada de 9pm a 7am** (horas de silencio) excepto algo crítico.
- **NO pasa de 1 briefing + 3 mensajes proactivos por día**.
- **NO da consejo médico** sin disclaimer (el review hook lo bloquea).
- **NO da consejo financiero** ni promete returns (review hook).
- **NO da detalles de plan a un cliente sin SOA firmada** (CMS violation — bloqueado).
- **NO usa vocabulario que clashe con la filosofía** (bloqueado).
- **NO cambia su propio código** ni inventa tools nuevas. Las skills solo usan lo que ya existe.
- **NO manda email/SMS/Sami durante una llamada en vivo** sin confirmación posterior de Isabel.

---

## El log de auditoría

Cada acción que Athena toma queda en `data/activity.json` (últimas 500 entradas, PII redactado). Sami puede revisarlo con `/historial` o desde el dashboard.

---

## Cómo Sami interactúa

### Slash commands desde WhatsApp
- `/help` — lista de comandos
- `/gaps` — qué le falta a tu cartera de clientes
- `/signals` — qué señales tiene activas
- `/auditar` — auditoría de calidad del CRM
- `/agenda` — próximos eventos
- `/clientes` — lista de clientes
- `/pendientes` — borradores esperando aprobación de Isabel
- `/historial` — últimas acciones de Athena
- `/compromisos` — promesas de terceros pendientes
- `/skills` — playbooks aprobados
- `/tareas` — tareas activas (de los 3 owners)

### Dashboard en `/dashboard`
- Login con `DASHBOARD_PASSWORD` (env var del servidor)
- Refresca cada 5 segundos
- Muestra: KPIs, compliance Medicare, auditor, gaps, señales, cola de envío, tareas por owner, compromisos, skills, audit log, backups

### Mensajes directos
Cuando Athena necesita que Sami haga algo (mandar SOA físico, llamar a un cliente, coordinar cita), Athena le manda un WhatsApp directo. Esos mensajes pasan por el review hook pero NO esperan aprobación — se envían inmediatamente.

---

## Las 17 coaches

| ID | Nombre | Rol |
|---|---|---|
| `directora` | **Athena** | Chief of Staff (la única que corre autónomamente) |
| `maria` | María Medicare | Clientes Medicare |
| `carmen` | Chef Carmen | Nutrición |
| `rivera` | Coach Rivera | Fuerza/fitness |
| `sofia` | Dra. Sofía | Hormonas/wellness |
| `luna` | Beauty Luna | Piel/belleza |
| `valentina` | Estilo Valentina | Estilo |
| `elena` | CFO Elena | Finanzas |
| `alma` | Mente Alma | Mindset |
| `rosa` | Casa Rosa | Casa/orden |
| `camila` | Decor Camila | Interiores |
| `marisol` | Brand Marisol | Marca/marketing |
| `lucia` | Voz Lucía | Voz/oratoria |
| `catalina` | Viajes Catalina | Viajes |
| `beatriz` | Network Beatriz | Networking/PR |
| `esperanza` | Guía Esperanza | Fe/espiritual |
| `victoria` | Visión Victoria | Visión/metas |

Cada una tiene la filosofía de Isabel (*Más completa, no más perfecta*) integrada en su forma de pensar. **Los IDs nunca cambian** — son routing keys.

---

## Stack técnico (para referencia)

- **Brain:** Anthropic Claude — Opus 4.8 (Athena), Sonnet 4.6 (especialistas + llamadas), Haiku 4.5 (clasificaciones baratas)
- **Voz in:** OpenAI Whisper
- **Voz out:** OpenAI TTS-1 default; ElevenLabs Flash v2.5 con voz clonada de Isabel
- **Vision:** Anthropic nativo (imágenes + PDFs)
- **WhatsApp:** Twilio
- **Llamadas:** Twilio Programmable Voice + ConversationRelay
- **Email:** Gmail IMAP IDLE + SMTP via nodemailer
- **Calendar:** Google Calendar API (read-only por ahora)
- **Storage:** Archivos JSON en `data/` (gitignored, backed up to R2 cada hora)
- **Hosting:** Railway Hobby con volumes persistentes

---

## Variables de entorno críticas

```bash
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
PUBLIC_URL=https://athena.example.com
TWILIO_ACCOUNT_SID=AC...
TWILIO_AUTH_TOKEN=...
TWILIO_WHATSAPP_FROM=whatsapp:+1...
TWILIO_REQUIRE_SIGNATURE=true       # SIEMPRE true en prod
ISABEL_WHATSAPP=whatsapp:+1...
GMAIL_USER=isabel.insurance@gmail.com
GMAIL_APP_PASSWORD=...
SAMI_EMAIL=...
SAMI_WHATSAPP=whatsapp:+1...
DASHBOARD_PASSWORD=...               # pa que veas el panel
```

Ver `server/.env.example` para la lista completa, y `CLAUDE.md` para la documentación detallada de cada variable.

---

## Si algo falla

1. Revisar `data/activity.json` para ver la última acción
2. Revisar logs del servidor (Railway → Deployments → Logs)
3. Los backups están en R2 (carpeta `athena-backups/`)
4. El servidor reintenta solo en muchos casos
5. Si Athena deja de responder en WhatsApp, reiniciar el servicio en Railway

---

## Filosofía de Isabel (integrada en TODAS las coaches)

> *"Más completa, no más perfecta"*

- 3 categorías: urgente / importante / mantenimiento
- 4 pasos: capturar → clasificar → ejecutar → revisar
- 13 áreas de vida
- Máx 3 prioridades por día
- Volver no es empezar de cero
- No todo es mío
- Descanso dentro de la estructura
- Growth desde curiosidad
