# Pendientes — siguiente sesión

Actualizado al cierre del 2 de junio 2026 (segunda tanda nocturna).

---

## 🚨 BLOQUEANTES — sin esto la mitad no jala

| # | Item | Quién | Dónde |
|---|---|---|---|
| 1 | `APP_PASSWORD` + `APP_SECRET` en Railway | Sami | Railway → Variables |
| 2 | Verificar / cargar créditos OpenAI | Sami | platform.openai.com/settings/organization/billing |
| 3 | Verificar / cargar créditos Anthropic | Sami | console.anthropic.com/settings/billing |

Sin **#1** no entras a la PWA. Sin **#2** no hay voz. Sin **#3** Athena no piensa.

---

## 🔧 Setup pendiente que desbloquea features ya construidas

| # | Item | Qué desbloquea |
|---|---|---|
| 4 | Verificar `GMAIL_APP_PASSWORD` desde `connect@withisabelfuentes.com` | Send email funcional |
| 5 | Comprar número Twilio SMS-capable + `TWILIO_SMS_FROM` | SMS outbound a clientes |
| 6 | `ISABEL_VOICE_PHONE=+1...` en Railway | Athena te LLAMA por eventos `[LLAMA]` |
| 7 | ElevenLabs: 5 min de audio + `ELEVENLABS_API_KEY` + `ELEVENLABS_VOICE_ID` | Athena te contesta con TU voz |
| 8 | VAPID keys para push notifications | Notificaciones nativas iPhone |
| 9 | `GITHUB_TOKEN` con scope `repo` | Athena crea issues de mejoras |
| 10 | `MCP_SERVERS` JSON en Railway (Zapier, Notion, etc.) | 8000+ apps de Zapier dentro de Athena |

---

## 📞 NEXTIVA — decisión pendiente

Isabel usa Nextiva para llamadas con clientes. La app está dando problemas.
4 opciones a discutir mañana — recomendación D (API integration) si
Nextiva tiene API decente, C (híbrido Athena/Twilio + Isabel/Nextiva) si no.

---

## 🎨 Features grandes que quedaron en el roadmap (no construidos)

| # | Feature | Notas |
|---|---|---|
| 11 | **MCP wiring real** | Scaffold existe (mcp_servers.js + directora.js). Falta env MCP_SERVERS con servers reales (Zapier, Notion, Drive). |
| 12 | **Voice notes desde PWA** | Hoy solo se mandan por WhatsApp. Recorder + upload + Whisper en el navegador. |
| 13 | **Onboarding tour / first-time tutorial** | Cuando Isabel entre por primera vez, walkthrough de las 18 páginas. |
| 14 | **Sami slash commands para WhatsApp Sandbox** | Ya están los slash (/briefing, /rapport, etc.), pero Sami necesita poder mandar al sandbox de Twilio sin que su número sea internacional. |
| 15 | **Anthropic Skills nativas** | Restructurar los 17 coaches como filesystem Skills para progressive disclosure. Refactor grande. |
| 16 | **WhatsApp Business Calling para llamadas en vivo** | Twilio API existe, integración no trivial. |
| 17 | **Coach knowledge baseline (smart coaches B)** | Cada coach con archivos curados (libros, papers) que conoce profundo. Manual heavy. |
| 18 | **Cross-domain correlations** | Detectar "cuando duermes <6h, journal muestra estrés siguiente día". ML simple. |

---

## ✅ Lo que SE CONSTRUYÓ en esta sesión completa (ambas tandas)

### Backend (server/src/)
- ✅ Fix crítico: `dropOrphanToolBlocks` (memory.js) — resolvió crash de tool_use/tool_result huérfanos
- ✅ Phase A: `coach_threads.js` — memoria conversacional por coach
- ✅ Phase B: `coach_plans.js` + `coach_plan_tools.js` — planes estructurados por coach
- ✅ Phase C: planes inyectados a Athena en WA + briefing
- ✅ Phase D: coaches actualizan plan/expediente desde WA (`consultar_especialistas`)
- ✅ Smart coaches A: web_search server-side para todas las coaches
- ✅ Smart coaches C: `coach_notes.js` — expediente markdown por coach
- ✅ Batch 1: `rapport.js` (snapshot semanal) + `journal_buscar` + `journal_resumen_dia` + voz→journal automático + calendar `[LLAMA]` call
- ✅ Batch 2: `reading_list.js` + brainstorm tool + `coach_notes.js` cross-channel
- ✅ Slash commands: /rapport, /research, /chase, /reading, /trends, /scan
- ✅ Observability dashboard mejorado (filtros, stats, errores, auto-refresh)
- ✅ MCP scaffold (`mcp_servers.js`) — listo para configurar
- ✅ `search.js` — búsqueda global cross-source
- ✅ `trends.js` — trend scout activo (5 dominios, 11am daily, score 1-10, proactivo si ≥8)
- ✅ `streaks.js` — journal/workout/water/rapport streaks
- ✅ Endpoints REST nuevos: /api/journal/*, /api/reading/*, /api/rapport/*, /api/coach_plans (cross-coach), /api/coach_notes/*, /api/coach_thread/*, /api/coaches/overview, /api/trends/*, /api/goals/*, /api/insights, /api/entities/*, /api/streaks, /api/search, /api/tasks (POST)

### PWA (app-v2/src/)
Pantallas nuevas:
- ✅ Journal — lista agrupada por día, búsqueda, composer, pattern emocional
- ✅ Reading list — composer, filtros por status, tags, resumen cacheado
- ✅ Rapport — sparkline SVG, composer con medidas, historial
- ✅ Plans — vista cross-coach de todas las recomendaciones
- ✅ Search — input + resultados categorizados cross-source
- ✅ Coaches — directorio de los 17 con stats
- ✅ Trends — 🔥 hits con score color-coded + scan ahora
- ✅ Goals — progress bars + proyección + composer + update inline
- ✅ Insights — signals nocturnas + pattern emocional + AAR
- ✅ Entities/Personas — directorio agrupado por tipo con expansión

Componentes:
- ✅ QuickAdd FAB — captura rápida desde cualquier página (Journal/Task/URL/Rapport)
- ✅ Hoy enhanced — stats grid 4x2 + streaks section

### Total
- **Commits**: 18 en esta sesión (orphan fix → A → B → C → Batch 1 → Batch 2 → D + slash + observability + MCP + PENDIENTES.md → Journal/Reading/Rapport → Plans → Search → Coaches → Trends → Goals + QuickAdd → Hoy → Insights → Entities → Streaks)
- **Páginas PWA**: 10 nuevas
- **Endpoints REST nuevos**: ~30
- **Tools nuevas para Athena**: 12 (journal_buscar, journal_resumen_dia, rapport_semanal, mi_rapport, brainstorm_estructurado, reading_agregar/lista/resumen/marcar, trends_pendientes, trends_scan_ahora, coach_notes_actualizar)
- **Slash commands nuevos**: 6 (/rapport, /research, /chase, /reading, /trends, /scan)
- **Crons nuevos**: 2 (rapport viernes 6pm, trends 11am daily)

---

## 🧪 Tests para validar cuando #1-#3 estén verdes

| # | Test | Cómo |
|---|---|---|
| T1 | PWA login | URL + APP_PASSWORD |
| T2 | Quick-add desde Hoy | FAB → Journal → Guardar |
| T3 | Chat directo con Sofía + memoria persistente | PWA → Coaches → Sofía → chatear → cerrar → volver |
| T4 | Web_search per coach | "Sofía, último estudio sobre Magnesio" |
| T5 | Expediente que se llena solo | Conversación larga con Sofía sobre cuerpo |
| T6 | Rapport viernes 6pm o `/rapport` manual | WhatsApp |
| T7 | Voz reflexiva → journal automático | Mandar nota de voz |
| T8 | Calendar `[LLAMA]` call | Crear evento Calendar 20 min adelante |
| T9 | Brainstorm | WhatsApp: "brainstorm conmigo sobre AEP" |
| T10 | Trend scout proactivo | `/scan` o esperar 11am |
| T11 | Search global | PWA → Buscar → "Skarleth" |
| T12 | Insights | PWA → Insights → ver signals + patterns |
| T13 | Streaks | PWA → Hoy (debería aparecer si hay actividad) |
| T14 | Entities/Personas | PWA → Personas → ver gente que Athena conoce |
| T15 | Goals con projection | PWA → Metas → crear + update |
| T16 | Cross-coach Plans | PWA → Planes → ver todas las recomendaciones |
| T17 | Coaches directory | PWA → Coaches → ver stats por cada uno |
| T18 | Trends scan ahora | PWA → Trends → "Scan ahora" |
