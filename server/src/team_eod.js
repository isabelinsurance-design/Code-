// ============================================================
//  Team EOD reports — el equipo reporta a las 3pm, Athena agrega
//  ──────────────────────────────────────────────────────────────
//  Antes: Sami/Skarleth/Arlette/Samia mandaban un email a Isabel
//  cada tarde con sus números. Isabel leía 4 emails al fin del
//  día buscando problemas. Cansancio.
//
//  Ahora: cada miembro del equipo manda /eod por WhatsApp con
//  sus números. Athena los acumula. A las 6pm chequea quién no
//  ha mandado y le pide a Sami que les recuerde. A las 9pm en
//  el evening check-in de Isabel, Athena ya tiene el resumen
//  agregado: "Hoy reportaron 3/4. Total: 47 llamadas, 6 citas,
//  2 apps, 1 problema (Sami: cliente confundido por SOA, escala
//  a ti)."
//
//  Detecta problemas que requieren a Isabel y los flagea
//  explícitamente. Pasa el ruido, queda lo importante.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'team_eod.json');

const TEAM_ALIASES = {
  sami: 'Sami', sammy: 'Sami',
  samia: 'Samia',
  skarleth: 'Skarleth', skarlet: 'Skarleth', scarleth: 'Skarleth',
  scarlett: 'Skarleth', skarl: 'Skarleth',
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
  return `eod_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
}

function todayKey() {
  const now = new Date();
  return now.toISOString().slice(0, 10);
}

// ─── Parse heurístico de números en el reporte ───
// Detecta "18 llamadas", "3 citas", "2 apps", "5 prospectos" etc.
function parseNumbers(text) {
  const lower = String(text || '').toLowerCase();
  const out = {};
  const patterns = {
    llamadas: /(\d+)\s*(?:llamada|call|contacto)s?/i,
    citas: /(\d+)\s*(?:cita|appointment|appt|reuni[oó]n|meeting)s?/i,
    apps: /(\d+)\s*(?:app|aplicaci[oó]n|application|enrollment)s?/i,
    polizas: /(\d+)\s*(?:p[oó]liz|poliz)a?s?/i,
    prospectos: /(\d+)\s*(?:prospect|lead|prospec)s?/i,
    tickets: /(\d+)\s*ticket?s?/i,
  };
  for (const [k, re] of Object.entries(patterns)) {
    const m = lower.match(re);
    if (m) out[k] = parseInt(m[1], 10);
  }
  // Problema indicado: si dice "problema", "issue", "ayuda", "escala"
  const tieneProblema = /problema|issue|ayuda|escala|urgente|importante/i.test(text || '');
  if (tieneProblema) out._problema = true;
  return out;
}

// ─── Submission ───
export function submitEodReport({ persona, texto, fuente = 'whatsapp' }) {
  if (!persona || !texto) return { ok: false, error: 'Falta persona o texto.' };
  const data = load();
  const dia = todayKey();
  const entry = {
    id: newId(),
    persona: canonicalPerson(persona),
    dia,
    texto: String(texto).slice(0, 2000),
    numeros: parseNumbers(texto),
    fuente,
    ts: new Date().toISOString(),
  };
  // Dedup: si ya hay reporte de esa persona ese día, lo reemplaza
  const existing = data.findIndex((e) => e.persona === entry.persona && e.dia === dia);
  if (existing >= 0) {
    data[existing] = { ...entry, id: data[existing].id, reemplazado: true };
  } else {
    data.push(entry);
  }
  save(data);
  return { ok: true, entry };
}

// ─── Reads ───
export function getTodayReports() {
  const dia = todayKey();
  return load().filter((e) => e.dia === dia);
}

export function getReportsByDate(dia) {
  return load().filter((e) => e.dia === dia);
}

// Quién no ha reportado HOY del equipo esperado.
export function getMissingReports(expectedTeam = ['Sami', 'Skarleth', 'Arlette', 'Samia']) {
  const today = getTodayReports();
  const reportaron = new Set(today.map((e) => e.persona));
  return expectedTeam.filter((p) => !reportaron.has(p));
}

// ─── Agregación para evening check-in ───
export function buildEodSummary() {
  const today = getTodayReports();
  if (!today.length) return null;
  const totals = { llamadas: 0, citas: 0, apps: 0, polizas: 0, prospectos: 0, tickets: 0 };
  const problemas = [];
  const reportedBy = [];
  for (const e of today) {
    reportedBy.push(e.persona);
    for (const k of Object.keys(totals)) {
      if (typeof e.numeros[k] === 'number') totals[k] += e.numeros[k];
    }
    if (e.numeros._problema) {
      problemas.push({ persona: e.persona, texto: e.texto });
    }
  }
  const expected = ['Sami', 'Skarleth', 'Arlette', 'Samia'];
  const missing = expected.filter((p) => !reportedBy.includes(p));
  const lines = [`📊 EOD EQUIPO — ${reportedBy.length}/${expected.length} reportaron`];
  if (missing.length) lines.push(`  ⚠️ Sin reportar: ${missing.join(', ')}`);
  const numbersLine = Object.entries(totals)
    .filter(([, v]) => v > 0)
    .map(([k, v]) => `${k}=${v}`)
    .join(' · ');
  if (numbersLine) lines.push(`Totales: ${numbersLine}`);
  if (problemas.length) {
    lines.push(`\n🚨 PROBLEMAS QUE FLAGEARON (${problemas.length}):`);
    for (const p of problemas) {
      lines.push(`  • ${p.persona}: ${p.texto.slice(0, 150)}`);
    }
  }
  return { summary: lines.join('\n'), totals, problemas, missing, reportedBy };
}

// Snapshot 1-línea para evening prompt (compacto).
export function buildEodInline() {
  const s = buildEodSummary();
  if (!s) return '';
  const totals = Object.entries(s.totals).filter(([, v]) => v > 0).map(([k, v]) => `${k}=${v}`).join(' · ') || 'sin números';
  const parts = [`reportaron ${s.reportedBy.length}/4 (${totals})`];
  if (s.missing.length) parts.push(`falta: ${s.missing.join('+')}`);
  if (s.problemas.length) parts.push(`🚨 ${s.problemas.length} problema(s)`);
  return parts.join(' | ');
}

// ─── 6pm check: ¿quién no ha reportado? ───
// Devuelve { missing: [], shouldNudgeSami: bool }
export function checkMissingReports() {
  const missing = getMissingReports();
  // No nudge si nadie falta o si todo el equipo falta (ej. weekend)
  const shouldNudgeSami = missing.length > 0 && missing.length < 4;
  return { missing, shouldNudgeSami };
}
