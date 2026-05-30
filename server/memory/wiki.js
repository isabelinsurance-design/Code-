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

export function getSeason() {
  return read().season || '';
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
  if (d.season) parts.push(`TEMPORADA ACTUAL: ${d.season}`);
  if (d.facts?.length) parts.push(`WIKI DEL EQUIPO:\n- ${d.facts.slice(-12).map((f) => f.fact).join('\n- ')}`);
  return parts.join('\n\n');
}
