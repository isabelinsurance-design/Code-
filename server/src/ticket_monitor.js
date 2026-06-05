// ============================================================
//  Ticket Monitor — avisa tickets LUNA estancados
//  ────────────────────────────────────────────
//  Cron 2x día (10am y 4pm). Lee tickets abiertos vía Pilar (LUNA).
//  Si alguno lleva más de N días abierto sin actividad reciente,
//  manda recordatorio a Isabel para que decida: nudge al equipo,
//  re-priorizar, o cerrar el ticket.
//
//  Configurable:
//    TICKET_STALE_DAYS   default 2 — días sin movimiento para ser stale
//    TICKET_ALTA_DAYS    default 0.5 — más estricto para ALTA prioridad
//
//  Falla suave: si LUNA no responde, log y salta. No tumba el cron.
// ============================================================
import { canSendProactive } from './proactive.js';

const STALE_DAYS = parseFloat(process.env.TICKET_STALE_DAYS || '2');
const ALTA_DAYS = parseFloat(process.env.TICKET_ALTA_DAYS || '0.5');

function daysSince(iso) {
  if (!iso) return 0;
  const ms = Date.now() - new Date(iso).getTime();
  return ms / 86_400_000;
}

export async function checkStaleTickets() {
  let lunaClient;
  try {
    lunaClient = await import('./luna_client.js');
  } catch { return { ok: false, reason: 'no luna_client' }; }

  if (!lunaClient.lunaConfigured()) {
    return { ok: false, reason: 'LUNA no configurado' };
  }

  let openRes;
  try {
    openRes = await lunaClient.openTickets({ priority: '' });
  } catch (e) {
    return { ok: false, reason: e.message };
  }

  if (!openRes.ok || !Array.isArray(openRes.data)) {
    return { ok: false, reason: openRes.error || 'sin data' };
  }

  const stale = openRes.data
    .map((t) => ({
      ...t,
      dias_sin_movimiento: daysSince(t.ultima_actividad || t.fecha_creacion),
    }))
    .filter((t) => {
      const limit = (t.prioridad || '').toUpperCase() === 'ALTA' ? ALTA_DAYS : STALE_DAYS;
      return t.dias_sin_movimiento >= limit;
    })
    .sort((a, b) => b.dias_sin_movimiento - a.dias_sin_movimiento);

  return { ok: true, stale, total_abiertos: openRes.data.length };
}

export async function sendStaleTicketAlert() {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) {
    console.warn('[ticket_monitor] No hay ISABEL_WHATSAPP configurado.');
    return;
  }
  const gate = canSendProactive({ force: false });
  if (!gate.ok) {
    console.log(`[ticket_monitor] saltado: ${gate.reason}`);
    return;
  }

  const r = await checkStaleTickets();
  if (!r.ok) {
    console.log(`[ticket_monitor] saltado: ${r.reason}`);
    return;
  }
  if (!r.stale.length) {
    console.log('[ticket_monitor] ningún ticket stale — sin alerta.');
    return;
  }

  // Solo mandamos si hay 3+ stale o al menos 1 ALTA prioridad.
  const altaStale = r.stale.filter((t) => (t.prioridad || '').toUpperCase() === 'ALTA');
  if (r.stale.length < 3 && altaStale.length === 0) {
    console.log(`[ticket_monitor] ${r.stale.length} stale pero ninguno ALTA — sin alerta.`);
    return;
  }

  const { sendMessage } = await import('./whatsapp.js');
  const { bumpProactiveCount, logActivity } = await import('./memory.js');

  const lines = [`Tickets sin movimiento — ${r.stale.length} de ${r.total_abiertos} abiertos`, ''];
  for (const t of r.stale.slice(0, 8)) {
    const owner = t.asignado_nombre || `id ${t.asignado_a || '?'}`;
    const cliente = t.miembro_nombre || (t.miembro_id ? `#${t.miembro_id}` : 'sin cliente');
    const prio = t.prioridad ? `[${t.prioridad}] ` : '';
    const desc = (t.descripcion || t.titulo || '').slice(0, 50);
    const dias = Math.floor(t.dias_sin_movimiento);
    lines.push(`${prio}#${t.id || '?'} · ${owner} · ${cliente} (${dias}d)`);
    if (desc) lines.push(`  ${desc}`);
  }
  if (r.stale.length > 8) {
    lines.push(`... y ${r.stale.length - 8} más`);
  }
  lines.push('');
  lines.push('¿Le doy nudge al equipo, los cierro, o los re-prioriza?');

  try {
    await sendMessage(to, lines.join('\n'));
    bumpProactiveCount(new Date().toISOString().slice(0, 10));
    logActivity({
      tool: 'ticket_monitor_alert',
      input_summary: `${r.stale.length} stale de ${r.total_abiertos}`,
      result_summary: 'enviado',
    });
    console.log(`[ticket_monitor] alerta enviada (${r.stale.length} stale).`);
  } catch (e) {
    console.warn('[ticket_monitor] send falló:', e.message);
  }
}
