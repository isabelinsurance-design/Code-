// ============================================================
//  Cola de tareas de Athena
//  ─────────────────────────
//  Athena tiene su propia "lista de cosas pendientes": tareas que
//  ella maneja, tareas que Isabel quiere que le recuerden, y
//  tareas que Sami va a ejecutar. Cada cierto tiempo (taskTick)
//  Athena trabaja en lo suyo SIN avisarle a Isabel; cuando algo
//  vence para Isabel o Sami, la avisa (respetando quiet hours +
//  cap diario).
// ============================================================
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { atomicWriteJson } from './storage.js';
import { runDirectora } from './directora.js';
import { sendMessage } from './whatsapp.js';
import { canSendProactive } from './proactive.js';
import { bumpProactiveCount, logActivity } from './memory.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const TASKS_FILE = join(DATA_DIR, 'tasks.json');

const STATUSES = ['pendiente', 'en_progreso', 'lista', 'cancelada'];
const OWNERS = ['athena', 'isabel', 'sami'];
const PRIORITIES = ['alta', 'media', 'baja'];

function loadAll() {
  try {
    if (existsSync(TASKS_FILE)) return JSON.parse(readFileSync(TASKS_FILE, 'utf8'));
  } catch (e) {
    // Archivo corrupto ≠ archivo vacío — gritar, no tragar (AUDIT.md P1).
    console.error(`[tasks] tasks.json ilegible (${e.message}) — usando lista vacía. Hay backup horario en R2.`);
  }
  return [];
}

function saveAll(tasks) {
  atomicWriteJson(TASKS_FILE, tasks);
}

function newId() {
  return `t${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
}

function nowIso() {
  return new Date().toISOString();
}

// Acepta ISO, o un offset en días/horas desde ahora. Devuelve ISO o null.
// vence_en_dias defaultea a 9am en la TZ de Isabel (no UTC).
function parseDueDate({ vence, vence_en_horas, vence_en_dias }) {
  if (vence) {
    const d = new Date(vence);
    if (!isNaN(d.getTime())) return d.toISOString();
  }
  if (typeof vence_en_horas === 'number' && vence_en_horas > 0) {
    return new Date(Date.now() + vence_en_horas * 3600_000).toISOString();
  }
  if (typeof vence_en_dias === 'number' && vence_en_dias > 0) {
    return nineAmLocalInDays(vence_en_dias);
  }
  return null;
}

function nineAmLocalInDays(days) {
  const tz = process.env.TIMEZONE || 'America/Los_Angeles';
  // 1) Tomamos "hoy" expresado en la TZ destino.
  const todayInTz = new Intl.DateTimeFormat('en-CA', {
    timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date()); // "YYYY-MM-DD" en la TZ.
  const [y, m, d] = todayInTz.split('-').map(Number);
  // 2) Construimos 9am UTC del día objetivo y restamos el offset de TZ.
  const utcMidnight = Date.UTC(y, m - 1, d + days, 9, 0, 0);
  // Calculamos offset de la TZ para esa fecha exacta:
  const offsetMin = tzOffsetMinutes(tz, new Date(utcMidnight));
  return new Date(utcMidnight - offsetMin * 60_000).toISOString();
}

function tzOffsetMinutes(tz, atDate) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: tz, timeZoneName: 'longOffset',
  }).formatToParts(atDate);
  const offStr = parts.find((p) => p.type === 'timeZoneName')?.value || 'GMT+00:00';
  const m = offStr.match(/GMT([+-])(\d{1,2})(?::?(\d{2}))?/);
  if (!m) return 0;
  const sign = m[1] === '+' ? 1 : -1;
  return sign * (parseInt(m[2], 10) * 60 + (m[3] ? parseInt(m[3], 10) : 0));
}

// ---- CRUD ----
export function createTask(input) {
  const { descripcion, responsable = 'athena', prioridad = 'media', notas_iniciales = '' } = input;
  if (!descripcion || !String(descripcion).trim()) {
    throw new Error('Falta descripción de la tarea.');
  }
  if (!OWNERS.includes(responsable)) {
    throw new Error(`Responsable inválido. Usa: ${OWNERS.join(', ')}.`);
  }
  if (!PRIORITIES.includes(prioridad)) {
    throw new Error(`Prioridad inválida. Usa: ${PRIORITIES.join(', ')}.`);
  }
  const tasks = loadAll();
  const t = {
    id: newId(),
    descripcion: String(descripcion).trim(),
    responsable,
    prioridad,
    status: 'pendiente',
    vence: parseDueDate(input),
    notas: notas_iniciales ? [{ ts: nowIso(), texto: notas_iniciales }] : [],
    resultado: null,
    created_at: nowIso(),
    updated_at: nowIso(),
    reminded_at: null,
  };
  tasks.unshift(t);
  saveAll(tasks);
  return t;
}

export function listTasks({ status = null, responsable = null, limit = 100 } = {}) {
  const all = loadAll();
  return all
    .filter((t) => (status ? t.status === status : t.status !== 'cancelada' && t.status !== 'lista'))
    .filter((t) => (responsable ? t.responsable === responsable : true))
    .slice(0, limit);
}

export function getTask(id) {
  return loadAll().find((t) => t.id === id) || null;
}

function patchTask(id, patch) {
  const tasks = loadAll();
  const idx = tasks.findIndex((t) => t.id === id);
  if (idx < 0) return null;
  tasks[idx] = { ...tasks[idx], ...patch, updated_at: nowIso() };
  saveAll(tasks);
  return tasks[idx];
}

export function addTaskNote(id, texto) {
  const t = getTask(id);
  if (!t) return null;
  t.notas.push({ ts: nowIso(), texto: String(texto).trim() });
  return patchTask(id, { notas: t.notas });
}

export function completeTask(id, resultado = '') {
  return patchTask(id, { status: 'lista', resultado: String(resultado || '').trim() });
}

export function snoozeTask(id, opts) {
  const newDue = parseDueDate(opts);
  if (!newDue) throw new Error('Necesitas una nueva fecha (vence, vence_en_horas o vence_en_dias).');
  return patchTask(id, { vence: newDue, status: 'pendiente' });
}

export function cancelTask(id) {
  return patchTask(id, { status: 'cancelada' });
}

// ---- Vista corta para meter en buildWikiContext ----
export function buildTasksContext() {
  const active = listTasks();
  if (!active.length) return '';
  const groups = { athena: [], isabel: [], sami: [] };
  for (const t of active) {
    const due = t.vence ? ` (vence ${shortDate(t.vence)})` : '';
    const pri = t.prioridad === 'alta' ? '★ ' : '';
    groups[t.responsable].push(`[${t.id}] ${pri}${t.descripcion}${due}`);
  }
  const parts = [];
  for (const owner of OWNERS) {
    if (!groups[owner].length) continue;
    parts.push(`${owner.toUpperCase()} (${groups[owner].length}):\n${groups[owner].map((s) => `  - ${s}`).join('\n')}`);
  }
  return `TAREAS ACTIVAS — tu cola actual:\n${parts.join('\n')}`;
}

function shortDate(iso) {
  return new Date(iso).toLocaleString('es-MX', {
    timeZone: process.env.TIMEZONE || 'America/Los_Angeles',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

// ============================================================
//  TASK TICK — corre cada hora durante horas despiertas.
//  1) Si Athena tiene cosas pendientes propias, le da un "work
//     session" silencioso (sin mensaje a Isabel) para avanzar.
//  2) Si hay tareas de Isabel/Sami que vencen pronto, las recuerda
//     vía WhatsApp respetando quiet hours + cap diario.
// ============================================================
const REMIND_WINDOW_MS = 60 * 60 * 1000;       // dentro de 1h = "due"
const REREMIND_GAP_MS = 6 * 60 * 60 * 1000;    // no re-recordar antes de 6h

function isDueSoon(t) {
  if (!t.vence) return false;
  const due = new Date(t.vence).getTime();
  return due - Date.now() <= REMIND_WINDOW_MS;
}

function isRecentlyReminded(t) {
  if (!t.reminded_at) return false;
  return Date.now() - new Date(t.reminded_at).getTime() < REREMIND_GAP_MS;
}

export async function taskTick() {
  // ---- 1. Trabajo silencioso de Athena ----
  const athenaPending = listTasks({ status: 'pendiente', responsable: 'athena' });
  if (athenaPending.length) {
    // Toma máximo 2 tareas por sesión para acotar tokens.
    const batch = athenaPending.slice(0, 2);
    await runAthenaWorkSession(batch);
  }

  // ---- 2. Recordatorios a Isabel (respeta quiet/cap) ----
  const dueForIsabel = listTasks({ status: 'pendiente' })
    .filter((t) => t.responsable === 'isabel')
    .filter(isDueSoon)
    .filter((t) => !isRecentlyReminded(t));
  for (const t of dueForIsabel) {
    const gate = canSendProactive();
    if (!gate.ok) {
      console.log(`[tasks] no recordar "${t.descripcion}": ${gate.reason}`);
      break;
    }
    await sendIsabelReminder(t);
    patchTask(t.id, { reminded_at: nowIso() });
    bumpProactiveCount(gate.dayKey);
  }

  // ---- 3. Tareas de Sami que vencen: delegar (sin gate, Sami es human-in-loop) ----
  const dueForSami = listTasks({ status: 'pendiente' })
    .filter((t) => t.responsable === 'sami')
    .filter(isDueSoon)
    .filter((t) => !isRecentlyReminded(t));
  for (const t of dueForSami) {
    await sendSamiReminder(t);
    patchTask(t.id, { reminded_at: nowIso() });
  }
}

// Athena trabaja en sus tareas SIN historial de chat y SIN mandar
// mensaje a Isabel. Solo las herramientas tienen efecto (web_search,
// recordar, completar_tarea, redactar borradores, etc.)
async function runAthenaWorkSession(batch) {
  const lista = batch
    .map((t) => `- [${t.id}] ${t.descripcion}${t.notas.length ? ` (notas previas: ${t.notas.slice(-2).map((n) => n.texto).join(' | ')})` : ''}`)
    .join('\n');
  const synthetic = {
    role: 'user',
    content: `[TASK TICK AUTOMÁTICA — NO le respondas a Isabel] Estás trabajando entre conversaciones. Estas son las tareas que te tocan a TI mismo (Athena) y están pendientes:

${lista}

Para cada una: (1) intenta avanzar usando tus herramientas (web_search, consultar_especialistas, recordar). Si la tarea es "redactar email/SMS para X", crea el borrador con enviar_email/enviar_sms — se quedará en cola para que Isabel lo confirme cuando hable contigo. (2) Si terminaste, llama completar_tarea con el resultado conciso. (3) Si solo avanzaste, anota el progreso vía recordar y, si necesitas más tiempo, llama posponer_tarea (ej. vence_en_dias=1). (4) Si la tarea está fuera de tu alcance, pásala a Sami con crear_tarea({responsable:'sami', descripcion:...}) y cancela la tuya.

NO mandes mensaje a Isabel — esto es trabajo silencioso tuyo. Responde con un resumen interno corto de qué hiciste con cada tarea.`,
  };
  try {
    // Task tick es trabajo de fondo silencioso — decisiones simples sobre
    // qué hacer con cada tarea de la cola. Haiku basta. Sin tier='cheap'
    // esto consumía Sonnet 3x/día = uno de los gastos mayores del mes.
    await runDirectora([synthetic], { maxRounds: 4, persistHistory: false, tier: 'cheap' });
    logActivity({ tool: 'task_tick_work', input_summary: `tasks=${batch.map((b) => b.id).join(',')}`, result_summary: 'ok' });
  } catch (err) {
    console.error('[tasks] work session error:', err.message);
  }
}

async function sendIsabelReminder(t) {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) return;
  const dueText = t.vence ? ` Vencía: ${shortDate(t.vence)}.` : '';
  const ctx = t.notas.length ? ` Contexto: ${t.notas.slice(-1)[0].texto}.` : '';
  const msg = `Recordatorio: ${t.descripcion}.${dueText}${ctx}`;
  await sendMessage(to, msg);
  logActivity({ tool: 'task_remind_isabel', input_summary: t.id, result_summary: t.descripcion });
}

async function sendSamiReminder(t) {
  const to = process.env.SAMI_WHATSAPP;
  if (!to) return;
  const dueText = t.vence ? ` (para ${shortDate(t.vence)})` : '';
  const ctx = t.notas.length ? ` Contexto: ${t.notas.slice(-1)[0].texto}.` : '';
  const msg = `De Athena: tarea para Isabel — ${t.descripcion}${dueText}.${ctx}`;
  await sendMessage(to, msg);
  logActivity({ tool: 'task_remind_sami', input_summary: t.id, result_summary: t.descripcion });
}

// Permite probarlo a mano: `node src/tasks.js tick`
if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  const cmd = process.argv[2];
  if (cmd === 'tick') {
    await taskTick();
    process.exit(0);
  } else if (cmd === 'list') {
    console.log(JSON.stringify(listTasks(), null, 2));
    process.exit(0);
  } else {
    console.error('Uso: node src/tasks.js [tick|list]');
    process.exit(1);
  }
}
