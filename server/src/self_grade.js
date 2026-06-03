// ───────────────────────────────────────────────────────────────────
//  Self-grade — Athena se califica a sí misma cada semana.
//
//  Comparativo: esta semana vs semana previa, score 0-100, y UNA
//  propuesta concreta de cambio para la próxima semana. Persiste para
//  que el chief_of_staff lens pueda referirlo ("último grade: 78,
//  propuesta no implementada todavía") en su análisis.
//
//  Subscores:
//   - response: error rate (menos errores = mejor)
//   - coverage: % de tools known usadas al menos una vez
//   - engagement: tool calls totales (más = mejor pero con techo)
//   - proactive_accuracy: % de proactive msgs que Isabel respondió/
//                        engaged (heurística: hubo mensaje suyo en
//                        los 30 min siguientes)
//   - team_health: tareas atrasadas (menos = mejor)
//
//  Cada uno 0-20 = score total 0-100.
//
//  Storage: data/self_grades.json — array de { semana, score, subscores,
//  delta_vs_prev, observaciones, cambio_propuesto, implementado, ts }
// ───────────────────────────────────────────────────────────────────

import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { anthropic } from './claude.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'self_grades.json');

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() { try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {} return []; }
function save(d) { ensureDir(); writeFileSync(FILE, JSON.stringify(d.slice(-52), null, 2)); }

function isoWeek(d = new Date()) {
  const x = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
  const dayNum = x.getUTCDay() || 7;
  x.setUTCDate(x.getUTCDate() + 4 - dayNum);
  const yearStart = new Date(Date.UTC(x.getUTCFullYear(), 0, 1));
  const weekNum = Math.ceil(((x - yearStart) / 86_400_000 + 1) / 7);
  return `${x.getUTCFullYear()}-W${String(weekNum).padStart(2, '0')}`;
}

function loadJsonSafe(file, fallback) {
  try { if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8')); }
  catch { /* ignore */ }
  return fallback;
}

// Computa los subscores raw para una ventana [from, to].
function computeSubscores(from, to) {
  const activity = loadJsonSafe(join(DATA_DIR, 'activity.json'), []);
  const tasks = loadJsonSafe(join(DATA_DIR, 'tasks.json'), []);
  const conversation = loadJsonSafe(join(DATA_DIR, 'conversation.json'), []);

  const window = activity.filter((a) => {
    const t = new Date(a.ts || 0).getTime();
    return t >= from && t < to;
  });

  const total = window.length;
  let errors = 0;
  const toolsUsadas = new Set();
  for (const a of window) {
    toolsUsadas.add(a.tool);
    const blob = `${a.result_summary || ''} ${a.input_summary || ''}`.toLowerCase();
    if (/error|falló|fail|timeout|no pude/.test(blob)) errors += 1;
  }

  const KNOWN_TOOL_COUNT = 50; // aprox — para el cálculo de coverage
  const coverage = Math.min(toolsUsadas.size / KNOWN_TOOL_COUNT, 1);

  // response: 20 si 0 errores. -1 por cada error %. Min 0.
  const errorRate = total > 0 ? errors / total : 0;
  const responseScore = Math.max(0, 20 - Math.round(errorRate * 100));

  // engagement: 20 si ≥ 200 tool calls/semana. Lineal abajo.
  const engagementScore = Math.min(20, Math.round((total / 200) * 20));

  // coverage: 20 si usa 50%+ de tools known. Lineal abajo.
  const coverageScore = Math.min(20, Math.round((coverage / 0.5) * 20));

  // proactive_accuracy: % de proactive msgs (en activity con tool=runProactive)
  // que tuvieron respuesta de Isabel en los 60 min siguientes (heurística:
  // hubo user message en conversation con ts cercano).
  const proactiveCalls = window.filter((a) => a.tool === 'runProactive' || a.tool === 'sendMorningBriefing' || a.tool === 'sendEveningCheckin');
  let proactiveEngaged = 0;
  if (proactiveCalls.length) {
    for (const p of proactiveCalls) {
      const pt = new Date(p.ts).getTime();
      const userMsg = conversation.find((m) => m.role === 'user' && Math.abs(new Date(m.ts || 0).getTime() - pt) < 60 * 60 * 1000);
      if (userMsg) proactiveEngaged += 1;
    }
  }
  const proactiveScore = proactiveCalls.length === 0
    ? 10 // neutral si no hubo proactive (no penalizar)
    : Math.round((proactiveEngaged / proactiveCalls.length) * 20);

  // team_health: 20 si 0 tareas atrasadas. -2 por cada una. Min 0.
  const atrasadas = tasks.filter((t) => {
    if (t.status === 'lista' || t.status === 'cancelada') return false;
    if (!t.vence) return false;
    return new Date(t.vence).getTime() < to;
  });
  const teamScore = Math.max(0, 20 - atrasadas.length * 2);

  return {
    response: responseScore,
    coverage: coverageScore,
    engagement: engagementScore,
    proactive: proactiveScore,
    team: teamScore,
    _raw: {
      total_calls: total,
      errors,
      tools_distintas: toolsUsadas.size,
      proactive_calls: proactiveCalls.length,
      proactive_engaged: proactiveEngaged,
      tareas_atrasadas: atrasadas.length,
    },
  };
}

// Genera UNA propuesta de cambio para la próxima semana usando Sonnet.
async function proponerCambio({ semana, scores, deltas, observaciones }) {
  try {
    const prompt = `Soy Athena (AI Chief of Staff de Isabel). Acabo de calificarme la semana ${semana}. Estos son mis números:

Score total: ${scores.total}/100 (${deltas.total >= 0 ? '+' : ''}${deltas.total} vs sem prev).

Subscores (cada uno 0-20):
- response (error rate): ${scores.response}
- coverage (% tools usadas): ${scores.coverage}
- engagement (volumen tool calls): ${scores.engagement}
- proactive (Isabel responde mis pings): ${scores.proactive}
- team (tareas no atrasadas): ${scores.team}

Observaciones del raw data:
${observaciones}

INSTRUCCIONES:
Identifica el SUBSCORE MÁS BAJO o con peor delta. Propón UN cambio CONCRETO y ACCIONABLE para la próxima semana. NO una lista — UNO solo, el que más impacto tendría.

Formato (texto plano, sin markdown):
PATRÓN: <qué patrón viste en el dato>
CAMBIO: <qué exactamente hacer — sea código, prompt, cron, o behavior shift>
DUEÑO: <Sami para implementar | Athena (yo) para cambiar prompt | Isabel para cambiar uso>
SUCCESS METRIC: <cómo sabré si funcionó la próxima semana>`;

    const res = await anthropic.messages.create({
      model: 'claude-sonnet-4-6',
      max_tokens: 600,
      messages: [{ role: 'user', content: prompt }],
    });
    return res.content.filter((b) => b.type === 'text').map((b) => b.text).join('\n').trim();
  } catch (err) {
    return `(no pude generar propuesta automática: ${err.message})`;
  }
}

export async function gradeWeek() {
  const now = Date.now();
  const sevenDaysAgo = now - 7 * 86_400_000;
  const fourteenDaysAgo = now - 14 * 86_400_000;

  const thisWeek = computeSubscores(sevenDaysAgo, now);
  const lastWeek = computeSubscores(fourteenDaysAgo, sevenDaysAgo);

  const scores = {
    response: thisWeek.response,
    coverage: thisWeek.coverage,
    engagement: thisWeek.engagement,
    proactive: thisWeek.proactive,
    team: thisWeek.team,
    total: thisWeek.response + thisWeek.coverage + thisWeek.engagement + thisWeek.proactive + thisWeek.team,
  };
  const lastTotal = lastWeek.response + lastWeek.coverage + lastWeek.engagement + lastWeek.proactive + lastWeek.team;
  const deltas = {
    response: thisWeek.response - lastWeek.response,
    coverage: thisWeek.coverage - lastWeek.coverage,
    engagement: thisWeek.engagement - lastWeek.engagement,
    proactive: thisWeek.proactive - lastWeek.proactive,
    team: thisWeek.team - lastWeek.team,
    total: scores.total - lastTotal,
  };

  const observaciones = [
    `Esta sem: ${thisWeek._raw.total_calls} calls, ${thisWeek._raw.errors} errores, ${thisWeek._raw.tools_distintas} tools distintas.`,
    `Proactive: ${thisWeek._raw.proactive_calls} mensajes mandados, ${thisWeek._raw.proactive_engaged} engaged por Isabel.`,
    `Tareas atrasadas al cierre: ${thisWeek._raw.tareas_atrasadas}.`,
    `Sem prev: ${lastWeek._raw.total_calls} calls, ${lastWeek._raw.errors} errores.`,
  ].join('\n');

  const cambio_propuesto = await proponerCambio({ semana: isoWeek(), scores, deltas, observaciones });

  const entry = {
    semana: isoWeek(),
    ts: new Date().toISOString(),
    score: scores.total,
    subscores: scores,
    deltas,
    raw: thisWeek._raw,
    raw_prev: lastWeek._raw,
    observaciones,
    cambio_propuesto,
    implementado: false,
  };

  const data = load();
  // Si ya hay un grade de esta semana, lo reemplazamos
  const idx = data.findIndex((g) => g.semana === entry.semana);
  if (idx >= 0) data[idx] = entry;
  else data.push(entry);
  save(data);

  return entry;
}

export function listSelfGrades({ limit = 12 } = {}) {
  return load().slice(-limit).reverse();
}

export function markGradeImplemented(semana) {
  const data = load();
  const g = data.find((x) => x.semana === semana);
  if (!g) throw new Error(`semana ${semana} no existe`);
  g.implementado = true;
  g.implementado_ts = new Date().toISOString();
  save(data);
  return g;
}

export function getLatestGrade() {
  const data = load();
  return data.length ? data[data.length - 1] : null;
}
