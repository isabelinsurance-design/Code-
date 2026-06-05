// ============================================================
//  Command Center — Athena como Chief of Staff activa
//  ────────────────────────────────────────────────────
//  Composite endpoints para que el PWA sienta que Athena ESTÁ
//  trabajando todo el tiempo, no que es una app pasiva.
//
//  Tres datasets clave:
//   - DECISIONS PENDING: cosas que Athena necesita tu OK
//     (drafts en outbound queue, skills draft, improvements pendientes,
//      commitments con nudge propuesto)
//   - AUTONOMY TODAY: cosas que Athena hizo SOLA hoy sin preguntarte
//     (tools ejecutados por crons + auto-actions)
//   - LIVE STATUS: snapshot rápido para mission bar fija
// ============================================================
import { getActivity, getPendingOutbound } from './memory.js';

// Tools que cuentan como "acción autónoma de Athena" (cron, auto, sin pedir)
const AUTONOMOUS_TOOLS = new Set([
  'morning_briefing', 'evening_checkin', 'weekly_review', 'nightly_reflection',
  'closing_loop', 'vacation_morning_report', 'vacation_evening_report',
  'triage_inbox', 'inbox_idle_react',
  'ticket_monitor_alert', 'commitment_chase', 'overload_alert',
  'eod_team_nudge', 'saturday_brief',
  'template_usar',  // template aplicado sin esperar envía
  'commitment_nudge', // nudge mandado a tercera persona
  'cal_premeeting_brief',
  'security_gc', 'audio_gc', 'backup_snapshot', 'mcp_refresh',
  'self_grade', 'closing_loop',
]);

// Tools de delegación que también cuentan (Athena ejecutó vs solo capturó)
const DELEGATION_TOOLS = new Set([
  'luna_crear_ticket', 'luna_agregar_nota', 'luna_registrar_actividad',
  'luna_crear_miembro', 'luna_crear_cita',
  'confirmar_envio', // un draft FINALIZADO (después que Isabel dijo envía)
  'mensaje_a_sami',
  'llamar_cliente',
  'crear_cita', 'reagendar_cita', 'cancelar_cita',
]);

function tsToday() {
  const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date());
}

function isToday(iso) {
  if (!iso) return false;
  const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
  const local = new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date(iso));
  return local === tsToday();
}

// === DECISIONS PENDING — cosas esperando tu OK ===
export async function getDecisionsPending() {
  const decisions = [];

  // 1. Outbound drafts (emails / SMS que Athena redactó)
  try {
    const drafts = getPendingOutbound() || [];
    for (const d of drafts) {
      decisions.push({
        kind: 'outbound',
        sub: (d.kind || d.tipo || 'mensaje').toLowerCase(),
        id: d.id,
        ts: d.creado || d.ts,
        to: d.to || d.destinatario,
        title: d.subject || d.asunto || `Mensaje a ${d.to || 'destinatario'}`,
        preview: (d.body || d.texto || '').slice(0, 200),
        urgency: 'normal',
      });
    }
  } catch { /* ignore */ }

  // 2. Improvements pendientes (capabilities que Athena propone construir)
  try {
    const { listImprovements } = await import('./improvements.js');
    const pend = listImprovements({ status: 'pendiente' }) || [];
    for (const m of pend) {
      decisions.push({
        kind: 'improvement',
        sub: m.prioridad || 'media',
        id: m.id,
        ts: m.creado,
        title: m.titulo,
        preview: m.problema || m.propuesta || '',
        urgency: m.prioridad === 'alta' ? 'high' : 'normal',
      });
    }
  } catch { /* ignore */ }

  // 3. Skills draft (playbooks que Athena armó)
  try {
    const { listSkills } = await import('./skills.js');
    const drafts = (listSkills({}) || []).filter(
      (s) => s.status === 'draft' || s.status === 'borrador'
    );
    for (const s of drafts) {
      decisions.push({
        kind: 'skill',
        sub: 'draft',
        id: s.nombre || s.slug,
        ts: s.creado,
        title: `Skill: ${s.nombre}`,
        preview: s.descripcion || '',
        urgency: 'low',
      });
    }
  } catch { /* ignore */ }

  // Ordena por urgencia → recencia
  const order = { high: 3, normal: 2, low: 1 };
  decisions.sort((a, b) => {
    const u = (order[b.urgency] || 0) - (order[a.urgency] || 0);
    if (u !== 0) return u;
    return new Date(b.ts || 0).getTime() - new Date(a.ts || 0).getTime();
  });

  return decisions;
}

// === AUTONOMY TODAY — qué hizo Athena hoy sin que se lo pidieran ===
export function getAutonomyToday() {
  const all = getActivity() || [];
  const todays = all
    .filter((a) => isToday(a.ts || a.timestamp))
    .filter((a) => AUTONOMOUS_TOOLS.has(a.tool) || DELEGATION_TOOLS.has(a.tool))
    .map((a) => ({
      ts: a.ts || a.timestamp,
      tool: a.tool,
      category: AUTONOMOUS_TOOLS.has(a.tool) ? 'proactive' : 'delegation',
      summary: a.result_summary || a.input_summary || '',
    }));
  // Agrupa por tool para no repetir
  const byTool = {};
  for (const a of todays) {
    if (!byTool[a.tool]) byTool[a.tool] = { tool: a.tool, count: 0, last_ts: a.ts, category: a.category, samples: [] };
    byTool[a.tool].count++;
    if (byTool[a.tool].samples.length < 3 && a.summary) byTool[a.tool].samples.push(a.summary);
    if (new Date(a.ts) > new Date(byTool[a.tool].last_ts)) byTool[a.tool].last_ts = a.ts;
  }
  return {
    total: todays.length,
    grouped: Object.values(byTool).sort((a, b) => b.count - a.count),
    recent: todays.slice(0, 10),
  };
}

// === LIVE STATUS — snapshot rápido para mission bar ===
// Incluye conteo de alertas (vencidas) cross-domain.
export async function getLiveStatus() {
  const status = {
    athena_state: 'active',
    decisions_pending: 0,
    autonomous_today: 0,
    alerts: 0,
    current_activity: null,
    ts: new Date().toISOString(),
  };

  try {
    const dec = await getDecisionsPending();
    status.decisions_pending = dec.length;
    status.decisions_high = dec.filter((d) => d.urgency === 'high').length;
  } catch { /* ignore */ }

  try {
    const aut = getAutonomyToday();
    status.autonomous_today = aut.total;
  } catch { /* ignore */ }

  // Alertas: vencidos cross-domain (tareas + commitments + tickets ALTA estancados)
  let alertCount = 0;
  try {
    const { listTasks } = await import('./tasks.js');
    const tasks = listTasks({ status: 'pendiente' }) || [];
    alertCount += tasks.filter((t) => t.vence && new Date(t.vence).getTime() < Date.now()).length;
  } catch { /* ignore */ }
  try {
    const { listCommitments } = await import('./commitments.js');
    const cs = listCommitments({ status: 'pendiente' }) || [];
    alertCount += cs.filter((c) => c.vence && new Date(c.vence).getTime() < Date.now()).length;
  } catch { /* ignore */ }
  try {
    const { checkStaleTickets } = await import('./ticket_monitor.js');
    const r = await checkStaleTickets();
    if (r.ok && r.stale) {
      alertCount += r.stale.filter((t) => (t.prioridad || '').toUpperCase() === 'ALTA').length;
    }
  } catch { /* ignore */ }
  status.alerts = alertCount;

  // Current activity — ¿hay tool calls en los últimos 60 segundos?
  try {
    const all = getActivity() || [];
    const cutoff = Date.now() - 60_000;
    const recent = all.find((a) => {
      const ts = new Date(a.ts || a.timestamp || 0).getTime();
      return ts > cutoff && !a.tool.startsWith('isabel_') && !a.tool.startsWith('athena_responde');
    });
    if (recent) {
      status.current_activity = {
        tool: recent.tool,
        summary: recent.result_summary || recent.input_summary || '',
        ts: recent.ts || recent.timestamp,
      };
    }
  } catch { /* ignore */ }

  return status;
}

// Acción de aprobar una decisión — rutea al backend correcto según kind.
export async function approveDecision(kind, id) {
  if (kind === 'outbound') {
    const { popOutbound } = await import('./memory.js');
    const item = popOutbound(id);
    if (!item) return { ok: false, error: 'no encontrado' };
    // Re-ejecuta el envío real
    if (item.kind === 'email' || item.tipo === 'email') {
      const { sendEmail } = await import('./email.js');
      await sendEmail({ to: item.to, subject: item.subject, text: item.body });
      return { ok: true, action: 'email_sent', to: item.to };
    }
    if (item.kind === 'sms' || item.tipo === 'sms') {
      const { sendMessage } = await import('./whatsapp.js');
      const to = (item.to || '').startsWith('+') ? item.to : `+${item.to.replace(/\D/g, '')}`;
      await sendMessage(to, item.body || item.text);
      return { ok: true, action: 'sms_sent', to };
    }
    return { ok: true, action: 'popped', item };
  }
  if (kind === 'improvement') {
    const { setImprovementStatus } = await import('./improvements.js');
    return { ok: true, result: setImprovementStatus(id, 'aprobada') };
  }
  if (kind === 'skill') {
    const { approveSkill } = await import('./skills.js');
    return { ok: true, result: approveSkill(id) };
  }
  return { ok: false, error: `kind desconocido: ${kind}` };
}

export async function declineDecision(kind, id, razon = '') {
  if (kind === 'outbound') {
    const { popOutbound } = await import('./memory.js');
    const item = popOutbound(id);
    return { ok: true, action: 'discarded', item };
  }
  if (kind === 'improvement') {
    const { setImprovementStatus } = await import('./improvements.js');
    return { ok: true, result: setImprovementStatus(id, 'descartada') };
  }
  if (kind === 'skill') {
    const { retireSkill } = await import('./skills.js');
    return { ok: true, result: retireSkill(id) };
  }
  return { ok: false, error: `kind desconocido: ${kind}` };
}
