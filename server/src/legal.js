// ============================================================
//  Legal calendar — paz mental sobre obligaciones regulatorias
//  ────────────────────────────────────────────────────────────
//  Una agente de Medicare tiene un calendario legal denso:
//   - CA broker license renewal (cada 2 años)
//   - AHIP recertification (anual antes Oct 1)
//   - Carrier certifications (9 carriers, anual)
//   - Continuing Education (24 hrs por ciclo CA)
//   - Business filings (LLC, DBA, BOI)
//   - Taxes (cuatrimestral + anual)
//   - Workers comp (si tiene empleadas)
//   - Cyber liability insurance renewal
//   - E&O insurance renewal
//
//  Cualquiera de estos vencido = problema serio. Athena los
//  trackea y surface alertas a 60/30/7 días vista en el
//  morning brief. Paz mental real.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'legal.json');

export const TIPOS_LEGAL = [
  'license',          // CA broker license
  'ahip',             // AHIP recertification anual
  'carrier_cert',     // Anthem, SCAN, etc.
  'ce',               // Continuing education
  'business_filing',  // LLC, DBA, BOI
  'tax',              // Quarterly + annual
  'insurance',        // E&O, cyber, workers comp
  'otro',
];

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() { try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {} return []; }
function save(d) { ensureDir(); atomicWriteJson(FILE, d.slice(-200)); }
function newId() { return `lg_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

export function registrarObligacion({ tipo, descripcion, vence, recurrencia = null, autoridad = '', monto = null, notas = '' }) {
  if (!descripcion || !vence) return { ok: false, error: 'Falta descripción o fecha de vencimiento.' };
  if (!TIPOS_LEGAL.includes(tipo)) tipo = 'otro';
  const data = load();
  const entry = {
    id: newId(),
    tipo,
    descripcion: String(descripcion).slice(0, 200),
    autoridad: String(autoridad).slice(0, 100),
    vence: new Date(vence).toISOString(),
    recurrencia, // anual | semestral | trimestral | bianual | null
    monto: monto !== null ? Number(monto) : null,
    notas: String(notas).slice(0, 300),
    status: 'pendiente',
    creado: new Date().toISOString(),
  };
  data.push(entry);
  save(data);
  return { ok: true, obligacion: entry };
}

export function marcarCumplida(id, evidencia = '') {
  const data = load();
  const i = data.findIndex((o) => o.id === id);
  if (i < 0) return null;
  data[i].status = 'cumplida';
  data[i].cumplida_el = new Date().toISOString();
  data[i].evidencia = String(evidencia).slice(0, 300);
  // Auto-renueva si tiene recurrencia
  if (data[i].recurrencia) {
    const next = new Date(data[i].vence);
    if (data[i].recurrencia === 'anual') next.setFullYear(next.getFullYear() + 1);
    else if (data[i].recurrencia === 'semestral') next.setMonth(next.getMonth() + 6);
    else if (data[i].recurrencia === 'trimestral') next.setMonth(next.getMonth() + 3);
    else if (data[i].recurrencia === 'bianual') next.setFullYear(next.getFullYear() + 2);
    const renewed = {
      ...data[i],
      id: newId(),
      vence: next.toISOString(),
      status: 'pendiente',
      cumplida_el: null,
      evidencia: '',
      creado: new Date().toISOString(),
      renovada_de: data[i].id,
    };
    data.push(renewed);
  }
  save(data);
  return data[i];
}

export function listarObligaciones({ status = 'pendiente' } = {}) {
  return load().filter((o) => !status || o.status === status);
}

// Devuelve las obligaciones en zona de alerta: vence en N días o menos.
export function alertasActivas({ ventanas = [7, 30, 60] } = {}) {
  const now = Date.now();
  const obligaciones = listarObligaciones({ status: 'pendiente' });
  const out = { '7': [], '30': [], '60': [], vencidas: [] };
  for (const o of obligaciones) {
    const vence = new Date(o.vence).getTime();
    const diasFalt = Math.ceil((vence - now) / 86_400_000);
    if (diasFalt < 0) {
      out.vencidas.push({ ...o, dias_vencida: Math.abs(diasFalt) });
    } else if (diasFalt <= 7) {
      out['7'].push({ ...o, dias_falt: diasFalt });
    } else if (diasFalt <= 30) {
      out['30'].push({ ...o, dias_falt: diasFalt });
    } else if (diasFalt <= 60) {
      out['60'].push({ ...o, dias_falt: diasFalt });
    }
  }
  return out;
}

export function buildLegalInline() {
  const a = alertasActivas();
  const urgentes = a['7'].length + a.vencidas.length;
  if (urgentes === 0 && a['30'].length === 0) return '';
  const parts = [];
  if (a.vencidas.length) parts.push(`🚨 ${a.vencidas.length} legal VENCIDAS`);
  if (a['7'].length) parts.push(`⚠️ ${a['7'].length} en ≤7d`);
  if (a['30'].length) parts.push(`${a['30'].length} en ≤30d`);
  return `legal: ${parts.join(' · ')}`;
}

export function buildLegalBriefingBlock() {
  const a = alertasActivas();
  if (!a.vencidas.length && !a['7'].length && !a['30'].length && !a['60'].length) return null;
  const lines = ['⚖️ LEGAL — obligaciones'];
  if (a.vencidas.length) {
    lines.push(`\n🚨 VENCIDAS (${a.vencidas.length}):`);
    for (const o of a.vencidas) {
      lines.push(`  · ${o.descripcion} (${o.dias_vencida}d vencida${o.autoridad ? ` — ${o.autoridad}` : ''})`);
    }
  }
  if (a['7'].length) {
    lines.push(`\n⚠️ ≤7 días:`);
    for (const o of a['7']) {
      lines.push(`  · ${o.descripcion} (en ${o.dias_falt}d${o.monto ? ` — $${o.monto}` : ''})`);
    }
  }
  if (a['30'].length) {
    lines.push(`\n≤30 días:`);
    for (const o of a['30'].slice(0, 3)) {
      lines.push(`  · ${o.descripcion} (en ${o.dias_falt}d)`);
    }
  }
  return lines.join('\n');
}
