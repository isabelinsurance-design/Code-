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

// --- CONSOLIDACION (para la reflexion nocturna, patron #15 paso 3) ---

// Funde la entidad `fromId` dentro de `intoId`: une alias, atributos, notas y gaps,
// suma salience, y borra la origen. Devuelve la entidad resultante.
export function mergeEntities(intoId, fromId) {
  if (intoId === fromId) return getEntity(intoId);
  const all = readAll();
  const a = all[intoId];
  const b = all[fromId];
  if (!a || !b) return null;

  // alias: el nombre de b + sus alias entran como alias de a
  for (const name of [b.canonicalName, ...(b.aliases || [])]) {
    if (norm(name) !== norm(a.canonicalName) && !a.aliases.some((x) => norm(x) === norm(name))) a.aliases.push(name);
  }
  // atributos: a gana; rellena los que le falten desde b. conditions se unen.
  for (const [k, v] of Object.entries(b.attrs || {})) {
    if (k === 'conditions') a.attrs.conditions = [...new Set([...(a.attrs.conditions || []), ...(v || [])])];
    else if (a.attrs[k] == null || a.attrs[k] === '') a.attrs[k] = v;
  }
  a.notes = [...(a.notes || []), ...(b.notes || [])].sort((x, y) => (x.ts > y.ts ? 1 : -1)).slice(-20);
  for (const g of b.gaps || []) addGapTo(a, g.what);
  a.salience = (a.salience || 0) + (b.salience || 0);
  a.lastSeen = a.lastSeen > b.lastSeen ? a.lastSeen : b.lastSeen;
  delete all[fromId];
  all[intoId] = a;
  writeAll(all);
  return a;
}

// Detecta candidatos a duplicado con DOS niveles de confianza:
//   - 'high':  un nombre/alias es substring exacto del otro (ej. "Maria" y
//              "Maria Hernandez"). Seguro para fundir automaticamente.
//   - 'low':   mismo apellido + primer nombre compatible por prefijo (ej. "Mari
//              Hernandez" / "Maria Hernandez"). Ambiguo (PHI) -> NO se funde solo;
//              se reporta para que un humano confirme.
// No funde nada; solo detecta.
export function findDuplicates() {
  const all = Object.values(readAll());
  const pairs = [];
  const namesOf = (e) => [e.canonicalName, ...(e.aliases || [])].map(norm);

  for (let i = 0; i < all.length; i++) {
    for (let j = i + 1; j < all.length; j++) {
      const A = all[i];
      const B = all[j];
      const an = namesOf(A);
      const bn = namesOf(B);

      let confidence = null;
      // high: substring exacto entre algun par de nombres
      if (an.some((x) => bn.some((y) => x === y || (x.length > 4 && (x.includes(y) || y.includes(x)))))) {
        confidence = 'high';
      } else {
        // low: comparten apellido (ultimo token) y el primer nombre es compatible
        // por prefijo (Mari/Maria) — minimo 3 chars para evitar falsos (Jo/Jose).
        const surnameMatch = an.some((x) => {
          const xs = x.split(' ');
          return bn.some((y) => {
            const ys = y.split(' ');
            if (xs.length < 2 || ys.length < 2) return false;
            const sameSurname = xs[xs.length - 1] === ys[ys.length - 1];
            const fx = xs[0];
            const fy = ys[0];
            const prefixOk = fx === fy || (fx.length >= 3 && fy.length >= 3 && (fx.startsWith(fy) || fy.startsWith(fx)));
            return sameSurname && prefixOk;
          });
        });
        if (surnameMatch) confidence = 'low';
      }

      if (confidence) pairs.push({ a: A.id, b: B.id, aName: A.canonicalName, bName: B.canonicalName, confidence });
    }
  }
  return pairs;
}

// Funde automaticamente SOLO los duplicados de alta confianza (mantiene el de
// mayor salience). Devuelve cuantas fusiones hizo. Los de baja confianza se dejan
// para revision humana (ver duplicateCandidates).
export function autoMergeDuplicates() {
  let merged = 0;
  const safety = 500;
  while (merged < safety) {
    const pair = findDuplicates().find((p) => p.confidence === 'high');
    if (!pair) break;
    const ea = getEntity(pair.a);
    const eb = getEntity(pair.b);
    if (!ea || !eb) continue;
    const [into, from] = (ea.salience || 0) >= (eb.salience || 0) ? [pair.a, pair.b] : [pair.b, pair.a];
    mergeEntities(into, from);
    merged++;
  }
  return merged;
}

// Candidatos de baja confianza para confirmacion humana (patron de confirmation gate).
export function duplicateCandidates() {
  return findDuplicates().filter((p) => p.confidence === 'low');
}

// Marca contradicciones en las notas de una persona (mismo atributo, valores
// distintos en distintas notas). No resuelve sola — las reporta para revision.
const SINGLE_ATTRS = ['plan', 'medicalGroup', 'doctor', 'mediCal'];
export function findContradictions() {
  const all = Object.values(readAll());
  const out = [];
  for (const e of all) {
    const a = e.attrs || {};
    // Busca en las notas valores de plan/grupo distintos al atributo actual.
    const notesText = (e.notes || []).map((n) => norm(n.note)).join(' ');
    for (const k of SINGLE_ATTRS) {
      const cur = a[k];
      if (!cur) continue;
      // heuristica: si una nota menciona "ya no" / "cambio" cerca del valor actual.
      if (new RegExp(`(ya no|cambio|antes ten|dejo).{0,40}${norm(cur)}`).test(notesText)) {
        out.push({ entityId: e.id, entity: e.canonicalName, attr: k, value: cur, hint: 'posible cambio mencionado en notas' });
      }
    }
  }
  return out;
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
