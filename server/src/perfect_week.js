// ============================================================
//  Perfect Week — template de cómo Isabel quiere que se vea
//  su semana ideal. Athena valida cada nueva cita contra esto.
//  ────────────────────────────────────────────────────────────
//  Pattern del Elite EA SOP. La idea: Isabel define UNA VEZ:
//    - Mañanas (6-10am): creative + workouts (cero meetings)
//    - Lunch: 12-12:30pm (sagrado)
//    - Tardes (2-5pm): meetings / client calls (ventana ideal)
//    - Evenings (5-9pm): family + personal
//    - Sábado/Dom: family — NO business
//
//  Cuando Athena va a crear cita o reagendar, valida contra
//  este template y avisa si choca. NO bloquea — solo señala
//  con explicación: "Esta cita cae en tu mañana protegida
//  (creative + workouts). ¿Confirmas?"
//
//  Es CONFIGURABLE — Isabel ajusta su perfect week vía tool
//  o vía UI en /configura.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'perfect_week.json');

// Default que matchea el SOP, adaptado a Isabel (PT/SoCal)
const DEFAULT_TEMPLATE = {
  // Cada slot: { dia: 0-6 (0=Dom), inicio: "HH:MM", fin: "HH:MM", tipo, etiqueta, prioridad }
  // prioridad: 'protegido' (no permitir sin override), 'preferido' (ideal), 'evitar' (último recurso)
  slots: [
    // L-V mañanas (6-10am) — creative + workouts, NO meetings
    ...[1,2,3,4,5].map((d) => ({ dia: d, inicio: '06:00', fin: '10:00', tipo: 'creative_workout', etiqueta: 'Mañana creativa + workout', prioridad: 'protegido' })),
    // L-V lunch (12-12:30) — sagrado
    ...[1,2,3,4,5].map((d) => ({ dia: d, inicio: '12:00', fin: '12:30', tipo: 'lunch', etiqueta: 'Almuerzo', prioridad: 'protegido' })),
    // L-V tardes (2-5pm) — ventana ideal de meetings
    ...[1,2,3,4,5].map((d) => ({ dia: d, inicio: '14:00', fin: '17:00', tipo: 'meetings', etiqueta: 'Ventana de meetings', prioridad: 'preferido' })),
    // L-V evenings (5-9pm) — family
    ...[1,2,3,4,5].map((d) => ({ dia: d, inicio: '17:00', fin: '21:00', tipo: 'family', etiqueta: 'Family/personal', prioridad: 'protegido' })),
    // Sábado completo — family
    { dia: 6, inicio: '00:00', fin: '23:59', tipo: 'family', etiqueta: 'Sábado familiar', prioridad: 'protegido' },
    // Domingo completo — family
    { dia: 0, inicio: '00:00', fin: '23:59', tipo: 'family', etiqueta: 'Domingo familiar', prioridad: 'protegido' },
  ],
  notas: 'Template inicial estilo Elite EA SOP. Ajusta vía tool actualizar_perfect_week o en /app/configura.',
  actualizado: new Date().toISOString(),
};

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() {
  try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {}
  return DEFAULT_TEMPLATE;
}
function save(t) { ensureDir(); atomicWriteJson(FILE, t); }

export function getPerfectWeek() { return load(); }

export function updatePerfectWeek(template) {
  if (!template || !Array.isArray(template.slots)) {
    return { ok: false, error: 'Template inválido — necesita slots array.' };
  }
  const t = { ...template, actualizado: new Date().toISOString() };
  save(t);
  return { ok: true, template: t };
}

export function resetToDefault() {
  save(DEFAULT_TEMPLATE);
  return DEFAULT_TEMPLATE;
}

// Recibe inicio + fin de evento propuesto (Date objects)
// Devuelve array de conflictos con slots del perfect week
export function validateEvent({ inicio, fin }) {
  const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
  if (!(inicio instanceof Date)) inicio = new Date(inicio);
  if (!(fin instanceof Date)) fin = new Date(fin);

  // Obtén día de semana + horas en TZ local
  const fmtDay = new Intl.DateTimeFormat('en-US', { timeZone: TZ, weekday: 'short' });
  const fmtTime = new Intl.DateTimeFormat('en-US', { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false });
  const dayMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  const startDay = dayMap[fmtDay.format(inicio)];
  const startTime = fmtTime.format(inicio).replace(/^24/, '00'); // edge case
  const endTime = fmtTime.format(fin).replace(/^24/, '00');

  const template = load();
  const conflicts = [];
  for (const slot of template.slots) {
    if (slot.dia !== startDay) continue;
    // Solapamiento de tiempo: [a,b) overlaps [c,d) iff a<d && c<b
    if (startTime < slot.fin && slot.inicio < endTime) {
      conflicts.push(slot);
    }
  }
  return conflicts;
}

// Helper: devuelve mensaje humano describiendo conflictos
export function describeConflicts(conflicts) {
  if (!conflicts.length) return null;
  const protegidos = conflicts.filter((c) => c.prioridad === 'protegido');
  const evitar = conflicts.filter((c) => c.prioridad === 'evitar');
  if (protegidos.length) {
    return `⚠️ Choca con tiempo PROTEGIDO de tu perfect week: "${protegidos[0].etiqueta}" (${protegidos[0].inicio}–${protegidos[0].fin}). ¿Confirmas o reagendamos?`;
  }
  if (evitar.length) {
    return `Cae en horario que prefieres evitar: "${evitar[0].etiqueta}". OK si es necesario.`;
  }
  return null;
}
