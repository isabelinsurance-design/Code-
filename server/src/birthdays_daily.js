// ============================================================
//  Birthdays Daily — email diario con cumpleaños del día
//  ────────────────────────────────────────────────────────
//  Isabel: "Can you send an email of all the birthdays of all
//  the members that we have daily? to just put staff on the email."
//
//  Rutina robótica (gratis — no llama a Anthropic): pulls miembros
//  LUNA con cumpleaños HOY, formatea, manda email a todo el staff.
//  Costo real: ~$0.001 en Resend (~$0.36/año). Esencialmente gratis.
//
//  Cron sugerido: 6:30am todos los días (después del team_morning_email).
//
//  Modo PREVIEW: si TEAM_EMAIL_PREVIEW_INBOX está set, manda UN solo
//  email a esa bandeja en vez de a todo el equipo. Bueno para arranque.
// ============================================================
import { sendEmail } from './email.js';
import { birthdaysToday, lunaConfigured } from './luna_client.js';

const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

function fechaEspanol() {
  const tz = process.env.TIMEZONE || 'America/Los_Angeles';
  const fmt = new Intl.DateTimeFormat('en-CA', {
    timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short',
  });
  const parts = fmt.formatToParts(new Date());
  const get = (t) => parts.find((p) => p.type === t)?.value;
  const day = parseInt(get('day'), 10);
  const monthIdx = parseInt(get('month'), 10) - 1;
  const wkShort = get('weekday');
  const wkMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  const dia = DIAS[wkMap[wkShort] ?? 0];
  return `${dia} ${day} de ${MESES[monthIdx]}`;
}

// Lista de destinatarios — todo el staff. En preview mode todos van
// a la bandeja única de Isabel.
function staffEmails() {
  const preview = process.env.TEAM_EMAIL_PREVIEW_INBOX || 'isabel.medicareadvantage@gmail.com';
  if (preview) return [preview];
  const list = [
    process.env.ISABEL_EMAIL   || 'isabel.insurance@gmail.com',
    process.env.SAMI_EMAIL,
    process.env.SKARLETH_EMAIL,
    process.env.SURI_EMAIL,
    process.env.ARLETTE_EMAIL,
  ].filter(Boolean);
  return list;
}

// Calcula edad cumplida a partir de fecha_nacimiento + fecha de cumpleaños hoy.
function edadHoy(fechaNac) {
  try {
    const nac = new Date(fechaNac);
    if (isNaN(nac.getTime())) return null;
    const hoy = new Date();
    let edad = hoy.getFullYear() - nac.getFullYear();
    // Si todavía no ha llegado el día este año (raro porque filtramos por hoy),
    // restamos 1. Defensive.
    const yaPaso = (hoy.getMonth() > nac.getMonth()) ||
                   (hoy.getMonth() === nac.getMonth() && hoy.getDate() >= nac.getDate());
    if (!yaPaso) edad -= 1;
    return edad;
  } catch { return null; }
}

function formatearMiembro(m) {
  const nombre = m.nombre_completo || m.nombre || `id ${m.id}`;
  const edad = edadHoy(m.fecha_nacimiento);
  const tel = m.telefono || m.tel || null;
  const carrier = m.carrier_actual || m.carrier || null;
  const estado = m.estado || null;

  const parts = [];
  parts.push(`• ${nombre}`);
  if (edad != null) parts.push(`cumple ${edad}`);
  const meta = [];
  if (carrier) meta.push(carrier);
  if (estado) meta.push(estado);
  if (tel) meta.push(tel);
  if (meta.length) parts.push(`(${meta.join(' · ')})`);
  return parts.join(' — ');
}

function buildEmailBody(miembros, fecha) {
  if (!miembros || miembros.length === 0) {
    return `Buenos días equipo,

No hay cumpleaños hoy entre los miembros LUNA. Disfruten el día.

— Athena`;
  }
  const lines = [];
  lines.push('Buenos días equipo,');
  lines.push('');
  lines.push(`Hoy ${fecha} cumplen años ${miembros.length} miembro${miembros.length !== 1 ? 's' : ''}:`);
  lines.push('');
  for (const m of miembros) {
    lines.push(formatearMiembro(m));
  }
  lines.push('');
  lines.push('Tip: una llamada rapidita el día del cumple sube retención fuerte. Los datos están en LUNA.');
  lines.push('');
  lines.push('— Athena');
  return lines.join('\n');
}

export async function sendBirthdaysDaily() {
  const fecha = fechaEspanol();
  const recipients = staffEmails();
  if (!recipients.length) {
    console.warn('[birthdays_daily] no hay destinatarios configurados, saltando');
    return { ok: false, reason: 'no_recipients' };
  }

  let miembros = [];
  let endpointError = null;
  if (!lunaConfigured()) {
    endpointError = 'LUNA no está configurado (LUNA_BASE_URL / LUNA_API_KEY)';
  } else {
    try {
      const r = await birthdaysToday();
      if (r.ok && Array.isArray(r.data)) {
        miembros = r.data;
      } else if (r.kind === 'action_not_supported') {
        endpointError = 'el endpoint luna_birthdays_today todavía no existe del lado PHP de LUNA. Pídele a Sami que lo agregue.';
      } else {
        endpointError = r.error || 'respuesta inesperada de LUNA';
      }
    } catch (e) {
      endpointError = e.message;
    }
  }

  let cuerpo;
  if (endpointError) {
    cuerpo = `Buenos días equipo,

Hoy no pude traer la lista de cumpleaños (${endpointError}). Cuando esto se resuelva el email vuelve solo.

— Athena`;
  } else {
    cuerpo = buildEmailBody(miembros, fecha);
  }

  const subject = `PARA STAFF — Cumpleaños de hoy — ${fecha}`;
  const results = await Promise.all(
    recipients.map(async (to) => {
      try {
        const r = await sendEmail(to, subject, cuerpo);
        return { ok: true, to, result: r };
      } catch (e) {
        console.error(`[birthdays_daily] falló a ${to}: ${e.message}`);
        return { ok: false, to, error: e.message };
      }
    })
  );

  const enviados = results.filter((r) => r.ok).length;
  console.log(`[birthdays_daily] terminado: ${enviados}/${recipients.length} enviados, ${miembros.length} cumpleaños hoy`);
  return { enviados, miembros_count: miembros.length, endpointError };
}

// Ejecución manual: `node src/birthdays_daily.js`
if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  await sendBirthdaysDaily();
  process.exit(0);
}
