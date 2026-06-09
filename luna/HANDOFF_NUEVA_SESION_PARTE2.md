# 🌙 LUNA — Handoff PARTE 2: Apéndice técnico + Historial completo

> Complemento de `HANDOFF_NUEVA_SESION.md`. Aquí va el detalle profundo:
> catálogo de endpoints, estructura de agentes, frontend, crons, base de datos,
> el historial de TODO lo que pasó hoy, y el contexto del negocio.

---

## A. CONTEXTO DEL NEGOCIO (para que LUNA hable con sentido)

- **Isabel Fuentes** — broker dueña, agente de Medicare licenciada en California, mercado **hispano 60+** del sur de California (SoCal). Marca: agente real, bilingüe, independiente (no call center).
- **Meta:** crecer a **1,000 miembros**. El marketing (Facebook, Lives, contenido viral) es el motor de crecimiento.
- **Equipo (ids en la tabla `usuarios`):** Isabel = 6 (admin), Skarleth = 7, Arlette = 9, Samia/Sami = 10.
- **Carriers:** SCAN, Anthem, Humana, Alignment, LA Care, Health Net.
- **Compliance CMS/TPMO:** marketing de Medicare está regulado — sin "el mejor plan", sin "gratis" sin contexto, disclaimer obligatorio, etc. (LUNA tiene reglas de voz/compliance horneadas en el bloque `OP` y en los agentes Compliance/Estudio/Marketing.)
- **Sami** = persona técnica del equipo que ayuda con el servidor (aunque Isabel ya montó el auto-deploy sola).

---

## B. CATÁLOGO DE ENDPOINTS de `luna_api.php` (acción = `?action=...`)

**Lectura/datos:** `luna_whoami`, `luna_pipeline_summary`, `luna_t65_alerts`, `luna_retention_alerts`, `luna_hot_leads`, `luna_search_member`, `luna_member_detail`, `luna_pending_soa`, `luna_open_tickets`, `luna_tickets_by_agent` (desglose por agente — abiertos/cerrados/alta/vencidos), `luna_my_tickets`, `luna_today_appointments`, `luna_attendance_today`, `luna_pending_callbacks`, `luna_recent_activity`, `luna_full_briefing`, `luna_my_daily_report`, `luna_my_goals`, `luna_get_all_goals`, `luna_business_health`, `luna_gaps_overview`, `luna_carriers_breakdown`, `luna_commissions_summary`, `luna_commissions_advanced`, `luna_commission_calc`.

**Escritura:** `luna_create_ticket`, `luna_close_ticket`, `luna_update_member_status`, `luna_create_member`, `luna_add_member_note`, `luna_log_activity`, `luna_create_appointment`, `luna_batch_retention_tickets`, `luna_mark_callback_done`, `luna_update_goal`, `luna_send_internal_notif`.

**IA/chat:** `luna_chat` (proxy a Anthropic, streaming SSE).

**Memoria/entidades/señales/skills:** `luna_memory_get/set/delete/init/bulk_import`, `luna_entity_search`, `luna_entity_upsert`, `luna_signals_list/compute`, `luna_signal_dismiss`, `luna_skill_list/save`, `luna_plan_get/set`.

**Radar (tendencias):** `luna_radar_latest`, `luna_radar_run`.

**Junta:** `luna_meeting_list`, `luna_meeting_save`, `luna_meeting_action`.

**Outbound (correos/notifs con aprobación):** `luna_outbound_list/enqueue/approve/reject`, `luna_review_outbound`.

**Admin/seguridad:** `luna_audit_view`, `luna_alerts_view`, `luna_actors_list`, `luna_actor_set`, `luna_structural_audit`, `luna_selftest`.

**Candados (funciones):** `requireAdmin()` (solo Isabel), `requireActor()` (Isabel/admin, actores autorizados, o cuenta de servicio), `requirePost()`. `$admin = ($user['rol']==='admin')`. La cuenta de servicio pasa `requireActor()` pero está limitada por el **allowlist**.

**Allowlist de la cuenta de servicio (Athena):** `luna_whoami, luna_pipeline_summary, luna_retention_alerts, luna_hot_leads, luna_search_member, luna_member_detail, luna_pending_soa, luna_open_tickets, luna_tickets_by_agent, luna_today_appointments, luna_pending_callbacks, luna_recent_activity, luna_full_briefing, luna_entity_search, luna_signals_list, luna_skill_list, luna_gaps_overview, luna_business_health, luna_create_ticket, luna_add_member_note, luna_log_activity, luna_create_appointment, luna_create_member`. (`luna_create_member` por la cuenta de servicio entra como PROSPECTO + `fuente='ATHENA'` para revisión.)

---

## C. ESTRUCTURA DE AGENTES (en `index.html`, array `const AGENTS = [...]`)

Cada agente es un objeto: `{ id, cat, emoji, name, color, colorS, desc, quick:[...], info:{...}, system:`...${OP}`, webSearch? , adminOnly? }`.
- `system` = el prompt del agente (termina con `${OP}`, un bloque compartido de voz/compliance, `const OP` ~línea 521).
- Orquestación: el agente `luna_main` puede consultar a otros con la tool `consult_specialists` (enum de ids permitidos). `AGENT_TOOLS` mapea qué tools del CRM puede usar cada agente.

**Los 12 agentes:** `luna_main` (🌙 LUNA, principal), `comando` (🎛️ Centro de Comando), `analista` (📊), `estudio` (✍️ Estudio Creativo), `compliance` (⚖️), `sales_coach` (🎯), `retencion` (💎), `coach` (💪), `config` (⚙️), `onboarding` (🎓), `ads` (📢), `marketing` (📣).

Para **agregar/editar un agente**: editar el array `AGENTS`. Para que el principal lo consulte: agregarlo al enum de `consult_specialists` y a `AGENT_TOOLS`.

---

## D. FRONTEND (`luna/index.html`)

- **Tema (CSS variables):** `:root` + `[data-theme="light"]`. Paleta lino/crema: `--bg:#f7f3ec`, `--panel:#fffdf9`, texto `--t1:#2b2419`, acento `--blue:#8b6f47`. Fuentes: Fraunces (serif), DM Sans, JetBrains Mono.
- **Vistas (se muestran/ocultan):** `gridView` (cuadrícula de agentes), `workspaceView` (chat con un agente), `radarView`, `meetingView` (Junta), `marketingView` (iframe de marketing.html), `recursosView`. Las funciones `toggleRadar/toggleMeeting/toggleMarketing/toggleRecursos/openWorkspace` ocultan las demás. Al cargar: `openWorkspace('luna_main')` (abre directo el chat).
- **Chat:** `sendMsg()` → `streamTurn()` (POST a `?action=luna_chat`) → loop agéntico (hasta `MAX_ROUNDS=6`): el modelo pide tools → `callTool()` las ejecuta contra el CRM → re-alimenta. `CRM_BASE = 'luna_api.php'` (misma carpeta). `canUseChat()` actualmente devuelve `true` (candado quitado).
- **Voz:** `speak()` (TTS, voz femenina es-MX/es-US vía `pickSpanishVoice()`), `startMic()` (webkitSpeechRecognition; en iPhone se oculta el botón y se usa el dictado del teclado), `toggleVoice()`. Íconos SVG de línea: `SVG_MIC`, `SVG_MIC_REC`, `SVG_VOL_ON`, `SVG_VOL_OFF`.
- **PWA:** `manifest.json`, `sw.js` (service worker passthrough, NO cachea para no servir versiones viejas), íconos. Meta tags de iOS en el `<head>`.
- **Selector de agente:** `<select id="agentSelect">` arriba del chat (cambia de agente sin salir). Se llena con `populateAgentSelect()`.

---

## E. ARCHIVOS DE `luna/`
- `index.html` — la app (frontend completo).
- `luna_api.php` — backend/API (todos los endpoints, ~120 KB).
- `luna_config.example.php` — plantilla del config (el real `luna_config.php` vive solo en el servidor, gitignored).
- `luna_diag.php` — diagnóstico público (config + prueba Anthropic + último chat).
- `luna_ai.php` — cerebro IA compartido para los crons.
- `luna_radar.php` — lógica del Radar de tendencias.
- `luna_meetings.php` — lógica de la Junta.
- `luna_telegram_webhook.php` — webhook de Telegram (Telegram está apagado).
- `marketing.html` — el "Sistema Maestro" de marketing (18 herramientas, ~1.4 MB), embebido como iframe en la sección Marketing.
- `recursos.json` — datos de la vista Recursos.
- `manifest.json`, `sw.js`, `icon-192/512.png`, `apple-touch-icon.png` — PWA.
- `cron/` — 9 crons (ver abajo).
- `.md` — documentación (no se ejecuta).

## F. CRONS (`luna/cron/`, se configuran en cPanel → Cron Jobs)
`luna_briefing_cron` (briefing diario, por correo; Telegram OFF), `luna_radar_cron` (radar diario/semanal de tendencias), `luna_weekly_cron` (reporte del viernes), `luna_task_reminders_cron` (recordatorios de tareas de la Junta), `luna_compliance_cron`, `luna_signals_cron`, `luna_referral_cron`, `luna_email_marketing_cron`, `luna_backup_cron`. Todos cargan `require __DIR__.'/../luna_config.php'` (+ `luna_ai.php`).

## G. TABLAS de la base (compartida con el CRM)
`miembros`, `tickets`, `ticket_responsables`, `usuarios`, `citas`, `actividad`, `comisiones`, `polizas`, `soa`, `metas`, `notas_miembro`, `notificaciones`, `asistencia`, `llamadas_perdidas`, `reporte_diario`, `plan_contenido`.
**Tablas que LUNA crea solas (`luna_*`):** `luna_audit_log`, `luna_authorized_actors`, `luna_outbound_queue`, `luna_memory`, `luna_entidades`, `luna_senales`, `luna_skills`. (No hay que correr SQL a mano.)

---

## H. 🗓️ HISTORIAL DE HOY (qué pasó, en orden — para entender el "por qué")

1. **Punto de partida:** se creó un PR con mejoras (fix de tickets de Athena, Radar, Junta, tickets por agente). Repo `Code-`, rama `claude/happy-planck-Dtzud`.
2. **Despliegue → error 500:** `luna_api.php` hacía `require '../config.php'` (ruta relativa frágil) y no encontraba el archivo. → Se cambió a `__DIR__` y se creó `luna_config.php`.
3. **Más 500s:** faltaban credenciales / constante indefinida. → Se simplificó `luna_config.php` y se hizo un **cargador robusto** que busca el config en varias rutas.
4. **Error "Unexpected token <DOCTYPE":** el frontend llamaba a `../luna_api.php` (un nivel arriba, mal). → Corregido a `luna_api.php` (misma carpeta).
5. **Se agregó `luna_tickets_by_agent`** (la pregunta de "cuántos tickets tiene Arlette").
6. **PWA + voz + Telegram OFF**, estética estilo Athena (colores lino/crema, selector de agentes, íconos).
7. **Marketing**: primero botón → luego agente nativo → finalmente AMBOS (agente + sección con iframe de las 18 herramientas).
8. **Aclaración de arquitectura:** se renombró el agente principal a "Athena" y luego se **revirtió** — Athena es app aparte (vida personal), LUNA es el cerebro del negocio. Athena consulta a LUNA por el **puente** (llave de servicio).
9. **Puente 403:** la llave de Athena no coincidía / nombre distinto (`LUNA_INTERNAL_KEY` viejo vs `LUNA_SERVICE_KEY`). → Se hizo que acepte ambos nombres + `trim()`.
10. **Auto-deploy:** Isabel montó **GitHub Actions → FTP a Bluehost** ella misma (creó cuenta FTP `lunadeploy` apuntando a la carpeta luna, y los 4 secrets en GitHub).
11. **Se desplegó el `index.html` equivocado** (el de marketing de la raíz en vez del de `luna/`). → Se aclaró: subir SOLO la carpeta `luna/`.
12. **Candado "chat solo Isabel" QUITADO** (a pedido de Isabel, "por ahora").
13. **DB en blanco → 500:** `luna_config.php` tenía las credenciales de base como placeholders. Se intentó reusar `crm/config.php` con un `require` → **causó otro 500** → se **revirtió**. Luego se envolvió `db()` para que no tire 500 si la base falla.
14. **Se creó `luna_diag.php`** (diagnóstico público) + prueba real a Anthropic + log del último chat.
15. **Causa raíz del chat encontrada:** la `ANTHROPIC_API_KEY` estaba **sin créditos** ("credit balance too low"). Isabel puso una **llave con saldo** → Anthropic responde 200. ⚠️ Esa llave se **expuso en el chat** → rotarla.
16. **Dos bugs de formato de tools** (PHP `{}` → `[]`): `input_schema.properties` y `tool_use.input` → Anthropic 400. → Forzar `stdClass`.
17. **EL CHAT FUNCIONA.** 🎉
18. **Íconos limpios** (mic + voz) estilo Athena. Faltan los demás (nav + agentes).

---

## I. COORDINACIÓN CON ATHENA (la otra sesión)
- Athena vive en la rama `claude/sleepy-darwin-P4k2z` (app React `app-v2/`, Railway). Se coordinó por **mensajes que Isabel copia y pega entre las dos sesiones** (no hay canal directo).
- Athena agregó un `.cpanel.yml` (deploy alternativo por cPanel Git) — se decidió usar el FTP de GitHub Actions como método principal.
- El puente quedó: Athena manda `X-LUNA-Key` = su `LUNA_API_KEY` de Railway (64 hex), que coincide con `LUNA_SERVICE_KEY` en el servidor de LUNA. Athena tiene acceso limitado (allowlist).

---

## J. PENDIENTES (prioridad)
1. 🔒 **Rotar la `ANTHROPIC_API_KEY`** (se expuso).
2. 🎨 **Terminar íconos** (nav Radar/Junta/Marketing + emojis de agentes) → estilo Lucide/Athena.
3. 💰 **Vigilar saldo de Claude** (LUNA + Athena comparten créditos). Posible re-activar candado del chat.
4. 🟡 **Marketing Fase 2:** mover datos de `localStorage` a MySQL.
5. (Opcional) limpiar warnings menores: el SW "no-op fetch handler" y la meta `apple-mobile-web-app-capable` deprecada (agregar `mobile-web-app-capable`).
