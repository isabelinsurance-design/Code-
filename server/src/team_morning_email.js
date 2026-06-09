// ============================================================
//  Team Morning Email — 6am daily, una por persona del equipo
//  ────────────────────────────────────────────────────────
//  Isabel pidió esto el sábado 6 jun 2026: que cada mañana
//  Sami, Skarleth, Arlette y ella misma reciban un email
//  con sus tareas del día. Email — no WhatsApp — porque es
//  archivable, scanneable, y arranca el día con claridad.
//
//  Source de tareas: LUNA tickets abiertos asignados a cada
//  persona, agrupados por prioridad. Para Isabel además
//  incluye sus tareas Athena (responsable: isabel) y
//  compromisos pendientes de terceros.
//
//  Si LUNA está caída: el email sale igual, pero dice "no
//  pude conectarme a LUNA hoy, chéquenla manual". No oculta
//  el fallo y no rompe el cron.
// ============================================================
import { sendEmail } from './email.js';
import { openTickets, lunaConfigured } from './luna_client.js';

// Roster del equipo. Email de cada uno se lee de env var.
// MODO PREVIEW: si TEAM_EMAIL_PREVIEW_INBOX está set, TODOS los emails
// se mandan a ese inbox (Isabel revisa los 4 en su propia bandeja antes
// de que cada persona los reciba). El nombre del destinatario va en el
// subject así Isabel los distingue. Bueno para arranque + verificación.
function teamRoster() {
  const preview = process.env.TEAM_EMAIL_PREVIEW_INBOX || 'isabel.medicareadvantage@gmail.com';
  const usePreview = Boolean(preview);
  return [
    { id: 6,  nombre: 'Isabel',   email: usePreview ? preview : (process.env.ISABEL_EMAIL   || 'isabel.insurance@gmail.com'), is_owner: true },
    { id: 7,  nombre: 'Skarleth', email: usePreview ? preview : (process.env.SKARLETH_EMAIL || null) },
    { id: 8,  nombre: 'Suri',     email: usePreview ? preview : (process.env.SURI_EMAIL     || null) },
    { id: 9,  nombre: 'Arlette',  email: usePreview ? preview : (process.env.ARLETTE_EMAIL  || null) },
    { id: 10, nombre: 'Sami',     email: usePreview ? preview : (process.env.SAMI_EMAIL     || null) },
  ];
}

// Días en español para el subject
const DIAS = ['Domingo', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

function fechaEspanol() {
  const tz = process.env.TIMEZONE || 'America/Los_Angeles';
  // Pull date components in TZ to avoid UTC drift
  const fmt = new Intl.DateTimeFormat('en-CA', {
    timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short',
  });
  const parts = fmt.formatToParts(new Date());
  const get = (t) => parts.find((p) => p.type === t)?.value;
  const day = parseInt(get('day'), 10);
  const monthIdx = parseInt(get('month'), 10) - 1;
  // weekday short -> dayOfWeek via a fresh Date in local
  const wkShort = get('weekday'); // Sun, Mon, ...
  const wkMap = { Sun: 0, Mon: 1, Tue: 2, Wed: 3, Thu: 4, Fri: 5, Sat: 6 };
  const dia = DIAS[wkMap[wkShort] ?? 0];
  return `${dia} ${day} de ${MESES[monthIdx]}`;
}

// Compone el bloque de tickets de UNA persona, agrupado por prioridad
function ticketsBlock(tickets, nombre) {
  if (!tickets || tickets.length === 0) {
    return `No tienes tickets abiertos en LUNA hoy. Disfrútalo, ${nombre}.`;
  }
  const byPrio = { ALTA: [], MEDIA: [], BAJA: [] };
  for (const t of tickets) {
    const p = (t.prioridad || '').toUpperCase();
    if (byPrio[p]) byPrio[p].push(t);
    else byPrio.MEDIA.push(t); // default
  }
  const lines = [];
  for (const prio of ['ALTA', 'MEDIA', 'BAJA']) {
    const arr = byPrio[prio];
    if (!arr.length) continue;
    lines.push('');
    lines.push(`${prio} — ${arr.length} ticket${arr.length !== 1 ? 's' : ''}:`);
    for (const t of arr) {
      const desc = String(t.descripcion || t.tipo || 'sin descripción').slice(0, 140);
      const tipo = t.tipo ? `[${t.tipo}] ` : '';
      lines.push(`  · ${tipo}${desc}`);
    }
  }
  return lines.join('\n').trim();
}

// Bloque extra para Isabel: tareas Athena + compromisos pendientes
async function isabelExtras() {
  const extras = [];
  try {
    const { listTasks } = await import('./tasks.js');
    const all = listTasks() || [];
    const pendientes = all.filter((t) => (t.responsable === 'isabel') && (t.estado === 'pendiente' || !t.estado));
    if (pendientes.length) {
      extras.push('');
      extras.push(`TUS TAREAS PERSONALES (Athena tracking) — ${pendientes.length}:`);
      for (const t of pendientes.slice(0, 10)) {
        extras.push(`  · ${String(t.descripcion || '').slice(0, 140)}`);
      }
    }
  } catch { /* tasks module shape change — ignore */ }

  try {
    const { listCommitments } = await import('./commitments.js');
    const all = listCommitments() || [];
    const open = all.filter((c) => !c.cumplido && !c.fallido);
    if (open.length) {
      extras.push('');
      extras.push(`COMPROMISOS PENDIENTES (lo que OTROS te deben) — ${open.length}:`);
      for (const c of open.slice(0, 8)) {
        const who = c.persona || c.entidad || 'alguien';
        extras.push(`  · ${who}: ${String(c.descripcion || '').slice(0, 120)}`);
      }
    }
  } catch { /* ignore */ }

  return extras.length ? extras.join('\n') : '';
}

// Envía email a UNA persona del roster
async function sendForPerson(person, allTickets, fecha, lunaErrorMsg) {
  if (!person.email) {
    console.log(`[team_email] skip ${person.nombre} — no email configurado`);
    return { ok: false, skipped: true };
  }

  let cuerpo;
  if (lunaErrorMsg) {
    cuerpo = `Buenos días ${person.nombre},

Hoy no pude conectarme a LUNA para traerte tus tickets (${lunaErrorMsg}). Por favor revísalos directo en LUNA cuando puedas.

`;
  } else {
    // Coerción numérica defensiva: PHP a veces devuelve asignado_a como
    // string en vez de int, y === fallaba silencioso. Number(null) → 0,
    // así que comparamos solo si el valor no es null/undefined.
    const mios = allTickets.filter((t) => {
      if (t.asignado_a == null) return false;
      return Number(t.asignado_a) === person.id;
    });
    cuerpo = `Buenos días ${person.nombre},

${ticketsBlock(mios, person.nombre)}

`;
  }

  // Extras solo para Isabel
  if (person.is_owner) {
    const extras = await isabelExtras();
    if (extras) cuerpo += extras + '\n\n';

    if (!lunaErrorMsg) {
      const sinAsignar = allTickets.filter((t) => t.asignado_a == null);
      if (sinAsignar.length) {
        cuerpo += `TICKETS SIN ASIGNAR (necesitan ser distribuidos) — ${sinAsignar.length}\n`;
      }
      // DIAGNÓSTICO al final del email de Isabel — la primera vez que
      // veas un email vacío, esto te dice por qué. Borrar después de
      // confirmar que funciona.
      cuerpo += `\n---\nDIAG (solo en tu email):\n`;
      cuerpo += `· Total tickets recibidos de LUNA: ${allTickets.length}\n`;
      if (allTickets.length) {
        const counts = {};
        for (const t of allTickets) {
          const k = t.asignado_a == null ? 'null' : `id_${t.asignado_a}`;
          counts[k] = (counts[k] || 0) + 1;
        }
        cuerpo += `· Distribución asignado_a: ${JSON.stringify(counts)}\n`;
        cuerpo += `· Roster esperado: 6=Isabel, 7=Skarleth, 8=Suri, 9=Arlette, 10=Sami\n`;
        const muestra = allTickets[0];
        cuerpo += `· Muestra primer ticket: ${JSON.stringify({ id: muestra.id, asignado_a: muestra.asignado_a, asignado_nombre: muestra.asignado_nombre, prioridad: muestra.prioridad, tipo: muestra.tipo })}\n`;
      }
    }
  }

  cuerpo += `\nCualquier duda me dicen.\n\n— Athena`;

  // En modo preview el nombre va GRANDE en el subject para distinguir
  // los 4 emails en la misma bandeja.
  const subject = `PARA ${person.nombre.toUpperCase()} — Tu día — ${fecha}`;
  try {
    const r = await sendEmail(person.email, subject, cuerpo);
    console.log(`[team_email] enviado a ${person.nombre} <${person.email}>`);
    return { ok: true, person: person.nombre, result: r };
  } catch (e) {
    console.error(`[team_email] falló enviar a ${person.nombre}: ${e.message}`);
    return { ok: false, person: person.nombre, error: e.message };
  }
}

// Función principal — la dispara el cron a las 6am
export async function sendTeamMorningEmails() {
  const fecha = fechaEspanol();
  const roster = teamRoster();

  // 1. Pull tickets de LUNA UNA sola vez (no por persona) para ahorrar requests
  let allTickets = [];
  let lunaErrorMsg = null;
  if (lunaConfigured()) {
    try {
      const r = await openTickets({ priority: '' });
      if (r.ok && Array.isArray(r.data)) {
        allTickets = r.data;
      } else {
        lunaErrorMsg = r.error || 'respuesta inesperada de LUNA';
      }
    } catch (e) {
      lunaErrorMsg = e.message;
    }
  } else {
    lunaErrorMsg = 'LUNA no está configurado (env vars LUNA_BASE_URL / LUNA_API_KEY)';
  }

  // 2. Enviar email a cada persona del roster que tenga email
  const results = await Promise.all(
    roster.map((p) => sendForPerson(p, allTickets, fecha, lunaErrorMsg))
  );

  const enviados = results.filter((r) => r.ok).length;
  const fallidos = results.filter((r) => !r.ok && !r.skipped).length;
  const saltados = results.filter((r) => r.skipped).length;
  console.log(`[team_email] terminado: ${enviados} enviados, ${fallidos} fallidos, ${saltados} saltados (sin email)`);

  return { enviados, fallidos, saltados, results };
}

// Permite correrlo a mano: `node src/team_morning_email.js`
if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  await sendTeamMorningEmails();
  process.exit(0);
}
