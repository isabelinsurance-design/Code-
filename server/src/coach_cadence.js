// ============================================================
//  Coach Cadence — citas programadas con las coaches de Athena
//  ────────────────────────────────────────────────────────────
//  Isabel no quiere hablar con cada coach por capricho — quiere
//  una ESTRUCTURA. Carmen diaria (nutrición), Rivera 3x/sem (gym
//  days), Victoria semanal (OKRs), Maria cada 15d (pipeline), etc.
//
//  Este módulo:
//    1. Guarda cadencia por coach (diaria/semanal/mensual/etc).
//    2. Computa qué coaches "tocan hoy".
//    3. Genera prompts iniciales específicos para cada tipo de
//       check-in (no abrir chat en blanco — abrir con la pregunta
//       que la coach haría).
//    4. Tracking de ejecución para snooze inteligente (si Isabel
//       salta 2x seguidos, bajamos cadencia o pausamos).
//
//  Cron diario 7am: detecta los que tocan + agrega al briefing.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'coach_cadence.json');

export const CADENCIAS = [
  'diaria',
  'L-V',
  'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sabado', 'domingo',
  '3x_semana', // L/X/V por default
  'semanal',   // un día específico
  'quincenal', // cada 15 días
  'mensual',   // día 1 del mes
  'trimestral',// cada 90 días
  'bajo_demanda', // sin cadencia fija
];

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function loadAll() {
  try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {}
  return { cadences: [], history: [] };
}
function saveAll(d) {
  ensureDir();
  writeFileSync(FILE, JSON.stringify({
    cadences: d.cadences || [],
    history: (d.history || []).slice(-500),
  }, null, 2));
}
function newId(prefix) { return `${prefix}_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

// Configura cadencia de un coach. Idempotente — actualiza si ya existe.
export function setCadence({ coach, cadencia, hora = null, dia = null, prompt_inicial = '' }) {
  if (!coach || !cadencia) return { ok: false, error: 'Falta coach o cadencia.' };
  if (!CADENCIAS.includes(cadencia)) return { ok: false, error: `Cadencia inválida. Usa: ${CADENCIAS.join(', ')}` };
  const data = loadAll();
  const i = data.cadences.findIndex((c) => c.coach === coach);
  const entry = {
    id: i >= 0 ? data.cadences[i].id : newId('cc'),
    coach,
    cadencia,
    hora,    // "07:00" — sugerida para el ping
    dia,     // 1-31 para mensual, 0-6 para semanal-en-día
    prompt_inicial: String(prompt_inicial).slice(0, 400),
    pausada: false,
    creado: i >= 0 ? data.cadences[i].creado : new Date().toISOString(),
    actualizado: new Date().toISOString(),
  };
  if (i >= 0) data.cadences[i] = entry; else data.cadences.push(entry);
  saveAll(data);
  return { ok: true, cadencia: entry };
}

export function listCadences({ activas_solo = true } = {}) {
  const data = loadAll();
  return activas_solo ? data.cadences.filter((c) => !c.pausada) : data.cadences;
}

export function pauseCadence(coach) {
  const data = loadAll();
  const i = data.cadences.findIndex((c) => c.coach === coach);
  if (i < 0) return null;
  data.cadences[i].pausada = !data.cadences[i].pausada;
  data.cadences[i].actualizado = new Date().toISOString();
  saveAll(data);
  return data.cadences[i];
}

export function removeCadence(coach) {
  const data = loadAll();
  const before = data.cadences.length;
  data.cadences = data.cadences.filter((c) => c.coach !== coach);
  saveAll(data);
  return data.cadences.length < before;
}

// Registra que Isabel hizo un check-in (o lo saltó)
export function registrarCheckIn({ coach, accion = 'completado', nota = '' }) {
  const data = loadAll();
  const entry = {
    id: newId('ch'),
    coach,
    accion, // completado | saltado | snoozeado
    nota: String(nota).slice(0, 200),
    ts: new Date().toISOString(),
    dia: new Date().toISOString().slice(0, 10),
  };
  data.history.push(entry);
  saveAll(data);
  return entry;
}

// ¿Cuántos saltos consecutivos para este coach? (para snooze inteligente)
export function saltosConsecutivos(coach) {
  const data = loadAll();
  const events = data.history.filter((h) => h.coach === coach).reverse();
  let count = 0;
  for (const e of events) {
    if (e.accion === 'saltado') count++;
    else break;
  }
  return count;
}

// ¿Qué coaches tocan HOY?
export function cadenciasDeHoy() {
  const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
  const now = new Date();
  const dow = new Intl.DateTimeFormat('en-US', { timeZone: TZ, weekday: 'short' }).format(now);
  const dayMap = { Sun: 'domingo', Mon: 'lunes', Tue: 'martes', Wed: 'miércoles', Thu: 'jueves', Fri: 'viernes', Sat: 'sabado' };
  const todayName = dayMap[dow];
  const dia = parseInt(new Intl.DateTimeFormat('en-US', { timeZone: TZ, day: 'numeric' }).format(now), 10);
  const dayOfYear = Math.floor((now - new Date(now.getFullYear(), 0, 0)) / 86400000);

  const data = loadAll();
  const out = [];
  for (const c of data.cadences) {
    if (c.pausada) continue;
    let toca = false;
    switch (c.cadencia) {
      case 'diaria': toca = true; break;
      case 'L-V':
        toca = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes'].includes(todayName);
        break;
      case '3x_semana':
        toca = ['lunes', 'miércoles', 'viernes'].includes(todayName);
        break;
      case 'semanal':
        // c.dia = 0-6 (0=domingo) o nombre día
        if (c.dia === todayName) toca = true;
        break;
      case 'quincenal':
        // Cada 14 días desde su día de creación
        const dCreado = Math.floor(new Date(c.creado).getTime() / 86400000);
        const dHoy = Math.floor(Date.now() / 86400000);
        toca = (dHoy - dCreado) % 14 === 0;
        break;
      case 'mensual':
        toca = dia === (c.dia || 1);
        break;
      case 'trimestral':
        // Día 1 de cada trimestre (ene/abr/jul/oct)
        const mes = now.getMonth() + 1;
        toca = dia === 1 && [1, 4, 7, 10].includes(mes);
        break;
      default:
        // Días de la semana directos (lunes/martes/etc)
        if (c.cadencia === todayName) toca = true;
    }
    if (toca) {
      out.push({
        ...c,
        ya_hecho: yaHechoHoy(c.coach),
      });
    }
  }
  return out.sort((a, b) => (a.hora || '99').localeCompare(b.hora || '99'));
}

function yaHechoHoy(coach) {
  const data = loadAll();
  const hoy = new Date().toISOString().slice(0, 10);
  return data.history.some((h) => h.coach === coach && h.dia === hoy && h.accion === 'completado');
}

// Prompt inicial sugerido para abrir el chat con esta coach
export function promptInicialPara(coach) {
  const data = loadAll();
  const c = data.cadences.find((x) => x.coach === coach);
  if (c?.prompt_inicial) return c.prompt_inicial;
  // Defaults por coach si no se configuró custom
  const defaults = {
    carmen: 'Tengo X comida planeada hoy. ¿Te late? ¿Cómo sumo proteína / corto azúcar / mejoro?',
    rivera: 'Hoy toca workout. ¿Qué me recomiendas según cómo me siento + lo de ayer?',
    sofia: '¿Cómo estoy de sueño, hormonas y vitaminas hoy? ¿Algo que ajustar?',
    alma: 'Check-in emocional. Te cuento cómo va mi semana y me ayudas a procesar.',
    maria: 'Pipeline review. ¿Quién está caliente? ¿Quién se enfría? ¿Qué SOA falta?',
    elena: 'Revisión financiera de la semana. ¿Cómo va vs metas? ¿Qué ajustar?',
    victoria: '¿Cómo voy con mis 3 rocas de la semana? ¿Cuál es la siguiente acción?',
    marisol: 'Plan de contenido próxima semana. Tírame 2-3 piezas del backlog para mover.',
    beatriz: '¿A quién de mi red NO he hablado en 2+ semanas que debería?',
    esperanza: 'Intención del mes / momento espiritual. Aterrízame.',
    rosa: '¿Qué área de casa necesita atención este mes?',
    luna: 'Skin check. Síntomas / cambios / qué probar.',
    valentina: 'Wardrobe review trimestral. Qué sobra, qué falta.',
    camila: 'Espacio nuevo / rediseño. Llévame paso a paso.',
    lucia: 'Tengo que hablar en público pronto. Prepárame.',
    catalina: 'Viaje próximo. Trip planning logístico.',
  };
  return defaults[coach] || `Check-in programado con ${coach}.`;
}

// ---- Snooze inteligente ----
// Si saltó N veces seguidas, sugiere bajar cadencia.
export function sugerenciaSnooze(coach) {
  const n = saltosConsecutivos(coach);
  if (n < 2) return null;
  const data = loadAll();
  const c = data.cadences.find((x) => x.coach === coach);
  if (!c) return null;
  const downgrade = {
    'diaria': 'L-V',
    'L-V': '3x_semana',
    '3x_semana': 'semanal',
    'semanal': 'quincenal',
    'quincenal': 'mensual',
    'mensual': 'trimestral',
  };
  const nueva = downgrade[c.cadencia];
  if (!nueva) return null;
  return { coach, saltos: n, cadencia_actual: c.cadencia, cadencia_sugerida: nueva };
}

// ---- Snapshot inline para context base ----
export function buildCoachCadenceInline() {
  const hoy = cadenciasDeHoy();
  if (!hoy.length) return '';
  const pendientes = hoy.filter((c) => !c.ya_hecho);
  if (!pendientes.length) return `coach check-ins: ${hoy.length} de hoy ya hechos ✓`;
  return `coach check-ins hoy: ${pendientes.map((c) => c.coach).join(', ')}`;
}

// ---- Bloque para morning brief ----
export function buildCoachCadenceBriefingBlock() {
  const hoy = cadenciasDeHoy();
  if (!hoy.length) return null;
  const pendientes = hoy.filter((c) => !c.ya_hecho);
  if (!pendientes.length) return null;
  const lines = ['👥 COACH CHECK-INS DE HOY'];
  for (const c of pendientes) {
    const horaStr = c.hora ? ` (${c.hora})` : '';
    lines.push(`  · ${c.coach}${horaStr} — ${c.cadencia}`);
  }
  // Si hay snooze sugerido, menciona
  for (const c of hoy) {
    const s = sugerenciaSnooze(c.coach);
    if (s) lines.push(`  ⚠ ${c.coach} llevas ${s.saltos} saltos — ¿bajamos a ${s.cadencia_sugerida}?`);
  }
  return lines.join('\n');
}

// ---- Seed inicial — la propuesta default ----
export function seedDefaultCadences() {
  const existing = loadAll();
  const have = new Set(existing.cadences.map((c) => c.coach));
  const seeds = [
    { coach: 'carmen', cadencia: 'diaria', hora: '08:00' },
    { coach: 'sofia', cadencia: 'diaria', hora: '07:00' },
    { coach: 'rivera', cadencia: '3x_semana', hora: '06:30' },
    { coach: 'victoria', cadencia: 'lunes', hora: '07:00' },
    { coach: 'alma', cadencia: 'lunes', hora: '20:00' },
    { coach: 'marisol', cadencia: 'viernes', hora: '21:00' },
    { coach: 'elena', cadencia: 'viernes', hora: '17:00' },
    { coach: 'maria', cadencia: 'quincenal' },
    { coach: 'beatriz', cadencia: 'quincenal' },
    { coach: 'esperanza', cadencia: 'mensual', dia: 1 },
    { coach: 'rosa', cadencia: 'mensual', dia: 1 },
    { coach: 'valentina', cadencia: 'trimestral' },
    { coach: 'camila', cadencia: 'trimestral' },
    { coach: 'luna', cadencia: 'bajo_demanda' },
    { coach: 'lucia', cadencia: 'bajo_demanda' },
    { coach: 'catalina', cadencia: 'bajo_demanda' },
  ];
  const created = [];
  const skipped = [];
  for (const s of seeds) {
    if (have.has(s.coach)) { skipped.push(s.coach); continue; }
    const r = setCadence(s);
    if (r.ok) created.push(s.coach);
  }
  return { created, skipped };
}
