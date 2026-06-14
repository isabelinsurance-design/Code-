// ============================================================
//  Focus Blocks — tiempo protegido para joy / hobby / deep work
//  ────────────────────────────────────────────────────────────
//  Isabel quiere leer un libro, tocar piano, grabar para YouTube.
//  Hoy Athena la interrumpe con notificaciones todo el día. Mal.
//
//  Focus blocks = ventanas de tiempo (recurrentes o ad-hoc) en
//  las que Athena:
//    - silencio: cero mensajes proactivos
//    - lectura: igual + responde corto si Isabel pregunta
//    - recording: silencio total + queue para después
//
//  Lo importante: NO sumar carga durante focus. Lo que pasa
//  queda en cola y se entrega cuando el bloque cierra.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'focus_blocks.json');

export const MODOS = ['silencio', 'lectura', 'recording', 'piano', 'gym'];

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() { try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {} return []; }
function save(d) { ensureDir(); atomicWriteJson(FILE, d.slice(-200)); }
function newId() { return `fb_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

// Crear bloque. recurrencia opcional: 'dias' (lun-mar-mié) o 'semanal' (cada lun)
export function crearBloque({ titulo, inicio_hhmm, fin_hhmm, dias_semana = null, modo = 'silencio', notas = '' }) {
  if (!titulo || !inicio_hhmm || !fin_hhmm) return { ok: false, error: 'Falta título / inicio / fin.' };
  if (!MODOS.includes(modo)) return { ok: false, error: `Modo inválido. Usa: ${MODOS.join(', ')}` };
  const data = load();
  const entry = {
    id: newId(),
    titulo: String(titulo).slice(0, 80),
    inicio_hhmm, // "19:00"
    fin_hhmm,    // "21:00"
    dias_semana: Array.isArray(dias_semana) ? dias_semana : [0, 1, 2, 3, 4, 5, 6], // todos por default
    modo,
    notas: String(notas).slice(0, 200),
    activo: true,
    creado: new Date().toISOString(),
  };
  data.push(entry);
  save(data);
  return { ok: true, bloque: entry };
}

export function listarBloques({ activos_solo = true } = {}) {
  return load().filter((b) => !activos_solo || b.activo);
}

export function desactivarBloque(id) {
  const data = load();
  const i = data.findIndex((b) => b.id === id);
  if (i < 0) return null;
  data[i].activo = false;
  data[i].desactivado = new Date().toISOString();
  save(data);
  return data[i];
}

// ¿Estamos en focus block AHORA?
export function bloqueActual() {
  const now = new Date();
  const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
  // Hora local en formato HH:MM
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false, weekday: 'short',
  });
  const parts = fmt.formatToParts(now);
  const h = parseInt(parts.find((p) => p.type === 'hour').value, 10);
  const m = parseInt(parts.find((p) => p.type === 'minute').value, 10);
  const wd = parts.find((p) => p.type === 'weekday').value;
  const dayMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  const dow = dayMap[wd];
  const minOfDay = h * 60 + m;

  for (const b of listarBloques()) {
    if (!b.dias_semana.includes(dow)) continue;
    const [sh, sm] = b.inicio_hhmm.split(':').map(Number);
    const [eh, em] = b.fin_hhmm.split(':').map(Number);
    const s = sh * 60 + sm;
    const e = eh * 60 + em;
    if (minOfDay >= s && minOfDay < e) {
      return b;
    }
  }
  return null;
}

// ¿Athena debería estar callada ahora?
export function enSilencio() {
  const b = bloqueActual();
  if (!b) return null;
  return ['silencio', 'recording', 'piano'].includes(b.modo) ? b : null;
}

// Snapshot inline para contexto base
export function buildFocusInline() {
  const b = bloqueActual();
  if (!b) return '';
  return `🛡️ EN FOCUS BLOCK: "${b.titulo}" (${b.modo}) hasta ${b.fin_hhmm} — NO LE SUMES CARGA, ella está protegida`;
}

// Para morning brief: lista bloques de hoy
export function bloquesDeHoy() {
  const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
  const now = new Date();
  const dow = new Intl.DateTimeFormat('en-US', { timeZone: TZ, weekday: 'short' }).format(now);
  const dayMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  const today = dayMap[dow];
  return listarBloques().filter((b) => b.dias_semana.includes(today));
}

// Briefing block: si hay bloques hoy, lístalos para que Isabel los vea.
export function buildFocusBriefingBlock() {
  const bloques = bloquesDeHoy();
  if (!bloques.length) return null;
  const lines = ['🛡️ FOCUS BLOCKS DE HOY'];
  for (const b of bloques) {
    lines.push(`  · ${b.titulo} (${b.modo}) ${b.inicio_hhmm}–${b.fin_hhmm}`);
  }
  return lines.join('\n');
}

// Expón bloqueActual a proactive.js vía globalThis para que canSendProactive
// pueda hacer un check sync sin top-level imports circulares.
globalThis.__focusBlocksCheck = bloqueActual;
