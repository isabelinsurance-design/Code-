// ============================================================
//  CRM ligero para Isabel — clientes Medicare + leads
//  ─────────────────────────────────────────────────────
//  Una base de datos chiquita que vive en data/crm.json y que
//  Athena maneja con sus herramientas. No es Salesforce, no
//  pretende serlo: es el "expediente vivo" de cada cliente con
//  lo que importa para una agente Medicare.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'crm.json');

const STATUSES = ['lead', 'prospect', 'active', 'inactive'];
const CARRIERS = ['SCAN', 'Anthem', 'Humana', 'Alignment', 'LA Care', 'Health Net', 'Molina', 'UHC', 'otro'];

function load() {
  try {
    if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch { /* ignore */ }
  return [];
}
function save(rows) {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
  writeFileSync(FILE, JSON.stringify(rows, null, 2));
}
function newId() {
  return `cl${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
}
const nowIso = () => new Date().toISOString();
const tz = () => process.env.TIMEZONE || 'America/Los_Angeles';
const shortDate = (iso) => iso
  ? new Date(iso).toLocaleDateString('es-MX', { timeZone: tz(), month: 'short', day: 'numeric', year: 'numeric' })
  : '—';

function normalizePhone(p) {
  if (!p) return '';
  const digits = String(p).replace(/\D/g, '');
  if (!digits) return '';
  if (digits.length === 10) return `+1${digits}`;
  if (digits.length === 11 && digits.startsWith('1')) return `+${digits}`;
  return `+${digits}`;
}

// ---- CRUD ----
export function createClient(input) {
  const {
    nombre, telefono = '', email = '', fecha_nacimiento = null,
    carrier = '', plan = '', mbi = '',
    effective_date = null, renewal_date = null,
    status = 'lead', fuente = '', notas_iniciales = '',
  } = input;
  if (!nombre || !String(nombre).trim()) throw new Error('Falta nombre.');
  if (!STATUSES.includes(status)) throw new Error(`Status inválido. Usa: ${STATUSES.join(', ')}.`);

  const rows = load();
  // Detección barata de duplicados por nombre+teléfono
  const phone = normalizePhone(telefono);
  const dup = rows.find((c) => c.nombre.toLowerCase() === nombre.toLowerCase() && (phone && c.telefono === phone));
  if (dup) {
    throw new Error(`Ya existe un cliente con ese nombre y teléfono: ${dup.id} (${dup.nombre}).`);
  }

  const c = {
    id: newId(),
    nombre: String(nombre).trim(),
    telefono: phone,
    email: String(email).trim().toLowerCase(),
    fecha_nacimiento,
    carrier: String(carrier).trim(),
    plan: String(plan).trim(),
    mbi: String(mbi).trim(),
    effective_date,
    renewal_date,
    status,
    fuente: String(fuente).trim(),
    tags: [],
    notas: notas_iniciales ? [{ ts: nowIso(), texto: String(notas_iniciales).trim() }] : [],
    ultimo_contacto: nowIso(),
    proximo_contacto: null,
    creado: nowIso(),
    actualizado: nowIso(),
  };
  rows.unshift(c);
  save(rows);
  return c;
}

export function updateClient(id, patchObj) {
  const rows = load();
  const i = rows.findIndex((c) => c.id === id);
  if (i < 0) return null;
  const allowed = ['nombre', 'telefono', 'email', 'fecha_nacimiento', 'carrier', 'plan', 'mbi', 'effective_date', 'renewal_date', 'status', 'fuente', 'proximo_contacto', 'tags'];
  const clean = {};
  for (const k of allowed) {
    if (patchObj[k] !== undefined) clean[k] = patchObj[k];
  }
  if (clean.telefono) clean.telefono = normalizePhone(clean.telefono);
  if (clean.email) clean.email = String(clean.email).trim().toLowerCase();
  if (clean.status && !STATUSES.includes(clean.status)) throw new Error(`Status inválido.`);
  rows[i] = { ...rows[i], ...clean, actualizado: nowIso() };
  save(rows);
  return rows[i];
}

export function addClientNote(id, texto) {
  const rows = load();
  const i = rows.findIndex((c) => c.id === id);
  if (i < 0) return null;
  rows[i].notas.push({ ts: nowIso(), texto: String(texto).trim() });
  rows[i].ultimo_contacto = nowIso();
  rows[i].actualizado = nowIso();
  save(rows);
  return rows[i];
}

export function findClient(query) {
  const q = String(query || '').toLowerCase().trim();
  if (!q) return [];
  const rows = load();
  return rows.filter((c) =>
    c.nombre.toLowerCase().includes(q) ||
    c.email.includes(q) ||
    c.telefono.includes(q.replace(/\D/g, '')) ||
    c.mbi.toLowerCase().includes(q) ||
    c.plan.toLowerCase().includes(q),
  );
}

export function getClient(id) {
  return load().find((c) => c.id === id) || null;
}

export function listClients({ status = null, limit = 50 } = {}) {
  return load()
    .filter((c) => (status ? c.status === status : true))
    .slice(0, limit);
}

// Clientes que no se han contactado en N días.
export function staleClients(diasUmbral = 30) {
  const cutoff = Date.now() - diasUmbral * 86_400_000;
  return load()
    .filter((c) => c.status === 'active' || c.status === 'prospect')
    .filter((c) => {
      const last = new Date(c.ultimo_contacto || 0).getTime();
      return last < cutoff;
    })
    .sort((a, b) => new Date(a.ultimo_contacto || 0).getTime() - new Date(b.ultimo_contacto || 0).getTime());
}

// Renovaciones próximas (default 60 días).
export function upcomingRenewals(diasVentana = 60) {
  const now = Date.now();
  const max = now + diasVentana * 86_400_000;
  return load()
    .filter((c) => c.renewal_date)
    .filter((c) => {
      const r = new Date(c.renewal_date).getTime();
      return r >= now && r <= max;
    })
    .sort((a, b) => new Date(a.renewal_date).getTime() - new Date(b.renewal_date).getTime());
}

// Cumpleaños próximos (default 14 días).
export function upcomingBirthdays(diasVentana = 14) {
  const all = load().filter((c) => c.fecha_nacimiento);
  const today = new Date();
  return all.map((c) => {
    const fn = new Date(c.fecha_nacimiento);
    const next = new Date(today.getFullYear(), fn.getMonth(), fn.getDate());
    if (next < today) next.setFullYear(today.getFullYear() + 1);
    const diasFalta = Math.ceil((next.getTime() - today.getTime()) / 86_400_000);
    return { ...c, diasFalta, proximo_cumple: next.toISOString() };
  })
    .filter((c) => c.diasFalta <= diasVentana)
    .sort((a, b) => a.diasFalta - b.diasFalta);
}

// Vista corta para el bloque de contexto de Athena.
export function buildCrmSnapshot() {
  const all = load();
  if (!all.length) return '';
  const counts = {
    lead: all.filter((c) => c.status === 'lead').length,
    prospect: all.filter((c) => c.status === 'prospect').length,
    active: all.filter((c) => c.status === 'active').length,
    inactive: all.filter((c) => c.status === 'inactive').length,
  };
  const stale = staleClients(30).length;
  const renewals = upcomingRenewals(60).length;
  const bdays = upcomingBirthdays(14).length;
  return `CRM SNAPSHOT — ${all.length} clientes (${counts.active} activos, ${counts.prospect} prospects, ${counts.lead} leads). Atención: ${stale} sin contactar 30+d, ${renewals} renovaciones en 60d, ${bdays} cumpleaños en 14d.`;
}

function clientLine(c, opts = {}) {
  const parts = [`[${c.id}]`, c.nombre];
  if (c.telefono) parts.push(c.telefono);
  if (c.carrier) parts.push(c.carrier);
  if (opts.showStatus !== false) parts.push(`(${c.status})`);
  if (opts.showLastContact) parts.push(`último: ${shortDate(c.ultimo_contacto)}`);
  if (opts.showRenewal && c.renewal_date) parts.push(`renew: ${shortDate(c.renewal_date)}`);
  return parts.join(' · ');
}

export function clientCard(c) {
  const lines = [
    `${c.nombre} [${c.id}]`,
    `Status: ${c.status}${c.fuente ? ` (fuente: ${c.fuente})` : ''}`,
  ];
  if (c.telefono) lines.push(`Tel: ${c.telefono}`);
  if (c.email) lines.push(`Email: ${c.email}`);
  if (c.fecha_nacimiento) lines.push(`Nacimiento: ${shortDate(c.fecha_nacimiento)}`);
  if (c.carrier || c.plan) lines.push(`Plan: ${c.carrier || ''} ${c.plan || ''}`.trim());
  if (c.mbi) lines.push(`MBI: ${c.mbi}`);
  if (c.effective_date) lines.push(`Efectivo: ${shortDate(c.effective_date)}`);
  if (c.renewal_date) lines.push(`Renovación: ${shortDate(c.renewal_date)}`);
  lines.push(`Último contacto: ${shortDate(c.ultimo_contacto)}`);
  if (c.proximo_contacto) lines.push(`Próximo: ${shortDate(c.proximo_contacto)}`);
  if (c.notas.length) {
    lines.push('\nÚltimas notas:');
    for (const n of c.notas.slice(-5)) lines.push(`  · ${shortDate(n.ts)} — ${n.texto}`);
  }
  return lines.join('\n');
}

export { clientLine };
