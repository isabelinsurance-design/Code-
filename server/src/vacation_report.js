// ============================================================
//  Vacation Reports — 2x al día en TIMEZONE de Isabel
//  ──────────────────────────────────────────────────
//  Cuando está de vacaciones:
//    - 9am en SU timezone: morning report (qué pasa hoy)
//    - 7pm en SU timezone: evening report (qué pasó / qué quedó)
//
//  Tono CONCISO. Ella está descansando. 3-5 líneas máximo.
//
//  Si no hay vacación activa, los crons no hacen nada.
// ============================================================
import { sendMessage } from './whatsapp.js';
import { getVacationState } from './vacation.js';
import { canSendProactive } from './proactive.js';
import { bumpProactiveCount, logActivity } from './memory.js';

function nowInTimezone(tz) {
  return new Date().toLocaleString('es-MX', {
    timeZone: tz, hour: '2-digit', minute: '2-digit',
  });
}

function hourInTimezone(tz) {
  return parseInt(new Date().toLocaleString('en-US', {
    timeZone: tz, hour: 'numeric', hour12: false,
  }), 10);
}

async function buildMorningReport(v) {
  const lines = [`🌴 Morning report — ${nowInTimezone(v.timezone)} en ${v.location || v.timezone}`, ''];
  // Team status from LUNA
  try {
    const { lunaConfigured, openTickets } = await import('./luna_client.js');
    if (lunaConfigured()) {
      const r = await openTickets({ priority: '' }).catch(() => ({ ok: false }));
      if (r.ok && Array.isArray(r.data)) {
        const alta = r.data.filter((t) => (t.prioridad || '').toUpperCase() === 'ALTA');
        lines.push(`${r.data.length} tickets abiertos${alta.length ? `, ${alta.length} ALTA` : ''}`);
      }
    }
  } catch { /* ignore */ }
  // Today's appointments
  try {
    const { lunaConfigured: lc, todayAppointments } = await import('./luna_client.js');
    if (lc()) {
      const r = await todayAppointments().catch(() => ({ ok: false }));
      if (r.ok && Array.isArray(r.data) && r.data.length) {
        lines.push(`${r.data.length} cita${r.data.length !== 1 ? 's' : ''} hoy (equipo las maneja)`);
      }
    }
  } catch { /* ignore */ }
  lines.push('');
  lines.push('Sigue disfrutando. Solo te interrumpo si es URGENTE.');
  return lines.join('\n');
}

async function buildEveningReport(v) {
  const lines = [`🌴 Evening report — ${nowInTimezone(v.timezone)}`, ''];
  // Closing loop pero abreviado
  try {
    const { computeClosingLoop } = await import('./closing_loop.js');
    const loop = computeClosingLoop();
    if (loop.total > 0) {
      lines.push(`Hoy: ${loop.total} acciones cerradas (Sami / equipo).`);
    } else {
      lines.push('Día tranquilo del equipo.');
    }
  } catch { /* ignore */ }
  // Tickets stuck
  try {
    const { checkStaleTickets } = await import('./ticket_monitor.js');
    const r = await checkStaleTickets();
    if (r.ok && r.stale.length) {
      const altaStale = r.stale.filter((t) => (t.prioridad || '').toUpperCase() === 'ALTA');
      if (altaStale.length) {
        lines.push(`⚠ ${altaStale.length} ticket ALTA estancado — ¿le doy nudge al equipo?`);
      } else if (r.stale.length >= 3) {
        lines.push(`${r.stale.length} tickets sin movimiento — los persigo mañana.`);
      }
    }
  } catch { /* ignore */ }
  lines.push('');
  lines.push('Mañana te paso el morning report a las 9am tuyas.');
  return lines.join('\n');
}

export async function sendVacationReports() {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) return;
  const v = getVacationState();
  if (!v.active) return;

  const tz = v.timezone || process.env.TIMEZONE || 'America/Los_Angeles';
  const hour = hourInTimezone(tz);

  // 9am morning report
  if (hour === 9) {
    const gate = canSendProactive({ force: true });
    if (!gate.ok) return;
    const msg = await buildMorningReport(v);
    await sendMessage(to, msg);
    bumpProactiveCount(new Date().toISOString().slice(0, 10));
    logActivity({ tool: 'vacation_morning_report', input_summary: tz, result_summary: 'enviado' });
    console.log(`[vacation] morning report enviado (TZ ${tz}, hora local ${hour}).`);
    return;
  }

  // 7pm evening report
  if (hour === 19) {
    const gate = canSendProactive({ force: false });
    if (!gate.ok) return;
    const msg = await buildEveningReport(v);
    await sendMessage(to, msg);
    bumpProactiveCount(new Date().toISOString().slice(0, 10));
    logActivity({ tool: 'vacation_evening_report', input_summary: tz, result_summary: 'enviado' });
    console.log(`[vacation] evening report enviado (TZ ${tz}, hora local ${hour}).`);
  }
}
