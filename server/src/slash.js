// ============================================================
//  Slash commands — driver directo para Sami (y para Isabel)
//  ──────────────────────────────────────────────────────────
//  Adopta el patrón Cole Medin: las funciones que normalmente
//  corren por cron también son comandos invocables a demanda
//  vía WhatsApp escribiendo "/<comando>".
//
//  Cuando llega un mensaje al webhook que empieza con "/",
//  index.js le pasa el texto entero a runSlash. Si es un
//  comando válido, lo ejecutamos y devolvemos el texto que
//  va a WhatsApp. Si no es un slash command, devolvemos null
//  y el mensaje sigue al flujo normal de Athena.
//
//  Sami sólo ve los comandos en SAMI_ALLOWED. Isabel ve todos.
//  Esto evita que un número equivocado dispare nada raro.
// ============================================================

const SAMI_ALLOWED = new Set([
  'help', 'gaps', 'signals', 'briefing',
  'agenda', 'clientes', 'pendientes', 'historial',
  'compromisos', 'skills', 'tareas', 'huecos', 'luna',
  'revisar',
]);

// Helper: ¿quién está mandando este slash?
function callerRole(from) {
  if (!from) return 'unknown';
  if (from === process.env.ISABEL_WHATSAPP) return 'isabel';
  if (from === process.env.SAMI_WHATSAPP) return 'sami';
  return 'other';
}

// Manejador principal. Devuelve { ok, reply } si era un slash,
// o null si no era.
export async function runSlash(text, from) {
  if (!text || !text.trim().startsWith('/')) return null;
  const raw = text.trim().slice(1);
  const [cmd, ...rest] = raw.split(/\s+/);
  const args = rest.join(' ').trim();
  const role = callerRole(from);

  // Otro número que no sea Isabel ni Sami → respuesta neutra.
  if (role === 'other') return { ok: false, reply: 'Comando no autorizado.' };
  // Sami: bloquear comandos fuera del allowlist.
  if (role === 'sami' && !SAMI_ALLOWED.has(cmd)) {
    return { ok: false, reply: `Sami no tiene permiso para "/${cmd}". Comandos disponibles: ${[...SAMI_ALLOWED].join(', ')}.` };
  }

  try {
    switch (cmd) {
      case 'help': return { ok: true, reply: buildHelp(role) };
      case 'briefing': return { ok: true, reply: await runBriefing() };
      case 'triage': return await runTriage();           // solo isabel
      case 'reflect': return await runReflect();          // solo isabel
      case 'evening': return await runEvening();          // solo isabel
      case 'weekly': return await runWeekly();            // solo isabel
      case 'gaps': return { ok: true, reply: await runGaps(args) };
      case 'signals': return { ok: true, reply: await runSignals() };
      case 'agenda': return { ok: true, reply: await runAgenda(args) };
      case 'clientes': return { ok: true, reply: await runClientes(args) };
      case 'pendientes': return { ok: true, reply: await runPendientes() };
      case 'historial': return { ok: true, reply: await runHistorial(args) };
      case 'compromisos': return { ok: true, reply: await runCompromisos(args) };
      case 'skills': return { ok: true, reply: await runSkills(args) };
      case 'tareas': return { ok: true, reply: await runTareas(args) };
      case 'auditar': return { ok: true, reply: await runAuditar(args) };
      case 'huecos': return { ok: true, reply: await runHuecos(args) };
      case 'luna': return { ok: true, reply: await runLuna(args) };
      case 'revisar': return { ok: true, reply: await runRevisar(from, args) };
      case 'sabado': return { ok: true, reply: await runSabado() };
      case 'auditar': return { ok: true, reply: 'Auditor local retirado — el CRM real vive en LUNA. Para auditoría estructural del equipo, usa LUNA directamente.' };
      case 'seed-medicare-pack': return await runSeedMedicare(); // solo isabel
      case 'envia':
      case 'envía': return await runEnvia(args);          // solo isabel — flush drafts
      case 'descartar': return await runDescartar();      // solo isabel
      case 'backup': return await runBackup();            // solo isabel
      default:
        return { ok: false, reply: `Comando "/${cmd}" no existe. Usa /help para ver la lista.` };
    }
  } catch (err) {
    return { ok: false, reply: `Error ejecutando /${cmd}: ${err.message}` };
  }
}

function buildHelp(role) {
  const samiCmds = [
    '/help — esta lista',
    '/briefing — corre el morning brief manualmente',
    '/gaps [alto|aviso|info] — qué huecos hay',
    '/signals — señales computadas anoche',
    '/agenda [hrs] — próximos eventos (default 24h)',
    '/clientes [activos|leads|prospects] — lista',
    '/pendientes — borradores que esperan envío',
    '/compromisos [persona] — promesas hacia Isabel',
    '/tareas [athena|isabel|sami] — cola por dueño',
    '/skills — playbooks activos',
    '/historial [n] — últimas N acciones',
    '/huecos [dias] — huecos libres en el calendario (default 7 días)',
    '/luna [ping] — briefing del CRM real de LUNA (sin args = full briefing)',
  ];
  const isabelExtras = [
    '/triage — corre triage de email ahora',
    '/reflect — corre reflexión nocturna ahora',
    '/evening — manda evening check-in ahora',
    '/weekly — manda weekly review ahora',
    '/envía [id?] — manda los borradores pendientes',
    '/descartar — descarta todos los borradores',
    '/backup — snapshot inmediato a R2',
    '/seed-medicare-pack — instala 6 skills draft del workflow Medicare',
  ];
  return role === 'isabel'
    ? `Comandos disponibles:\n${samiCmds.join('\n')}\n\nExtras (solo tú):\n${isabelExtras.join('\n')}`
    : `Comandos para ti (Sami):\n${samiCmds.join('\n')}`;
}

// ---- Implementaciones (cada una un import dinámico para no
//      inflar el bundle de slash.js y evitar ciclos) ----

async function runBriefing() {
  const { sendMorningBriefing } = await import('./briefing.js');
  await sendMorningBriefing();
  return 'Briefing enviado ✓';
}

async function runTriage() {
  const { nightlyEmailTriage } = await import('./triage.js');
  await nightlyEmailTriage();
  return { ok: true, reply: 'Triage corrió ✓' };
}

async function runReflect() {
  const { nightlyReflection } = await import('./proactive.js');
  await nightlyReflection();
  return { ok: true, reply: 'Reflexión corrida ✓' };
}

async function runEvening() {
  const { sendEveningCheckin } = await import('./proactive.js');
  await sendEveningCheckin();
  return { ok: true, reply: 'Evening check-in enviado ✓' };
}

async function runWeekly() {
  const { sendWeeklyReview } = await import('./proactive.js');
  await sendWeeklyReview();
  return { ok: true, reply: 'Weekly review enviada ✓' };
}

async function runGaps(args) {
  const { computeGaps } = await import('./gaps.js');
  let gaps = computeGaps({ limit: 100 });
  if (args && ['alto', 'aviso', 'info'].includes(args.toLowerCase())) {
    gaps = gaps.filter((g) => g.severidad === args.toLowerCase());
  }
  if (!gaps.length) return 'Sin huecos. ✓';
  return gaps.slice(0, 20).map((g) => {
    const icon = g.severidad === 'alto' ? '🛑' : g.severidad === 'aviso' ? '⚠️' : 'ℹ️';
    return `${icon} ${g.target_name}: ${g.missing_field} — ${g.mensaje}`;
  }).join('\n');
}

async function runSignals() {
  const { loadSignals } = await import('./signals.js');
  const { signals } = loadSignals();
  if (!signals?.length) return 'Sin señales todavía (la reflexión corre a las 2am).';
  const byPrio = ['alto', 'aviso', 'info'];
  return signals
    .slice()
    .sort((a, b) => byPrio.indexOf(a.severidad) - byPrio.indexOf(b.severidad))
    .map((s) => `[${s.severidad}] ${s.mensaje}`).join('\n');
}

async function runAgenda(args) {
  const { listUpcomingEvents, calendarConfigured } = await import('./calendar.js');
  if (!calendarConfigured()) return 'Calendar no configurado.';
  const hrs = parseInt(args, 10) || 24;
  const r = await listUpcomingEvents({ withinHours: hrs, limit: 15 });
  if (!r.ok) return r.reason;
  if (!r.events.length) return `Sin eventos en las próximas ${hrs}h.`;
  return r.events.map((e) => `${e.inicio_local} — ${e.titulo}${e.asistentes.length ? ` (con ${e.asistentes.join(', ')})` : ''}`).join('\n');
}

async function runClientes(args) {
  const { listClients } = await import('./crm.js');
  const status = ['active', 'lead', 'prospect', 'inactive'].includes((args || '').toLowerCase()) ? args.toLowerCase() : null;
  // Soportar plural: "activos" → "active"
  const map = { activos: 'active', leads: 'lead', prospects: 'prospect', inactivos: 'inactive' };
  const final = status || map[(args || '').toLowerCase()] || null;
  const cs = listClients({ status: final, limit: 50 });
  if (!cs.length) return 'Sin clientes en ese filtro.';
  return cs.slice(0, 25).map((c) => `[${c.id}] ${c.nombre} · ${c.carrier || '?'} · ${c.status}`).join('\n');
}

async function runPendientes() {
  const { getPendingOutbound } = await import('./memory.js');
  const queue = getPendingOutbound();
  if (!queue.length) return 'Sin borradores pendientes.';
  return queue.map((p) => {
    if (p.type === 'email') return `[${p.id}] EMAIL a ${p.para} — "${p.asunto}"`;
    if (p.type === 'sms') return `[${p.id}] SMS a ${p.para}`;
    return `[${p.id}] ${p.type}`;
  }).join('\n');
}

async function runHistorial(args) {
  const { getActivity } = await import('./memory.js');
  const n = parseInt(args, 10) || 20;
  const log = getActivity().slice(0, n);
  if (!log.length) return 'Audit log vacío.';
  return log.map((e) => `${e.ts.slice(11, 16)} ${e.tool}: ${e.result_summary || e.input_summary}`).join('\n');
}

async function runCompromisos(args) {
  const { listCommitments } = await import('./commitments.js');
  const items = listCommitments({ persona: args || null });
  if (!items.length) return 'Sin compromisos pendientes.';
  return items.map((c) => {
    const due = c.vence ? ` (vence ${c.vence.slice(0, 10)})` : '';
    return `[${c.id}] ${c.persona}: ${c.descripcion}${due}`;
  }).join('\n');
}

async function runSkills(args) {
  const { listSkills } = await import('./skills.js');
  const status = ['draft', 'active', 'retired'].includes(args) ? args : 'active';
  const items = listSkills({ status });
  if (!items.length) return `Sin skills (${status}).`;
  return items.map((s) => `[${s.name}] v${s.version} · ${s.invocaciones || 0} usos · ${s.descripcion}`).join('\n');
}

async function runTareas(args) {
  const { listTasks } = await import('./tasks.js');
  const owner = ['athena', 'isabel', 'sami'].includes(args) ? args : null;
  const items = listTasks({ responsable: owner });
  if (!items.length) return 'Sin tareas activas.';
  return items.map((t) => `[${t.id}] ${t.responsable}: ${t.descripcion}${t.vence ? ` (vence ${t.vence.slice(0, 10)})` : ''}`).join('\n');
}

async function runEnvia(args) {
  const { popOutbound } = await import('./memory.js');
  const { sendEmail } = await import('./email.js');
  const { sendMessage } = await import('./whatsapp.js');
  const item = popOutbound(args || null);
  if (!item) return { ok: false, reply: 'No había nada en cola.' };
  try {
    if (item.type === 'email') {
      const msg = await sendEmail(item.para, item.asunto, item.cuerpo);
      return { ok: true, reply: `Email enviado. ${msg}` };
    }
    if (item.type === 'sms') {
      await sendMessage(item.para, item.mensaje);
      return { ok: true, reply: `SMS enviado a ${item.para}.` };
    }
    return { ok: false, reply: `Tipo desconocido: ${item.type}` };
  } catch (err) {
    return { ok: false, reply: `Error: ${err.message}` };
  }
}

async function runDescartar() {
  const { clearOutbound } = await import('./memory.js');
  const n = clearOutbound();
  return { ok: true, reply: n ? `Descarté ${n} borrador(es).` : 'No había nada en cola.' };
}

// Detectar quién manda (Isabel o Sami) según número.
function inferPersona(from) {
  const isabel = process.env.ISABEL_WHATSAPP || '';
  const sami = process.env.SAMI_WHATSAPP || '';
  if (from === isabel) return 'Isabel';
  if (from === sami) return 'Sami';
  return 'Equipo';
}

async function runSabado() {
  const { buildSaturdayBrief } = await import('./saturday_brief.js');
  return buildSaturdayBrief();
}

async function runRevisar(from, args) {
  if (!args || args.length < 5) {
    return 'Uso: /revisar <texto del borrador que vas a mandar>. Athena lo revisa antes de que salga (typos Medicare, claims CMS, tono, disclaimer). Te dice APROBADO / APROBADO CON NOTAS / RECHAZADO.';
  }
  const { reviewTeamDraft, formatReviewResult } = await import('./team_review.js');
  const persona = inferPersona(from);
  const r = await reviewTeamDraft({
    persona,
    contenido: args,
    destinatario: '',
    tipo: 'email',
  });
  return formatReviewResult(r);
}

async function runLuna(args) {
  const { lunaConfigured, fullBriefing, pipelineSummary } = await import('./luna_client.js');
  if (!lunaConfigured()) {
    return 'LUNA no está configurado en este servidor. Faltan LUNA_BASE_URL y LUNA_API_KEY.';
  }
  // Sin args → briefing. Con "ping" → solo pipeline (test ligero).
  if (args === 'ping') {
    const r = await pipelineSummary();
    if (!r.ok) return `LUNA inalcanzable: ${r.error}`;
    return `LUNA ✓ — ${r.data?.total_miembros || 0} miembros en pipeline.`;
  }
  const r = await fullBriefing();
  if (!r.ok) return `LUNA inalcanzable: ${r.error}`;
  const d = r.data || {};
  const lines = [];
  if (d.estados) lines.push(`Pipeline: ${Object.entries(d.estados).map(([k, v]) => `${k}=${v}`).join(' · ')}`);
  if (d.hot_leads_frios?.length) lines.push(`🔥 ${d.hot_leads_frios.length} hot leads fríos`);
  if (d.t65_urgentes?.length) lines.push(`🎂 ${d.t65_urgentes.length} T65 urgentes`);
  if (d.retencion_hoy?.length) lines.push(`📞 ${d.retencion_hoy.length} llamadas retención hoy`);
  if (d.soa_pendiente) lines.push(`⚠️ ${d.soa_pendiente} SOAs faltantes`);
  if (d.tickets_urgentes?.length) lines.push(`🚨 ${d.tickets_urgentes.length} tickets ALTA`);
  if (d.callbacks) lines.push(`☎️ ${d.callbacks} callbacks pendientes`);
  return lines.length ? lines.join('\n') : 'LUNA limpio — sin alertas activas.';
}

async function runHuecos(args) {
  const { findFreeSlots, calendarConfigured } = await import('./calendar.js');
  if (!calendarConfigured()) return 'Google Calendar no configurado.';
  // Default: próximos 7 días, slots de 30 min, 09:00–17:00, L-V.
  const dias = Math.min(Math.max(parseInt(args, 10) || 7, 1), 30);
  const ahora = new Date();
  const fin = new Date(ahora.getTime() + dias * 86_400_000);
  const r = await findFreeSlots({
    fecha_inicio: ahora.toISOString(),
    fecha_fin: fin.toISOString(),
    duracion_min: 30,
    limit: 12,
  });
  if (!r.ok) return `No pude buscar huecos: ${r.reason}`;
  if (!r.slots.length) return `No hay huecos en los próximos ${dias} días con horario laboral default.`;
  return `${r.slots.length} huecos en los próximos ${dias} días:\n${r.slots.map((s) => `  • ${s.inicio_local}`).join('\n')}`;
}

async function runSeedMedicare() {
  const { seedMedicareSkills } = await import('./skills.js');
  const r = seedMedicareSkills();
  const parts = [];
  if (r.created.length) parts.push(`Creadas (${r.created.length}): ${r.created.join(', ')}`);
  if (r.skipped.length) parts.push(`Ya existían: ${r.skipped.join(', ')}`);
  parts.push('Aprueba cada una con "aprueba la skill X" cuando quieras activarla.');
  return { ok: true, reply: parts.join('\n') };
}

async function runBackup() {
  const { snapshot } = await import('./backup.js');
  const r = await snapshot();
  return {
    ok: r.ok,
    reply: r.ok ? `Snapshot OK: ${r.file}${r.synced ? ` (sync: ${r.synced})` : ''}` : `Backup falló: ${r.reason}`,
  };
}
