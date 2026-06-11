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
