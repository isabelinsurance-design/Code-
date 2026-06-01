// ============================================================
//  Team Accountability — el peso de delegación, Athena lo carga
//  ────────────────────────────────────────────────────────────
//  Isabel tiene un equipo (Sami, Skarleth, Arlette, Samia). El
//  problema documentado: ella gasta 2h/día recordándoles qué
//  tienen que hacer, verificando si se hizo, escalando cuando
//  no se hizo. Esto la quema.
//
//  Athena toma ese peso:
//
//  1. CAPTURE: cuando Isabel dicta "que Skarleth llame a Carlos
//     cuando llegue", Athena registra el compromiso aquí —
//     persona + descripción + vence + contexto.
//
//  2. SURFACE: cada mañana en el briefing, Athena le presenta
//     a Isabel "esto fue lo que prometió cada una ayer + qué
//     vence hoy + qué se atrasó".
//
//  3. CROSS-CHECK con LUNA: Pilar puede verificar si Skarleth
//     registró actividad relacionada en LUNA → marca cumplido
//     automático.
//
//  4. ESCALATION: si pasa el deadline sin cumplir, Athena le
//     pregunta a Isabel "¿escalamos o solo recordatorio?".
//
//  Distinto a commitments.js (promesas de OTROS externos —
//  vendors, doctores, clientes). Distinto a saydo.js (promesas
//  que ATHENA hace a Isabel). Este es ESPECÍFICAMENTE para el
//  equipo interno que Isabel paga / coordina.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'team_commitments.json');

// El equipo conocido. Es ALIAS-mapped — Athena puede decir
// "skarl" / "skarleth" / "Skarleth" y matchea. NO bloqueamos
// nombres fuera de esta lista (alguna empleada nueva entra
// fácil), pero canonicalizamos los conocidos.
const TEAM_ALIASES = {
  sami: 'Sami',
  sammy: 'Sami',
  samia: 'Samia',
  skarleth: 'Skarleth',
  skarlet: 'Skarleth',
  scarleth: 'Skarleth',
  scarlett: 'Skarleth',
  skarl: 'Skarleth',
  arlette: 'Arlette',
};

function canonicalPerson(name) {
  if (!name) return 'unknown';
  const key = String(name).toLowerCase().trim().replace(/[^a-záéíóúñ]/gi, '');
  return TEAM_ALIASES[key] || String(name).trim();
}

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
  writeFileSync(FILE, JSON.stringify(data.slice(-500), null, 2));
}

function newId() {
  return `tc_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

// ─── Registrar compromiso ───
export function recordTeamCommitment({
  persona,
  descripcion,
  vence_en_horas = 24,
  contexto = '',
  recordarle_cuando = null, // 'llegue', 'horario_normal', null
}) {
  if (!descripcion) return { ok: false, error: 'Falta descripción.' };
  const data = load();
  const entry = {
    id: newId(),
    persona: canonicalPerson(persona),
    descripcion: String(descripcion).slice(0, 400),
    creado: new Date().toISOString(),
    vence: new Date(Date.now() + vence_en_horas * 3600_000).toISOString(),
    contexto: String(contexto).slice(0, 300),
    recordarle_cuando,
    status: 'pendiente',
    recordatorios_mandados: 0,
  };
  data.push(entry);
  save(data);
  return { ok: true, commitment: entry };
}

// ─── Listas ───
export function listTeamCommitments({ persona = null, status = 'pendiente', limit = 50 } = {}) {
  const data = load();
  let out = data;
  if (persona) {
    const p = canonicalPerson(persona);
    out = out.filter((c) => c.persona === p);
  }
  if (status) out = out.filter((c) => c.status === status);
  return out.slice(-limit).reverse();
}

export function listOverdueTeamCommitments() {
  const now = Date.now();
  return listTeamCommitments({ status: 'pendiente' }).filter(
    (c) => new Date(c.vence).getTime() < now
  );
}

export function listDueToday() {
  const now = Date.now();
  const endOfDay = new Date();
  endOfDay.setHours(23, 59, 59, 999);
  return listTeamCommitments({ status: 'pendiente' }).filter((c) => {
    const v = new Date(c.vence).getTime();
    return v <= endOfDay.getTime() && v >= now - 24 * 3600_000;
  });
}

// ─── Estado ───
export function markFulfilled(id, evidencia = '') {
  const data = load();
  const i = data.findIndex((c) => c.id === id);
  if (i < 0) return null;
  data[i] = {
    ...data[i],
    status: 'cumplida',
    evidencia: String(evidencia).slice(0, 400),
    cumplido_el: new Date().toISOString(),
  };
  save(data);
  return data[i];
}

export function markFailed(id, razon = '') {
  const data = load();
  const i = data.findIndex((c) => c.id === id);
  if (i < 0) return null;
  data[i] = {
    ...data[i],
    status: 'fallida',
    razon: String(razon).slice(0, 400),
    fallida_el: new Date().toISOString(),
  };
  save(data);
  return data[i];
}

export function cancelCommitment(id, razon = '') {
  const data = load();
  const i = data.findIndex((c) => c.id === id);
  if (i < 0) return null;
  data[i] = {
    ...data[i],
    status: 'cancelada',
    razon: String(razon).slice(0, 300),
    cancelada_el: new Date().toISOString(),
  };
  save(data);
  return data[i];
}

export function incrementReminder(id) {
  const data = load();
  const i = data.findIndex((c) => c.id === id);
  if (i < 0) return null;
  data[i].recordatorios_mandados = (data[i].recordatorios_mandados || 0) + 1;
  data[i].ultimo_recordatorio = new Date().toISOString();
  save(data);
  return data[i];
}

// ─── Stats por persona ───
export function statsByPerson({ sinceDays = 7 } = {}) {
  const cutoff = Date.now() - sinceDays * 86_400_000;
  const data = load().filter((c) => new Date(c.creado).getTime() >= cutoff);
  const byPerson = {};
  for (const c of data) {
    const p = c.persona;
    if (!byPerson[p]) byPerson[p] = { cumplidas: 0, fallidas: 0, pendientes: 0, total: 0, ratio: null };
    byPerson[p].total++;
    if (c.status === 'cumplida') byPerson[p].cumplidas++;
    if (c.status === 'fallida') byPerson[p].fallidas++;
    if (c.status === 'pendiente') byPerson[p].pendientes++;
  }
  for (const p of Object.keys(byPerson)) {
    const s = byPerson[p];
    const closed = s.cumplidas + s.fallidas;
    s.ratio = closed ? s.cumplidas / closed : null;
  }
  return byPerson;
}

// ─── Snapshot inline para contexto ───
export function buildTeamInline() {
  const overdue = listOverdueTeamCommitments();
  const dueToday = listDueToday();
  if (!overdue.length && !dueToday.length) return '';
  const parts = [];
  if (overdue.length) {
    const by = {};
    for (const c of overdue) by[c.persona] = (by[c.persona] || 0) + 1;
    parts.push(`vencidos sin cumplir: ${Object.entries(by).map(([p, n]) => `${p}(${n})`).join(' · ')}`);
  }
  if (dueToday.length) {
    parts.push(`vencen hoy: ${dueToday.length}`);
  }
  return parts.join(' | ');
}

// Para el briefing matutino: resumen estructurado por persona.
export function buildTeamBriefingBlock() {
  const overdue = listOverdueTeamCommitments();
  const dueToday = listDueToday();
  if (!overdue.length && !dueToday.length) return null;
  const lines = ['📋 EQUIPO — qué quedó suelto y qué vence hoy:'];
  const grouped = {};
  for (const c of [...overdue, ...dueToday]) {
    if (!grouped[c.persona]) grouped[c.persona] = { vencidas: [], hoy: [] };
    const v = new Date(c.vence).getTime() < Date.now();
    grouped[c.persona][v ? 'vencidas' : 'hoy'].push(c);
  }
  for (const [persona, items] of Object.entries(grouped)) {
    lines.push(`\n${persona}:`);
    for (const c of items.vencidas) {
      lines.push(`  🔴 [${c.id}] VENCIDA: ${c.descripcion.slice(0, 80)}`);
    }
    for (const c of items.hoy) {
      lines.push(`  ⏳ [${c.id}] hoy: ${c.descripcion.slice(0, 80)}`);
    }
  }
  return lines.join('\n');
}
