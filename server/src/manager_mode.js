// ============================================================
//  Manager Mode — Athena como tu mánager, no como tu asistente
//  ─────────────────────────────────────────────────────────
//  Las 6 rutinas que la hacen sentir como un manager humano:
//
//   1. dayPlanScheduled()    — briefing 7am con HORARIO real
//   2. coachCadenceAuto()    — agenda check-ins semanales con coaches
//   3. focusBlocksAuto()     — crea bloques en calendar (con standing order)
//   4. hourlyNudge()         — ping cada 30min si algo viene en <30min
//   5. dailyAudit()          — 8pm rinde cuentas vs lo que dijiste hoy
//   6. preMeetingDeepBrief() — brief serio 15min antes (vs el genérico)
// ============================================================
import { runDirectora } from './directora.js';
import { sendMessage } from './whatsapp.js';
import { getHistory, saveHistory, logActivity, bumpProactiveCount } from './memory.js';
import { canSendProactive } from './proactive.js';

function fechaEs() {
  return new Date().toLocaleDateString('es-MX', {
    weekday: 'long', day: 'numeric', month: 'long',
    timeZone: process.env.TIMEZONE || 'America/Los_Angeles',
  });
}

function nowLocalHHMM() {
  return new Date().toLocaleTimeString('es-MX', {
    hour: '2-digit', minute: '2-digit', hour12: false,
    timeZone: process.env.TIMEZONE || 'America/Los_Angeles',
  });
}

function localHour() {
  return parseInt(new Date().toLocaleString('en-US', {
    timeZone: process.env.TIMEZONE || 'America/Los_Angeles',
    hour: 'numeric', hour12: false,
  }), 10);
}

// ============================================================
//  1. DAY PLAN SCHEDULED — briefing matutino con horario REAL
// ============================================================
export async function dayPlanScheduled() {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) return;
  const gate = canSendProactive({ force: true });
  if (!gate.ok) { console.log(`[manager/day_plan] saltado: ${gate.reason}`); return; }

  // Trae calendar events de hoy
  let calendarLines = '';
  try {
    const { calendarConfigured, listUpcomingEvents } = await import('./calendar.js');
    if (calendarConfigured()) {
      const { events } = await listUpcomingEvents({ withinHours: 16, limit: 20 });
      if (Array.isArray(events) && events.length) {
        calendarLines = events.map((e) => {
          const start = e.inicio || e.start;
          if (!start) return null;
          const hh = new Date(start).toLocaleTimeString('es-MX', {
            timeZone: process.env.TIMEZONE || 'America/Los_Angeles',
            hour: '2-digit', minute: '2-digit', hour12: false,
          });
          return `· ${hh} — ${e.titulo || e.summary || 'cita'}`;
        }).filter(Boolean).join('\n');
      }
    }
  } catch { /* ignore */ }

  // Trae cadencias de coach hoy
  let coachLines = '';
  try {
    const { cadenciasDeHoy } = await import('./coach_cadence.js');
    const cads = cadenciasDeHoy() || [];
    if (cads.length) coachLines = cads.map((c) => `· check-in con ${c.coach} (${c.cadencia})`).join('\n');
  } catch { /* ignore */ }

  // Trae tareas que vencen hoy
  let tareasLines = '';
  try {
    const { listTasks } = await import('./tasks.js');
    const ts = (listTasks({ status: 'pendiente' }) || []).filter((t) => {
      if (!t.vence) return false;
      const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
      const venceLocal = new Intl.DateTimeFormat('en-CA', {
        timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
      }).format(new Date(t.vence));
      const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
      }).format(new Date());
      return venceLocal === today;
    });
    if (ts.length) tareasLines = ts.map((t) => `· ${t.descripcion || t.titulo}`).join('\n');
  } catch { /* ignore */ }

  // Prompt para Athena — ella arma el horario real
  const prompt = `Es 7:00 AM ${fechaEs()}. ARMA EL DÍA DE ISABEL EN HORARIO REAL.

ENTRADAS (lo que tienes que orquestar):
${calendarLines ? `\nCITAS YA AGENDADAS HOY:\n${calendarLines}` : ''}
${coachLines ? `\nCHECK-INS DE COACHES HOY:\n${coachLines}` : ''}
${tareasLines ? `\nTAREAS QUE VENCEN HOY:\n${tareasLines}` : ''}

CONSIDERA:
- Sus rutinas (proteína 110g, agua 80oz, workout 4x/sem)
- Su filosofía (máx 3 prioridades + descanso real, no es factory)
- Si hay focus block free, propón uno (90min) para lo más importante
- Sé ESPECÍFICA con horas, no genérica
- Bloquea tiempo de lunch + un break

FORMATO (NO uses markdown, es para WhatsApp):

🌅 ${fechaEs().toUpperCase()}

Tu día:
07:00 — [actividad concreta]
08:00 — [actividad concreta]
09:00 — [actividad concreta]
...
20:00 — [cierre del día]

Después de la tabla, 1 línea de "consejo del mánager" tipo:
"El bloque de 10am es lo único innegociable. Si Maritza no contesta, pasa al siguiente."

Máximo 350 palabras. Sé estilo COS humana, no robot.`;

  const messages = getHistory();
  messages.push({ role: 'user', content: prompt });
  const { reply, messages: updated } = await runDirectora(messages, { tier: 'default' });
  saveHistory(updated);
  if (!reply) return;

  await sendMessage(to, reply);
  bumpProactiveCount(new Date().toISOString().slice(0, 10));
  logActivity({ tool: 'day_plan_scheduled', input_summary: '7am day plan', result_summary: 'enviado' });

  try {
    const { sendToAll, pushEnabled } = await import('./push.js');
    if (pushEnabled()) {
      const firstLine = (reply || '').split('\n').find((l) => l.trim());
      await sendToAll({
        title: 'Tu día está listo',
        body: firstLine?.slice(0, 140) || 'Athena armó tu horario',
        url: '/app/hoy', tag: 'day_plan',
      });
    }
  } catch (e) { console.warn('[push] day_plan falló:', e.message); }
}

// ============================================================
//  2. COACH CADENCE AUTO — agenda check-ins con coaches
// ============================================================
export async function coachCadenceAuto() {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) return;
  let due = [];
  try {
    const { cadenciasDeHoy } = await import('./coach_cadence.js');
    due = cadenciasDeHoy() || [];
  } catch { return; }
  if (!due.length) return;

  const gate = canSendProactive({ force: false });
  if (!gate.ok) return;

  // Para cada coach due hoy, manda ping con prompt sugerido + link al chat
  const lines = ['📅 Check-ins de coaches hoy:', ''];
  for (const c of due) {
    try {
      const { promptInicialPara } = await import('./coach_cadence.js');
      const prompt = promptInicialPara(c.coach) || `¿Qué quieres revisar con ${c.coach}?`;
      lines.push(`· ${c.coach} — "${prompt.slice(0, 80)}"`);
    } catch {
      lines.push(`· ${c.coach}`);
    }
  }
  lines.push('');
  lines.push('Abre cualquiera tocando su nombre en /coaches del PWA.');

  await sendMessage(to, lines.join('\n'));
  logActivity({ tool: 'coach_cadence_auto', input_summary: `${due.length} due`, result_summary: 'enviado' });
}

// ============================================================
//  3. FOCUS BLOCKS AUTO — crea bloques en calendar si standing order
// ============================================================
export async function focusBlocksAuto() {
  // Solo corre si hay standing order "siempre bloquea focus matutino" o similar
  try {
    const { listOrders } = await import('./standing_orders.js');
    const orders = listOrders({ status: 'activa', categoria: 'tiempo' });
    const hasFocusRule = orders.some((o) => /focus|bloqu|protejid|protegid/i.test(o.regla));
    if (!hasFocusRule) return;
  } catch { return; }

  // Crea focus block 9-11am AM si no existe ya
  try {
    const { calendarConfigured, listUpcomingEvents, createEvent } = await import('./calendar.js');
    if (!calendarConfigured()) return;
    const { events } = await listUpcomingEvents({ withinHours: 16, limit: 20 });
    const today = new Date().toISOString().slice(0, 10);
    const focusStart = new Date(`${today}T09:00:00`);
    const focusEnd = new Date(`${today}T11:00:00`);
    const exists = (events || []).some((e) => {
      const start = e.inicio || e.start;
      if (!start) return false;
      const s = new Date(start);
      return s.getHours() === 9 && s.toDateString() === focusStart.toDateString();
    });
    if (exists) return;
    await createEvent({
      titulo: 'FOCUS BLOCK — protegido por Athena',
      inicio: focusStart.toISOString(),
      duracion_min: 120,
      descripcion: 'Bloque protegido. No emails, no llamadas no urgentes. Athena rutea lo que entre.',
    });
    logActivity({ tool: 'focus_block_auto', input_summary: '9-11am', result_summary: 'creado' });
  } catch (e) { console.warn('[focus_blocks_auto]', e.message); }
}

// ============================================================
//  4. HOURLY NUDGE — ping si algo viene en <30min
// ============================================================
export async function hourlyNudge() {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) return;
  const hour = localHour();
  if (hour < 7 || hour > 21) return; // respeta quiet hours

  const gate = canSendProactive({ force: false });
  if (!gate.ok) return;

  let upcoming = null;
  try {
    const { calendarConfigured, listUpcomingEvents } = await import('./calendar.js');
    if (!calendarConfigured()) return;
    const { events } = await listUpcomingEvents({ withinHours: 1, limit: 5 });
    upcoming = (events || []).find((e) => {
      const start = e.inicio || e.start;
      if (!start) return false;
      const ms = new Date(start).getTime() - Date.now();
      return ms > 0 && ms < 35 * 60 * 1000;
    });
  } catch { /* ignore */ }

  if (!upcoming) return;

  const start = new Date(upcoming.inicio || upcoming.start);
  const mins = Math.round((start.getTime() - Date.now()) / 60000);
  const hh = start.toLocaleTimeString('es-MX', {
    timeZone: process.env.TIMEZONE || 'America/Los_Angeles',
    hour: '2-digit', minute: '2-digit', hour12: false,
  });
  await sendMessage(to, `⏰ En ${mins}min: ${upcoming.titulo || upcoming.summary} (${hh}).`);
  logActivity({ tool: 'hourly_nudge', input_summary: upcoming.titulo, result_summary: `${mins}min` });
}

// ============================================================
//  5. DAILY AUDIT — 8pm "dijiste X, hiciste Y"
// ============================================================
export async function dailyAudit() {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) return;
  const gate = canSendProactive({ force: false });
  if (!gate.ok) return;

  // Trae el briefing de hoy (lo que ELLA prometió)
  let briefingCards = [];
  try {
    const { loadTodayBriefing } = await import('./briefing.js');
    const b = loadTodayBriefing();
    if (b?.cards) briefingCards = b.cards;
  } catch { /* ignore */ }

  // Trae lo que la actividad del día reporta
  let activityLines = [];
  try {
    const { getActivity } = await import('./memory.js');
    const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
    const today = new Intl.DateTimeFormat('en-CA', {
      timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(new Date());
    const todays = (getActivity() || []).filter((a) => {
      const ts = a.ts || a.timestamp;
      if (!ts) return false;
      return new Intl.DateTimeFormat('en-CA', {
        timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
      }).format(new Date(ts)) === today;
    });
    const byTool = {};
    for (const a of todays) byTool[a.tool] = (byTool[a.tool] || 0) + 1;
    activityLines = Object.entries(byTool).sort((a, b) => b[1] - a[1]).slice(0, 8).map(
      ([t, n]) => `· ${t.replace(/_/g, ' ')} × ${n}`
    );
  } catch { /* ignore */ }

  const prompt = `Es 8:00 PM ${fechaEs()}. Es la hora del AUDIT DIARIO — rinde cuentas como mánager.

LO QUE ISABEL SE PROPUSO HOY (de tu briefing matutino):
${briefingCards.slice(0, 2).join('\n\n') || '(no había briefing)'}

LO QUE EL ACTIVITY LOG REPORTA:
${activityLines.join('\n') || '(sin actividad registrada)'}

COMO MÁNAGER HUMANA: díle a Isabel:
- Qué cumplió de lo que se propuso (sé específica)
- Qué quedó pendiente (sin juicio, solo dato)
- Una observación de patrón (ej: "siempre dejas X para mañana")
- Una mini-recomendación para mañana (ej: "ese bloque de focus muévelo a 8am, te has saltado el de 9 tres veces")

NO uses markdown. Formato WhatsApp. Máximo 200 palabras.
Tono: directa, cariñosa, sin culpa. Como Sheryl Sandberg al final del día.`;

  const messages = getHistory();
  messages.push({ role: 'user', content: prompt });
  const { reply, messages: updated } = await runDirectora(messages);
  saveHistory(updated);
  if (!reply) return;

  await sendMessage(to, reply);
  bumpProactiveCount(new Date().toISOString().slice(0, 10));
  logActivity({ tool: 'daily_audit', input_summary: '8pm audit', result_summary: 'enviado' });
}

// ============================================================
//  6. PRE-MEETING DEEP BRIEF — 15min antes, con contexto serio
// ============================================================
export async function preMeetingDeepBrief() {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) return;
  const gate = canSendProactive({ force: false });
  if (!gate.ok) return;

  let upcoming = null;
  try {
    const { calendarConfigured, listUpcomingEvents } = await import('./calendar.js');
    if (!calendarConfigured()) return;
    const { events } = await listUpcomingEvents({ withinHours: 0.5, limit: 5 });
    upcoming = (events || []).find((e) => {
      const start = e.inicio || e.start;
      if (!start) return false;
      const ms = new Date(start).getTime() - Date.now();
      return ms > 10 * 60_000 && ms < 18 * 60_000;
    });
  } catch { /* ignore */ }

  if (!upcoming) return;

  // Detecta nombre de cliente Medicare en el título
  const titulo = upcoming.titulo || upcoming.summary || '';
  let lunaContext = '';
  try {
    const { lunaConfigured, searchMember } = await import('./luna_client.js');
    if (lunaConfigured()) {
      // Extrae nombres posibles (palabras con mayúsculas)
      const names = titulo.match(/[A-Z][a-záéíóúñ]+/g) || [];
      for (const name of names.slice(0, 3)) {
        const r = await searchMember(name).catch(() => null);
        if (r?.ok && Array.isArray(r.data) && r.data.length > 0) {
          const m = r.data[0];
          lunaContext = `\n\nMIEMBRO LUNA: ${m.nombre} (id ${m.id})${m.carrier ? ` · ${m.carrier}` : ''}${m.estado ? ` · ${m.estado}` : ''}`;
          break;
        }
      }
    }
  } catch { /* ignore */ }

  const start = new Date(upcoming.inicio || upcoming.start);
  const hh = start.toLocaleTimeString('es-MX', {
    timeZone: process.env.TIMEZONE || 'America/Los_Angeles',
    hour: '2-digit', minute: '2-digit', hour12: false,
  });

  const prompt = `En 15min Isabel tiene esta cita:

"${titulo}" a las ${hh}
${upcoming.descripcion ? `\nDescripción: ${upcoming.descripcion}` : ''}
${upcoming.asistentes?.length ? `\nAsistentes: ${upcoming.asistentes.map((a) => a.email || a).join(', ')}` : ''}
${lunaContext}

DAME UN BRIEFING PRE-CITA SERIO (no genérico):
- Quién es la persona (1 línea de contexto si la conoces)
- 2-3 puntos concretos a tratar
- Una cosa que NO debe olvidar
- Si es cliente Medicare: status de SOA, MBI, drug list relevante
- Si es cita personal (Dr Bobby, gym, etc.): lo que aplique

Formato WhatsApp, sin markdown. Máximo 150 palabras. Tono profesional pero cálido.`;

  const messages = getHistory();
  messages.push({ role: 'user', content: prompt });
  const { reply, messages: updated } = await runDirectora(messages);
  saveHistory(updated);
  if (!reply) return;

  await sendMessage(to, `⏰ En 15min: ${titulo}\n\n${reply}`);
  logActivity({ tool: 'pre_meeting_deep', input_summary: titulo, result_summary: 'enviado' });
}
