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
import { todayAppointments, hotLeads, pendingSoa, lunaConfigured } from './luna_client.js';
import { isOnLeave } from './team_status.js';

// Campos donde LUNA PODRÍA traer el agente asignado de cada item.
// No sabemos cuál usa (citas/leads quizá no traen agente), así que
// probamos varios. Si NINGÚN item trae agente, el resumen es del
// equipo completo (igual para todos) y el DIAG lo avisa.
const AGENT_FIELDS = ['asignado_a', 'agente_id', 'agente', 'user_id', 'asignado', 'asesor_id'];
export function agentIdOf(item) {
  for (const f of AGENT_FIELDS) {
    if (item && item[f] != null && item[f] !== '') return Number(item[f]);
  }
  return null;
}
function listHasAgentField(list) {
  return Array.isArray(list) && list.some((it) => agentIdOf(it) != null);
}
export function forPerson(list, personId, agentMode) {
  if (!Array.isArray(list)) return [];
  if (!agentMode) return list; // LUNA no trae agente → resumen del equipo
  return list.filter((it) => agentIdOf(it) === personId);
}

// Roster del equipo. Email de cada uno se lee de env var.
// MODO PREVIEW: si TEAM_EMAIL_PREVIEW_INBOX está set, TODOS los emails
// se mandan a ese inbox (Isabel revisa los 4 en su propia bandeja antes
// de que cada persona los reciba). El nombre del destinatario va en el
// subject así Isabel los distingue. Bueno para arranque + verificación.
function teamRoster() {
  const preview = process.env.TEAM_EMAIL_PREVIEW_INBOX || 'isabel.medicareadvantage@gmail.com';
  const usePreview = Boolean(preview);
  const all = [
    { id: 6,  nombre: 'Isabel',   email: usePreview ? preview : (process.env.ISABEL_EMAIL   || 'isabel.insurance@gmail.com'), is_owner: true },
    { id: 7,  nombre: 'Skarleth', email: usePreview ? preview : (process.env.SKARLETH_EMAIL || null) },
    { id: 8,  nombre: 'Suri',     email: usePreview ? preview : (process.env.SURI_EMAIL     || null) },
    { id: 9,  nombre: 'Arlette',  email: usePreview ? preview : (process.env.ARLETTE_EMAIL  || null) },
    { id: 10, nombre: 'Sami',     email: usePreview ? preview : (process.env.SAMI_EMAIL     || null) },
  ];
  // No molestamos a quien está de licencia (cirugía, baja). Se reactiva
  // solo cuando pasa su fecha. Isabel (owner) nunca se filtra.
  return all.filter((p) => {
    if (p.is_owner) return true;
    if (isOnLeave(p.nombre)) {
      console.log(`[team_email] ${p.nombre} está de licencia — no se le manda email.`);
      return false;
    }
    return true;
  });
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

// Hora legible de una cita (LUNA puede mandar fecha_hora ISO o hora suelta)
export function horaCita(c) {
  const raw = c.fecha_hora || c.hora || c.fecha || '';
  if (/\d{4}-\d{2}-\d{2}T/.test(raw)) return raw.slice(11, 16);
  if (/^\d{2}:\d{2}/.test(raw)) return raw.slice(0, 5);
  return '';
}
export function nombreCliente(x) {
  return (
    x.miembro_nombre ||
    [x.nombre, x.apellido].filter(Boolean).join(' ').trim() ||
    (x.miembro_id ? `cliente #${x.miembro_id}` : (x.id ? `#${x.id}` : 'cliente'))
  );
}

// Compone "cómo se ve tu día" para UNA persona, a partir del CRM real.
// citas = citas de hoy · leads = seguimientos pendientes · soas = compliance
export function daySummary({ citas, leads, soas, nombre }) {
  const nCitas = citas.length;
  const nLeads = leads.length;
  const nSoas = soas.length;

  if (!nCitas && !nLeads && !nSoas) {
    return `Hoy no tienes nada agendado ni pendiente en el CRM. Día tranquilo, ${nombre}.`;
  }

  const lines = [];
  // Conteo de cabecera — "cuántas cosas tiene" (lo que pidió Isabel)
  const resumen = [];
  if (nCitas) resumen.push(`${nCitas} cita${nCitas === 1 ? '' : 's'}`);
  if (nLeads) resumen.push(`${nLeads} seguimiento${nLeads === 1 ? '' : 's'}`);
  if (nSoas) resumen.push(`${nSoas} SOA${nSoas === 1 ? '' : 's'} pendiente${nSoas === 1 ? '' : 's'}`);
  lines.push(`Hoy: ${resumen.join(' · ')}.`);

  // URGENTE arriba — leads fríos (3+ días sin contacto) y compliance
  const urgentes = [];
  for (const l of leads) {
    const d = Number(l.dias_sin_contacto || 0);
    if (d >= 3) urgentes.push(`Lead frío: ${nombreCliente(l)} — ${d} días sin contacto`);
  }
  if (nSoas) urgentes.push(`${nSoas} SOA pendiente${nSoas === 1 ? '' : 's'} de firmar (compliance)`);
  if (urgentes.length) {
    lines.push('');
    lines.push('URGENTE:');
    for (const u of urgentes.slice(0, 8)) lines.push(`  · ${u}`);
  }

  // Citas de hoy
  if (nCitas) {
    lines.push('');
    lines.push(`CITAS DE HOY (${nCitas}):`);
    for (const c of citas.slice(0, 20)) {
      const h = horaCita(c);
      const tipo = c.tipo ? ` · ${c.tipo}` : '';
      const lugar = c.lugar || c.modalidad ? ` · ${c.lugar || c.modalidad}` : '';
      lines.push(`  · ${h ? h + ' — ' : ''}${nombreCliente(c)}${tipo}${lugar}`);
    }
  }

  // Seguimientos (leads a contactar)
  if (nLeads) {
    lines.push('');
    lines.push(`SEGUIMIENTOS (${nLeads}):`);
    for (const l of leads.slice(0, 15)) {
      const d = Number(l.dias_sin_contacto || 0);
      lines.push(`  · ${nombreCliente(l)}${d ? ` — ${d}d sin contacto` : ''}`);
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
async function sendForPerson(person, crm, fecha, lunaErrorMsg) {
  if (!person.email) {
    console.log(`[team_email] skip ${person.nombre} — no email configurado`);
    return { ok: false, skipped: true };
  }

  let cuerpo;
  if (lunaErrorMsg) {
    cuerpo = `Buenos días ${person.nombre},

Hoy no pude conectarme a LUNA para traerte tu día (${lunaErrorMsg}). Por favor revisa el CRM directo cuando puedas.

`;
  } else {
    // Lo de cada quien — si LUNA trae agente, filtramos; si no, es del equipo.
    const citas = forPerson(crm.citas, person.id, crm.agentMode);
    const leads = forPerson(crm.leads, person.id, crm.agentMode);
    const soas = forPerson(crm.soas, person.id, crm.agentMode);
    cuerpo = `Buenos días ${person.nombre},

${daySummary({ citas, leads, soas, nombre: person.nombre })}

`;
  }

  // Extras solo para Isabel
  if (person.is_owner) {
    const extras = await isabelExtras();
    if (extras) cuerpo += extras + '\n\n';

    if (!lunaErrorMsg) {
      // DIAGNÓSTICO al final del email de Isabel — revela qué devolvió cada
      // fuente del CRM y si trae agente. Borrar cuando confirmemos que jala.
      cuerpo += `\n---\nDIAG (solo en tu email):\n`;
      cuerpo += `· Citas hoy: ${crm.citas.length} · Seguimientos: ${crm.leads.length} · SOAs: ${crm.soas.length}\n`;
      cuerpo += `· ¿LUNA trae agente por item? ${crm.agentMode ? 'SÍ → cada quien ve lo suyo' : 'NO → todos ven el resumen del equipo'}\n`;
      if (crm.citas.length) cuerpo += `· Muestra cita: ${JSON.stringify(crm.citas[0]).slice(0, 200)}\n`;
      if (crm.leads.length) cuerpo += `· Muestra lead: ${JSON.stringify(crm.leads[0]).slice(0, 200)}\n`;
      if (crm.soas.length) cuerpo += `· Muestra SOA: ${JSON.stringify(crm.soas[0]).slice(0, 200)}\n`;
      cuerpo += `· Errores de fuente: ${crm.errors.length ? crm.errors.join(' | ') : 'ninguno'}\n`;
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

  // 1. Pull del CRM real (LUNA) UNA sola vez: citas de hoy, seguimientos
  //    (hot leads), y compliance (SOAs). NO tickets — LUNA no tiene tickets.
  let crm = { citas: [], leads: [], soas: [], agentMode: false, errors: [] };
  let lunaErrorMsg = null;
  if (lunaConfigured()) {
    const errors = [];
    const pull = async (label, fn) => {
      try {
        const r = await fn();
        if (r.ok && Array.isArray(r.data)) return r.data;
        errors.push(`${label}: ${r.error || 'respuesta inesperada'}`);
        return [];
      } catch (e) {
        errors.push(`${label}: ${e.message}`);
        return [];
      }
    };
    const [citas, leads, soas] = await Promise.all([
      pull('citas', () => todayAppointments()),
      pull('leads', () => hotLeads()),
      pull('soas', () => pendingSoa()),
    ]);
    // ¿LUNA trae agente en algún item? Si sí, personalizamos por persona.
    const agentMode = listHasAgentField(citas) || listHasAgentField(leads) || listHasAgentField(soas);
    crm = { citas, leads, soas, agentMode, errors };
    // Solo marcamos error duro si TODO falló (las 3 fuentes con error).
    if (errors.length === 3 && !citas.length && !leads.length && !soas.length) {
      lunaErrorMsg = errors.join(' | ');
    }
  } else {
    lunaErrorMsg = 'LUNA no está configurado (env vars LUNA_BASE_URL / LUNA_API_KEY)';
  }

  // 2. Enviar email a cada persona del roster que tenga email
  const results = await Promise.all(
    roster.map((p) => sendForPerson(p, crm, fecha, lunaErrorMsg))
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
