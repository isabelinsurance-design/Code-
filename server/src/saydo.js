// ============================================================
//  Say-Do tracker — la confiabilidad de Athena medida
//  ───────────────────────────────────────────────────
//  Trackea cuando Athena promete algo a Isabel ("te traigo el
//  resumen al rato", "voy a investigar X", "te aviso cuando esté
//  listo") y si cumple. El say-do ratio es el lubricante de la
//  relación CoS-principal — si Athena promete y no cumple, la
//  confianza muere.
//
//  Distinto a commitments.js: ese tracker es para promesas que
//  OTROS le hicieron a Isabel. Este es para promesas que ATHENA
//  HACE a Isabel.
//
//  Patrones detectados (heurística automática):
//    - "te traigo X"
//    - "voy a investigar / consultar / revisar X"
//    - "te aviso cuando esté listo"
//    - "esta noche / mañana / al rato / en un rato"
//
//  Sin tool nuevo expuesto al LLM — la detección es post-respuesta.
//  Cuando se cumple: se marca automáticamente cuando Athena emite
//  el resultado prometido (heurística simple por keyword match) o
//  cuando Isabel dice "ya, gracias / ya está".
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'saydo.json');

function ensureDir() {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
}

function load() {
  try {
    if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch { /* ignore */ }
  return [];
}

function save(data) {
  ensureDir();
  atomicWriteJson(FILE, data.slice(-500));
}

function newId() {
  return `sd_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

// Patrones que indican que Athena hizo una promesa.
// Cada patrón captura un grupo opcional con la "cosa" prometida.
const PROMISE_PATTERNS = [
  /\bte\s+(?:traigo|paso|mando|aviso|confirmo|escribo)\b[^.!?\n]{3,80}/gi,
  /\bvoy\s+a\s+(?:investigar|consultar|revisar|preguntar|verificar|chequear|buscar|llamar)\b[^.!?\n]{3,80}/gi,
  /\bal\s+rato\s+te\b[^.!?\n]{3,80}/gi,
  /\b(?:esta\s+noche|mañana|en\s+un\s+rato|ahorita)\s+(?:te\s+)?(?:tengo|traigo|paso|aviso)\b[^.!?\n]{3,80}/gi,
];

// Detecta promesas en el texto que Athena va a mandar.
// Devuelve array de {descripcion, vence_en_horas_estimadas}.
export function detectPromises(text) {
  if (!text || text.length < 10) return [];
  const out = [];
  const seen = new Set();
  for (const pattern of PROMISE_PATTERNS) {
    const matches = String(text).matchAll(pattern);
    for (const m of matches) {
      const desc = m[0].trim().replace(/\s+/g, ' ').slice(0, 120);
      if (seen.has(desc.toLowerCase())) continue;
      seen.add(desc.toLowerCase());
      out.push({
        descripcion: desc,
        vence_en_horas_estimadas: estimateHorizon(desc),
      });
    }
  }
  return out;
}

function estimateHorizon(desc) {
  const d = desc.toLowerCase();
  if (/ahorita|al rato|en un rato/.test(d)) return 2;
  if (/esta noche/.test(d)) return 8;
  if (/mañana/.test(d)) return 24;
  if (/esta semana/.test(d)) return 24 * 5;
  return 24; // default 1 día
}

// Registra una promesa nueva (llamada por directora.js post-respuesta).
export function recordPromise({ descripcion, vence_en_horas = 24, contexto = '' }) {
  const data = load();
  const entry = {
    id: newId(),
    descripcion,
    contexto: contexto.slice(0, 200),
    prometido_el: new Date().toISOString(),
    vence_en: new Date(Date.now() + vence_en_horas * 3600_000).toISOString(),
    status: 'pendiente',
  };
  data.push(entry);
  save(data);
  return entry;
}

// Marca una promesa cumplida.
export function fulfillPromise(id, resultado = '') {
  const data = load();
  const i = data.findIndex((p) => p.id === id);
  if (i < 0) return null;
  data[i] = {
    ...data[i],
    status: 'cumplida',
    resultado: resultado.slice(0, 300),
    cumplido_el: new Date().toISOString(),
  };
  save(data);
  return data[i];
}

// Marca una promesa fallida (vencida sin cumplir).
export function failPromise(id, razon = '') {
  const data = load();
  const i = data.findIndex((p) => p.id === id);
  if (i < 0) return null;
  data[i] = {
    ...data[i],
    status: 'fallida',
    razon: razon.slice(0, 300),
    fallida_el: new Date().toISOString(),
  };
  save(data);
  return data[i];
}

export function listActive() {
  const data = load();
  return data.filter((p) => p.status === 'pendiente');
}

export function listOverdue() {
  const now = Date.now();
  return listActive().filter((p) => new Date(p.vence_en).getTime() < now);
}

// Estadísticas: % cumplido en últimos N días.
export function stats({ sinceDays = 7 } = {}) {
  const cutoff = Date.now() - sinceDays * 86_400_000;
  const data = load();
  const recent = data.filter((p) => new Date(p.prometido_el).getTime() >= cutoff);
  const cumplidas = recent.filter((p) => p.status === 'cumplida').length;
  const fallidas = recent.filter((p) => p.status === 'fallida').length;
  const pendientes = recent.filter((p) => p.status === 'pendiente').length;
  const total = recent.length;
  const ratio = total ? cumplidas / (cumplidas + fallidas || 1) : null;
  return { since_days: sinceDays, total, cumplidas, fallidas, pendientes, ratio };
}

// Snapshot 1-línea para el contexto base de cada turno.
// Ej: "Tus say-do últimos 7d: 12/14 cumplido (86%), 2 pendientes vencen hoy."
export function buildSayDoInline() {
  const s = stats({ sinceDays: 7 });
  if (!s.total) return '';
  const overdue = listOverdue().length;
  const ratio = s.ratio == null ? '—' : `${Math.round(s.ratio * 100)}%`;
  const parts = [`Tu say-do (7d): ${s.cumplidas}/${s.cumplidas + s.fallidas} cumplido (${ratio})`];
  if (overdue) parts.push(`${overdue} promesa(s) vencidas sin cumplir`);
  if (s.pendientes) parts.push(`${s.pendientes} pendiente(s)`);
  return parts.join(' · ');
}
