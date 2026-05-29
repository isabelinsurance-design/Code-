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
// shortDate: para timestamps "instante en el tiempo" (creado, ultimo_contacto,
// SOA signed_at). Se muestra en la TZ de Isabel.
const shortDate = (iso) => iso
  ? new Date(iso).toLocaleDateString('es-MX', { timeZone: tz(), month: 'short', day: 'numeric', year: 'numeric' })
  : '—';
// shortCalDate: para fechas-de-calendario (fecha_nacimiento, renewal_date,
// effective_date, ICEP window, retention_until). Estas NO son momentos —
// son "el día X del calendario", por lo que se formatean en UTC para
// evitar el shift visual de -1 día.
const shortCalDate = (iso) => iso
  ? new Date(iso).toLocaleDateString('es-MX', { timeZone: 'UTC', month: 'short', day: 'numeric', year: 'numeric' })
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
    // ---- Compliance Medicare (CMS 2026 / Final Rule 2027) ----
    soa: { status: 'none', signed_at: null, version: null, products_discussed: [], retention_until: null },
    mbi_verified: { status: 'pending', source: null, verified_at: null },
    tcpa_consent: { granted: false, granted_at: null, version: null, language: null },
    aep_touchpoints: [], // [{ts, type, summary}] — para regla 12-meses de contacto
    drug_list: [],       // [{nombre, dosis, frecuencia, generico_o_marca}]
    providers: [],       // [{nombre, especialidad, ubicacion}]
    last_call_recording: null, // {url, transcript_ref, at}
    ahip_carrier_certs: [],    // [{carrier, year, completed_at, expires}]
    // ----------------------------------------------------------
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
// fecha_nacimiento es una fecha-calendario, por eso usamos getUTCMonth/Date
// para extraer mes/día sin que la TZ del server los corra un día.
export function upcomingBirthdays(diasVentana = 14) {
  const all = load().filter((c) => c.fecha_nacimiento);
  const today = new Date();
  return all.map((c) => {
    const fn = new Date(c.fecha_nacimiento);
    const next = new Date(Date.UTC(today.getUTCFullYear(), fn.getUTCMonth(), fn.getUTCDate()));
    if (next.getTime() < today.getTime()) next.setUTCFullYear(today.getUTCFullYear() + 1);
    const diasFalta = Math.ceil((next.getTime() - today.getTime()) / 86_400_000);
    return { ...c, diasFalta, proximo_cumple: next.toISOString() };
  })
    .filter((c) => c.diasFalta <= diasVentana)
    .sort((a, b) => a.diasFalta - b.diasFalta);
}

// ---- Cómputos derivados de Medicare ----

// T65 / aging-in: si el cumple 65 cae en los próximos 6 meses,
// devuelve la fecha y la ventana ICEP (3 meses antes hasta 3 meses
// después). OEP es ene-mar siempre.
export function t65Info(c, ventanaMeses = 6) {
  if (!c.fecha_nacimiento) return null;
  const fn = new Date(c.fecha_nacimiento);
  // Usamos UTC en todo el cómputo para que fecha_nacimiento "1961-09-15"
  // (que JS parsea como medianoche UTC) no se vuelva 14 sep en zonas con
  // offset negativo. Y devolvemos ISO de medianoche UTC del día calendario.
  const y65 = fn.getUTCFullYear() + 65;
  const m65 = fn.getUTCMonth();
  const d65 = fn.getUTCDate();
  const sixtyFive = new Date(Date.UTC(y65, m65, d65));
  const today = new Date();
  const diffMonths = (y65 - today.getUTCFullYear()) * 12 + (m65 - today.getUTCMonth());
  if (diffMonths < -3 || diffMonths > ventanaMeses) return null;
  const icep_start = new Date(Date.UTC(y65, m65 - 3, 1));
  const icep_end = new Date(Date.UTC(y65, m65 + 4, 0));
  return {
    cumple_65: sixtyFive.toISOString(),
    meses_para_65: diffMonths,
    icep_start: icep_start.toISOString(),
    icep_end: icep_end.toISOString(),
  };
}

// AEP es 15 oct - 7 dic cada año. Devuelve true si HOY estamos en AEP.
export function isAepNow(now = new Date()) {
  const year = now.getFullYear();
  const start = new Date(year, 9, 15);  // 15 oct
  const end = new Date(year, 11, 8);    // 7 dic (exclusivo el 8)
  return now >= start && now < end;
}

// Conteo de touchpoints en los últimos N meses (para el 12-month rule
// que se endurece en 2027). Default 12.
export function aepTouchpointCount(c, mesesAtras = 12) {
  const cutoff = Date.now() - mesesAtras * 30 * 86_400_000;
  return (c.aep_touchpoints || []).filter((t) => new Date(t.ts).getTime() >= cutoff).length;
}

// Clientes activos sin touchpoint en los últimos 12 meses (riesgo
// CMS por la regla de contacto). Crítico durante AEP.
export function clientsNeedingAnnualTouch() {
  return load()
    .filter((c) => c.status === 'active' || c.status === 'prospect')
    .filter((c) => aepTouchpointCount(c, 12) === 0)
    .sort((a, b) => new Date(a.ultimo_contacto || 0).getTime() - new Date(b.ultimo_contacto || 0).getTime());
}

// Clientes con MBI pendiente de verificar (los activos sin MBI verificado
// son una bomba para enrollment).
export function clientsWithMbiPending() {
  return load()
    .filter((c) => c.status === 'active' || c.status === 'prospect')
    .filter((c) => (c.mbi_verified?.status || 'pending') !== 'verified');
}

// Clientes con SOA expirada o sin SOA — para AEP/SEP necesitas SOA
// firmada antes de hablar de planes (CMS regla 48h + retención 10 años).
export function clientsWithSoaIssue() {
  return load()
    .filter((c) => c.status === 'active' || c.status === 'prospect' || c.status === 'lead')
    .filter((c) => {
      const s = c.soa?.status || 'none';
      if (s === 'signed') {
        const retain = c.soa?.retention_until ? new Date(c.soa.retention_until).getTime() : 0;
        return retain && retain < Date.now();
      }
      return s !== 'signed';
    });
}

// T65 pipeline: cumples 65 en los próximos N meses (default 6).
export function t65Pipeline(mesesVentana = 6) {
  return load()
    .filter((c) => c.fecha_nacimiento)
    .map((c) => ({ ...c, t65: t65Info(c, mesesVentana) }))
    .filter((c) => c.t65)
    .sort((a, b) => a.t65.meses_para_65 - b.t65.meses_para_65);
}

// ---- Mutadores de compliance ----

export function recordSoa(id, { version = '2026.1', products_discussed = [] } = {}) {
  const rows = load();
  const i = rows.findIndex((c) => c.id === id);
  if (i < 0) return null;
  const signed_at = nowIso();
  const retention_until = new Date(Date.now() + 10 * 365 * 86_400_000).toISOString(); // 10 años CMS
  rows[i].soa = { status: 'signed', signed_at, version, products_discussed, retention_until };
  rows[i].actualizado = nowIso();
  save(rows);
  return rows[i];
}

export function setMbiVerification(id, { status, source = null }) {
  const valid = ['verified', 'pending', 'invalid'];
  if (!valid.includes(status)) throw new Error(`status MBI inválido. Usa ${valid.join(', ')}`);
  const rows = load();
  const i = rows.findIndex((c) => c.id === id);
  if (i < 0) return null;
  rows[i].mbi_verified = { status, source, verified_at: status === 'verified' ? nowIso() : null };
  rows[i].actualizado = nowIso();
  save(rows);
  return rows[i];
}

export function recordTcpaConsent(id, { version = '2026.1', language = 'es' } = {}) {
  const rows = load();
  const i = rows.findIndex((c) => c.id === id);
  if (i < 0) return null;
  rows[i].tcpa_consent = { granted: true, granted_at: nowIso(), version, language };
  rows[i].actualizado = nowIso();
  save(rows);
  return rows[i];
}

export function addTouchpoint(id, { type, summary }) {
  const valid = ['call', 'email', 'sms', 'in_person', 'whatsapp'];
  if (!valid.includes(type)) throw new Error(`tipo de touchpoint inválido. Usa ${valid.join(', ')}`);
  const rows = load();
  const i = rows.findIndex((c) => c.id === id);
  if (i < 0) return null;
  rows[i].aep_touchpoints.push({ ts: nowIso(), type, summary: String(summary || '').trim() });
  rows[i].aep_touchpoints = rows[i].aep_touchpoints.slice(-50); // límite suave
  rows[i].ultimo_contacto = nowIso();
  rows[i].actualizado = nowIso();
  save(rows);
  return rows[i];
}

export function addDrug(id, { nombre, dosis = '', frecuencia = '', generico_o_marca = '' }) {
  const rows = load();
  const i = rows.findIndex((c) => c.id === id);
  if (i < 0) return null;
  rows[i].drug_list = rows[i].drug_list || [];
  rows[i].drug_list.push({ nombre: String(nombre).trim(), dosis, frecuencia, generico_o_marca, agregado: nowIso() });
  rows[i].actualizado = nowIso();
  save(rows);
  return rows[i];
}

export function removeDrug(id, nombre) {
  const rows = load();
  const i = rows.findIndex((c) => c.id === id);
  if (i < 0) return null;
  const n = String(nombre).toLowerCase();
  rows[i].drug_list = (rows[i].drug_list || []).filter((d) => d.nombre.toLowerCase() !== n);
  rows[i].actualizado = nowIso();
  save(rows);
  return rows[i];
}

export function addProvider(id, { nombre, especialidad = '', ubicacion = '' }) {
  const rows = load();
  const i = rows.findIndex((c) => c.id === id);
  if (i < 0) return null;
  rows[i].providers = rows[i].providers || [];
  rows[i].providers.push({ nombre: String(nombre).trim(), especialidad, ubicacion, agregado: nowIso() });
  rows[i].actualizado = nowIso();
  save(rows);
  return rows[i];
}

export function recordCallRecording(id, { url, transcript_ref = null }) {
  const rows = load();
  const i = rows.findIndex((c) => c.id === id);
  if (i < 0) return null;
  rows[i].last_call_recording = { url, transcript_ref, at: nowIso() };
  rows[i].actualizado = nowIso();
  save(rows);
  return rows[i];
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
  const annualTouch = clientsNeedingAnnualTouch().length;
  const mbiPending = clientsWithMbiPending().length;
  const soaIssue = clientsWithSoaIssue().length;
  const t65 = t65Pipeline(6).length;
  const lines = [
    `CRM — ${all.length} clientes (${counts.active} activos, ${counts.prospect} prospects, ${counts.lead} leads).`,
    `Atención: ${stale} sin contactar 30+d · ${renewals} renovaciones en 60d · ${bdays} cumpleaños en 14d.`,
  ];
  const compliance = [];
  if (annualTouch) compliance.push(`${annualTouch} sin touchpoint 12+m (regla CMS)`);
  if (mbiPending) compliance.push(`${mbiPending} con MBI pendiente`);
  if (soaIssue) compliance.push(`${soaIssue} con SOA faltante o vencida`);
  if (t65) compliance.push(`${t65} T65 en 6m`);
  if (compliance.length) lines.push(`Compliance: ${compliance.join(' · ')}.`);
  if (isAepNow()) lines.push('AEP ACTIVO — prioridad de outreach a activos sin touchpoint.');
  return lines.join('\n');
}

function clientLine(c, opts = {}) {
  const parts = [`[${c.id}]`, c.nombre];
  if (c.telefono) parts.push(c.telefono);
  if (c.carrier) parts.push(c.carrier);
  if (opts.showStatus !== false) parts.push(`(${c.status})`);
  if (opts.showLastContact) parts.push(`último: ${shortDate(c.ultimo_contacto)}`);
  if (opts.showRenewal && c.renewal_date) parts.push(`renew: ${shortCalDate(c.renewal_date)}`);
  return parts.join(' · ');
}

export function clientCard(c) {
  const lines = [
    `${c.nombre} [${c.id}]`,
    `Status: ${c.status}${c.fuente ? ` (fuente: ${c.fuente})` : ''}`,
  ];
  if (c.telefono) lines.push(`Tel: ${c.telefono}`);
  if (c.email) lines.push(`Email: ${c.email}`);
  if (c.fecha_nacimiento) lines.push(`Nacimiento: ${shortCalDate(c.fecha_nacimiento)}`);
  if (c.carrier || c.plan) lines.push(`Plan: ${c.carrier || ''} ${c.plan || ''}`.trim());
  if (c.mbi) {
    const mbiStatus = c.mbi_verified?.status || 'pending';
    const mbiIcon = mbiStatus === 'verified' ? '✓' : mbiStatus === 'invalid' ? '✗' : '?';
    lines.push(`MBI: ${c.mbi} ${mbiIcon} ${mbiStatus}${c.mbi_verified?.source ? ` (${c.mbi_verified.source})` : ''}`);
  }
  if (c.effective_date) lines.push(`Efectivo: ${shortCalDate(c.effective_date)}`);
  if (c.renewal_date) lines.push(`Renovación: ${shortCalDate(c.renewal_date)}`);

  // Compliance block
  if (c.soa) {
    const s = c.soa.status;
    // signed_at es un INSTANTE (cuándo se firmó), shortDate. retention_until
    // es una fecha-calendario (hasta qué día calendario hay que retenerla).
    if (s === 'signed') lines.push(`SOA: firmada ${shortDate(c.soa.signed_at)} (retención hasta ${shortCalDate(c.soa.retention_until)})`);
    else if (s !== 'none') lines.push(`SOA: ${s}`);
  }
  if (c.tcpa_consent?.granted) lines.push(`TCPA: consentido ${shortDate(c.tcpa_consent.granted_at)} (v${c.tcpa_consent.version})`);
  const t65 = t65Info(c, 6);
  if (t65) lines.push(`T65: en ${t65.meses_para_65} meses (ICEP ${shortCalDate(t65.icep_start)} → ${shortCalDate(t65.icep_end)})`);
  const aepCount = aepTouchpointCount(c, 12);
  if (aepCount) lines.push(`Touchpoints últimos 12m: ${aepCount}`);
  if (c.drug_list?.length) {
    lines.push(`Medicamentos (${c.drug_list.length}): ${c.drug_list.slice(0, 5).map((d) => d.nombre).join(', ')}${c.drug_list.length > 5 ? '…' : ''}`);
  }
  if (c.providers?.length) {
    lines.push(`Doctores (${c.providers.length}): ${c.providers.slice(0, 3).map((p) => p.nombre).join(', ')}${c.providers.length > 3 ? '…' : ''}`);
  }
  if (c.last_call_recording) lines.push(`Última llamada grabada: ${shortDate(c.last_call_recording.at)}`);

  lines.push(`Último contacto: ${shortDate(c.ultimo_contacto)}`);
  if (c.proximo_contacto) lines.push(`Próximo: ${shortDate(c.proximo_contacto)}`);
  if (c.notas.length) {
    lines.push('\nÚltimas notas:');
    for (const n of c.notas.slice(-5)) lines.push(`  · ${shortDate(n.ts)} — ${n.texto}`);
  }
  return lines.join('\n');
}

export { clientLine };
