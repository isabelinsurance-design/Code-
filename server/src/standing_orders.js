// ============================================================
//  Standing Orders — reglas permanentes que Athena obedece
//  ────────────────────────────────────────────────────────
//  Delegación al nivel meta. En vez de decirle a Athena "haz X
//  esta vez", le das una REGLA que aplica para siempre:
//
//   · "Si lead nuevo Medicare → template welcome + crear miembro LUNA"
//   · "Si Sami no contesta en 24h → SMS auto"
//   · "Carrier rep con deadline <24h → escalar inmediato"
//   · "Nunca me interrumpas entre 9pm y 7am salvo emergencia"
//   · "Siempre asigna a Sami (10) por default si no nombro a quién"
//
//  Las reglas son TEXTO declarativo — Athena las lee cada turno y
//  las aplica. No es un rules engine — es delegación cognitiva.
//
//  Storage: data/standing_orders.json
// ============================================================
import { writeFileSync, readFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const FILE = join(__dirname, '..', 'data', 'standing_orders.json');

export const CATEGORIAS = [
  'comunicacion',    // cómo responder a comunicaciones entrantes
  'escalacion',      // qué te despierta
  'tiempo',          // ventanas / quiet hours
  'equipo',          // auto-followup, asignación default
  'delegacion',      // qué hace sin preguntar
  'compliance',      // CMS, SOA, MBI, TCPA gates
  'otro',
];

function load() {
  try {
    if (!existsSync(FILE)) return [];
    return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch { return []; }
}

function save(list) {
  try {
    if (!existsSync(dirname(FILE))) mkdirSync(dirname(FILE), { recursive: true });
    atomicWriteJson(FILE, list);
  } catch (e) { console.warn('[standing_orders] save falló:', e.message); }
}

function nowIso() { return new Date().toISOString(); }

function slugify(s) {
  return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 50);
}

function newId() {
  return `so_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

export function listOrders({ status = null, categoria = null } = {}) {
  let all = load();
  if (status) all = all.filter((o) => o.status === status);
  if (categoria) all = all.filter((o) => o.categoria === categoria);
  return all;
}

export function getOrder(id) {
  return load().find((o) => o.id === id || o.slug === id) || null;
}

export function createOrder({ regla, categoria = 'otro', nombre = null }) {
  if (!regla || !regla.trim()) throw new Error('regla es requerida');
  if (!CATEGORIAS.includes(categoria)) throw new Error(`categoria debe ser: ${CATEGORIAS.join(', ')}`);
  const all = load();
  const slug = slugify(nombre || regla).slice(0, 50);
  const o = {
    id: newId(),
    slug: slug || `regla_${Date.now().toString(36).slice(-6)}`,
    nombre: nombre || regla.slice(0, 60),
    categoria,
    regla: regla.trim(),
    status: 'activa',
    veces_aplicada: 0,
    ultima_aplicacion: null,
    creada: nowIso(),
  };
  all.push(o);
  save(all);
  return o;
}

export function updateOrder(id, patch) {
  const all = load();
  const i = all.findIndex((o) => o.id === id || o.slug === id);
  if (i < 0) return null;
  if (patch.categoria && !CATEGORIAS.includes(patch.categoria)) {
    throw new Error(`categoria invalida`);
  }
  all[i] = { ...all[i], ...patch, actualizada: nowIso() };
  save(all);
  return all[i];
}

export function pauseOrder(id) { return updateOrder(id, { status: 'pausada' }); }
export function activateOrder(id) { return updateOrder(id, { status: 'activa' }); }
export function retireOrder(id) { return updateOrder(id, { status: 'retirada' }); }

export function deleteOrder(id) {
  const all = load();
  const filtered = all.filter((o) => o.id !== id && o.slug !== id);
  if (filtered.length === all.length) return { ok: false, error: 'no existe' };
  save(filtered);
  return { ok: true };
}

export function bumpApplication(id) {
  const o = getOrder(id);
  if (!o) return null;
  return updateOrder(id, {
    veces_aplicada: (o.veces_aplicada || 0) + 1,
    ultima_aplicacion: nowIso(),
  });
}

// === CONTEXT BLOCK — inyectado al prompt de Athena cada turno ===
// Solo reglas ACTIVAS. Categorizadas para que el modelo las escanee rápido.
export function buildStandingOrdersBlock() {
  const active = load().filter((o) => o.status === 'activa');
  if (!active.length) return '';
  const byCategory = {};
  for (const o of active) {
    if (!byCategory[o.categoria]) byCategory[o.categoria] = [];
    byCategory[o.categoria].push(o);
  }
  const lines = ['📜 ÓRDENES PERMANENTES DE ISABEL — APLICA SIEMPRE:'];
  const order = ['compliance', 'escalacion', 'tiempo', 'comunicacion', 'delegacion', 'equipo', 'otro'];
  for (const cat of order) {
    const items = byCategory[cat];
    if (!items?.length) continue;
    lines.push(`\n[${cat.toUpperCase()}]`);
    for (const o of items) {
      lines.push(`· ${o.regla}`);
    }
  }
  lines.push('\nEstas son reglas que Isabel YA decidió. NO le preguntes si aplicarlas — síguelas y reporta lo que hiciste.');
  return lines.join('\n');
}
