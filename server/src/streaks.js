// ───────────────────────────────────────────────────────────────────
//  Streaks — días/semanas consecutivos con actividad relevante.
//
//  Calculados on-demand (no persistidos) desde los datos crudos:
//   - journal_streak: días consecutivos con ≥1 entrada de journal
//   - workout_streak: días consecutivos con workout logged en habits
//   - rapport_streak: semanas consecutivas con rapport registrado
//   - water_streak: días consecutivos con ≥1 oz de agua loggeado
//
//  Motivación honesta — solo muestra el streak si existe. No regaña
//  si está roto. La idea es celebrar continuidad, no avergonzar lapsos.
// ───────────────────────────────────────────────────────────────────

import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');

function loadJsonSafe(file, fallback) {
  try { if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8')); }
  catch { /* ignore */ }
  return fallback;
}

// Convierte iso → YYYY-MM-DD en TZ de Isabel
function dayKey(iso) {
  const tz = process.env.TIMEZONE || 'America/Los_Angeles';
  try {
    return new Date(iso).toLocaleDateString('en-CA', { timeZone: tz });
  } catch { return null; }
}

function todayKey() {
  const tz = process.env.TIMEZONE || 'America/Los_Angeles';
  return new Date().toLocaleDateString('en-CA', { timeZone: tz });
}

// Dado un set de "días con actividad", calcula streak terminando HOY
// o AYER (si hoy aún no hay actividad pero ayer sí).
function streakFromDays(daysSet) {
  if (!daysSet.size) return 0;
  const today = todayKey();
  const yesterday = (() => {
    const d = new Date(today + 'T12:00:00');
    d.setDate(d.getDate() - 1);
    return d.toISOString().slice(0, 10);
  })();

  // Empezamos desde hoy si hay actividad, o desde ayer si no.
  let cursor;
  if (daysSet.has(today)) cursor = today;
  else if (daysSet.has(yesterday)) cursor = yesterday;
  else return 0;

  let streak = 0;
  while (true) {
    if (!daysSet.has(cursor)) break;
    streak += 1;
    const d = new Date(cursor + 'T12:00:00');
    d.setDate(d.getDate() - 1);
    cursor = d.toISOString().slice(0, 10);
  }
  return streak;
}

export function journalStreak() {
  const data = loadJsonSafe(join(DATA_DIR, 'journal.json'), []);
  const days = new Set();
  for (const e of data) {
    const d = e.dia || dayKey(e.ts);
    if (d) days.add(d);
  }
  return streakFromDays(days);
}

export function workoutStreak() {
  const data = loadJsonSafe(join(DATA_DIR, 'habits.json'), []);
  const days = new Set();
  for (const e of data) {
    if (e.tipo === 'workout') {
      const d = e.dia || dayKey(e.ts);
      if (d) days.add(d);
    }
  }
  return streakFromDays(days);
}

export function waterStreak() {
  const data = loadJsonSafe(join(DATA_DIR, 'habits.json'), []);
  const days = new Set();
  for (const e of data) {
    if (e.tipo === 'agua' && Number(e.valor) > 0) {
      const d = e.dia || dayKey(e.ts);
      if (d) days.add(d);
    }
  }
  return streakFromDays(days);
}

export function rapportStreak() {
  // Streak por SEMANAS ISO. Una semana cuenta si hay ≥1 rapport.
  const data = loadJsonSafe(join(DATA_DIR, 'rapport.json'), []);
  const weeks = new Set((data || []).map((r) => r.semana).filter(Boolean));
  if (!weeks.size) return 0;
  // Calcula semana actual y va hacia atrás.
  const now = new Date();
  function isoWeek(d) {
    const x = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const dayNum = x.getUTCDay() || 7;
    x.setUTCDate(x.getUTCDate() + 4 - dayNum);
    const yearStart = new Date(Date.UTC(x.getUTCFullYear(), 0, 1));
    const weekNum = Math.ceil(((x - yearStart) / 86_400_000 + 1) / 7);
    return `${x.getUTCFullYear()}-W${String(weekNum).padStart(2, '0')}`;
  }
  let streak = 0;
  const cursor = new Date(now);
  if (!weeks.has(isoWeek(cursor))) {
    // Si esta semana NO está, intenta semana anterior
    cursor.setDate(cursor.getDate() - 7);
    if (!weeks.has(isoWeek(cursor))) return 0;
  }
  while (weeks.has(isoWeek(cursor))) {
    streak += 1;
    cursor.setDate(cursor.getDate() - 7);
  }
  return streak;
}

export function allStreaks() {
  return {
    journal: journalStreak(),
    workout: workoutStreak(),
    water: waterStreak(),
    rapport: rapportStreak(),
  };
}
