# HANDOFF vivo — Isabel Fuentes · Athena (canónico)

> Este es el handoff ACTUAL. Reemplaza cualquier handoff pegado en chat de antes
> del 10 jun 2026. Una sesión nueva lee: CLAUDE.md → este archivo → los docs de plan.
> Rama: claude/sleepy-darwin-P4k2z. Último commit base: 3790e84.

## ⚠️ CORRECCIÓN CRÍTICA (lo que handoffs viejos tienen MAL)

- **LUNA NO TIENE TICKETS. Nunca los va a tener.** El CRM (LUNA) tiene: clientes/miembros,
  citas, leads, pólizas, SOAs, actividad. NO tickets. Cualquier doc/handoff/commit viejo que
  hable de "tickets de LUNA", "reporte de tickets abiertos", "tickets por agente" parte de una
  suposición FALSA. No reintroducir tickets.
- Por eso el **team morning email** salía siempre vacío ("no tienes tickets"): jalaba de tickets
  inexistentes. **Ya se reescribió** (commit 3790e84) para armar "cómo se ve tu día" desde
  **citas de hoy + seguimientos (hot leads) + SOAs pendientes**, con conteo arriba y sección
  URGENTE. Detecta si LUNA marca agente por item (si sí, personaliza; si no, resumen de equipo).
  Sigue en MODO PREVIEW (todo a isabel.medicareadvantage@gmail.com).

## Cómo trabajar con Isabel

Spanglish (no corregir). Tiene acento → la voz transcribe con typos (suri/Skarleth, sammy/Sami,
Crown/cron, athrupic/Anthropic): interpreta contexto, no la palabra. No la hagas repetir. No
inventes — si te equivocas, dilo. En CHAT le gustan bullets cortos (lo lee en pantalla); en VOZ
texto plano sin asteriscos (Athena se lo lee). "short" = acorta. "dale/hazlo" = ejecuta sin
preguntar. Hecho = commit + push sin preguntar. Filosofía: máx 3 prioridades/día.

## Lo que SÍ puedo y NO puedo desde Claude Code

- SÍ: leer/editar código, git, docs, investigar web, Google Calendar de isabel.insurance@gmail.com.
- NO: ver Railway en vivo, billing de Anthropic, WhatsApp, ni la data REAL de LUNA. Para eso
  Isabel es mis ojos (PWA Diagnóstico, screenshots).

## Trabajo de planeación de vida hecho esta sesión (docs en el repo)

- ESTADO_BRIDGE_LUNA_6JUN2026.md — estado del bridge + test final + rotar llave.
- PLAN_FITNESS_ISABEL.md — plan basado en evidencia (5 frentes investigados). Arranque: Tonal
  2 días/sem, proteína 125-150g, caminar. Coaches Rivera/Carmen/Sofía.
- PLAN_DE_VIDA_ISABEL.md — captura maestra: salud, hormonas, taxes, negocio, seguros.
  · Hormonas: llamar Samuel Dixon Family Health Center (Santa Clarita, toma Medi-Cal). Midi/
    telehealth NO toman Medi-Cal. El test SÍ lo cubre Medi-Cal si lo ordena un doctor de la red.
  · Taxes: en EXTENSIÓN (oct). Gateados por la decisión de la SOCIEDAD (partnership) — esa va
    primero porque cambia cómo se declaran. Es sesión de estrategia, no tarea.
- Ritmo diario propuesto (pendiente que Isabel confirme hora): junta con Athena 7am +
  Tonal mar/vie. Calendarios en su cuenta: principal, "ISABEL APPOINTMENTS MEDICARE" (trabajo,
  lo usa el equipo), Family. Falta crear un "super calendario" dedicado a Athena.

## Auditoría + endurecimiento (13 jun 2026)

Se auditó todo el codebase → AUDIT.md (severidad + esfuerzo + lista P0-P3). Hecho hoy
(pusheado, último commit 066b2f1; FALTA que Sami despliegue — ver PARA_SAMI_AUDIT_DEPLOY.md):
- P0: escritura atómica de los JSON (storage.js — ya no se truncan en un crash); secreto de
  sesión seguro (api.js ya no usa la constante 'dev-only'); quitado `gaps_overview` del briefing
  (tool que no existía).
- P1: el bridge ya distingue "forma rara" de "vacío" (luna_shape.js); el gate de SOA consulta
  LUNA cuando el CRM local está vacío; crons en async/try-catch; lecturas corruptas avisan.
- P2: rate-limit en /api/login; timeout en /api/transcribe; SSN redactado en logs;
  .env.example corregido. NO tocado a propósito (riesgo prod, coordinar con LUNA): firma de
  voz Twilio, single-header LUNA. web-push NO se quitó (sí se usa en push.js — el audit erró).
- PERSISTENCIA (la causa real de "Athena no guarda mis cosas"): `persistence_check.js` detecta
  y grita si data/ es efímero (volumen de Railway mal montado); `restoreIfEmpty()` en backup.js
  auto-recupera la memoria del último backup (R2 o local) si arranca vacía — candado: solo si
  data/ está vacío, nunca encima de datos vivos. Paso 0 de Sami: montar el volumen en
  /app/server/data (ver PARA_SAMI_AUDIT_DEPLOY.md).
- CLAUDE.md refrescado a la realidad (21 coaches, 33 crons, LUNA sin tickets).
- CONFIG self-check: al arrancar, Athena imprime qué integraciones tiene/le faltan (config_check.js).
- EQUIPO: modo "de licencia" (team_status.js) — SAMI_ON_LEAVE_UNTIL=YYYY-MM-DD pausa a Sami
  (cirugía, ~1 mes) y se reactiva sola; sus tareas/mensajes rebotan a Isabel (Opción A).
  Skarleth (id 7) REMOVIDA del roster/delegación — ya no está en el equipo. PENDIENTE (lado LUNA,
  no Athena): reasignar los clientes/trabajo de Skarleth en LUNA (agente 7) o quedan huérfanos.
- COMPLIANCE: extraída deterministicFlags (testeable); cerrado hueco de claims CMS en español
  ("100% gratis", "ahorro garantizado").
- tools.js PARTIDO: definiciones (1,718 líneas) → tool_definitions.js; tools.js de 3,579 → 1,884.
  Verificado: 142 tools intactas. Falta el dispatcher (el "cómo") — NO partir a ciegas, requiere
  correr la app de punta a punta.
- CI: `.github/workflows/test.yml` corre `npm test` en cada push/PR.
- RED DE SEGURIDAD: `cd server && npm test` → **48 pruebas** (node:test, 0 deps de prod) cubriendo
  storage atómico, persistencia, restore, forma de LUNA, team email, compliance CMS, superficie de
  tools (142), modo de licencia, y fechas/recordatorios (9am local robusto a DST). Para correr:
  `npm install --ignore-scripts` (node_modules está gitignored).

**Lo que QUEDA (deuda estructural, no urgente — la reliability ya está sólida):**
- Partir el dispatcher de tools.js (~1,650 líneas, el "cómo") — maintainability; riesgo real;
  hacerlo en entorno donde la app corra y con tests que ejerciten cada tool.
- ¿JSON→DB? Marginal para una sola usuaria ahora que la escritura es atómica + hay restore;
  agrega dep nativa (riesgo en Railway). Reconsiderar solo si crece el volumen de datos.

## Pendientes abiertos (los reales)

1. ANTHROPIC SIN SALDO (bloqueador #1, probable): Athena decía "tuve un problema técnico".
   Isabel recarga en console.anthropic.com → Billing + auto-recharge. Sin esto NADA conversacional.
2. TEAM EMAIL v2: confirmar con el DIAG del próximo preview qué devuelve LUNA (citas/leads/SOAs)
   y si trae agente. Railway debe redesplegar ≥ commit 3790e84. Borrar DIAG cuando se confirme.
   Decisión de diseño pendiente: ¿cada quien ve lo suyo o el día del equipo completo?
3. ROTAR LUNA_SERVICE_KEY (se expuso: 5e6c…, 64 hex). Railway LUNA_API_KEY → nueva → pegar en
   luna_config.php Bluehost → verificar con luna_diag.php.
4. SURI id=8 sin verificar.
5. Cadencias de coaches + temas de research NUNCA se seedearon. Ojo: activar crons/cadencias =
   tocar producción (Isabel dijo varias veces "no muevas lo que funciona") — confirmar con ella
   antes, y es vía Railway/Sami, no un botón mágico desde Claude Code.
6. birthdays_today endpoint no existe en LUNA PHP (otra sesión, rama claude/happy-planck-Dtzud).

## Modelo de costos (acordado)

Athena cuesta por turno (Anthropic). Claude Code = suscripción, marginal ~0 → pensar/planear AQUÍ,
y a Athena darle el plan final en un turno para ejecutar/recordar. Rutinas robóticas (sin LLM,
gratis): team email, birthdays, backups. Tiers: Opus deep, Sonnet default, Haiku barato.
</content>
