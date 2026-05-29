import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const WIKI_FILE = join(DATA_DIR, 'isabel_wiki.json');
const HISTORY_FILE = join(DATA_DIR, 'conversation.json');
const SEASON_FILE = join(DATA_DIR, 'season.json');
const ACTIVITY_FILE = join(DATA_DIR, 'activity.json');
const OUTBOUND_FILE = join(DATA_DIR, 'outbound_queue.json');
const PROACTIVE_FILE = join(DATA_DIR, 'proactive_counter.json');

// NOTA: esto guarda en un archivo JSON en el disco del servidor.
// Funciona perfecto para una sola usuaria. En Railway el disco se
// reinicia al re-desplegar — para memoria 100% permanente, conecta
// un volumen de Railway o una base de datos (ver README).

function load(file, fallback) {
  try {
    if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    /* archivo corrupto o vacío — usamos el fallback */
  }
  return fallback;
}

function save(file, data) {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
  writeFileSync(file, JSON.stringify(data, null, 2));
}

// ---- Isabel Wiki: memoria de largo plazo compartida ----
export function getWiki() {
  return load(WIKI_FILE, { notas: [], perfil: {}, actualizado: null });
}

export function remember(nota) {
  const wiki = getWiki();
  wiki.notas.unshift({ nota, fecha: new Date().toISOString() });
  wiki.notas = wiki.notas.slice(0, 100); // máximo 100 notas recientes
  wiki.actualizado = new Date().toISOString();
  save(WIKI_FILE, wiki);
  return wiki;
}

// Borra entradas de la wiki que matcheen el query (substring, case-insensitive).
// Devuelve cuántas se borraron.
export function forget(query) {
  const wiki = getWiki();
  const q = String(query || '').toLowerCase().trim();
  if (!q) return { borradas: 0, restantes: wiki.notas.length };
  const before = wiki.notas.length;
  wiki.notas = wiki.notas.filter((n) => !n.nota.toLowerCase().includes(q));
  wiki.actualizado = new Date().toISOString();
  save(WIKI_FILE, wiki);
  return { borradas: before - wiki.notas.length, restantes: wiki.notas.length };
}

// Devuelve un listado plano de lo que Athena recuerda (para Isabel).
export function listMemories(limit = 30) {
  const wiki = getWiki();
  return wiki.notas.slice(0, limit);
}

// ---- "Temporada actual" — el foco de Isabel ahora mismo. 1-2 frases. ----
export function getSeason() {
  return load(SEASON_FILE, { texto: '', actualizado: null });
}

export function setSeason(texto) {
  const s = { texto: String(texto || '').trim(), actualizado: new Date().toISOString() };
  save(SEASON_FILE, s);
  return s;
}

// Lee tareas directo del disco (sin importar tasks.js) para evitar
// el ciclo tasks ↔ memory. Genera la vista corta inline.
const TASKS_FILE_PATH = join(DATA_DIR, 'tasks.json');
function readTasksFile() {
  try {
    if (existsSync(TASKS_FILE_PATH)) return JSON.parse(readFileSync(TASKS_FILE_PATH, 'utf8'));
  } catch {
    /* corrupt or missing — return [] */
  }
  return [];
}
function tasksContextInline() {
  const all = readTasksFile().filter((t) => t.status !== 'lista' && t.status !== 'cancelada');
  if (!all.length) return '';
  const groups = { athena: [], isabel: [], sami: [] };
  const tz = process.env.TIMEZONE || 'America/Los_Angeles';
  for (const t of all) {
    if (!groups[t.responsable]) continue;
    const due = t.vence
      ? ` (vence ${new Date(t.vence).toLocaleString('es-MX', { timeZone: tz, month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })})`
      : '';
    const pri = t.prioridad === 'alta' ? '★ ' : '';
    groups[t.responsable].push(`[${t.id}] ${pri}${t.descripcion}${due}`);
  }
  const lines = [];
  for (const owner of ['athena', 'isabel', 'sami']) {
    if (!groups[owner].length) continue;
    lines.push(`${owner.toUpperCase()} (${groups[owner].length}):\n${groups[owner].map((s) => `  - ${s}`).join('\n')}`);
  }
  return `TAREAS ACTIVAS — tu cola actual:\n${lines.join('\n')}`;
}

export function buildWikiContext() {
  const season = getSeason();
  const wiki = getWiki();
  const pending = getPendingOutbound();
  const parts = [];
  if (season.texto) parts.push(`TEMPORADA ACTUAL (en qué está enfocada Isabel ahora): ${season.texto}`);
  if (wiki.notas.length) {
    parts.push(wiki.notas.slice(0, 25).map((n) => `- ${n.nota}`).join('\n'));
  }
  const tasksCtx = tasksContextInline();
  if (tasksCtx) parts.push(tasksCtx);
  if (pending.length) {
    const items = pending.map((p) => {
      if (p.type === 'email') return `- [${p.id}] EMAIL a ${p.para} · asunto: "${p.asunto}"`;
      if (p.type === 'sms') return `- [${p.id}] SMS a ${p.para}`;
      return `- [${p.id}] ${p.type}`;
    }).join('\n');
    parts.push(`BORRADORES PENDIENTES DE CONFIRMACIÓN (NO se han mandado — Isabel debe decir "envía" o "sí"):\n${items}`);
  }
  return parts.join('\n\n');
}

// ---- Historial de la conversación de WhatsApp con Athena ----
// La API de Anthropic es sin estado: hay que mandarle el historial
// completo cada vez. Lo guardamos aquí entre mensajes.
export function getHistory() {
  return load(HISTORY_FILE, []);
}

export function saveHistory(messages) {
  // Guardamos solo los últimos 40 turnos para no crecer sin límite.
  save(HISTORY_FILE, messages.slice(-40));
}

// ---- Audit log: TODA acción de Athena queda registrada ----
// Esto da trazabilidad ("¿qué hiciste hoy en mi nombre, Athena?") y
// es el backbone del comando /historial.
export function logActivity({ tool, input_summary, result_summary }) {
  const log = load(ACTIVITY_FILE, []);
  log.unshift({
    ts: new Date().toISOString(),
    tool,
    input_summary: String(input_summary || '').slice(0, 200),
    result_summary: String(result_summary || '').slice(0, 200),
  });
  save(ACTIVITY_FILE, log.slice(0, 500));
}

export function getActivity(sinceIso = null) {
  const log = load(ACTIVITY_FILE, []);
  if (!sinceIso) return log;
  return log.filter((e) => e.ts >= sinceIso);
}

// ---- Cola de envíos pendientes de confirmación ----
// Toda comunicación a TERCEROS (email + SMS a clientes) entra aquí.
// Solo se manda después de que Isabel diga "envía"/"sí".
// mensaje_a_sami NO usa esta cola porque Sami es humano-en-el-loop.
export function queueOutbound(item) {
  const queue = load(OUTBOUND_FILE, []);
  const id = `q${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
  queue.push({ id, ts: new Date().toISOString(), ...item });
  save(OUTBOUND_FILE, queue);
  return id;
}

export function getPendingOutbound() {
  return load(OUTBOUND_FILE, []);
}

export function popOutbound(id = null) {
  const queue = load(OUTBOUND_FILE, []);
  if (!queue.length) return null;
  let item;
  if (id) {
    const idx = queue.findIndex((q) => q.id === id);
    if (idx < 0) return null;
    [item] = queue.splice(idx, 1);
  } else {
    // Sin id → toma el último (el que acaba de redactarse, más probable).
    item = queue.pop();
  }
  save(OUTBOUND_FILE, queue);
  return item;
}

export function clearOutbound() {
  const queue = load(OUTBOUND_FILE, []);
  const n = queue.length;
  save(OUTBOUND_FILE, []);
  return n;
}

// ---- Contador diario de mensajes proactivos (para el rate-limit) ----
// Resetea automáticamente cada día.
export function getProactiveCount(dayKey) {
  const data = load(PROACTIVE_FILE, { day: null, count: 0 });
  if (data.day !== dayKey) return 0;
  return data.count;
}

export function bumpProactiveCount(dayKey) {
  const data = load(PROACTIVE_FILE, { day: null, count: 0 });
  if (data.day !== dayKey) {
    save(PROACTIVE_FILE, { day: dayKey, count: 1 });
    return 1;
  }
  data.count += 1;
  save(PROACTIVE_FILE, data);
  return data.count;
}
