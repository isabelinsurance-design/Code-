// ============================================================
//  Memoria por entidades
//  ─────────────────────
//  La wiki es para hechos sobre Isabel. Las entidades son para
//  hechos sobre OTRAS personas. Cada persona que Isabel menciona
//  se vuelve una entidad con su propia colección de notas, alias
//  y tipo (client/family/vendor/team/...). Esto cierra el gap
//  #1 de memoria identificado en el audit de mayo 2026:
//  preguntar "¿qué sabes de Maria?" devuelve TODO lo que se ha
//  acumulado sobre Maria, en vez de tres strings sueltos.
//
//  Si la entidad es un cliente Medicare, se puede vincular al
//  expediente CRM (linkClient) para que Athena cruce las dos
//  vistas sin duplicar info.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'entities.json');

const TYPES = ['client', 'lead', 'family', 'team', 'vendor', 'broker', 'doctor', 'friend', 'other'];

function load() {
  try {
    if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch { /* ignore */ }
  return [];
}
function save(rows) {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
  writeFileSync(FILE, JSON.stringify(rows, null, 2));
}
function newId() {
  return `ent_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
}
const nowIso = () => new Date().toISOString();

// Normalización para matching: minúsculas, sin acentos, sin puntos.
function norm(s) {
  return String(s || '')
    .toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^\w\s]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

// Busca por canonical_name O por cualquier alias. Devuelve la
// PRIMERA entidad que matchea (substring contra normalized name).
function findByName(rows, name) {
  const n = norm(name);
  if (!n) return null;
  // Match exacto primero
  for (const e of rows) {
    if (norm(e.canonical_name) === n) return e;
    if ((e.aliases || []).some((a) => norm(a) === n)) return e;
  }
  // Match parcial (substring)
  for (const e of rows) {
    if (norm(e.canonical_name).includes(n) || n.includes(norm(e.canonical_name))) return e;
    if ((e.aliases || []).some((a) => norm(a).includes(n) || n.includes(norm(a)))) return e;
  }
  return null;
}

// upsert: si ya existe por nombre/alias, le agrega la nota y el
// alias (si es nuevo). Si no existe, crea.
// salience: 0-10 (default 5). El nightly puede repuntuar.
export function upsertEntity({ canonical_name, type = 'other', alias = null, nota = null, salience = 5, cliente_id = null }) {
  if (!canonical_name || !String(canonical_name).trim()) throw new Error('Falta canonical_name.');
  if (!TYPES.includes(type)) throw new Error(`Tipo inválido. Usa: ${TYPES.join(', ')}.`);
  const rows = load();
  let e = findByName(rows, canonical_name);
  if (alias && !e) e = findByName(rows, alias);

  if (!e) {
    e = {
      id: newId(),
      canonical_name: String(canonical_name).trim(),
      aliases: alias ? [String(alias).trim()] : [],
      type,
      notas: [],
      cliente_id: cliente_id || null,
      creado: nowIso(),
      actualizado: nowIso(),
      ultima_mencion: nowIso(),
    };
    rows.unshift(e);
  } else {
    // Agregar alias si es nuevo
    if (alias) {
      const na = norm(alias);
      const has = e.aliases.some((a) => norm(a) === na) || norm(e.canonical_name) === na;
      if (!has) e.aliases.push(String(alias).trim());
    }
    // Actualizar tipo si era 'other' y ahora sabemos algo mejor
    if (e.type === 'other' && type !== 'other') e.type = type;
    if (cliente_id && !e.cliente_id) e.cliente_id = cliente_id;
    e.actualizado = nowIso();
    e.ultima_mencion = nowIso();
  }

  if (nota) {
    e.notas.unshift({
      ts: nowIso(),
      texto: String(nota).trim(),
      salience: Math.max(0, Math.min(10, Number(salience) || 5)),
    });
    e.notas = e.notas.slice(0, 100);
  }

  save(rows);
  return e;
}

export function findEntity(query) {
  const rows = load();
  const n = norm(query);
  if (!n) return [];
  const matches = rows.filter((e) =>
    norm(e.canonical_name).includes(n) ||
    (e.aliases || []).some((a) => norm(a).includes(n)),
  );
  return matches;
}

export function getEntity(id) {
  return load().find((e) => e.id === id) || null;
}

export function listEntities({ type = null, limit = 50 } = {}) {
  return load()
    .filter((e) => (type ? e.type === type : true))
    .sort((a, b) => new Date(b.ultima_mencion).getTime() - new Date(a.ultima_mencion).getTime())
    .slice(0, limit);
}

export function linkClient(entityId, clienteId) {
  const rows = load();
  const i = rows.findIndex((e) => e.id === entityId);
  if (i < 0) return null;
  rows[i].cliente_id = clienteId;
  rows[i].actualizado = nowIso();
  if (rows[i].type === 'other' || rows[i].type === 'lead') rows[i].type = 'client';
  save(rows);
  return rows[i];
}

// Combina dos entidades en una (caso típico: "Maria" y "Maria
// Hernandez" terminaron como dos por error — las fusionamos).
export function mergeEntities(keepId, dropId) {
  if (keepId === dropId) return null;
  const rows = load();
  const keep = rows.find((e) => e.id === keepId);
  const drop = rows.find((e) => e.id === dropId);
  if (!keep || !drop) return null;
  // Agregar nombre + alias del drop como alias del keep
  const newAliases = new Set(keep.aliases || []);
  newAliases.add(drop.canonical_name);
  for (const a of (drop.aliases || [])) newAliases.add(a);
  // Quitar nuestro propio canonical_name de los alias
  newAliases.delete(keep.canonical_name);
  keep.aliases = [...newAliases];
  // Notas combinadas
  keep.notas = [...keep.notas, ...drop.notas]
    .sort((a, b) => new Date(b.ts).getTime() - new Date(a.ts).getTime())
    .slice(0, 100);
  // cliente_id: si el keep no tiene, hereda del drop
  if (!keep.cliente_id && drop.cliente_id) keep.cliente_id = drop.cliente_id;
  keep.actualizado = nowIso();
  const next = rows.filter((e) => e.id !== dropId);
  save(next);
  return keep;
}

const tz = () => process.env.TIMEZONE || 'America/Los_Angeles';
const shortDate = (iso) => iso
  ? new Date(iso).toLocaleDateString('es-MX', { timeZone: tz(), month: 'short', day: 'numeric', year: 'numeric' })
  : '—';

export function entityCard(e) {
  if (!e) return '';
  const lines = [
    `${e.canonical_name} [${e.id}]`,
    `Tipo: ${e.type}${e.cliente_id ? ` · vinculado al cliente ${e.cliente_id}` : ''}`,
  ];
  if (e.aliases?.length) lines.push(`También conocida como: ${e.aliases.join(', ')}`);
  lines.push(`Última mención: ${shortDate(e.ultima_mencion)}`);
  if (e.notas?.length) {
    lines.push(`\nNotas (${e.notas.length}):`);
    for (const n of e.notas.slice(0, 10)) {
      const sal = typeof n.salience === 'number' ? ` (s${n.salience})` : '';
      lines.push(`  · ${shortDate(n.ts)}${sal} — ${n.texto}`);
    }
    if (e.notas.length > 10) lines.push(`  … y ${e.notas.length - 10} más viejas.`);
  } else {
    lines.push('\nSin notas todavía.');
  }
  return lines.join('\n');
}

// Snapshot corto para el contexto de Athena: las N personas más
// recientes con UNA línea de la nota más alta. Mantiene el contexto
// barato pero le da a Athena memoria operativa de "quién está activo".
export function buildEntitiesContext({ limit = 12 } = {}) {
  const rows = load();
  if (!rows.length) return '';
  const recent = rows
    .slice()
    .sort((a, b) => new Date(b.ultima_mencion).getTime() - new Date(a.ultima_mencion).getTime())
    .slice(0, limit);
  const lines = recent.map((e) => {
    const top = (e.notas || []).slice().sort((a, b) => (b.salience || 5) - (a.salience || 5))[0];
    const blurb = top ? top.texto.slice(0, 70) : 'sin notas';
    return `  - [${e.id}] ${e.canonical_name} (${e.type}): ${blurb}`;
  });
  return `PERSONAS QUE ATHENA RECONOCE (entidades — usa entidad_anotar para añadir, entidad_expediente para profundizar):\n${lines.join('\n')}`;
}
