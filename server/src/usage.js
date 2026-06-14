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
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { atomicWriteJson } from './storage.js';
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

// ============================================================
//  COSTO REAL — basado en tokens de verdad (no estimación)
//  ────────────────────────────────────────────────────────
//  claude.js/directora.js llaman recordUsage() con res.usage y res.model
//  después de cada respuesta de Anthropic. Aquí se calcula el costo REAL
//  con los tokens exactos × precios por modelo. Editar PRICING si cambia.
// ============================================================
const __dirname2 = dirname(fileURLToPath(import.meta.url));
const USAGE_FILE = join(__dirname2, '..', 'data', 'usage_log.json');

// USD por millón de tokens (junio 2026). cache_write asume TTL 1h (~2× input).
const PRICING = {
  opus:   { in: 15,  out: 75, cache_read: 1.5,  cache_write: 30 },
  sonnet: { in: 3,   out: 15, cache_read: 0.3,  cache_write: 6 },
  haiku:  { in: 0.8, out: 4,  cache_read: 0.08, cache_write: 1.6 },
};
function pricingFor(model) {
  const m = String(model || '').toLowerCase();
  if (m.includes('opus')) return PRICING.opus;
  if (m.includes('haiku')) return PRICING.haiku;
  return PRICING.sonnet; // default
}

// Función PURA: tokens + modelo → costo USD real.
export function costFromTokens(model, usage = {}) {
  const p = pricingFor(model);
  const inTok = usage.input_tokens || 0;
  const outTok = usage.output_tokens || 0;
  const cacheRead = usage.cache_read_input_tokens || 0;
  const cacheWrite = usage.cache_creation_input_tokens || 0;
  const usd = (inTok * p.in + outTok * p.out + cacheRead * p.cache_read + cacheWrite * p.cache_write) / 1_000_000;
  return Math.round(usd * 1e6) / 1e6;
}

function loadUsageLog() {
  try { if (existsSync(USAGE_FILE)) return JSON.parse(readFileSync(USAGE_FILE, 'utf8')); }
  catch (e) { console.error('[usage] usage_log.json ilegible:', e.message); }
  return [];
}

// Registra el uso real de una llamada. Nunca tumba la respuesta del LLM.
export function recordUsage({ model, usage, label = '' }) {
  try {
    if (!usage) return;
    const log = loadUsageLog();
    log.unshift({
      ts: new Date().toISOString(),
      model: String(model || '').slice(0, 40),
      label: String(label || '').slice(0, 40),
      in: usage.input_tokens || 0,
      out: usage.output_tokens || 0,
      cache_read: usage.cache_read_input_tokens || 0,
      cache_write: usage.cache_creation_input_tokens || 0,
      cost: costFromTokens(model, usage),
    });
    atomicWriteJson(USAGE_FILE, log.slice(0, 3000));
  } catch { /* el tracking nunca tumba la llamada al LLM */ }
}

// Resumen de costo REAL (testeable): suma de hoy/semana/mes desde el usage log.
export function realCostSummary(rows = loadUsageLog(), now = new Date()) {
  const list = Array.isArray(rows) ? rows : [];
  const bucket = () => ({ count: 0, cost: 0, tokens_in: 0, tokens_out: 0 });
  const out = { today: bucket(), week: bucket(), month: bucket(), has_data: list.length > 0 };
  const nowMs = now.getTime();
  for (const r of list) {
    const t = r?.ts ? new Date(r.ts) : null;
    if (!t || Number.isNaN(t.getTime())) continue;
    const add = (b) => { b.count++; b.cost += r.cost || 0; b.tokens_in += r.in || 0; b.tokens_out += r.out || 0; };
    if (isToday(r.ts)) add(out.today);
    if (nowMs - t.getTime() < 7 * 86_400_000) add(out.week);
    if (isThisMonth(r.ts)) add(out.month);
  }
  for (const k of ['today', 'week', 'month']) out[k].cost = Math.round(out[k].cost * 1e4) / 1e4;
  return out;
}
