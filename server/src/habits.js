// ============================================================
//  Habits tracker — el lado personal de Athena
//  ──────────────────────────────────────────
//  Isabel tiene metas concretas: 168 lbs target (de 178),
//  110 g protein/día, 80 oz water, workout 4×/semana. Hasta
//  ahora las coaches (Carmen / Rivera / Sofía) opinaban
//  basado en el wiki — sin datos reales.
//
//  Este módulo cierra el loop:
//   1. Isabel registra (peso, agua, comida, workout, sueño,
//      ánimo) por WhatsApp, voz o tool calls.
//   2. Athena agrega a su contexto la racha actual + promedio
//      vs meta + tendencia.
//   3. Cuando consulta Carmen/Rivera/Sofía, las coaches ven
//      los datos REALES y pueden coachear con verdad.
//   4. Morning brief / evening check-in incluyen el estado
//      de hábitos del día.
//
//  Sin datos: coaches genéricas. Con datos: cambio real.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'habits.json');

// Tipos de hábitos válidos + sus metas/configuración
export const HABIT_TYPES = {
  peso: { unidad: 'lbs', meta: 168, direccion: 'bajar', frecuencia: 'diario' },
  agua: { unidad: 'oz', meta: 80, direccion: 'meta', frecuencia: 'diario' },
  proteina: { unidad: 'g', meta: 110, direccion: 'meta', frecuencia: 'diario' },
  workout: { unidad: 'sesion', meta: 4, direccion: 'semanal', frecuencia: 'semanal' },
  sueno: { unidad: 'hrs', meta: 8, direccion: 'meta', frecuencia: 'diario' },
  animo: { unidad: '1-10', meta: 7, direccion: 'subir', frecuencia: 'diario' },
  energia: { unidad: '1-10', meta: 7, direccion: 'subir', frecuencia: 'diario' },
};

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
  writeFileSync(FILE, JSON.stringify(data.slice(-2000), null, 2));
}

function newId() {
  return `h_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
}

function dayKey(d = new Date()) {
  return d.toISOString().slice(0, 10);
}

// ─── Log ───
export function logHabit({ tipo, valor, dia = null, nota = '', detalle = null }) {
  if (!HABIT_TYPES[tipo]) return { ok: false, error: `Tipo desconocido. Usa: ${Object.keys(HABIT_TYPES).join(', ')}` };
  if (valor === undefined || valor === null) return { ok: false, error: 'Falta valor.' };
  const cfg = HABIT_TYPES[tipo];
  const data = load();
  const d = dia || dayKey();
  const entry = {
    id: newId(),
    tipo,
    valor: Number(valor),
    unidad: cfg.unidad,
    dia: d,
    nota: String(nota).slice(0, 200),
    detalle: detalle || null,
    ts: new Date().toISOString(),
  };
  // Para hábitos diarios excepto agua y proteína (que son cumulativos),
  // reemplazar si ya hay uno del mismo tipo+día.
  const CUMULATIVE = ['agua', 'proteina'];
  if (!CUMULATIVE.includes(tipo)) {
    const idx = data.findIndex((e) => e.tipo === tipo && e.dia === d);
    if (idx >= 0) {
      data[idx] = { ...entry, id: data[idx].id, reemplazado: true };
      save(data);
      return { ok: true, entry: data[idx] };
    }
  }
  data.push(entry);
  save(data);
  return { ok: true, entry };
}

// Para agua/proteina, sumar el día (cumulativo).
export function dayTotal(tipo, dia = null) {
  const d = dia || dayKey();
  return load()
    .filter((e) => e.tipo === tipo && e.dia === d)
    .reduce((sum, e) => sum + e.valor, 0);
}

// Para peso/sueño/etc., último valor del día.
export function dayValue(tipo, dia = null) {
  const d = dia || dayKey();
  const matches = load().filter((e) => e.tipo === tipo && e.dia === d);
  if (!matches.length) return null;
  return matches[matches.length - 1].valor;
}

// ─── Stats ───
export function recentValues(tipo, dias = 7) {
  const cutoff = Date.now() - dias * 86_400_000;
  return load()
    .filter((e) => e.tipo === tipo && new Date(e.ts).getTime() >= cutoff)
    .sort((a, b) => a.dia.localeCompare(b.dia));
}

export function statsForType(tipo, dias = 7) {
  const CUMULATIVE = ['agua', 'proteina'];
  const days = [];
  for (let i = dias - 1; i >= 0; i--) {
    const d = dayKey(new Date(Date.now() - i * 86_400_000));
    if (CUMULATIVE.includes(tipo)) {
      const total = dayTotal(tipo, d);
      if (total > 0) days.push({ dia: d, valor: total });
    } else {
      const v = dayValue(tipo, d);
      if (v !== null) days.push({ dia: d, valor: v });
    }
  }
  if (!days.length) return null;
  const valores = days.map((d) => d.valor);
  const avg = valores.reduce((a, b) => a + b, 0) / valores.length;
  return {
    tipo,
    dias_con_data: days.length,
    promedio: Math.round(avg * 10) / 10,
    minimo: Math.min(...valores),
    maximo: Math.max(...valores),
    ultimo: days[days.length - 1].valor,
    days,
  };
}

// Racha: cuántos días consecutivos cumpliendo la meta.
export function getStreak(tipo) {
  const cfg = HABIT_TYPES[tipo];
  if (!cfg || cfg.frecuencia === 'semanal') return null;
  const data = load().filter((e) => e.tipo === tipo);
  if (!data.length) return 0;
  const CUMULATIVE = ['agua', 'proteina'];
  let streak = 0;
  for (let i = 0; i < 365; i++) {
    const d = dayKey(new Date(Date.now() - i * 86_400_000));
    const v = CUMULATIVE.includes(tipo) ? dayTotal(tipo, d) : dayValue(tipo, d);
    if (v === null || v === 0) break;
    let met;
    if (cfg.direccion === 'meta' || cfg.direccion === 'subir') met = v >= cfg.meta;
    else if (cfg.direccion === 'bajar') met = v <= cfg.meta;
    else met = true;
    if (met) streak++;
    else break;
  }
  return streak;
}

// Workouts por semana actual
export function weeklyWorkouts() {
  const cutoff = Date.now() - 7 * 86_400_000;
  return load().filter((e) => e.tipo === 'workout' && new Date(e.ts).getTime() >= cutoff).length;
}

// ─── Contexto para Athena ───
// Snapshot 1-2 líneas para contexto base de cada turno.
export function buildHabitsInline() {
  const parts = [];
  const peso = dayValue('peso') || (statsForType('peso', 7)?.ultimo);
  if (peso) {
    const dist = peso - 168;
    parts.push(`peso ${peso}lbs (${dist > 0 ? '+' : ''}${Math.round(dist * 10) / 10} de meta)`);
  }
  const aguaHoy = dayTotal('agua');
  if (aguaHoy > 0) parts.push(`agua ${aguaHoy}/80oz`);
  const protHoy = dayTotal('proteina');
  if (protHoy > 0) parts.push(`prot ${protHoy}/110g`);
  const wks = weeklyWorkouts();
  if (wks > 0) parts.push(`workouts ${wks}/4 semana`);
  const sueno = dayValue('sueno');
  if (sueno) parts.push(`sueño ${sueno}h`);
  if (!parts.length) return '';
  return parts.join(' · ');
}

// Bloque más rico para morning brief / evening check-in.
export function buildHabitsBriefingBlock() {
  const peso7 = statsForType('peso', 7);
  const agua7 = statsForType('agua', 7);
  const prot7 = statsForType('proteina', 7);
  const wks = weeklyWorkouts();
  const sueno7 = statsForType('sueno', 7);
  if (!peso7 && !agua7 && !prot7 && !wks && !sueno7) return null;
  const lines = ['🌱 HÁBITOS — últimos 7 días'];
  if (peso7) {
    const trend = peso7.days.length >= 3
      ? (peso7.days[peso7.days.length - 1].valor - peso7.days[0].valor)
      : null;
    const trendStr = trend == null ? '' : ` (${trend > 0 ? '+' : ''}${Math.round(trend * 10) / 10} en ${peso7.dias_con_data}d)`;
    lines.push(`Peso: último ${peso7.ultimo}lbs · meta 168${trendStr}`);
  }
  if (agua7) {
    const dias80 = agua7.days.filter((d) => d.valor >= 80).length;
    lines.push(`Agua: ${dias80}/${agua7.dias_con_data}d con meta 80oz (promedio ${agua7.promedio}oz)`);
  }
  if (prot7) {
    const dias110 = prot7.days.filter((d) => d.valor >= 110).length;
    lines.push(`Proteína: ${dias110}/${prot7.dias_con_data}d con meta 110g (promedio ${prot7.promedio}g)`);
  }
  lines.push(`Workouts semana: ${wks}/4`);
  if (sueno7) lines.push(`Sueño promedio: ${sueno7.promedio}h`);
  // Rachas
  const rachas = [];
  for (const tipo of ['agua', 'proteina', 'sueno']) {
    const s = getStreak(tipo);
    if (s >= 3) rachas.push(`${tipo} ${s}d 🔥`);
  }
  if (rachas.length) lines.push(`Rachas: ${rachas.join(' · ')}`);
  return lines.join('\n');
}

// Para inyectar en coaches Carmen / Rivera / Sofía cuando son consultadas.
export function buildHabitsForCoach(coachId) {
  const block = buildHabitsBriefingBlock();
  if (!block) return '';
  // Carmen necesita más: comidas, agua, proteína, peso
  // Rivera necesita: workouts, energía, sueño
  // Sofía necesita: sueño, energía, ánimo, peso
  // Por ahora les damos el block completo — pueden ignorar lo que no aplica.
  return `\n\nDATOS REALES DE LOS HÁBITOS DE ISABEL (últimos 7 días):\n${block}\n\nUsa estos datos para coachear con la verdad, no con suposiciones. Si los datos contradicen lo que ella dice ("me siento bien" pero promedio sueño 5h), llámaselo con cariño.`;
}
