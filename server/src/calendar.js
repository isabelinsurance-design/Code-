// ============================================================
//  Integración con Google Calendar
//  ────────────────────────────────
//  Lectura de próximos eventos + brief de pre-junta 15 min antes.
//  Por ahora SOLO lectura — escribir/crear eventos requiere otro
//  scope y un humano-en-el-loop más cuidadoso, lo dejamos para
//  iteración siguiente.
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
