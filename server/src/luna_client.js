// ============================================================
//  LUNA client — puente Athena ↔ LUNA (PHP/MySQL en Bluehost)
//  ──────────────────────────────────────────────────────────
//  LUNA es el workspace del equipo (Skarleth, Arlette, Samia)
//  + el CRM operacional en MySQL. Vive aparte de Athena.
//  Este módulo le permite a Athena leer/escribir contra la
//  base de datos REAL de LUNA, así Pilar deja de tener su
//  propio `data/crm.json` paralelo y todos comparten verdad.
//
//  Auth: shared secret en header X-LUNA-Key (definido por el equipo
//  LUNA). El endpoint ya acepta este header como bypass de session,
//  no requiere patch — el equipo de LUNA lo construyó del lado PHP.
//
//  Variables de entorno:
//    LUNA_BASE_URL    URL completa al endpoint. Ej:
//                     https://withisabelfuentes.com/luna/luna_api.php
//    LUNA_API_KEY     Shared secret provisto por equipo LUNA.
//                     Pegar directo en Railway, NUNCA en repo.
//
//  Si LUNA_BASE_URL no está, lunaConfigured() devuelve false y
//  todas las tools devuelven un mensaje claro de "no configurado"
//  sin romper Athena.
// ============================================================
//  El bypass de session lo construyó el equipo LUNA (junio 2026).
//  Ya no se requiere patch del lado de Athena.
// ============================================================

import { logActivity } from './memory.js';

const TIMEOUT_MS = 12_000;

export function lunaConfigured() {
  return Boolean(process.env.LUNA_BASE_URL && process.env.LUNA_API_KEY);
}

function baseUrl() {
  return process.env.LUNA_BASE_URL.replace(/\/+$/, '');
}

// PII redactor para el audit log — mismo patrón que security.js.
function redactPii(s) {
  if (!s) return s;
  return String(s)
    .replace(/\b[A-Z0-9]{4,5}-?[A-Z0-9]{3}-?[A-Z0-9]{2,4}\b/gi, '<MBI>')
    .replace(/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/g, '<phone>')
    .replace(/[\w.+-]+@[\w-]+\.[\w.-]+/g, '<email>');
}

async function lunaFetch(action, { method = 'GET', params = {}, body = null } = {}) {
  if (!lunaConfigured()) {
    return { ok: false, error: 'LUNA no está configurado (LUNA_BASE_URL / LUNA_API_KEY).' };
  }

  const url = new URL(baseUrl());
  url.searchParams.set('action', `luna_${action}`);
  if (method === 'GET') {
    for (const [k, v] of Object.entries(params)) {
      if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));
    }
  }

  const init = {
    method,
    headers: { 'X-LUNA-Key': process.env.LUNA_API_KEY },
    signal: AbortSignal.timeout(TIMEOUT_MS),
  };

  if (method === 'POST' && body) {
    const fd = new URLSearchParams();
    for (const [k, v] of Object.entries(body)) {
      if (v !== undefined && v !== null) fd.append(k, String(v));
    }
    init.body = fd;
    init.headers['Content-Type'] = 'application/x-www-form-urlencoded';
  }

  try {
    const res = await fetch(url.toString(), init);
    if (!res.ok) return { ok: false, error: `HTTP ${res.status}: ${res.statusText}` };
    const data = await res.json();
    return data;
  } catch (err) {
    return { ok: false, error: err.message || 'fetch failed' };
  }
}

// ============================================================
//  Lectura — Pilar consulta LUNA cuando Isabel pregunta
// ============================================================

export async function searchMember(query) {
  if (!query) return { ok: false, error: 'Falta query.' };
  return lunaFetch('search_member', { params: { q: query } });
}

export async function memberDetail(memberId) {
  if (!memberId) return { ok: false, error: 'Falta miembro_id.' };
  return lunaFetch('member_detail', { params: { id: memberId } });
}

export async function pipelineSummary() {
  return lunaFetch('pipeline_summary');
}

export async function fullBriefing() {
  return lunaFetch('full_briefing');
}

export async function t65Alerts({ days = 90 } = {}) {
  return lunaFetch('t65_alerts', { params: { days } });
}

export async function retentionAlerts() {
  return lunaFetch('retention_alerts');
}

export async function hotLeads() {
  return lunaFetch('hot_leads');
}

export async function pendingSoa() {
  return lunaFetch('pending_soa');
}

export async function openTickets({ priority = '' } = {}) {
  return lunaFetch('open_tickets', { params: { priority } });
}

export async function todayAppointments() {
  return lunaFetch('today_appointments');
}

export async function recentActivity({ limit = 20 } = {}) {
  return lunaFetch('recent_activity', { params: { limit } });
}

export async function carriersBreakdown() {
  return lunaFetch('carriers_breakdown');
}

// ============================================================
//  Escritura — Isabel dicta, Pilar escribe a LUNA en tiempo real
// ============================================================

export async function addMemberNote(memberId, nota) {
  if (!memberId || !nota) return { ok: false, error: 'Faltan miembro_id o nota.' };
  const r = await lunaFetch('add_member_note', {
    method: 'POST',
    body: { miembro_id: memberId, nota },
  });
  logActivity({
    tool: 'luna_add_member_note',
    input_summary: `id=${memberId} nota=${redactPii(nota).slice(0, 80)}`,
    result_summary: r.ok ? 'nota escrita en LUNA' : `error: ${r.error}`,
  });
  return r;
}

export async function logActivityToLuna({ tipo = 'NOTA', descripcion, memberId = null }) {
  if (!descripcion) return { ok: false, error: 'Falta descripcion.' };
  const r = await lunaFetch('log_activity', {
    method: 'POST',
    body: { tipo, descripcion, miembro_id: memberId || '' },
  });
  logActivity({
    tool: 'luna_log_activity',
    input_summary: `${tipo}: ${redactPii(descripcion).slice(0, 80)}`,
    result_summary: r.ok ? 'log escrito en LUNA' : `error: ${r.error}`,
  });
  return r;
}

export async function createMember(data) {
  if (!data?.nombre) return { ok: false, error: 'Falta nombre.' };
  const body = {
    nombre: data.nombre,
    apellido: data.apellido || '',
    telefono: data.telefono || '',
    email: data.email || '',
    dob: data.fecha_nacimiento || data.dob || '',
    estado: data.estado || 'PROSPECTO',
    ciudad: data.ciudad || '',
    fuente: data.fuente || 'Athena (Isabel verbal)',
  };
  const r = await lunaFetch('create_member', { method: 'POST', body });
  logActivity({
    tool: 'luna_create_member',
    input_summary: `${body.nombre} ${body.apellido} estado=${body.estado}`,
    result_summary: r.ok ? `creado id=${r.data?.id || '?'}` : `error: ${r.error}`,
  });
  return r;
}

// Tipos válidos de ticket en LUNA — la columna `tickets.tipo` es ENUM,
// MySQL trunca + warning si recibe algo distinto. Mantener sincronizado
// con el schema MySQL. Default fallback: SEGUIMIENTO (cubre la mayoría).
const TICKET_TIPOS_VALIDOS = new Set([
  'SEGUIMIENTO',
  'LLAMADA',
  'CITA',
  'SERVICIO',
  'RECORDATORIO',
  'COMPLIANCE',
  'DOCUMENTACION',
]);

// Mapeo defensivo de variantes comunes (inglés, lowercase, sinónimos) →
// el valor canónico aceptado por LUNA. Si Pilar manda "follow-up" o
// "seguimiento" o "Follow Up", todo normaliza a SEGUIMIENTO.
const TICKET_TIPO_ALIAS = {
  'follow-up': 'SEGUIMIENTO', 'followup': 'SEGUIMIENTO', 'follow up': 'SEGUIMIENTO',
  'seguimiento': 'SEGUIMIENTO',
  'call': 'LLAMADA', 'phone': 'LLAMADA', 'phone call': 'LLAMADA', 'telefono': 'LLAMADA',
  'llamada': 'LLAMADA',
  'appointment': 'CITA', 'meeting': 'CITA', 'cita': 'CITA',
  'service': 'SERVICIO', 'servicio': 'SERVICIO',
  'reminder': 'RECORDATORIO', 'recordatorio': 'RECORDATORIO',
  'compliance': 'COMPLIANCE', 'soa': 'COMPLIANCE', 'mbi': 'COMPLIANCE',
  'documentation': 'DOCUMENTACION', 'docs': 'DOCUMENTACION', 'documentacion': 'DOCUMENTACION', 'documentación': 'DOCUMENTACION',
};

function normalizeTicketTipo(input) {
  if (!input) return 'SEGUIMIENTO';
  const up = String(input).trim().toUpperCase();
  if (TICKET_TIPOS_VALIDOS.has(up)) return up;
  const lower = String(input).trim().toLowerCase();
  if (TICKET_TIPO_ALIAS[lower]) return TICKET_TIPO_ALIAS[lower];
  // Si Pilar mandó algo creativo, default a SEGUIMIENTO (no fail).
  console.warn(`[luna] tipo de ticket no reconocido "${input}" — usando SEGUIMIENTO`);
  return 'SEGUIMIENTO';
}

const TICKET_PRIORIDADES_VALIDAS = new Set(['ALTA', 'MEDIA', 'BAJA']);
function normalizeTicketPrioridad(input) {
  if (!input) return 'MEDIA';
  const up = String(input).trim().toUpperCase();
  if (TICKET_PRIORIDADES_VALIDAS.has(up)) return up;
  // Aliases comunes
  if (['HIGH', 'URGENT', 'CRITICAL', 'URGENTE'].includes(up)) return 'ALTA';
  if (['LOW', 'NORMAL'].includes(up)) return 'BAJA';
  return 'MEDIA';
}

export async function createTicket(data) {
  if (!data?.descripcion) return { ok: false, error: 'Falta descripcion.' };
  const body = {
    tipo: normalizeTicketTipo(data.tipo),
    prioridad: normalizeTicketPrioridad(data.prioridad),
    descripcion: data.descripcion,
    miembro_id: data.miembro_id || '',
    asignado_a: data.asignado_a || '',
  };
  const r = await lunaFetch('create_ticket', { method: 'POST', body });
  logActivity({
    tool: 'luna_create_ticket',
    input_summary: `${body.tipo}/${body.prioridad}: ${redactPii(body.descripcion).slice(0, 80)}`,
    result_summary: r.ok ? `ticket creado id=${r.data?.id || '?'}` : `error: ${r.error}`,
  });
  return r;
}

export async function createAppointment(data) {
  if (!data?.miembro_id || !data?.fecha) {
    return { ok: false, error: 'Faltan miembro_id o fecha.' };
  }
  const body = {
    miembro_id: data.miembro_id,
    fecha: data.fecha,
    hora: data.hora || '',
    tipo: data.tipo || 'CONSULTA',
    modalidad: data.modalidad || 'TELÉFONO',
  };
  const r = await lunaFetch('create_appointment', { method: 'POST', body });
  logActivity({
    tool: 'luna_create_appointment',
    input_summary: `miembro=${body.miembro_id} ${body.fecha} ${body.hora}`,
    result_summary: r.ok ? `cita creada id=${r.data?.id || '?'}` : `error: ${r.error}`,
  });
  return r;
}

// ============================================================
//  Helpers de presentación — formatear respuestas LUNA para
//  el contexto de WhatsApp (Athena es texto, no UI).
// ============================================================

export function formatMemberCard(m) {
  if (!m) return '(sin datos)';
  const lines = [];
  const nombre = `${m.nombre || ''} ${m.apellido || ''}`.trim();
  lines.push(`📋 ${nombre} · id=${m.id}`);
  if (m.estado) lines.push(`Estado: ${m.estado}`);
  if (m.carrier) lines.push(`Carrier: ${m.carrier}${m.plan ? ` — ${m.plan}` : ''}`);
  if (m.fecha_efectiva) lines.push(`Efectivo desde: ${String(m.fecha_efectiva).slice(0, 10)}`);
  if (m.telefono) lines.push(`Tel: ${m.telefono}`);
  if (m.email) lines.push(`Email: ${m.email}`);
  if (m.soa_status) lines.push(`SOA: ${m.soa_status}`);
  if (m.mbi) lines.push(`MBI: ${m.mbi.slice(0, 4)}…`);
  return lines.join('\n');
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  console.log('LUNA configured:', lunaConfigured());
  if (lunaConfigured()) {
    console.log('Base URL:', baseUrl());
    console.log('\nProbando pipeline_summary...');
    console.log(JSON.stringify(await pipelineSummary(), null, 2));
  }
  process.exit(0);
}
