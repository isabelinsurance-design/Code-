// ============================================================
//  After-Action Review (AAR) — el sistema que aprende
//  ───────────────────────────────────────────────────
//  Después de cada decisión significativa que Athena toma
//  (consulta a especialistas, borrador de comunicación, tarea
//  delegada, llamada que hace), se abre un AAR con:
//    - intended_outcome (qué se esperaba)
//    - actual_outcome (qué pasó realmente)
//    - gap (delta entre los dos)
//    - learning (qué hacer distinto la próxima vez)
//
//  Wharton: AAR no es debrief — es delta escrito que fuerza
//  aprendizaje. McKinsey lo identifica como behavior #8 de CoS
//  transformacionales.
//
//  Athena llama aar_abrir cuando toma una decisión significativa,
//  y aar_cerrar cuando puede evaluar el resultado (puede ser en
//  el mismo turno o días después). Los AARs abiertos se surface
//  en reflexión nocturna para cerrar lo que se quedó suelto.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'aar.json');

function ensureDir() {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
}

function load() {
  try {
    if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch { /* ignore */ }
  return [];
}

function save(data) {
  ensureDir();
  writeFileSync(FILE, JSON.stringify(data.slice(-300), null, 2));
}

function newId() {
  return `aar_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

// Tipos de decisiones que valen un AAR.
// (Filtra ruido — no toda tool call merece review.)
export const AAR_DECISION_TYPES = [
  'outreach',        // mandó email/SMS a cliente o lead
  'delegation',      // delegó algo a Sami / equipo
  'consult',         // consultó a un especialista
  'meeting',         // creó / movió / canceló cita
  'commitment',      // se comprometió a algo (say-do)
  'briefing',        // armó el briefing matutino
  'recommendation',  // hizo una recomendación significativa
  'call',            // hizo o contestó una llamada
];

// Abre un AAR — guarda la intención.
export function openDecision({ type, intended, target = '', context = '' }) {
  if (!AAR_DECISION_TYPES.includes(type)) {
    // Tipo no reconocido — no abrimos AAR, no es bug
    return null;
  }
  if (!intended) return null;
  const data = load();
  const entry = {
    id: newId(),
    type,
    intended: String(intended).slice(0, 400),
    target: String(target).slice(0, 100),
    context: String(context).slice(0, 200),
    opened_at: new Date().toISOString(),
    status: 'abierta',
  };
  data.push(entry);
  save(data);
  return entry;
}

// Cierra un AAR — graba qué pasó realmente.
export function closeDecision({ id, actual, gap = '', learning = '' }) {
  if (!id || !actual) return null;
  const data = load();
  const i = data.findIndex((d) => d.id === id);
  if (i < 0) return null;
  data[i] = {
    ...data[i],
    status: 'cerrada',
    actual: String(actual).slice(0, 400),
    gap: String(gap).slice(0, 300),
    learning: String(learning).slice(0, 300),
    closed_at: new Date().toISOString(),
  };
  save(data);
  return data[i];
}

export function listOpen() {
  return load().filter((d) => d.status === 'abierta');
}

export function listRecent({ limit = 20 } = {}) {
  const data = load();
  return data.slice(-limit).reverse();
}

// Aprendizajes acumulados (los gap/learning de las cerradas).
// Para usar en reflexión nocturna + briefing semanal.
export function recentLearnings({ limit = 10 } = {}) {
  return load()
    .filter((d) => d.status === 'cerrada' && d.learning)
    .slice(-limit)
    .reverse()
    .map((d) => ({
      type: d.type,
      target: d.target,
      gap: d.gap,
      learning: d.learning,
      closed_at: d.closed_at,
    }));
}

// Snapshot 1-línea para el contexto base.
export function buildAarInline() {
  const open = listOpen().length;
  const recent = recentLearnings({ limit: 3 });
  if (!open && !recent.length) return '';
  const parts = [];
  if (open) parts.push(`AARs abiertos: ${open}`);
  if (recent.length) {
    parts.push(`Últimos learnings: ${recent.map((l) => l.learning.slice(0, 60)).join(' | ')}`);
  }
  return parts.join(' · ');
}
