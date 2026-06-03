// ───────────────────────────────────────────────────────────────────
//  Reading list / pocket — guardás URLs (artículos, videos, podcasts)
//  para procesarlos después. Athena puede:
//   - Agregar con notas/tags
//   - Listar pendientes
//   - Generar resumen on-demand (vía web_search)
//   - Marcar leído / archivado
//   - Surfacear cuando hay tiempo libre o en el evening recap
//
//  Storage: data/reading_list.json
//  Shape: { id, url, titulo?, fuente?, agregado_ts, status, notas?, resumen?, tags[] }
// ───────────────────────────────────────────────────────────────────

import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'reading_list.json');

const VALID_STATUS = new Set(['pending', 'leido', 'archivado']);

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() { try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {} return []; }
function save(d) { ensureDir(); writeFileSync(FILE, JSON.stringify(d.slice(-500), null, 2)); }
function newId() { return `rd_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

function extractFuente(url) {
  try {
    const u = new URL(url);
    return u.hostname.replace(/^www\./, '');
  } catch { return null; }
}

export function addItem({ url, titulo = null, notas = null, tags = [] } = {}) {
  const u = String(url || '').trim();
  if (!u) throw new Error('url vacía');
  if (!/^https?:\/\//i.test(u)) throw new Error('url debe empezar con http:// o https://');
  const data = load();
  // dedup por URL exacta
  const existing = data.find((i) => i.url === u);
  if (existing) {
    // si vuelve a pasar el mismo, refresh notas/tags pero no duplicar
    if (notas) existing.notas = notas;
    if (Array.isArray(tags) && tags.length) existing.tags = [...new Set([...(existing.tags || []), ...tags])];
    save(data);
    return existing;
  }
  const entry = {
    id: newId(),
    url: u,
    titulo: titulo ? String(titulo).slice(0, 300) : null,
    fuente: extractFuente(u),
    agregado_ts: new Date().toISOString(),
    status: 'pending',
    notas: notas ? String(notas).slice(0, 500) : null,
    resumen: null,
    tags: Array.isArray(tags) ? tags.map((t) => String(t).toLowerCase().trim()).filter(Boolean) : [],
  };
  data.push(entry);
  save(data);
  return entry;
}

export function listItems({ status = 'pending', limit = 30, tag = null } = {}) {
  let items = load();
  if (status) items = items.filter((i) => i.status === status);
  if (tag) items = items.filter((i) => (i.tags || []).includes(String(tag).toLowerCase()));
  return items.slice(-limit).reverse();
}

export function getItem(id) {
  return load().find((i) => i.id === id) || null;
}

export function updateItem(id, patch) {
  const data = load();
  const item = data.find((i) => i.id === id);
  if (!item) throw new Error(`item ${id} no existe`);
  if (patch.status !== undefined) {
    if (!VALID_STATUS.has(patch.status)) throw new Error(`status inválido: ${patch.status}`);
    item.status = patch.status;
  }
  if (patch.titulo !== undefined) item.titulo = String(patch.titulo).slice(0, 300);
  if (patch.notas !== undefined) item.notas = patch.notas ? String(patch.notas).slice(0, 500) : null;
  if (patch.resumen !== undefined) item.resumen = patch.resumen ? String(patch.resumen).slice(0, 2000) : null;
  if (patch.tags !== undefined && Array.isArray(patch.tags)) {
    item.tags = patch.tags.map((t) => String(t).toLowerCase().trim()).filter(Boolean);
  }
  save(data);
  return item;
}

export function removeItem(id) {
  const data = load();
  const before = data.length;
  const filtered = data.filter((i) => i.id !== id);
  if (filtered.length === before) throw new Error(`item ${id} no existe`);
  save(filtered);
  return { ok: true };
}

// Inline corto para Athena: cuántos pendientes, top 3 más recientes.
// Para que en evening/weekly pueda decir "tienes 7 artículos pendientes,
// el más reciente es X — ¿quieres que te lo resuma?".
export function buildReadingListInline() {
  const pending = listItems({ status: 'pending', limit: 50 });
  if (!pending.length) return '';
  const top3 = pending.slice(0, 3);
  const lines = [`READING LIST: ${pending.length} pendiente(s). Top 3 recientes:`];
  for (const i of top3) {
    const label = i.titulo || i.url.slice(0, 80);
    lines.push(`  - [${i.id}] ${label} (${i.fuente || 'web'})`);
  }
  return lines.join('\n');
}
