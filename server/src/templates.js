// ============================================================
//  Templates pre-aprobados de Isabel
//  ──────────────────────────────────
//  Templates de email/SMS que Isabel aprobó UNA vez. Athena los puede
//  usar SIN pasar por drafts queue (bypass del "envía" gate).
//
//  Útiles especialmente en vacaciones — Athena puede responder
//  comunicaciones rutinarias sin despertar a Isabel.
//
//  Cada template tiene:
//    slug         id único
//    nombre       human-friendly
//    canal        'email' | 'sms'
//    asunto       solo para email
//    cuerpo       con {{vars}}
//    aprobado_at  ISO timestamp
//    aprobada_por 'isabel' (futuro: roles)
//
//  Storage: data/templates.json
// ============================================================
import { writeFileSync, readFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const FILE = join(__dirname, '..', 'data', 'templates.json');

function loadAll() {
  try {
    if (!existsSync(FILE)) return [];
    return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch { return []; }
}

function saveAll(list) {
  try {
    if (!existsSync(dirname(FILE))) mkdirSync(dirname(FILE), { recursive: true });
    atomicWriteJson(FILE, list);
  } catch (e) { console.warn('[templates] save falló:', e.message); }
}

function slugify(s) {
  return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 40);
}

export function listTemplates() {
  return loadAll();
}

export function getTemplate(slug) {
  return loadAll().find((t) => t.slug === slug) || null;
}

export function addTemplate({ nombre, canal = 'email', asunto = '', cuerpo, aprobada_por = 'isabel' }) {
  if (!nombre || !cuerpo) throw new Error('nombre y cuerpo son requeridos');
  if (canal === 'email' && !asunto) throw new Error('email necesita asunto');
  const list = loadAll();
  const slug = slugify(nombre);
  if (list.find((t) => t.slug === slug)) throw new Error(`Ya existe template "${slug}"`);
  const tpl = {
    slug, nombre, canal, asunto, cuerpo,
    aprobado_at: new Date().toISOString(),
    aprobada_por,
    veces_usado: 0,
  };
  list.push(tpl);
  saveAll(list);
  return tpl;
}

export function removeTemplate(slug) {
  const list = loadAll();
  const filtered = list.filter((t) => t.slug !== slug);
  if (filtered.length === list.length) return { ok: false, error: 'no existe' };
  saveAll(filtered);
  return { ok: true };
}

// Renderiza el template con variables. Reemplaza {{var}} con el valor.
export function renderTemplate(slug, vars = {}) {
  const tpl = getTemplate(slug);
  if (!tpl) throw new Error(`Template "${slug}" no existe`);
  function fill(text) {
    return String(text || '').replace(/\{\{(\w+)\}\}/g, (_, k) => vars[k] ?? `[${k}]`);
  }
  // Incrementa contador uso
  const list = loadAll();
  const t = list.find((x) => x.slug === slug);
  if (t) { t.veces_usado = (t.veces_usado || 0) + 1; t.ultimo_uso = new Date().toISOString(); saveAll(list); }
  return {
    slug,
    canal: tpl.canal,
    asunto: fill(tpl.asunto),
    cuerpo: fill(tpl.cuerpo),
  };
}
