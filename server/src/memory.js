import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { redactPII } from './security.js';
import { buildGapsSummary } from './gaps.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const WIKI_FILE = join(DATA_DIR, 'isabel_wiki.json');
const HISTORY_FILE = join(DATA_DIR, 'conversation.json');
const SEASON_FILE = join(DATA_DIR, 'season.json');
const ACTIVITY_FILE = join(DATA_DIR, 'activity.json');
const OUTBOUND_FILE = join(DATA_DIR, 'outbound_queue.json');
const PROACTIVE_FILE = join(DATA_DIR, 'proactive_counter.json');

// NOTA: esto guarda en un archivo JSON en el disco del servidor.
// Funciona perfecto para una sola usuaria. En Railway el disco se
// reinicia al re-desplegar — para memoria 100% permanente, conecta
// un volumen de Railway o una base de datos (ver README).

function load(file, fallback) {
  try {
    if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    /* archivo corrupto o vacío — usamos el fallback */
  }
  return fallback;
}

function save(file, data) {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
  writeFileSync(file, JSON.stringify(data, null, 2));
}

// ---- Isabel Wiki: memoria de largo plazo compartida ----
export function getWiki() {
  return load(WIKI_FILE, { notas: [], perfil: {}, actualizado: null });
}

export function remember(nota) {
  const wiki = getWiki();
  wiki.notas.unshift({ nota, fecha: new Date().toISOString() });
  wiki.notas = wiki.notas.slice(0, 100); // máximo 100 notas recientes
  wiki.actualizado = new Date().toISOString();
  save(WIKI_FILE, wiki);
  return wiki;
}

// Borra entradas de la wiki que matcheen el query (substring, case-insensitive).
// Devuelve cuántas se borraron.
export function forget(query) {
  const wiki = getWiki();
  const q = String(query || '').toLowerCase().trim();
  if (!q) return { borradas: 0, restantes: wiki.notas.length };
  const before = wiki.notas.length;
  wiki.notas = wiki.notas.filter((n) => !n.nota.toLowerCase().includes(q));
  wiki.actualizado = new Date().toISOString();
  save(WIKI_FILE, wiki);
  return { borradas: before - wiki.notas.length, restantes: wiki.notas.length };
}

// Devuelve un listado plano de lo que Athena recuerda (para Isabel).
export function listMemories(limit = 30) {
  const wiki = getWiki();
  return wiki.notas.slice(0, limit);
}

// ---- "Temporada actual" — el foco de Isabel ahora mismo. 1-2 frases. ----
export function getSeason() {
  return load(SEASON_FILE, { texto: '', actualizado: null });
}

export function setSeason(texto) {
  const s = { texto: String(texto || '').trim(), actualizado: new Date().toISOString() };
  save(SEASON_FILE, s);
  return s;
}

// Lee tareas y compromisos directo del disco (sin importar los
// módulos respectivos) para evitar ciclos tasks/commitments/crm ↔ memory.
const TASKS_FILE_PATH = join(DATA_DIR, 'tasks.json');
const COMMITMENTS_FILE_PATH = join(DATA_DIR, 'commitments.json');
const CRM_FILE_PATH = join(DATA_DIR, 'crm.json');
const ENTITIES_FILE_PATH = join(DATA_DIR, 'entities.json');
const SIGNALS_FILE_PATH = join(DATA_DIR, 'signals.json');
// Gaps se computan on-demand (no se persisten) — import dinámico abajo.

function readJsonSafe(path, fallback) {
  try {
    if (existsSync(path)) return JSON.parse(readFileSync(path, 'utf8'));
  } catch { /* ignore */ }
  return fallback;
}

function commitmentsContextInline() {
  const rows = readJsonSafe(COMMITMENTS_FILE_PATH, []).filter((c) => c.status === 'pendiente');
  if (!rows.length) return '';
  const tz = process.env.TIMEZONE || 'America/Los_Angeles';
  const lines = rows.slice(0, 15).map((c) => {
    const due = c.vence
      ? ` (vence ${new Date(c.vence).toLocaleString('es-MX', { timeZone: tz, month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })})`
      : '';
    const overdue = c.vence && new Date(c.vence).getTime() < Date.now() ? ' VENCIDO' : '';
    return `  - [${c.id}] ${c.persona} → ${c.descripcion} via ${c.canal}${due}${overdue}`;
  });
  return `COMPROMISOS DE TERCEROS HACIA ISABEL (lo que otros le deben — persigue cuando venzan):\n${lines.join('\n')}`;
}

function crmSnapshotInline() {
  // Lo importante (compliance + atención) lo construye crm.buildCrmSnapshot
  // pero crm.js depende de fs y no de memory — para evitar el ciclo,
  // import dinámico aquí. Si falla, fallback a la versión cruda inline.
  try {
    // eslint-disable-next-line @typescript-eslint/no-var-requires
    const all = readJsonSafe(CRM_FILE_PATH, []);
    if (!all.length) return '';
    // Versión cruda — buildCrmSnapshot vive en crm.js y se usa
    // cuando tools.js lo llama. Para el contexto inline, replicamos
    // los conteos mínimos sin importar crm.js (evita ciclo).
    const counts = {
      lead: all.filter((c) => c.status === 'lead').length,
      prospect: all.filter((c) => c.status === 'prospect').length,
      active: all.filter((c) => c.status === 'active').length,
    };
    const cutoff30 = Date.now() - 30 * 86_400_000;
    const now = Date.now();
    const stale = all.filter(
      (c) => (c.status === 'active' || c.status === 'prospect') && new Date(c.ultimo_contacto || 0).getTime() < cutoff30,
    ).length;
    const renewals30 = all.filter(
      (c) => c.renewal_date && new Date(c.renewal_date).getTime() >= now && new Date(c.renewal_date).getTime() <= now + 30 * 86_400_000,
    ).length;
    // Compliance counters
    const mbiPending = all.filter((c) => (c.status === 'active' || c.status === 'prospect') && (c.mbi_verified?.status || 'pending') !== 'verified').length;
    const soaIssue = all.filter((c) => {
      const s = c.soa?.status || 'none';
      return s !== 'signed';
    }).length;
    const annualTouch = all.filter((c) => (c.status === 'active' || c.status === 'prospect')).filter((c) => {
      const cutoff = Date.now() - 12 * 30 * 86_400_000;
      const tps = c.aep_touchpoints || [];
      return tps.filter((t) => new Date(t.ts).getTime() >= cutoff).length === 0;
    }).length;
    const lines = [
      `CRM: ${all.length} clientes (${counts.active} activos, ${counts.prospect} prospects, ${counts.lead} leads).`,
      `Atención: ${stale} sin contactar 30+d · ${renewals30} renovaciones en 30d.`,
    ];
    const comp = [];
    if (mbiPending) comp.push(`${mbiPending} MBI pendiente`);
    if (soaIssue) comp.push(`${soaIssue} SOA faltante`);
    if (annualTouch) comp.push(`${annualTouch} sin touchpoint 12+m`);
    if (comp.length) lines.push(`Compliance: ${comp.join(' · ')}.`);
    return lines.join('\n');
  } catch {
    return '';
  }
}

function entitiesContextInline() {
  const rows = readJsonSafe(ENTITIES_FILE_PATH, []);
  if (!rows.length) return '';
  const recent = rows
    .slice()
    .sort((a, b) => new Date(b.ultima_mencion || b.actualizado || 0).getTime() - new Date(a.ultima_mencion || a.actualizado || 0).getTime())
    .slice(0, 12);
  const lines = recent.map((e) => {
    const top = (e.notas || []).slice().sort((a, b) => (b.salience || 5) - (a.salience || 5))[0];
    const blurb = top ? top.texto.slice(0, 70) : 'sin notas';
    return `  - [${e.id}] ${e.canonical_name} (${e.type}): ${blurb}`;
  });
  return `PERSONAS QUE RECONOZCO (entidades — usa entidad_anotar para añadir, entidad_expediente para profundizar):\n${lines.join('\n')}`;
}

// Snapshot 1-línea de gaps. NO el detalle completo (eso vive en la tool
// gaps_overview), solo "hay 4 altos, top campos faltantes son MBI, SOA,
// touchpoint_12m". El briefing pide el detalle cuando lo necesita.
function gapsContextInline() {
  try { return buildGapsSummary(); } catch { return ''; }
}

function signalsContextInline() {
  const blob = readJsonSafe(SIGNALS_FILE_PATH, { signals: [] });
  const sigs = blob.signals || [];
  if (!sigs.length) return '';
  const byPrio = ['alto', 'aviso', 'info'];
  const sorted = sigs.slice().sort((a, b) => byPrio.indexOf(a.severidad) - byPrio.indexOf(b.severidad));
  return `SEÑALES ACTIVAS (computadas anoche — úsalas para decidir qué traer arriba hoy):\n${sorted.map((s) => `  [${s.severidad}] ${s.mensaje}`).join('\n')}`;
}

function tasksContextInline() {
  const all = readJsonSafe(TASKS_FILE_PATH, []).filter((t) => t.status !== 'lista' && t.status !== 'cancelada');
  if (!all.length) return '';
  const groups = { athena: [], isabel: [], sami: [] };
  const tz = process.env.TIMEZONE || 'America/Los_Angeles';
  for (const t of all) {
    if (!groups[t.responsable]) continue;
    const due = t.vence
      ? ` (vence ${new Date(t.vence).toLocaleString('es-MX', { timeZone: tz, month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })})`
      : '';
    const pri = t.prioridad === 'alta' ? '★ ' : '';
    groups[t.responsable].push(`[${t.id}] ${pri}${t.descripcion}${due}`);
  }
  const lines = [];
  for (const owner of ['athena', 'isabel', 'sami']) {
    if (!groups[owner].length) continue;
    lines.push(`${owner.toUpperCase()} (${groups[owner].length}):\n${groups[owner].map((s) => `  - ${s}`).join('\n')}`);
  }
  return `TAREAS ACTIVAS — tu cola actual:\n${lines.join('\n')}`;
}

export function buildWikiContext() {
  const season = getSeason();
  const wiki = getWiki();
  const pending = getPendingOutbound();
  const parts = [];
  if (season.texto) parts.push(`TEMPORADA ACTUAL (en qué está enfocada Isabel ahora): ${season.texto}`);
  if (wiki.notas.length) {
    parts.push(wiki.notas.slice(0, 25).map((n) => `- ${n.nota}`).join('\n'));
  }
  const tasksCtx = tasksContextInline();
  if (tasksCtx) parts.push(tasksCtx);
  const commitCtx = commitmentsContextInline();
  if (commitCtx) parts.push(commitCtx);
  const crmCtx = crmSnapshotInline();
  if (crmCtx) parts.push(crmCtx);
  const entitiesCtx = entitiesContextInline();
  if (entitiesCtx) parts.push(entitiesCtx);
  const signalsCtx = signalsContextInline();
  if (signalsCtx) parts.push(signalsCtx);
  const gapsCtx = gapsContextInline();
  if (gapsCtx) parts.push(gapsCtx);
  if (pending.length) {
    const items = pending.map((p) => {
      if (p.type === 'email') return `- [${p.id}] EMAIL a ${p.para} · asunto: "${p.asunto}"`;
      if (p.type === 'sms') return `- [${p.id}] SMS a ${p.para}`;
      return `- [${p.id}] ${p.type}`;
    }).join('\n');
    parts.push(`BORRADORES PENDIENTES DE CONFIRMACIÓN (NO se han mandado — Isabel debe decir "envía" o "sí"):\n${items}`);
  }
  return parts.join('\n\n');
}

// ---- Historial de la conversación de WhatsApp con Athena ----
// La API de Anthropic es sin estado: hay que mandarle el historial
// completo cada vez. Lo guardamos aquí entre mensajes.
export function getHistory() {
  return load(HISTORY_FILE, []);
}

export function saveHistory(messages) {
  // Guardamos solo los últimos 40 turnos para no crecer sin límite.
  save(HISTORY_FILE, messages.slice(-40));
}

// ---- Audit log: TODA acción de Athena queda registrada ----
// Esto da trazabilidad ("¿qué hiciste hoy en mi nombre, Athena?") y
// es el backbone del comando /historial.
export function logActivity({ tool, input_summary, result_summary }) {
  // Redactamos PII antes de persistir — el audit log NO debe contener
  // teléfonos, emails, SSN o MBI en claro. Los expedientes de cliente
  // viven en crm.json (acceso controlado); aquí solo metadatos.
  const log = load(ACTIVITY_FILE, []);
  log.unshift({
    ts: new Date().toISOString(),
    tool,
    input_summary: redactPII(String(input_summary || '').slice(0, 200)),
    result_summary: redactPII(String(result_summary || '').slice(0, 200)),
  });
  save(ACTIVITY_FILE, log.slice(0, 500));
}

export function getActivity(sinceIso = null) {
  const log = load(ACTIVITY_FILE, []);
  if (!sinceIso) return log;
  return log.filter((e) => e.ts >= sinceIso);
}

// ---- Cola de envíos pendientes de confirmación ----
// Toda comunicación a TERCEROS (email + SMS a clientes) entra aquí.
// Solo se manda después de que Isabel diga "envía"/"sí".
// mensaje_a_sami NO usa esta cola porque Sami es humano-en-el-loop.
export function queueOutbound(item) {
  const queue = load(OUTBOUND_FILE, []);
  const id = `q${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
  queue.push({ id, ts: new Date().toISOString(), ...item });
  save(OUTBOUND_FILE, queue);
  return id;
}

export function getPendingOutbound() {
  return load(OUTBOUND_FILE, []);
}

export function popOutbound(id = null) {
  const queue = load(OUTBOUND_FILE, []);
  if (!queue.length) return null;
  let item;
  if (id) {
    const idx = queue.findIndex((q) => q.id === id);
    if (idx < 0) return null;
    [item] = queue.splice(idx, 1);
  } else {
    // Sin id → toma el último (el que acaba de redactarse, más probable).
    item = queue.pop();
  }
  save(OUTBOUND_FILE, queue);
  return item;
}

export function clearOutbound() {
  const queue = load(OUTBOUND_FILE, []);
  const n = queue.length;
  save(OUTBOUND_FILE, []);
  return n;
}

// ---- Contador diario de mensajes proactivos (para el rate-limit) ----
// Resetea automáticamente cada día.
export function getProactiveCount(dayKey) {
  const data = load(PROACTIVE_FILE, { day: null, count: 0 });
  if (data.day !== dayKey) return 0;
  return data.count;
}

export function bumpProactiveCount(dayKey) {
  const data = load(PROACTIVE_FILE, { day: null, count: 0 });
  if (data.day !== dayKey) {
    save(PROACTIVE_FILE, { day: dayKey, count: 1 });
    return 1;
  }
  data.count += 1;
  save(PROACTIVE_FILE, data);
  return data.count;
}
