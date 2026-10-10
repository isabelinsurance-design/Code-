// TEMPORADA + WIKI LARGO PLAZO  (Playbook patrones #9 y #10)
//
//   - temporada: 1-2 frases de "en que esta enfocado el equipo hoy". Cambia cuando
//     cambia el foco (ej. "Estamos en plena AEP — prioridad: cerrar Full Duals").
//   - wiki: hechos que NO caducan sobre el equipo/negocio. Append-only.
//     ("El equipo escala bills complejos a Crystal." "Panorama Dental es de la oficina.")
//
// Almacen: data/wiki.json.

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { DATA_DIR } from '../config.js';

const FILE = resolve(DATA_DIR, 'wiki.json');
const nowIso = () => new Date().toISOString();

function ensure() {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
}
function read() {
  try {
    return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch {
    return { season: '', facts: [] };
  }
}
function write(data) {
  ensure();
  writeFileSync(FILE, JSON.stringify(data, null, 1));
}

// Calendario Medicare AUTOMATICO (deterministico, sin key): SAMIA siempre sabe en
// que ventana de inscripcion estamos y de que plan year se habla. Fechas fijas y
// publicas de CMS; no inventa montos ni detalles de plan (eso va en el KB / Connecture).
export function medicareSeason(now = new Date()) {
  const y = now.getFullYear();
  const m = now.getMonth(); // 0 = enero
  const d = now.getDate();
  const afterOct15 = m > 9 || (m === 9 && d >= 15);
  const beforeDec8 = m < 11 || (m === 11 && d <= 7);
  let window, planYear, line;
  if (afterOct15 && beforeDec8) {
    window = 'AEP';
    planYear = y + 1;
    line = `AEP ACTIVA (plan year ${planYear}): inscripciones 15 oct – 7 dic. Prioridad: cerrar antes del 7 dic; lo inscrito entra en vigor el 1 de enero.`;
  } else if (m === 9 && d < 15) {
    window = 'pre-AEP';
    planYear = y + 1;
    line = `Faltan pocos días para AEP (arranca 15 oct, plan year ${planYear}). Prepárate: materiales ${planYear}, SOAs listas, citas agendadas.`;
  } else if (m === 11) {
    window = 'post-AEP';
    planYear = y + 1;
    line = `Cerró AEP (7 dic). Lo inscrito entra en vigor el 1 de enero ${planYear}. Fuera de AEP: solo SEPs.`;
  } else if (m <= 2) {
    window = 'OEP';
    planYear = y;
    line = `OEP (1 ene – 31 mar, plan year ${planYear}): quien YA está en Medicare Advantage puede hacer UN cambio. No sirve para pasar de Original Medicare a MA.`;
  } else {
    window = 'lock-in';
    planYear = y;
    line = `Fuera de AEP/OEP (plan year ${planYear}): solo con un SEP válido (mudanza de condado, pérdida de Medi-Cal, salir de hospital, etc.). Verifica qué SEP aplica antes de cualquier cambio.`;
  }
  return { window, planYear, line, note: 'Full Dual / DSNP suelen tener su propio SEP — verifica la regla vigente antes de inscribir.' };
}

export function getSeason() {
  // Manual (foco que puso el equipo) si existe; si no, el calendario Medicare automatico.
  return read().season || medicareSeason().line;
}

export function setSeason(text) {
  const d = read();
  d.season = String(text || '').slice(0, 400);
  d.seasonUpdated = nowIso();
  write(d);
  return d.season;
}

const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').trim();

export function addFact(fact) {
  const f = String(fact || '').slice(0, 400).trim();
  if (!f) return null;
  const d = read();
  // dedupe simple
  if ((d.facts || []).some((x) => norm(x.fact) === norm(f))) return d;
  d.facts = d.facts || [];
  d.facts.push({ ts: nowIso(), fact: f });
  write(d);
  return d;
}

export function getFacts(limit = 40) {
  return (read().facts || []).slice(-limit);
}

// Bloque para inyectar en el prompt.
export function wikiContext() {
  const d = read();
  const parts = [];
  const ms = medicareSeason();
  parts.push(`CALENDARIO MEDICARE (hoy): ${ms.line} ${ms.note}`); // siempre, automatico
  if (d.season) parts.push(`FOCO DEL EQUIPO: ${d.season}`);
  if (d.facts?.length) parts.push(`WIKI DEL EQUIPO:\n- ${d.facts.slice(-12).map((f) => f.fact).join('\n- ')}`);
  return parts.join('\n\n');
}
