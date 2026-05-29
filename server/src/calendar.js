// ============================================================
//  Integración con Google Calendar
//  ────────────────────────────────
//  Lectura + escritura. Lectura: próximos eventos + brief de pre-junta
//  15 min antes. Escritura: crear, mover, cancelar. Disponibilidad:
//  findFreeSlots para que Athena proponga horas reales y listConflicts
//  como guard automático antes de crear evento.
//
//  Requiere variables de entorno:
//    GOOGLE_CLIENT_ID
//    GOOGLE_CLIENT_SECRET
//    GOOGLE_REFRESH_TOKEN   (Isabel hace el OAuth una vez y guarda esto)
//    GOOGLE_CALENDAR_ID     (opcional, default = "primary")
//
//  Si faltan, las herramientas devuelven un mensaje claro indicando
//  qué configurar — la app sigue funcionando sin calendar.
// ============================================================
import { google } from 'googleapis';
import { sendMessage } from './whatsapp.js';
import { canSendProactive } from './proactive.js';
import { bumpProactiveCount, logActivity } from './memory.js';

const TZ = () => process.env.TIMEZONE || 'America/Los_Angeles';

let _cachedClient = null;

export function calendarConfigured() {
  return Boolean(
    process.env.GOOGLE_CLIENT_ID &&
      process.env.GOOGLE_CLIENT_SECRET &&
      process.env.GOOGLE_REFRESH_TOKEN,
  );
}

function getCalendarClient() {
  if (_cachedClient) return _cachedClient;
  if (!calendarConfigured()) return null;
  const auth = new google.auth.OAuth2(
    process.env.GOOGLE_CLIENT_ID,
    process.env.GOOGLE_CLIENT_SECRET,
  );
  auth.setCredentials({ refresh_token: process.env.GOOGLE_REFRESH_TOKEN });
  _cachedClient = google.calendar({ version: 'v3', auth });
  return _cachedClient;
}

// Devuelve los próximos eventos de "ahora" hasta `withinHours`.
export async function listUpcomingEvents({ withinHours = 24, limit = 10 } = {}) {
  const cal = getCalendarClient();
  if (!cal) return { ok: false, reason: 'Google Calendar no está configurado (faltan GOOGLE_* en el .env).', events: [] };
  const calendarId = process.env.GOOGLE_CALENDAR_ID || 'primary';
  const now = new Date();
  const max = new Date(now.getTime() + withinHours * 3600_000);
  try {
    const res = await cal.events.list({
      calendarId,
      timeMin: now.toISOString(),
      timeMax: max.toISOString(),
      maxResults: limit,
      singleEvents: true,
      orderBy: 'startTime',
    });
    const events = (res.data.items || []).map(toLite);
    return { ok: true, events };
  } catch (err) {
    return { ok: false, reason: err.message, events: [] };
  }
}

// ---- ESCRITURA: crear / actualizar / cancelar eventos ----
// Cuando hay cliente_id, registramos automáticamente un touchpoint
// (call/in_person según el tipo de evento) para que la CMS regla de
// 12 meses cuente.
//
// IMPORTANTE: Google manda invitaciones a los attendees por default —
// estamos esencialmente metiéndonos en sus calendarios. Por eso el
// prompt de Athena exige confirmación explícita de Isabel antes de
// llamar crear_cita salvo casos triviales (eventos personales sin
// attendees).
export async function createEvent({
  titulo,
  inicio,           // ISO 8601 con tz, ej "2026-06-05T15:00:00-07:00"
  duracion_min = 30,
  descripcion = '',
  ubicacion = '',
  asistentes = [],  // array de emails
  conferencia = false,  // true = pide hangoutLink (Google Meet)
  evitar_conflicto = true, // si true y la hora choca con otro evento, no crea
}) {
  const cal = getCalendarClient();
  if (!cal) return { ok: false, reason: 'Calendar no configurado.' };
  if (!titulo) return { ok: false, reason: 'Falta título.' };
  if (!inicio) return { ok: false, reason: 'Falta hora de inicio.' };
  const start = new Date(inicio);
  if (isNaN(start.getTime())) return { ok: false, reason: 'inicio no es una fecha válida.' };
  const end = new Date(start.getTime() + Number(duracion_min) * 60_000);

  const calendarId = process.env.GOOGLE_CALENDAR_ID || 'primary';

  // Guard de conflicto: si ya hay algo agendado en la ventana,
  // devolvemos los eventos que chocan en vez de crear duplicado.
  if (evitar_conflicto) {
    const conflicts = await listConflicts(start, end);
    if (conflicts.length > 0) {
      return { ok: false, reason: 'conflicto', conflictos: conflicts };
    }
  }

  const requestBody = {
    summary: titulo,
    description: descripcion || undefined,
    location: ubicacion || undefined,
    start: { dateTime: start.toISOString(), timeZone: TZ() },
    end: { dateTime: end.toISOString(), timeZone: TZ() },
    attendees: asistentes.filter((a) => a && a.includes('@')).map((email) => ({ email })),
  };
  // Google Meet requiere conferenceData + conferenceDataVersion=1
  const params = { calendarId, requestBody, sendUpdates: 'all' };
  if (conferencia) {
    requestBody.conferenceData = {
      createRequest: {
        requestId: `athena-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
        conferenceSolutionKey: { type: 'hangoutsMeet' },
      },
    };
    params.conferenceDataVersion = 1;
  }
  try {
    const res = await cal.events.insert(params);
    return { ok: true, event: toLite(res.data, true) };
  } catch (err) {
    return { ok: false, reason: err.message };
  }
}

export async function updateEvent(eventId, patch) {
  const cal = getCalendarClient();
  if (!cal) return { ok: false, reason: 'Calendar no configurado.' };
  const calendarId = process.env.GOOGLE_CALENDAR_ID || 'primary';
  // Traemos el evento actual primero para no perder campos que el patch
  // no toque (Google espera el body completo en update; usamos patch
  // para el merge automático).
  const requestBody = {};
  if (patch.titulo !== undefined) requestBody.summary = patch.titulo;
  if (patch.descripcion !== undefined) requestBody.description = patch.descripcion;
  if (patch.ubicacion !== undefined) requestBody.location = patch.ubicacion;
  if (patch.inicio || patch.duracion_min) {
    const current = await cal.events.get({ calendarId, eventId });
    const currStart = current.data.start?.dateTime;
    const newStart = patch.inicio ? new Date(patch.inicio) : new Date(currStart);
    if (isNaN(newStart.getTime())) return { ok: false, reason: 'inicio inválido.' };
    const dur = patch.duracion_min
      ? Number(patch.duracion_min)
      : (new Date(current.data.end?.dateTime).getTime() - new Date(currStart).getTime()) / 60_000;
    const newEnd = new Date(newStart.getTime() + dur * 60_000);
    requestBody.start = { dateTime: newStart.toISOString(), timeZone: TZ() };
    requestBody.end = { dateTime: newEnd.toISOString(), timeZone: TZ() };
  }
  if (Array.isArray(patch.asistentes)) {
    requestBody.attendees = patch.asistentes.filter((a) => a && a.includes('@')).map((email) => ({ email }));
  }
  try {
    const res = await cal.events.patch({
      calendarId,
      eventId,
      requestBody,
      sendUpdates: 'all',
    });
    return { ok: true, event: toLite(res.data, true) };
  } catch (err) {
    return { ok: false, reason: err.message };
  }
}

export async function deleteEvent(eventId) {
  const cal = getCalendarClient();
  if (!cal) return { ok: false, reason: 'Calendar no configurado.' };
  const calendarId = process.env.GOOGLE_CALENDAR_ID || 'primary';
  try {
    await cal.events.delete({ calendarId, eventId, sendUpdates: 'all' });
    return { ok: true };
  } catch (err) {
    return { ok: false, reason: err.message };
  }
}

export async function getEvent(eventId) {
  const cal = getCalendarClient();
  if (!cal) return { ok: false, reason: 'Calendar no configurado.' };
  const calendarId = process.env.GOOGLE_CALENDAR_ID || 'primary';
  try {
    const res = await cal.events.get({ calendarId, eventId });
    return { ok: true, event: toLite(res.data, true) };
  } catch (err) {
    return { ok: false, reason: err.message };
  }
}

function toLite(ev, withDetails = false) {
  const start = ev.start?.dateTime || ev.start?.date;
  const end = ev.end?.dateTime || ev.end?.date;
  const out = {
    id: ev.id,
    titulo: ev.summary || '(sin título)',
    inicio: start,
    fin: end,
    inicio_local: start ? new Date(start).toLocaleString('es-MX', { timeZone: TZ(), weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : null,
    ubicacion: ev.location || '',
    asistentes: (ev.attendees || []).map((a) => a.displayName || a.email).filter(Boolean),
    organizador: ev.organizer?.displayName || ev.organizer?.email || '',
    link: ev.htmlLink || '',
  };
  if (withDetails) {
    out.descripcion = ev.description || '';
    out.meet = ev.hangoutLink || '';
  }
  return out;
}

// ---- Pre-meeting brief: 15 min antes de cada cita, Athena le manda
//      un brief corto a Isabel. ----
// Memoria efímera de qué ya recordamos en esta ejecución (en RAM,
// se reinicia con el server — está bien, los eventos también).
const _remindedRecently = new Map(); // eventId → ts

function isFresh(id) {
  const t = _remindedRecently.get(id);
  if (!t) return true;
  return Date.now() - t > 30 * 60_000; // 30 min de cooldown
}

export async function checkUpcomingMeetingsTick() {
  if (!calendarConfigured()) return;
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) return;

  const { ok, events } = await listUpcomingEvents({ withinHours: 0.5, limit: 5 });
  if (!ok || !events.length) return;

  const now = Date.now();
  const due = events.filter((e) => {
    if (!e.inicio) return false;
    const startMs = new Date(e.inicio).getTime();
    const minsAway = (startMs - now) / 60_000;
    // Avisamos cuando faltan entre 10 y 20 minutos (margen para el tick).
    return minsAway > 10 && minsAway < 20 && isFresh(e.id);
  });

  for (const ev of due) {
    const gate = canSendProactive();
    if (!gate.ok) {
      console.log(`[cal] no avisar de "${ev.titulo}": ${gate.reason}`);
      break;
    }
    const lines = [];
    lines.push(`En ~15 min: ${ev.titulo} (${ev.inicio_local}).`);
    if (ev.ubicacion) lines.push(`Lugar: ${ev.ubicacion}`);
    if (ev.asistentes.length) lines.push(`Con: ${ev.asistentes.join(', ')}`);
    const brief = lines.join('\n');
    await sendMessage(to, brief);
    _remindedRecently.set(ev.id, now);
    bumpProactiveCount(gate.dayKey);
    logActivity({ tool: 'pre_meeting_brief', input_summary: ev.id, result_summary: ev.titulo });
  }
}

// ============================================================
//  Disponibilidad: huecos + detección de conflictos
//  ────────────────────────────────────────────────
//  buscar_huecos le da a Athena la lista de horas reales en que
//  Isabel está libre, para que proponga citas que NO chocan con
//  nada. Sin esto, Athena sugiere a ciegas y luego falla al crear.
// ============================================================

// Devuelve los eventos que se traslapan con [start, end). Vacío si está libre.
async function listConflicts(start, end) {
  const cal = getCalendarClient();
  if (!cal) return [];
  const calendarId = process.env.GOOGLE_CALENDAR_ID || 'primary';
  try {
    const res = await cal.events.list({
      calendarId,
      timeMin: new Date(start.getTime() - 60_000).toISOString(),
      timeMax: new Date(end.getTime() + 60_000).toISOString(),
      singleEvents: true,
      orderBy: 'startTime',
    });
    return (res.data.items || [])
      .filter((ev) => {
        const evStart = new Date(ev.start?.dateTime || ev.start?.date).getTime();
        const evEnd = new Date(ev.end?.dateTime || ev.end?.date).getTime();
        return evStart < end.getTime() && evEnd > start.getTime();
      })
      .map((ev) => toLite(ev, false));
  } catch {
    return [];
  }
}

// Hora local (HH:MM) y día de la semana (0=Dom..6=Sáb) en la TZ configurada.
function tzClock(date, tz) {
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone: tz,
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    weekday: 'short',
  });
  const parts = fmt.formatToParts(date);
  const hour = parseInt(parts.find((p) => p.type === 'hour').value, 10);
  const minute = parseInt(parts.find((p) => p.type === 'minute').value, 10);
  const wd = parts.find((p) => p.type === 'weekday').value;
  const dayMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  return { minOfDay: hour * 60 + minute, dow: dayMap[wd] };
}

function parseHHMM(s) {
  const [h, m] = String(s || '').split(':').map(Number);
  return (h || 0) * 60 + (m || 0);
}

// Busca huecos libres entre fecha_inicio y fecha_fin, respetando
// horario laboral, días de la semana y un buffer entre citas.
export async function findFreeSlots({
  fecha_inicio,
  fecha_fin,
  duracion_min = 30,
  horario = { inicio: '09:00', fin: '17:00' },
  dias_semana = [1, 2, 3, 4, 5],
  buffer_min = 15,
  step_min = 30,
  limit = 12,
} = {}) {
  const cal = getCalendarClient();
  if (!cal) return { ok: false, reason: 'Calendar no configurado.', slots: [] };

  const start = new Date(fecha_inicio);
  const end = new Date(fecha_fin);
  if (isNaN(start.getTime()) || isNaN(end.getTime())) {
    return { ok: false, reason: 'fecha_inicio o fecha_fin inválida.', slots: [] };
  }
  if (end <= start) {
    return { ok: false, reason: 'fecha_fin debe ser posterior a fecha_inicio.', slots: [] };
  }

  // Limita la ventana a 30 días para no abusar de la API.
  const MAX_WINDOW_MS = 30 * 86_400_000;
  if (end - start > MAX_WINDOW_MS) {
    return { ok: false, reason: 'Ventana máxima de 30 días.', slots: [] };
  }

  // Eventos ocupados
  const calendarId = process.env.GOOGLE_CALENDAR_ID || 'primary';
  let busy = [];
  try {
    const fb = await cal.freebusy.query({
      requestBody: {
        timeMin: start.toISOString(),
        timeMax: end.toISOString(),
        timeZone: TZ(),
        items: [{ id: calendarId }],
      },
    });
    busy = (fb.data.calendars?.[calendarId]?.busy || []).map((b) => ({
      start: new Date(b.start).getTime(),
      end: new Date(b.end).getTime(),
    }));
  } catch (err) {
    return { ok: false, reason: err.message, slots: [] };
  }

  const tz = TZ();
  const whStart = parseHHMM(horario.inicio || '09:00');
  const whEnd = parseHHMM(horario.fin || '17:00');
  const stepMs = Number(step_min) * 60_000;
  const durMs = Number(duracion_min) * 60_000;
  const bufMs = Number(buffer_min) * 60_000;

  // Snap cursor al siguiente múltiplo de step_min en UTC para limpieza visual.
  const slots = [];
  let cursor = new Date(Math.ceil(start.getTime() / stepMs) * stepMs);
  while (cursor < end && slots.length < limit) {
    const slotEnd = new Date(cursor.getTime() + durMs);
    if (slotEnd > end) break;

    const clockStart = tzClock(cursor, tz);
    const clockEnd = tzClock(new Date(slotEnd.getTime() - 1), tz);

    const dayOk = dias_semana.includes(clockStart.dow) && dias_semana.includes(clockEnd.dow);
    const inHours = clockStart.minOfDay >= whStart && clockEnd.minOfDay <= whEnd;

    if (dayOk && inHours) {
      const sMs = cursor.getTime();
      const eMs = slotEnd.getTime();
      const conflict = busy.some((b) => !(eMs + bufMs <= b.start || sMs >= b.end + bufMs));
      if (!conflict) {
        slots.push({
          inicio: cursor.toISOString(),
          fin: slotEnd.toISOString(),
          inicio_local: cursor.toLocaleString('es-MX', {
            timeZone: tz,
            weekday: 'short',
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
          }),
          duracion_min,
        });
      }
    }
    cursor = new Date(cursor.getTime() + stepMs);
  }
  return { ok: true, slots };
}

// Para CLI: `node src/calendar.js list` o `node src/calendar.js tick`
if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  const cmd = process.argv[2];
  if (cmd === 'list') {
    console.log(JSON.stringify(await listUpcomingEvents({ withinHours: 48 }), null, 2));
  } else if (cmd === 'tick') {
    await checkUpcomingMeetingsTick();
  } else {
    console.error('Uso: node src/calendar.js [list|tick]');
    process.exit(1);
  }
  process.exit(0);
}
