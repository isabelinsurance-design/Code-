// ============================================================
//  errors.js — registro VISIBLE de fallas de Athena
//  ────────────────────────────────────────────────
//  Antes, cuando algo se rompía (un cron, el bridge, una tool), iba
//  a console.error y se perdía en los logs de Railway. Nadie se
//  enteraba — la falla invisible del garaje. Esto las guarda en un
//  archivo que el PWA puede mostrar: "esto se rompió y cuándo".
//  PII-redacted, atómico, capado a 300. Nunca tumba a quien lo llama.
// ============================================================
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { atomicWriteJson } from './storage.js';
import { redactPII } from './security.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const FILE = join(__dirname, '..', 'data', 'errors.json');

function load() {
  try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); }
  catch (e) { console.error('[errors] errors.json ilegible:', e.message); }
  return [];
}

export function logError({ source, message, detail = '' }) {
  try {
    const log = load();
    log.unshift({
      ts: new Date().toISOString(),
      source: String(source || 'desconocido').slice(0, 60),
      message: redactPII(String(message || '').slice(0, 300)),
      detail: redactPII(String(detail || '').slice(0, 300)),
    });
    atomicWriteJson(FILE, log.slice(0, 300));
  } catch { /* el registro de errores NUNCA debe tumbar a quien lo llama */ }
}

export function getErrors() { return load(); }

// Resumen puro (testeable): cuántos hoy / 24h, por fuente, y los recientes.
export function errorsSummary(rows = load(), now = new Date()) {
  const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
  const dayKey = (d) => new Intl.DateTimeFormat('en-CA', { timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit' }).format(d);
  const hoy = dayKey(now);
  const list = Array.isArray(rows) ? rows : [];
  let today = 0;
  let last24h = 0;
  const bySource = {};
  for (const r of list) {
    const t = r?.ts ? new Date(r.ts) : null;
    if (!t || Number.isNaN(t.getTime())) continue;
    if (dayKey(t) === hoy) today++;
    if (now.getTime() - t.getTime() < 86_400_000) last24h++;
    const s = r.source || 'desconocido';
    bySource[s] = (bySource[s] || 0) + 1;
  }
  return {
    total: list.length,
    today,
    last24h,
    by_source: bySource,
    recent: list.slice(0, 10),
  };
}
