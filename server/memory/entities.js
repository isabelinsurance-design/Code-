// ENTIDADES — memoria por persona  (Playbook patron #11)
//
// En el dominio de Isabel, una "entidad" es una PERSONA: un miembro, un lead, un
// prospecto, o alguien del equipo. Cada entidad acumula:
//   - nombre canonico + alias ("Mari" = "Maria Hernandez")
//   - atributos tipados (plan, grupo medico, doctor, Medi-Cal, condiciones, telefono)
//   - notas con fecha
//   - salience: que tan importante/activa es (sube con cada mencion)
//   - gaps: lo que falta saber de esa persona (patron #17, known unknowns)
//
// Resuelve referencias ambiguas: cuando el agente dice "Mari otra vez", SAMIA sabe
// de quien habla. Almacen: data/entities.json (migrable a Postgres).

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { DATA_DIR } from '../config.js';

const FILE = resolve(DATA_DIR, 'entities.json');
const nowIso = () => new Date().toISOString();

function ensure() {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
}
function readAll() {
  try {
    return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch {
    return {};
  }
}
function writeAll(data) {
  ensure();
  writeFileSync(FILE, JSON.stringify(data, null, 1));
}

export const norm = (s) =>
  String(s || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/\s+/g, ' ')
    .trim();

const slug = (s) =>
  norm(s).replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '').slice(0, 48) || 'persona';

// Atributos tipados que reconocemos para una persona.
const ATTR_KEYS = ['plan', 'medicalGroup', 'doctor', 'mediCal', 'phone'];

// Resuelve una entidad por nombre o alias (tolerante a acentos/abreviaturas).
export function resolveEntity(nameOrAlias) {
  const q = norm(nameOrAlias);
  if (!q) return null;
  const all = readAll();
  let best = null;
  for (const e of Object.values(all)) {
    const names = [e.canonicalName, ...(e.aliases || [])].map(norm);
    for (const n of names) {
      if (!n) continue;
      // match exacto, o uno contiene al otro (Mari <-> Maria Hernandez)
      if (n === q || n.includes(q) || q.includes(n)) {
        if (!best || (e.salience || 0) > (best.salience || 0)) best = e;
      }
    }
  }
  return best;
}

export function getEntity(id) {
  return readAll()[id] || null;
}

export function listEntities({ q = '', limit = 50 } = {}) {
  const all = Object.values(readAll());
  const nq = norm(q);
  const filtered = nq
    ? all.filter((e) => [e.canonicalName, ...(e.aliases || [])].some((n) => norm(n).includes(nq)))
    : all;
  return filtered.sort((a, b) => (b.salience || 0) - (a.salience || 0)).slice(0, limit);
}

// Crea o actualiza una entidad. Devuelve la entidad resultante.
export function upsertEntity({ name, type = 'member', alias = null, attrs = {}, note = null, gaps = [] }) {
  if (!name) return null;
  const all = readAll();

  // Intenta resolver a una existente antes de crear (evita duplicados).
  let existing = resolveEntity(name);
  let e;
  if (existing) {
    e = all[existing.id];
  } else {
    let id = slug(name);
    while (all[id]) id += '-2';
    e = { id, canonicalName: name, type, aliases: [], attrs: {}, notes: [], gaps: [], salience: 0, firstSeen: nowIso() };
    all[id] = e;
  }

  // alias nuevo
  if (alias && norm(alias) !== norm(e.canonicalName) && !(e.aliases || []).some((a) => norm(a) === norm(alias))) {
    e.aliases.push(alias);
  }
  // Si el nombre entrante es mas largo/completo, vuelvelo canonico y guarda el viejo como alias.
  if (norm(name) !== norm(e.canonicalName) && name.length > e.canonicalName.length) {
    if (!e.aliases.some((a) => norm(a) === norm(e.canonicalName))) e.aliases.push(e.canonicalName);
    e.canonicalName = name;
  }

  for (const k of ATTR_KEYS) {
    if (attrs[k] != null && attrs[k] !== '') e.attrs[k] = attrs[k];
  }
  if (Array.isArray(attrs.conditions)) {
    e.attrs.conditions = [...new Set([...(e.attrs.conditions || []), ...attrs.conditions])];
  }
  if (note) e.notes.push({ ts: nowIso(), note: String(note).slice(0, 500) });
  for (const g of gaps) addGapTo(e, g);

  e.salience = (e.salience || 0) + 1;
  e.lastSeen = nowIso();
  all[e.id] = e;
  writeAll(all);
  return e;
}

function addGapTo(e, gap) {
  const g = String(gap).slice(0, 160);
  if (!g) return;
  e.gaps = e.gaps || [];
  if (!e.gaps.some((x) => norm(x.what) === norm(g))) e.gaps.push({ what: g, ts: nowIso() });
}

export function addNote(id, note) {
  const all = readAll();
  const e = all[id];
  if (!e || !note) return null;
  e.notes.push({ ts: nowIso(), note: String(note).slice(0, 500) });
  writeAll(all);
  return e;
}

export function resolveGap(id, what) {
  const all = readAll();
  const e = all[id];
  if (!e) return null;
  e.gaps = (e.gaps || []).filter((g) => norm(g.what) !== norm(what));
  writeAll(all);
  return e;
}

// Known unknowns globales: todos los gaps de todas las personas, rankeados por
// salience de la persona (patron #17).
export function rankedGaps(limit = 20) {
  const all = Object.values(readAll());
  const out = [];
  for (const e of all) {
    for (const g of e.gaps || []) {
      out.push({ entity: e.canonicalName, entityId: e.id, what: g.what, salience: e.salience || 0, ts: g.ts });
    }
  }
  return out.sort((a, b) => b.salience - a.salience).slice(0, limit);
}

// Ficha compacta para inyectar en el prompt.
export function entityCard(e) {
  if (!e) return '';
  const a = e.attrs || {};
  const bits = [];
  if (a.plan) bits.push(`plan ${a.plan}`);
  if (a.medicalGroup) bits.push(`grupo ${a.medicalGroup}`);
  if (a.doctor) bits.push(`Dr. ${a.doctor}`);
  if (a.mediCal) bits.push(`Medi-Cal: ${a.mediCal}`);
  if (a.conditions?.length) bits.push(`condiciones: ${a.conditions.join(', ')}`);
  if (a.phone) bits.push(`tel ${a.phone}`);
  const aka = e.aliases?.length ? ` (alias: ${e.aliases.join(', ')})` : '';
  const notes = e.notes?.length ? ` Notas: ${e.notes.slice(-2).map((n) => n.note).join(' | ')}` : '';
  const gaps = e.gaps?.length ? ` FALTA: ${e.gaps.map((g) => g.what).join('; ')}` : '';
  return `${e.canonicalName}${aka} — ${bits.join(', ') || 'sin datos aun'}.${notes}${gaps}`;
}
