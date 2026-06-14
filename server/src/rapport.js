// ───────────────────────────────────────────────────────────────────
//  Rapport semanal — snapshot del cuerpo + cómo se siente Isabel.
//
//  Cada viernes 6pm (cron 'rapport'), Athena pingea a Isabel pidiéndole:
//   - Foto (opcional, ella la guarda en su propio rollo o la manda)
//   - Peso (lbs)
//   - Medidas (cintura / cadera / brazo / muslo en pulgadas)
//   - Sentires (free text — energía, sueño, ánimo, periodo, etc.)
//
//  Athena guarda con `rapport_semanal` y arma trend cuando hay 2+ entradas.
//  Sofía y Rivera pueden leerlo cuando es relevante (sin necesidad de que
//  Isabel lo repita cada vez).
//
//  Storage: data/rapport.json
// ───────────────────────────────────────────────────────────────────

import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'rapport.json');

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() { try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {} return []; }
function save(d) { ensureDir(); atomicWriteJson(FILE, d.slice(-200)); }
function newId() { return `r_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

// Devuelve la "semana ISO" en formato YYYY-W## — usable para agrupar y
// para evitar registrar dos rapports en la misma semana sin querer.
function isoWeek(date = new Date()) {
  const d = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
  const dayNum = d.getUTCDay() || 7;
  d.setUTCDate(d.getUTCDate() + 4 - dayNum);
  const yearStart = new Date(Date.UTC(d.getUTCFullYear(), 0, 1));
  const weekNum = Math.ceil(((d - yearStart) / 86_400_000 + 1) / 7);
  return `${d.getUTCFullYear()}-W${String(weekNum).padStart(2, '0')}`;
}

export function registrarRapport({ peso_lbs = null, medidas = null, foto_url = null, sentires = '', periodo = null } = {}) {
  const entry = {
    id: newId(),
    ts: new Date().toISOString(),
    semana: isoWeek(),
    peso_lbs: peso_lbs ? Number(peso_lbs) : null,
    medidas: medidas && typeof medidas === 'object' ? medidas : null,
    foto_url: foto_url ? String(foto_url) : null,
    sentires: String(sentires || '').slice(0, 1000),
    periodo: periodo || null,
  };
  const data = load();
  data.push(entry);
  save(data);
  return entry;
}

export function listRapports({ limit = 12 } = {}) {
  return load().slice(-limit).reverse();
}

// Resumen comparativo: peso actual vs hace 4 semanas vs hace 12 semanas.
// Para que cuando Sofía o Rivera coachee, vea la trayectoria real.
export function rapportTrend() {
  const all = load();
  if (!all.length) return null;
  const sorted = all.slice().sort((a, b) => new Date(a.ts) - new Date(b.ts));
  const latest = sorted[sorted.length - 1];
  if (!latest.peso_lbs) return { latest, delta_4w: null, delta_12w: null };
  const now = Date.now();
  const findClosest = (daysBack) => {
    const target = now - daysBack * 86_400_000;
    let closest = null;
    let minDiff = Infinity;
    for (const e of sorted) {
      if (!e.peso_lbs) continue;
      const diff = Math.abs(new Date(e.ts).getTime() - target);
      if (diff < minDiff) { minDiff = diff; closest = e; }
    }
    return closest;
  };
  const ref4w = findClosest(28);
  const ref12w = findClosest(84);
  return {
    latest,
    delta_4w: ref4w && ref4w.id !== latest.id ? +(latest.peso_lbs - ref4w.peso_lbs).toFixed(1) : null,
    delta_12w: ref12w && ref12w.id !== latest.id ? +(latest.peso_lbs - ref12w.peso_lbs).toFixed(1) : null,
  };
}

// Inline para Sofía / Rivera — qué saber del cuerpo de Isabel sin que
// ella tenga que repetirse en cada sesión.
export function buildRapportForCoach() {
  const t = rapportTrend();
  if (!t) return '';
  const lines = [`RAPPORT MÁS RECIENTE de Isabel (semana ${t.latest.semana}):`];
  if (t.latest.peso_lbs) lines.push(`  Peso: ${t.latest.peso_lbs} lbs`);
  if (t.latest.medidas) {
    const m = Object.entries(t.latest.medidas).map(([k, v]) => `${k} ${v}"`).join(' · ');
    if (m) lines.push(`  Medidas: ${m}`);
  }
  if (t.latest.sentires) lines.push(`  Sentires: ${t.latest.sentires}`);
  if (t.latest.periodo) lines.push(`  Periodo: ${t.latest.periodo}`);
  if (t.delta_4w !== null) lines.push(`  Δ peso 4 sem: ${t.delta_4w > 0 ? '+' : ''}${t.delta_4w} lbs`);
  if (t.delta_12w !== null) lines.push(`  Δ peso 12 sem: ${t.delta_12w > 0 ? '+' : ''}${t.delta_12w} lbs`);
  return '\n\n' + lines.join('\n');
}
