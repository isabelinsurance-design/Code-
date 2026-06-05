// ============================================================
//  Usage Tracker — estimación de costos Anthropic en tiempo real
//  ───────────────────────────────────────────────────────────
//  No tenemos acceso al API de billing de Anthropic, pero podemos
//  estimar costo agregando tokens manualmente cada vez que se llama
//  a la API. claude.js debería loggear esto.
//
//  Como aproximación rápida sin tocar claude.js, contamos llamadas
//  por activity log y aplicamos un costo promedio por tipo de tool.
//
//  Esto es ESTIMACIÓN, no facturación real. Para el número exacto
//  Isabel sigue yendo a anthropic.com/usage. Pero esto le da
//  visibilidad en vivo en el PWA sin salir.
// ============================================================
import { getActivity } from './memory.js';

// Costos promedio por tipo de operación (en USD), basados en
// pricing junio 2026:
//   Sonnet 4.6: $3/M input, $15/M output (cached: $0.30/M)
//   Opus 4.8:   $15/M input, $75/M output (cached: $1.50/M)
//   Haiku 4.5:  $0.80/M input, $4/M output
//
// Estos números son medias observadas — ajustar si Anthropic cambia.
const COST_ESTIMATES = {
  // Crons proactivos
  morning_briefing:       0.04,  // contexto grande + tool calls
  day_plan_scheduled:     0.03,  // Sonnet (era 0.10 con Opus)
  evening_checkin:        0.02,
  weekly_review:          0.05,
  saturday_brief:         0.04,
  nightly_reflection:     0.06,  // dreaming usa más tokens
  closing_loop:           0.02,
  triage_inbox:           0.04,  // Haiku barato pero muchos emails
  inbox_idle_react:       0.015,
  ticket_monitor_alert:   0.01,
  vacation_morning_report:0.02,
  vacation_evening_report:0.02,
  commitment_chase:       0.01,
  overload_check:         0.01,
  eod_team_nudge:         0.01,
  hourly_nudge:           0.005,
  daily_audit:            0.02,
  pre_meeting_deep:       0.02,
  coach_cadence_auto:     0.005,
  focus_block_auto:       0.001,
  self_grade:             0.03,
  trends_scan:            0.03,
  research_digest:        0.04,

  // Reactivos
  isabel_pregunta:        0.015,  // promedio chat turn
  athena_responde:        0,      // doble cuenta — solo contamos isabel_pregunta
  consultar_especialistas: 0.04,  // múltiples coaches en paralelo
  llamar_cliente:         0.15,   // call + transcript + summary
  web_search:             0.015,

  // Default para tools desconocidos
  _default:               0.002,
};

function isToday(iso) {
  if (!iso) return false;
  const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
  const local = new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date(iso));
  const today = new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date());
  return local === today;
}

function isThisWeek(iso) {
  if (!iso) return false;
  const ms = Date.now() - new Date(iso).getTime();
  return ms < 7 * 86_400_000;
}

function isThisMonth(iso) {
  if (!iso) return false;
  const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
  const localMonth = new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ, year: 'numeric', month: '2-digit',
  }).format(new Date(iso));
  const thisMonth = new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ, year: 'numeric', month: '2-digit',
  }).format(new Date());
  return localMonth === thisMonth;
}

function costOf(tool) {
  return COST_ESTIMATES[tool] ?? COST_ESTIMATES._default;
}

export function usageSnapshot() {
  const all = getActivity() || [];
  const today = { count: 0, cost: 0, by_tool: {} };
  const week  = { count: 0, cost: 0, by_tool: {} };
  const month = { count: 0, cost: 0, by_tool: {} };

  for (const a of all) {
    const ts = a.ts || a.timestamp;
    if (!ts) continue;
    const cost = costOf(a.tool);
    if (a.tool === 'athena_responde') continue; // no doblamos contado
    if (isToday(ts)) {
      today.count++;
      today.cost += cost;
      today.by_tool[a.tool] = (today.by_tool[a.tool] || 0) + cost;
    }
    if (isThisWeek(ts)) {
      week.count++;
      week.cost += cost;
      week.by_tool[a.tool] = (week.by_tool[a.tool] || 0) + cost;
    }
    if (isThisMonth(ts)) {
      month.count++;
      month.cost += cost;
      month.by_tool[a.tool] = (month.by_tool[a.tool] || 0) + cost;
    }
  }

  function topTools(by_tool, n = 10) {
    return Object.entries(by_tool)
      .sort((a, b) => b[1] - a[1])
      .slice(0, n)
      .map(([tool, cost]) => ({ tool, cost: round(cost), pct: 0 }));
  }
  function fillPct(top, total) {
    if (!total) return top;
    return top.map((t) => ({ ...t, pct: Math.round((t.cost / total) * 100) }));
  }
  function round(n) { return Math.round(n * 1000) / 1000; }

  today.cost = round(today.cost);
  week.cost = round(week.cost);
  month.cost = round(month.cost);
  today.top = fillPct(topTools(today.by_tool), today.cost);
  week.top = fillPct(topTools(week.by_tool), week.cost);
  month.top = fillPct(topTools(month.by_tool), month.cost);

  return { today, week, month, disclaimer: 'estimación basada en activity log + precios junio 2026. Para número exacto: anthropic.com/usage' };
}
