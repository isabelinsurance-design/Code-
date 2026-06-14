// ============================================================
//  Routines — rutinas multi-paso recurrentes
//  ─────────────────────────────────────────
//  Diferente a tasks (cosas una vez) y cron (Athena propio).
//  Esto son LAS rutinas de Isabel: morning ritual, meal prep
//  semanal, recording day, monthly admin, etc.
//
//  Cada rutina:
//    - Multi-step (3-7 pasos típicos)
//    - Recurrence (diario / semanal específico día / mensual)
//    - Hora de inicio (Athena recuerda)
//    - Tracking de completion por paso
//
//  Athena al inicio de la rutina: ping con primer paso.
//  Isabel responde "listo" / "skip" → Athena pinga el siguiente.
//  Al final: registra completion + felicita.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const ROUTINES_FILE = join(DATA_DIR, 'routines.json');
const COMPLETIONS_FILE = join(DATA_DIR, 'routine_completions.json');

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function loadR() { try { if (existsSync(ROUTINES_FILE)) return JSON.parse(readFileSync(ROUTINES_FILE, 'utf8')); } catch {} return []; }
function saveR(d) { ensureDir(); atomicWriteJson(ROUTINES_FILE, d.slice(-100)); }
function loadC() { try { if (existsSync(COMPLETIONS_FILE)) return JSON.parse(readFileSync(COMPLETIONS_FILE, 'utf8')); } catch {} return []; }
function saveC(d) { ensureDir(); atomicWriteJson(COMPLETIONS_FILE, d.slice(-500)); }
function newId(prefix) { return `${prefix}_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

export function crearRutina({ nombre, pasos, recurrencia, hora_inicio = null }) {
  if (!nombre || !Array.isArray(pasos) || !pasos.length) return { ok: false, error: 'Falta nombre o pasos.' };
  const data = loadR();
  const entry = {
    id: newId('rt'),
    nombre: String(nombre).slice(0, 80),
    pasos: pasos.map((p) => String(p).slice(0, 200)),
    recurrencia, // 'diaria' | 'lunes' | 'L-V' | 'sabado' | 'mensual_dia_1' | 'libre'
    hora_inicio, // 'HH:MM' opcional
    activa: true,
    creado: new Date().toISOString(),
  };
  data.push(entry);
  saveR(data);
  return { ok: true, rutina: entry };
}

export function listarRutinas({ activas_solo = true } = {}) {
  return loadR().filter((r) => !activas_solo || r.activa);
}

export function desactivarRutina(id) {
  const data = loadR();
  const i = data.findIndex((r) => r.id === id);
  if (i < 0) return null;
  data[i].activa = false;
  saveR(data);
  return data[i];
}

// Registra que Isabel completó un paso (o saltó)
export function registrarPaso({ rutina_id, paso_idx, accion = 'completado', nota = '' }) {
  const c = loadC();
  const entry = {
    id: newId('rc'),
    rutina_id,
    paso_idx,
    accion, // completado | saltado
    nota: String(nota).slice(0, 200),
    ts: new Date().toISOString(),
    dia: new Date().toISOString().slice(0, 10),
  };
  c.push(entry);
  saveC(c);
  return entry;
}

// ¿Cuántos pasos completó hoy de una rutina?
export function progresoHoy(rutina_id) {
  const dia = new Date().toISOString().slice(0, 10);
  return loadC().filter((c) => c.rutina_id === rutina_id && c.dia === dia);
}

// ¿Qué rutinas tocan hoy?
export function rutinasDeHoy() {
  const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
  const dow = new Intl.DateTimeFormat('en-US', { timeZone: TZ, weekday: 'short' }).format(new Date());
  const dayMap = { Sun: 'domingo', Mon: 'lunes', Tue: 'martes', Wed: 'miércoles', Thu: 'jueves', Fri: 'viernes', Sat: 'sabado' };
  const today = dayMap[dow];
  const dia = new Date().getDate();

  return listarRutinas().filter((r) => {
    if (r.recurrencia === 'diaria') return true;
    if (r.recurrencia === 'L-V' && ['lunes', 'martes', 'miércoles', 'jueves', 'viernes'].includes(today)) return true;
    if (r.recurrencia === today) return true; // 'lunes', 'sabado', etc.
    if (r.recurrencia?.startsWith('mensual_dia_')) {
      const targetDay = parseInt(r.recurrencia.split('_').pop(), 10);
      return dia === targetDay;
    }
    return false;
  });
}

export function buildRoutinesInline() {
  const today = rutinasDeHoy();
  if (!today.length) return '';
  // Cuáles ya tienen progreso hoy
  const stats = today.map((r) => {
    const done = progresoHoy(r.id).filter((c) => c.accion === 'completado').length;
    return `${r.nombre} ${done}/${r.pasos.length}`;
  });
  return `rutinas hoy: ${stats.join(' · ')}`;
}

export function buildRoutinesBriefingBlock() {
  const today = rutinasDeHoy();
  if (!today.length) return null;
  const lines = ['🔁 RUTINAS DE HOY'];
  for (const r of today) {
    const done = progresoHoy(r.id).filter((c) => c.accion === 'completado').length;
    const status = done === r.pasos.length ? '✓' : done > 0 ? `${done}/${r.pasos.length}` : 'pendiente';
    lines.push(`  · ${r.nombre} ${r.hora_inicio ? `(${r.hora_inicio})` : ''} — ${status}`);
  }
  return lines.join('\n');
}
