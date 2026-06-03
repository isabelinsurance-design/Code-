# Pendientes — siguiente sesión

Compilado al cierre del 2 de junio 2026. Trabajo hecho en esta tanda: orphan
tool fix, fases A/B/C de coaches (memoria, planes, expediente), Batch 1
(journal search, rapport semanal, voz→journal, web_search por coach, calendar
call), Batch 2 (brainstorm, reading list, smart coaches C).

---

## 🚨 BLOQUEANTES — sin esto la mitad no jala

| # | Item | Quién | Dónde |
|---|---|---|---|
| 1 | `APP_PASSWORD` + `APP_SECRET` en Railway | Sami | Railway → Variables |
| 2 | Verificar / cargar créditos OpenAI | Sami | platform.openai.com/settings/organization/billing |
| 3 | Verificar / cargar créditos Anthropic | Sami | console.anthropic.com/settings/billing |

Sin **#1** no entras a la PWA. Sin **#2** no hay voz (Whisper + TTS + voice
calls). Sin **#3** Athena no piensa.

---

## 🔧 Setup pendiente que desbloquea features ya construidas

| # | Item | Qué desbloquea | Cómo |
|---|---|---|---|
| 4 | Verificar `GMAIL_APP_PASSWORD` (16 chars sin espacios desde `connect@withisabelfuentes.com`) | Send email funcional (IMAP IDLE ya conecta — falta probar SMTP) | Athena pruebas reales después de fix |
| 5 | Comprar número Twilio SMS-capable + `TWILIO_SMS_FROM` en Railway | SMS outbound a clientes Medicare | twilio.com console + Railway env |
| 6 | `ISABEL_VOICE_PHONE=+1...` en Railway (sin "whatsapp:") | Athena te LLAMA por eventos `[LLAMA]` en Calendar | Railway env. Si no lo pones, deriva del WhatsApp. |
| 7 | ElevenLabs voice cloning: grabar 5 min de Isabel en Spanglish + `ELEVENLABS_API_KEY` + `ELEVENLABS_VOICE_ID` en Railway | Athena te contesta con TU voz cuando mandas voz | elevenlabs.io + Railway env |
| 8 | VAPID keys para push notifications (`cd server && node src/push.js --generate-keys`) | Notificaciones nativas al iPhone (briefing, urgentes) cuando la PWA está cerrada | Generar local + pegar 3 valores en Railway |
| 9 | GitHub token con scope `repo` en `GITHUB_TOKEN` Railway | Athena puede crear issues de mejoras propuestas (ya tiene la tool, falta el token) | github.com/settings/tokens |

---

## 📞 NEXTIVA — necesita conversación seria

**Contexto:** Isabel usa **Nextiva** para llamadas con clientes. La app de
Nextiva está dando problemas. Athena tiene un scaffold `nextiva.js`
(read-only) pero no jala funcional.

**Opciones a discutir:**

**A) Reemplazar Nextiva con Twilio**
- Pro: ya tenemos Twilio configurado (voz + WhatsApp + SMS). Athena ya hace
  outbound calls con `llamar_cliente` via Twilio. La voz queda en TU voz
  (ElevenLabs).
- Contra: cambio operacional grande. Cambias de número. Los clientes ya
  conocen tu número Nextiva. Compliance recording está en Nextiva históricamente.

**B) Mantener Nextiva, arreglar la app**
- Pro: cero disrupción. Compliance histórica intacta.
- Contra: depende de Nextiva soporte. No resuelve la integración con Athena.

**C) Híbrido — Athena usa Twilio, tú sigues con Nextiva**
- Pro: separas roles claros. Athena tiene su línea (Twilio); tú la tuya
  (Nextiva). Ambas coexisten.
- Contra: dos números para clientes. Necesitas explicarles.

**D) Investigar API de Nextiva** y conectar Athena bidireccional (que pueda
ver tus llamadas pasadas, ver mensajes de voz, crear tickets de seguimiento).
- Pro: mejor de los mundos.
- Contra: depende de si Nextiva tiene API decente. Trabajo de research +
  desarrollo.

**Decisión necesaria mañana:** ¿A, B, C, D, o combinación? Yo recomendaría
**D si Nextiva tiene API**, **C si no**.

---

## 🎨 Features grandes que quedaron en el roadmap

| # | Feature | Notas |
|---|---|---|
| 10 | **Phase D — coaches escriben planes/expediente desde WhatsApp** | Hoy solo lo hacen en chat directo de PWA. Cross-channel write. Cuando consultes a Sofía desde WA y ella aprenda algo nuevo, que pueda actualizar. |
| 11 | **MCP integration** | Canva, Instacart, OpenTable, Zapier-class. Athena tendría 8000+ apps gratis. Trabajo: configurar MCP client + exponer skills en directora prompt. |
| 12 | **Live observability dashboard** | Pantalla con cada tool call en vivo (IndyDevDan pattern). Útil para Sami debuggear. SQLite log + websocket. |
| 13 | **Slash commands para Sami** | Wrap los 11 crons (`/briefing`, `/rapport`, `/triage`, etc.) como comandos on-demand. Cole Medin pattern. |
| 14 | **Anthropic Skills nativas** | Restructurar los 17 coaches como filesystem Skills para progressive disclosure / cheaper context. Refactor grande. |
| 15 | **WhatsApp Business Calling para llamadas en vivo** | Hoy las llamadas son async (voice notes) o por Twilio Programmable Voice. Twilio API existe pero integración no trivial. |

---

## 🧪 Cosas a PROBAR cuando #1-#3 estén verdes

| # | Test | Cómo |
|---|---|---|
| T1 | PWA login | Abrir https://athena-integrity-production.up.railway.app/app/ → password de Sami → ver pantalla Hoy |
| T2 | Chat directo con Sofía (Phase A/B/C) | PWA → /chat → Sofía → mencionarle vitaminas → cerrar → volver → debe recordar |
| T3 | Web_search per coach (smart coaches A) | PWA o WA → "Sofía, busca último estudio sobre Magnesio glicinato 400mg" → debe buscar y citar |
| T4 | Expediente que se llena solo (smart coaches C) | Chatear largo con Sofía sobre tu cuerpo → ver panel "Expediente" arriba poblarse |
| T5 | Rapport semanal | Esperar viernes 6pm O correr manual: `node server/src/proactive.js rapport` |
| T6 | Voz reflexiva → journal automático | Mandar nota de voz emocional → ver que aparezca en journal |
| T7 | Calendar `[LLAMA]` call | Crear evento Calendar 20 min adelante con `[LLAMA] Test` → debería WA + llamada 15 min antes |
| T8 | Brainstorm estructurado | WA: "Athena, brainstorm conmigo sobre AEP" → debería dar 5 secciones formateadas |
| T9 | Reading list + resumen | "Athena, guarda https://X" → después "resúmeme el de [id]" → web_search debe resumir |
| T10 | Email SMTP outbound | "Athena, manda email a [tu personal] que diga prueba" → "envía" → debe llegar al inbox |
| T11 | Adherencia en briefing matutino | Esperar 6:30am → briefing debe mencionar plan activo de alguna coach con tono adherencia |

---

## 🗂️ Inventario de lo que ya quedó construido

**Fase / módulo nuevo creado en esta tanda:**

- `server/src/coach_threads.js` — Phase A: hilos persistentes por coach
- `server/src/coach_plans.js` — Phase B: planes estructurados por coach
- `server/src/coach_plan_tools.js` — tools que la coach usa para auto-mantener su plan + expediente
- `server/src/coach_notes.js` — Phase C smart C: expediente markdown por coach
- `server/src/rapport.js` — snapshot semanal del cuerpo
- `server/src/reading_list.js` — pocket interno
- Tools nuevas en `tools.js`: `journal_buscar`, `journal_resumen_dia`,
  `rapport_semanal`, `mi_rapport`, `brainstorm_estructurado`,
  `reading_agregar`, `reading_lista`, `reading_resumen`, `reading_marcar`
- Tools nuevas en `coach_plan_tools.js`: `coach_plan_agregar`,
  `coach_plan_actualizar`, `coach_plan_ver`, `coach_notes_actualizar`
- Endpoints REST nuevos en `api.js`: `/api/coach_thread/:coach` (GET/DEL),
  `/api/coach_plan/:coach` (GET/POST/PATCH/DELETE), `/api/coach_notes/:coach` (GET/DEL)
- Smart coaches A: web_search server-side disponible para los 17
- Calendar enhancement: detección `[LLAMA]` → además llama por Twilio
- briefing.js: hint de adherencia a planes de coaches
- memory.js: inyecta `buildAllPlansInline` + `buildReadingListInline`
- proactive.js: `sendWeeklyRapport` (viernes 6pm)
- index.js: cron `rapport` registrado
- agents.js: voz reflexiva → journal automático en prompt directora
- Fix crítico previo: `dropOrphanToolBlocks` en memory.js (resolvió crash)

**PWA (`app-v2/`):**
- Chat.jsx: paneles colapsables del expediente + plan vigente, botones
  inline para reactivar/pausar/marcar-hecho/borrar items de plan, input
  para agregar items manuales, refresh automático
- lib/api.js: 9 métodos nuevos para coach_thread, coach_plan, coach_notes

**Total commits en esta tanda:** 7 (orphan fix → Phase A → Phase B → Phase C
→ Batch 1 → Batch 2 + el de notes preparatorio).
