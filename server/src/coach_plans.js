// ───────────────────────────────────────────────────────────────────
//  Coach plans — recomendaciones vigentes por coach.
//
//  Cada coach especialista (Sofía, Carmen, Rivera, etc.) mantiene un
//  "plan vigente" — la lista de cosas que le ha recomendado a Isabel.
//  Items individuales con estado (activo / pausado / hecho).
//
//  Para qué sirve:
//   1. Continuidad: cuando Isabel vuelve a chatear con esa coach, la
//      coach ve qué le ha recomendado y le da seguimiento real.
//   2. UI: en la pantalla de Chat con esa coach se muestra el plan
//      arriba, con badges de estado y botones para marcar hecho/pausar.
//   3. (Phase C) Athena puede asomarse: "Sofía te recomendó D3 hace
//      3 semanas, ¿cómo vas?".
//
//  Storage: data/coach_plans/<coach_id>.json
//  Shape: { coach_id, items: [{ id, text, status, ts_created, ts_updated }], actualizado }
//  Status válidos: 'active' | 'paused' | 'done'
// ───────────────────────────────────────────────────────────────────

import { readFileSync, writeFileSync, existsSync, mkdirSync, unlinkSync, readdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const PLANS_DIR = join(DATA_DIR, 'coach_plans');

const VALID_ID = /^[a-z0-9_]+$/;
const VALID_STATUS = new Set(['active', 'paused', 'done']);

function ensureDir() {
  if (!existsSync(PLANS_DIR)) mkdirSync(PLANS_DIR, { recursive: true });
}

function fileFor(coachId) {
  if (!VALID_ID.test(coachId)) throw new Error(`coach_id inválido: ${coachId}`);
  return join(PLANS_DIR, `${coachId}.json`);
}

function emptyPlan(coachId) {
  return { coach_id: coachId, items: [], actualizado: null };
}

export function loadCoachPlan(coachId) {
  try {
    const f = fileFor(coachId);
    if (!existsSync(f)) return emptyPlan(coachId);
    const raw = JSON.parse(readFileSync(f, 'utf8'));
    return {
      coach_id: coachId,
      items: Array.isArray(raw.items) ? raw.items : [],
      actualizado: raw.actualizado || null,
    };
  } catch {
    return emptyPlan(coachId);
  }
}

function savePlan(coachId, plan) {
  ensureDir();
  const f = fileFor(coachId);
  plan.actualizado = new Date().toISOString();
  atomicWriteJson(f, plan);
  return plan;
}

export function addPlanItem(coachId, text) {
  const t = String(text || '').trim();
  if (!t) throw new Error('text vacío');
  const plan = loadCoachPlan(coachId);
  const now = new Date().toISOString();
  const id = `p${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
  plan.items.push({ id, text: t, status: 'active', ts_created: now, ts_updated: now });
  return savePlan(coachId, plan);
}

export function updatePlanItem(coachId, itemId, patch) {
  const plan = loadCoachPlan(coachId);
  const item = plan.items.find((i) => i.id === itemId);
  if (!item) throw new Error(`item ${itemId} no existe`);
  if (patch.text !== undefined) item.text = String(patch.text).trim();
  if (patch.status !== undefined) {
    if (!VALID_STATUS.has(patch.status)) throw new Error(`status inválido: ${patch.status}`);
    item.status = patch.status;
  }
  item.ts_updated = new Date().toISOString();
  return savePlan(coachId, plan);
}

export function removePlanItem(coachId, itemId) {
  const plan = loadCoachPlan(coachId);
  const before = plan.items.length;
  plan.items = plan.items.filter((i) => i.id !== itemId);
  if (plan.items.length === before) throw new Error(`item ${itemId} no existe`);
  return savePlan(coachId, plan);
}

export function clearCoachPlan(coachId) {
  const f = fileFor(coachId);
  if (existsSync(f)) unlinkSync(f);
  return emptyPlan(coachId);
}

// Lista todos los coach_ids con plan no vacío (escanea el directorio).
// Útil para Athena y briefings que necesitan mostrar todo lo activo.
function listCoachesWithPlan() {
  try {
    if (!existsSync(PLANS_DIR)) return [];
    return readdirSync(PLANS_DIR)
      .filter((f) => f.endsWith('.json'))
      .map((f) => f.slice(0, -5))
      .filter((id) => VALID_ID.test(id));
  } catch {
    return [];
  }
}

// Resumen compacto de TODOS los planes activos (cross-coach) para inyectar
// en el contexto de Athena. Solo items 'active' para no inflar tokens.
// Si una coach tiene 0 activos, se omite. Si no hay nada, devuelve ''.
// `coachNameLookup`: función opcional id → "Nombre legible" (si no, usa id).
export function buildAllPlansInline(coachNameLookup) {
  const ids = listCoachesWithPlan();
  if (!ids.length) return '';
  const lookup = typeof coachNameLookup === 'function' ? coachNameLookup : (id) => id;
  const blocks = [];
  for (const id of ids) {
    const plan = loadCoachPlan(id);
    const active = plan.items.filter((i) => i.status === 'active');
    if (!active.length) continue;
    const lines = active.map((i) => `  - ${i.text}  (desde ${i.ts_created.slice(0, 10)})`);
    blocks.push(`${lookup(id)}:\n${lines.join('\n')}`);
  }
  if (!blocks.length) return '';
  return `PLANES VIGENTES DE TUS COACHES (lo que cada una le ha recomendado a Isabel — chequea adherencia cuando aplique, NO repitas lo que ya está aquí):\n${blocks.join('\n\n')}`;
}

// Formato de texto que se inyecta como contexto al sistema cuando la
// coach va a responder — para que SEPA lo que le ha recomendado a Isabel
// y le pueda dar seguimiento sin tener que adivinar.
export function planAsContext(coachId, coachName) {
  const plan = loadCoachPlan(coachId);
  const active = plan.items.filter((i) => i.status === 'active');
  const paused = plan.items.filter((i) => i.status === 'paused');
  if (!active.length && !paused.length) {
    return `(${coachName}: todavía no le has dejado a Isabel ningún plan estructurado. Cuando le recomiendes algo concreto que quieras que recuerde, usa la tool coach_plan_agregar.)`;
  }
  const lines = [`PLAN VIGENTE QUE TÚ (${coachName}) LE RECOMENDASTE A ISABEL — léelo antes de responder, dale seguimiento, NO repitas cosas que ya están aquí:`];
  if (active.length) {
    lines.push('Activos:');
    for (const i of active) lines.push(`  - [${i.id}] ${i.text}  (desde ${i.ts_created.slice(0, 10)})`);
  }
  if (paused.length) {
    lines.push('Pausados:');
    for (const i of paused) lines.push(`  - [${i.id}] ${i.text}`);
  }
  lines.push('Para actualizar: coach_plan_agregar (nuevo item), coach_plan_actualizar (cambiar status o texto de un item existente).');
  return lines.join('\n');
}
