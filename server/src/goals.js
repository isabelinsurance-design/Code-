// ============================================================
//  Goals (OKRs) — Victoria Vision ahora confronta con dato
//  ──────────────────────────────────────────────────────
//  Distinto a tasks.js (qué hacer esta semana) y a season.json
//  (en qué estás enfocada este mes). Goals son OKRs:
//   - Largo plazo (anual / trimestral)
//   - Cuantitativos cuando sea posible (meta numérica)
//   - Trackeados semanalmente
//
//  Ejemplo:
//   meta: "AEP 2026: 40 nuevos enrollments"
//   target: 40, progreso: 18, % avance: 45%, ventana: Oct-Dec
//
//  Victoria los lee cuando es consultada y te confronta con
//  el % real, no con vibes. "Querías 40, vas en 18 con 6
//  semanas restantes — necesitas 3.7/sem para llegar."
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'goals.json');

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() { try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {} return []; }
function save(d) { ensureDir(); atomicWriteJson(FILE, d.slice(-100)); }
function newId() { return `g_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

export function registrarMeta({ nombre, target = null, unidad = '', vence, area = 'personal', notas = '' }) {
  if (!nombre || !vence) return { ok: false, error: 'Falta nombre o fecha de vencimiento.' };
  const data = load();
  const entry = {
    id: newId(),
    nombre: String(nombre).slice(0, 150),
    target: target !== null ? Number(target) : null,
    unidad: String(unidad).slice(0, 30),
    progreso: 0,
    area: String(area).slice(0, 30), // personal | trabajo | salud | finanzas | otro
    notas: String(notas).slice(0, 300),
    creado: new Date().toISOString(),
    vence: new Date(vence).toISOString(),
    status: 'activa', // activa | completada | abandonada | renovada
  };
  data.push(entry); save(data);
  return { ok: true, entry };
}

export function actualizarProgreso({ id, progreso, nota = '' }) {
  const data = load();
  const i = data.findIndex((g) => g.id === id);
  if (i < 0) return null;
  data[i].progreso = Number(progreso);
  data[i].actualizado = new Date().toISOString();
  if (nota) data[i].ultima_nota = String(nota).slice(0, 200);
  // Auto-completa si llegó al target
  if (data[i].target !== null && data[i].progreso >= data[i].target && data[i].status === 'activa') {
    data[i].status = 'completada';
    data[i].completada_el = new Date().toISOString();
  }
  save(data);
  return data[i];
}

export function cambiarStatus(id, newStatus) {
  const data = load();
  const i = data.findIndex((g) => g.id === id);
  if (i < 0) return null;
  data[i].status = newStatus;
  data[i].actualizado = new Date().toISOString();
  save(data);
  return data[i];
}

export function listMetas({ status = 'activa', area = null } = {}) {
  return load()
    .filter((g) => !status || g.status === status)
    .filter((g) => !area || g.area === area)
    .reverse();
}

// Calcula proyección: si sigues al ritmo actual, ¿llegas al target?
export function proyeccion(meta) {
  if (meta.target === null || !meta.creado) return null;
  const totalDias = (new Date(meta.vence).getTime() - new Date(meta.creado).getTime()) / 86_400_000;
  const transcurridos = (Date.now() - new Date(meta.creado).getTime()) / 86_400_000;
  const restantes = Math.max(0, Math.ceil((new Date(meta.vence).getTime() - Date.now()) / 86_400_000));
  if (transcurridos <= 0) return null;
  const ritmoActual = meta.progreso / transcurridos;
  const proyectado = ritmoActual * totalDias;
  const falta = meta.target - meta.progreso;
  const requerido_diario = restantes > 0 ? falta / restantes : null;
  const pct_avance = meta.target > 0 ? Math.round((meta.progreso / meta.target) * 100) : null;
  const pct_tiempo = Math.round((transcurridos / totalDias) * 100);
  return {
    dias_restantes: restantes,
    pct_avance,
    pct_tiempo_transcurrido: pct_tiempo,
    en_track: pct_avance >= pct_tiempo - 10, // tolerancia 10%
    proyeccion_final: Math.round(proyectado * 10) / 10,
    requerido_diario,
  };
}

export function buildGoalsInline() {
  const activas = listMetas({ status: 'activa' });
  if (!activas.length) return '';
  const off = activas.filter((m) => {
    const p = proyeccion(m);
    return p && !p.en_track;
  });
  if (off.length === 0) return `metas activas: ${activas.length} (todas en track ✓)`;
  return `metas activas: ${activas.length} · ⚠️ ${off.length} off-track`;
}

export function buildGoalsForCoach() {
  const activas = listMetas({ status: 'activa' });
  if (!activas.length) return '';
  const lines = ['🎯 METAS ACTIVAS (OKRs)'];
  for (const m of activas) {
    const p = proyeccion(m);
    let line = `[${m.id}] ${m.nombre} — ${m.target !== null ? `${m.progreso}/${m.target}${m.unidad}` : 'sin métrica'}`;
    if (p) {
      const trackFlag = p.en_track ? '✓ on track' : '⚠️ OFF TRACK';
      line += ` · ${p.pct_avance}% avance (${p.pct_tiempo_transcurrido}% del tiempo) · ${p.dias_restantes}d restantes · ${trackFlag}`;
      if (!p.en_track && p.requerido_diario != null) {
        line += `\n  Requerido para llegar: ${Math.round(p.requerido_diario * 10) / 10}${m.unidad}/día`;
      }
    }
    lines.push(line);
  }
  return `\n\nDATOS REALES DE METAS DE ISABEL:\n${lines.join('\n\n')}\n\nUsa estos datos para confrontar con dato real. Si una meta está OFF TRACK, dilo. Si ya pasó el 80% del tiempo y solo 40% de avance, la matemática no miente. Tu trabajo: ayudar a Isabel a renovar la meta, ajustarla, o decidir abandonarla con dignidad. NO sermones — datos + decisión.`;
}
