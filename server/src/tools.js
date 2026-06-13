import { SPECIALISTS, specialistList } from './agents.js';
import { askSpecialist } from './claude.js';
import { sendMessage } from './whatsapp.js';
import { sendEmail, checkEmails } from './email.js';
import {
  remember,
  forget,
  listMemories,
  setSeason,
  buildWikiContext,
  queueOutbound,
  popOutbound,
  clearOutbound,
  getPendingOutbound,
  logActivity,
  getActivity,
} from './memory.js';
import {
  createTask,
  listTasks,
  completeTask,
  snoozeTask,
  cancelTask,
  addTaskNote,
} from './tasks.js';
import { listUpcomingEvents, getEvent, createEvent, updateEvent, deleteEvent, findFreeSlots, calendarConfigured } from './calendar.js';
import {
  createCommitment,
  listCommitments,
  getCommitment,
  completeCommitment,
  failCommitment,
  cancelCommitment,
  noteCommitment,
} from './commitments.js';
import { pendingResponses, recentActivity, nextivaConfigured } from './nextiva.js';
import {
  pendingDms,
  pendingComments,
  recentComments,
  snapshot as igSnapshot,
  instagramConfigured,
} from './instagram.js';
import {
  upsertEntity,
  findEntity,
  getEntity,
  listEntities,
  linkClient,
  mergeEntities,
  entityCard,
} from './entities.js';
import { loadSignals } from './signals.js';
import { placeOutboundCall } from './voice.js';
import { reviewOutbound, formatReviewForHumans } from './hooks.js';
import {
  proposeSkill,
  approveSkill,
  retireSkill,
  deleteSkill,
  loadSkill,
  listSkills,
  markInvoked,
  skillCard,
  seedMedicareSkills,
} from './skills.js';

// Definiciones de las herramientas que Athena puede usar.
// Cada una tiene un esquema (qué inputs acepta) que Claude lee.
//
// Tools MCP (Zapier/Notion/etc.) se agregan dinámicamente al boot
// via getDynamicToolDefinitions() — directora.js llama esa función
// en vez del array directo para incluirlas.
import { toolDefinitions } from './tool_definitions.js';
export { toolDefinitions };

// Ejecuta una herramienta y devuelve el resultado como texto.
// Toda llamada queda registrada en el activity log (audit trail).
// Devuelve toolDefinitions + tools MCP descubiertas. Lo llama directora.js
// en cada llamada a Anthropic — tools MCP son descubiertas al boot via
// initMcpClients() y refrescadas cada hora por cron mcp_refresh.
export function getDynamicToolDefinitions() {
  // require-style import — solo importamos cuando se llama, para que
  // tests/scripts simples no requieran inicializar MCP.
  try {
    // dynamic import sync via cached: usamos un wrapper para evitar await
    const mcp = globalThis.__mcpToolsCache || [];
    return [...toolDefinitions, ...mcp];
  } catch {
    return toolDefinitions;
  }
}

// Carga inicial de MCP — llamado al boot por index.js.
// Cachea el resultado en global para que getDynamicToolDefinitions sea sync.
export async function initToolsFromMcp() {
  const { initMcpClients, getMcpToolDefinitions } = await import('./mcp_client.js');
  const r = await initMcpClients();
  globalThis.__mcpToolsCache = getMcpToolDefinitions();
  return r;
}

export async function refreshMcpToolsCache() {
  const { refreshMcpClients, getMcpToolDefinitions } = await import('./mcp_client.js');
  await refreshMcpClients();
  globalThis.__mcpToolsCache = getMcpToolDefinitions();
}

export async function runTool(name, input) {
  // MCP tools tienen prefijo mcp_<alias>_ — dispatch separado.
  if (typeof name === 'string' && name.startsWith('mcp_')) {
    const { runMcpTool } = await import('./mcp_client.js');
    const result = await runMcpTool(name, input);
    try {
      logActivity({
        tool: name,
        input_summary: JSON.stringify(input).slice(0, 200),
        result_summary: typeof result === 'string' ? result.slice(0, 200) : String(result).slice(0, 200),
      });
    } catch { /* ignore */ }
    return result;
  }
  const result = await dispatchTool(name, input);
  try {
    logActivity({
      tool: name,
      input_summary: summarizeInput(name, input),
      result_summary: typeof result === 'string' ? result : String(result),
    });
  } catch {
    /* el log nunca debe tumbar la herramienta */
  }
  // Auto-AAR: para tools de "decisión significativa" abrimos un AAR
  // automáticamente. Athena no tiene que llamar aar_abrir cada vez.
  // El AAR queda abierto para que la directora lo cierre con
  // aar_cerrar cuando sepa el resultado real (puede ser días después).
  try {
    await maybeOpenAutoAar(name, input, result);
  } catch { /* nunca tumba la tool */ }
  return result;
}

// Mapa de tools que merecen AAR automático.
// El tipo + intended se derivan del input.
const AUTO_AAR_MAP = {
  enviar_email: (input) => ({
    type: 'outreach',
    intended: `Mandar email a ${input.para || '?'} sobre "${input.asunto || '?'}"`,
    target: String(input.para || '').slice(0, 80),
  }),
  enviar_sms: (input) => ({
    type: 'outreach',
    intended: `SMS a ${input.para || '?'}: ${String(input.mensaje || '').slice(0, 80)}`,
    target: String(input.para || '').slice(0, 80),
  }),
  mensaje_a_sami: (input) => ({
    type: 'delegation',
    intended: `Delegar a Sami: ${String(input.mensaje || '').slice(0, 100)}`,
    target: 'Sami',
  }),
  llamar_cliente: (input) => ({
    type: 'call',
    intended: `Llamar a ${input.para || '?'} para ${String(input.motivo || '').slice(0, 80)}`,
    target: String(input.para || '').slice(0, 80),
  }),
  crear_cita: (input) => ({
    type: 'meeting',
    intended: `Agendar ${input.titulo || '?'} para ${input.inicio || '?'}`,
    target: (input.asistentes || []).join(', ').slice(0, 80),
  }),
  consultar_especialistas: (input) => ({
    type: 'consult',
    intended: `Consultar ${(input.consultas || []).map((c) => c.especialista).join('+')} sobre "${String(input.consultas?.[0]?.tarea || '').slice(0, 80)}"`,
    target: (input.consultas || []).map((c) => c.especialista).join('+'),
  }),
};

async function maybeOpenAutoAar(name, input, result) {
  const fn = AUTO_AAR_MAP[name];
  if (!fn) return;
  // Si el resultado dice "error" o "no pude" / "no pude crear", no abre AAR.
  const resStr = typeof result === 'string' ? result.toLowerCase() : '';
  if (resStr.startsWith('error') || resStr.includes('no pude')) return;
  const decision = fn(input);
  if (!decision?.intended) return;
  try {
    const { openDecision } = await import('./aar.js');
    openDecision({
      type: decision.type,
      intended: decision.intended,
      target: decision.target || '',
      context: `auto-tool: ${name}`,
    });
  } catch { /* ignore */ }
}

function summarizeInput(name, input) {
  if (!input) return '';
  // Resumen corto sin volcar datos sensibles enteros (cuerpos de email, etc.)
  if (name === 'enviar_email') return `para=${input.para} asunto="${input.asunto}"`;
  if (name === 'enviar_sms') return `para=${input.para} (${(input.mensaje || '').length} chars)`;
  if (name === 'mensaje_a_sami') return `(${(input.mensaje || '').length} chars)`;
  if (name === 'consultar_especialistas') {
    const ids = (input.consultas || []).map((c) => c.especialista).join('+');
    return `coaches=${ids}`;
  }
  if (name === 'crear_tarea') return `${input.responsable}: ${String(input.descripcion || '').slice(0, 80)}`;
  if (name === 'completar_tarea') return input.id;
  if (name === 'posponer_tarea' || name === 'cancelar_tarea') return input.id;
  return JSON.stringify(input).slice(0, 200);
}

async function dispatchTool(name, input) {
  switch (name) {
    case 'consultar_especialistas': {
      const consultas = Array.isArray(input.consultas) ? input.consultas : [];
      if (!consultas.length) {
        return 'Pasa al menos una entrada en `consultas` con {especialista, tarea}.';
      }
      // Pilar Medicare es la única coach con acceso a LUNA. Se inyectan
      // dinámicamente las 14 tools luna_* solo cuando es ella la consultada.
      // Carmen/Rivera/Sofía reciben datos REALES de hábitos (peso, agua,
      // proteína, workouts, sueño) — sin esto coachean a ciegas.
      const { LUNA_TOOL_DEFINITIONS, runLunaTool } = await import('./luna_tools.js');
      const { buildHabitsForCoach } = await import('./habits.js');
      const { buildFinanzasForCoach } = await import('./finanzas.js');
      const { buildJournalForCoach } = await import('./journal.js');
      const { buildGoalsForCoach } = await import('./goals.js');
      const HEALTH_COACHES = new Set(['carmen', 'rivera', 'sofia']);
      const wiki = buildWikiContext();
      const results = await Promise.all(
        consultas.map(async (c) => {
          const spec = SPECIALISTS[c.especialista];
          if (!spec) {
            return `[${c.especialista} — no existe esa coach. Opciones: ${specialistList()}]`;
          }
          // Smart coaches A: cada coach tiene web_search server-side de
          // Anthropic (max 2 usos por turno) — datos actuales de su
          // dominio en lugar de coachear con knowledge de entrenamiento.
          const WEB_SEARCH = { type: 'web_search_20250305', name: 'web_search', max_uses: 2 };
          const opts = {
            formato: c.formato_salida,
            presupuesto: c.presupuesto_palabras,
            tools: [WEB_SEARCH],
          };
          if (c.especialista === 'luna' || c.especialista === 'pilar') {
            // Pilar: LUNA + web_search. Sus datos viven en LUNA — no
            // necesita coach_plan/notes (los miembros, pólizas, tickets
            // ya son su "plan/expediente" estructurado).
            opts.tools = [WEB_SEARCH, ...LUNA_TOOL_DEFINITIONS];
            opts.toolDispatcher = runLunaTool;
          } else {
            // Phase D: las demás coaches pueden actualizar su plan +
            // expediente AUNQUE estés en WhatsApp consultándolas vía
            // Athena. Antes solo podían hacerlo en chat directo de PWA.
            // El dispatcher está scoped al coach específico — Sofía no
            // toca el expediente de Carmen, etc.
            const { coachPlanTools, makeCoachPlanDispatcher } = await import('./coach_plan_tools.js');
            opts.tools = [WEB_SEARCH, ...coachPlanTools];
            opts.toolDispatcher = makeCoachPlanDispatcher(c.especialista);
          }
          // Cada coach recibe los datos relevantes a su dominio.
          let wikiAumentado = wiki;
          if (HEALTH_COACHES.has(c.especialista)) {
            const habits = buildHabitsForCoach(c.especialista);
            if (habits) wikiAumentado += habits;
            // Rapport semanal (peso/medidas/sentires) para Sofía/Rivera/Carmen
            try {
              const { buildRapportForCoach } = await import('./rapport.js');
              const rap = buildRapportForCoach();
              if (rap) wikiAumentado += rap;
            } catch { /* ignore */ }
          }
          if (c.especialista === 'elena') {
            const f = buildFinanzasForCoach();
            if (f) wikiAumentado += f;
          }
          if (c.especialista === 'alma') {
            const j = buildJournalForCoach();
            if (j) wikiAumentado += j;
            // Alma también lee hábitos para correlacionar sueño con ánimo
            const h = buildHabitsForCoach('alma');
            if (h) wikiAumentado += h;
          }
          if (c.especialista === 'victoria') {
            const g = buildGoalsForCoach();
            if (g) wikiAumentado += g;
          }
          if (c.especialista === 'marisol') {
            const { buildBrandForMarisol } = await import('./brand.js');
            const b = buildBrandForMarisol();
            if (b) wikiAumentado += b;
          }
          // Cada coach ve SU expediente + SU plan vigente (smart coaches
          // C). Pilar no aplica — sus "datos" viven en LUNA como
          // miembros/pólizas/tickets.
          if (c.especialista !== 'luna' && c.especialista !== 'pilar') {
            const { planAsContext } = await import('./coach_plans.js');
            const { notesAsContext } = await import('./coach_notes.js');
            const notesCtx = notesAsContext(c.especialista, spec.name);
            if (notesCtx) wikiAumentado += '\n\n' + notesCtx;
            const planCtx = planAsContext(c.especialista, spec.name);
            if (planCtx) wikiAumentado += '\n\n' + planCtx;
          }
          try {
            const answer = await askSpecialist(spec, c.tarea, wikiAumentado, opts);
            return { name: spec.name, id: c.especialista, answer, wiki: wikiAumentado, spec, opts, tarea: c.tarea };
          } catch (err) {
            return { name: spec.name, id: c.especialista, answer: `[error: ${err.message}]`, error: true };
          }
        })
      );

      // ─── HUDDLE MODE: ronda 2 — cada coach ve a las otras y refina ───
      const mode = input.mode === 'huddle' && consultas.length >= 2 ? 'huddle' : 'parallel';
      if (mode === 'huddle') {
        const otrosFor = (currentId) => results
          .filter((r) => r.id !== currentId && !r.error)
          .map((r) => `${r.name} dijo:\n"${r.answer}"`)
          .join('\n\n');
        const refined = await Promise.all(
          results.map(async (r) => {
            if (r.error) return r;
            const otrosTexto = otrosFor(r.id);
            if (!otrosTexto) return r;
            const huddleTarea = `${r.tarea}\n\n--- TEAM HUDDLE — lo que respondieron las OTRAS coaches ---\n${otrosTexto}\n\nAhora REFINA tu consejo en contexto del grupo. ¿Alguna trae algo que cambie tu vista? ¿Algún punto que necesites empujar o complementar? Mantén tu autoridad de dominio (no invadas el suyo) pero reconoce el cruce. Máximo 150 palabras. NO repitas tu respuesta anterior — DELTA solamente.`;
            try {
              const refined = await askSpecialist(r.spec, huddleTarea, r.wiki, r.opts);
              return { ...r, refined };
            } catch (err) {
              return r;
            }
          })
        );
        const out = refined.map((r) => {
          if (r.error || !r.refined) return `${r.name} dice:\n${r.answer}`;
          return `${r.name} (ronda 1):\n${r.answer}\n\n${r.name} (ronda 2 — refinada en huddle):\n${r.refined}`;
        });
        return `[Team huddle — 2 rondas]\n\n${out.join('\n\n---\n\n')}`;
      }

      return results.map((r) => `${r.name} dice:\n${r.answer}`).join('\n\n---\n\n');
    }
    case 'mensaje_a_sami': {
      // Opción A: si Sami está de licencia (cirugía/baja), no la molestamos —
      // el mensaje rebota a Isabel para que ella decida.
      const { isOnLeave, leaveUntil } = await import('./team_status.js');
      if (isOnLeave('Sami')) {
        const hasta = leaveUntil('Sami');
        const isabelNum = process.env.ISABEL_WHATSAPP;
        if (isabelNum) {
          try {
            await sendMessage(isabelNum, `↪️ Esto era para Sami, pero está de licencia${hasta ? ` hasta ${hasta}` : ''}:\n\n"${input.mensaje}"\n\nTú decides: hazlo, espera, o pásalo a Arlette.`);
          } catch { /* si falla el rebote, igual avisamos abajo */ }
        }
        return `Sami está de licencia${hasta ? ` hasta ${hasta}` : ''} — no le mandé el mensaje. Te lo reboté a ti para que decidas.`;
      }
      const to = process.env.SAMI_WHATSAPP;
      if (!to) return 'No hay número de Sami configurado (SAMI_WHATSAPP en el .env).';
      // Sami se manda solo (humano-en-el-loop) → revisamos ANTES de mandar.
      // Si hay flag "alto" lo bloqueamos para que Athena recapacite.
      const review = await reviewOutbound({ toolName: 'mensaje_a_sami', input });
      if (review.severidad_max === 'alto') {
        return `🛑 Mensaje a Sami BLOQUEADO por revisión:\n${formatReviewForHumans(review)}\n\nReformula y vuelve a llamar la tool.`;
      }
      await sendMessage(to, `De Athena (Isabel):\n${input.mensaje}`);
      const flagSuffix = review.flags.length ? `\n${formatReviewForHumans(review)}` : '';
      return `Mensaje enviado a Sami: "${input.mensaje}"${flagSuffix}`;
    }
    case 'enviar_sms': {
      let to = String(input.para || '').trim();
      if (!to) return 'Falta el número de teléfono.';
      if (!to.startsWith('+')) to = '+' + to.replace(/^[^\d]*/, '');
      // Review en paralelo — corre mientras encolamos. El resultado
      // se incluye en el draft para que Isabel lo vea antes de "envía".
      const review = await reviewOutbound({ toolName: 'enviar_sms', input: { para: to, mensaje: input.mensaje } });
      const id = queueOutbound({ type: 'sms', para: to, mensaje: input.mensaje, review: review.flags });
      const flagSuffix = review.flags.length ? `\n${formatReviewForHumans(review)}` : '';
      return `Borrador SMS encolado (id=${id}). Para: ${to}. Mensaje: "${input.mensaje}".${flagSuffix}\nESPERA que Isabel diga "envía" o "sí" antes de llamar confirmar_envio.`;
    }
    case 'enviar_email': {
      const review = await reviewOutbound({ toolName: 'enviar_email', input });
      const id = queueOutbound({
        type: 'email',
        para: input.para,
        asunto: input.asunto,
        cuerpo: input.cuerpo,
        review: review.flags,
      });
      const flagSuffix = review.flags.length ? `\n${formatReviewForHumans(review)}` : '';
      return `Borrador email encolado (id=${id}).\nPara: ${input.para}\nAsunto: ${input.asunto}\n---\n${input.cuerpo}\n---${flagSuffix}\nESPERA que Isabel confirme antes de llamar confirmar_envio.`;
    }
    case 'confirmar_envio': {
      const item = popOutbound(input.id || null);
      if (!item) return 'No había ningún borrador pendiente.';
      try {
        if (item.type === 'email') {
          const msg = await sendEmail(item.para, item.asunto, item.cuerpo);
          return `Confirmado y enviado. ${msg}`;
        }
        if (item.type === 'sms') {
          await sendMessage(item.para, item.mensaje);
          return `SMS enviado a ${item.para}.`;
        }
        return `Tipo desconocido en cola: ${item.type}`;
      } catch (err) {
        // Re-encolar para que Isabel pueda reintentar sin volver a redactar.
        // Antes este bug perdía el draft tras falla SMTP — provocaba el ciclo
        // "envía → 'borrador no en cola' → 'sí preparalo de nuevo'".
        try {
          const { queueOutbound } = await import('./memory.js');
          queueOutbound(item);
        } catch { /* mejor preservar el error original */ }
        return `Error al enviar el borrador ${item.id} — lo dejé en cola para retry: ${err.message}`;
      }
    }
    case 'descartar_envio': {
      if (input.id) {
        const item = popOutbound(input.id);
        return item ? `Borrador ${item.id} descartado.` : `No encontré el borrador ${input.id}.`;
      }
      const n = clearOutbound();
      return n ? `Descarté ${n} borrador(es) pendientes.` : 'No había nada pendiente.';
    }
    case 'revisar_emails':
      return await checkEmails(input.cuantos || 5);
    case 'recordar':
      remember(input.nota);
      return `Guardado en la memoria: "${input.nota}"`;
    case 'olvidar': {
      const { borradas, restantes } = forget(input.que);
      if (!borradas) return `No encontré nada en la memoria que matchee "${input.que}".`;
      return `Borré ${borradas} nota(s) que mencionaban "${input.que}". Quedan ${restantes} en total.`;
    }
    case 'que_recuerdas': {
      const cuantas = Math.min(Math.max(parseInt(input.cuantas, 10) || 20, 1), 50);
      const notas = listMemories(cuantas);
      if (!notas.length) return 'Tu wiki está vacía — todavía no he guardado nada.';
      return notas.map((n, i) => `${i + 1}. ${n.nota}`).join('\n');
    }
    case 'actualizar_temporada': {
      const s = setSeason(input.texto);
      return s.texto ? `Temporada actualizada: "${s.texto}"` : 'Temporada vacía.';
    }
    case 'historial': {
      const horas = Math.min(Math.max(parseInt(input.desde_horas, 10) || 24, 1), 7 * 24);
      const limite = Math.min(Math.max(parseInt(input.limite, 10) || 25, 1), 100);
      const since = new Date(Date.now() - horas * 60 * 60 * 1000).toISOString();
      const entries = getActivity(since).slice(0, limite);
      if (!entries.length) return `Sin actividad en las últimas ${horas}h.`;
      return entries
        .map((e) => {
          const t = new Date(e.ts).toLocaleString('es-MX', { timeZone: process.env.TIMEZONE || 'America/Los_Angeles' });
          return `${t} · ${e.tool}${e.input_summary ? ` (${e.input_summary})` : ''}`;
        })
        .join('\n');
    }
    case 'crear_tarea': {
      try {
        const t = createTask(input);
        const venceStr = t.vence
          ? ` Vence: ${new Date(t.vence).toLocaleString('es-MX', { timeZone: process.env.TIMEZONE || 'America/Los_Angeles' })}.`
          : '';
        // Auto-grouping: si la tarea encaja en un proyecto activo, vincula.
        let autoStr = '';
        try {
          const { autoGroupItem } = await import('./project_classifier.js');
          const r = await autoGroupItem({
            kind: 'task',
            itemId: t.id,
            title: t.descripcion || t.titulo,
            description: t.contexto || '',
          });
          if (r.auto_grouped) autoStr = ` · vinculada a proyecto "${r.project_nombre}".`;
        } catch (e) { console.warn('[autogroup tarea]', e.message); }
        return `Tarea creada [${t.id}] para ${t.responsable}: "${t.descripcion}".${venceStr}${autoStr}`;
      } catch (err) {
        return `Error creando tarea: ${err.message}`;
      }
    }
    case 'mis_tareas': {
      const items = listTasks({ responsable: input.responsable || null, status: input.status || null });
      if (!items.length) return 'No hay tareas activas.';
      return items
        .map((t) => {
          const due = t.vence
            ? ` (vence ${new Date(t.vence).toLocaleDateString('es-MX', { timeZone: process.env.TIMEZONE || 'America/Los_Angeles', month: 'short', day: 'numeric' })})`
            : '';
          return `[${t.id}] ${t.responsable} · ${t.descripcion}${due}${t.prioridad === 'alta' ? ' ★' : ''}`;
        })
        .join('\n');
    }
    case 'completar_tarea': {
      const t = completeTask(input.id, input.resultado);
      if (!t) return `No encontré la tarea ${input.id}.`;
      return `Tarea ${t.id} completada: "${t.descripcion}". Resultado guardado.`;
    }
    case 'posponer_tarea': {
      try {
        const t = snoozeTask(input.id, input);
        if (!t) return `No encontré la tarea ${input.id}.`;
        if (input.nota) addTaskNote(input.id, input.nota);
        return `Tarea ${t.id} pospuesta. Nueva fecha: ${new Date(t.vence).toLocaleString('es-MX', { timeZone: process.env.TIMEZONE || 'America/Los_Angeles' })}.`;
      } catch (err) {
        return `Error posponiendo: ${err.message}`;
      }
    }
    case 'cancelar_tarea': {
      const t = cancelTask(input.id);
      if (!t) return `No encontré la tarea ${input.id}.`;
      if (input.razon) addTaskNote(input.id, `Cancelada: ${input.razon}`);
      return `Tarea ${t.id} cancelada.`;
    }
    case 'proximos_eventos': {
      if (!calendarConfigured()) {
        return 'Google Calendar todavía no está conectado. Para activarlo Isabel necesita autorizar OAuth (Sami o tú le pueden guiar) y agregar GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REFRESH_TOKEN al .env.';
      }
      const horas = Math.min(Math.max(parseInt(input.horas, 10) || 24, 1), 168);
      const limite = Math.min(Math.max(parseInt(input.limite, 10) || 10, 1), 25);
      const r = await listUpcomingEvents({ withinHours: horas, limit: limite });
      if (!r.ok) return `No pude leer el calendario: ${r.reason}`;
      if (!r.events.length) return 'No hay eventos en ese rango.';
      return r.events
        .map((e) => {
          const who = e.asistentes.length ? ` · con ${e.asistentes.slice(0, 3).join(', ')}` : '';
          const where = e.ubicacion ? ` · ${e.ubicacion}` : '';
          return `[${e.id}] ${e.inicio_local} — ${e.titulo}${who}${where}`;
        })
        .join('\n');
    }
    case 'detalles_cita': {
      if (!calendarConfigured()) return 'Google Calendar no configurado.';
      const r = await getEvent(input.id);
      if (!r.ok) return `No pude obtener el evento: ${r.reason}`;
      const e = r.event;
      const lines = [`${e.titulo}`, `Cuándo: ${e.inicio_local}`];
      if (e.ubicacion) lines.push(`Lugar: ${e.ubicacion}`);
      if (e.meet) lines.push(`Meet: ${e.meet}`);
      if (e.asistentes.length) lines.push(`Asistentes: ${e.asistentes.join(', ')}`);
      if (e.organizador) lines.push(`Organiza: ${e.organizador}`);
      if (e.descripcion) lines.push(`\nDescripción:\n${e.descripcion}`);
      return lines.join('\n');
    }
    // ── compromisos ──
    case 'comprometer_entrega': {
      try {
        const c = createCommitment(input);
        const due = c.vence ? ` Vence: ${new Date(c.vence).toLocaleString('es-MX', { timeZone: process.env.TIMEZONE || 'America/Los_Angeles' })}.` : '';
        const reach = c.persona_contacto ? ` Lo voy a perseguir vía ${c.canal} a ${c.persona_contacto}.` : ' Sin contacto registrado — solo te aviso cuando se atrase.';
        // Auto-grouping
        let autoStr = '';
        try {
          const { autoGroupItem } = await import('./project_classifier.js');
          const r = await autoGroupItem({
            kind: 'commitment',
            itemId: c.id,
            title: `${c.persona}: ${c.descripcion}`,
            description: c.descripcion,
          });
          if (r.auto_grouped) autoStr = ` · vinculado a "${r.project_nombre}".`;
        } catch (e) { console.warn('[autogroup compromiso]', e.message); }
        return `Compromiso registrado [${c.id}]: ${c.persona} → "${c.descripcion}".${due}${reach}${autoStr}`;
      } catch (err) {
        return `Error: ${err.message}`;
      }
    }
    case 'mis_compromisos': {
      const items = listCommitments({ status: input.status || null, persona: input.persona || null });
      if (!items.length) return 'No hay compromisos en ese filtro.';
      return items.map((c) => {
        const due = c.vence ? ` (vence ${new Date(c.vence).toLocaleString('es-MX', { timeZone: process.env.TIMEZONE || 'America/Los_Angeles', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })})` : '';
        const overdue = c.vence && new Date(c.vence).getTime() < Date.now() && c.status === 'pendiente' ? ' VENCIDO' : '';
        return `[${c.id}] ${c.persona} · ${c.descripcion} (via ${c.canal})${due}${overdue}`;
      }).join('\n');
    }
    case 'marcar_cumplido': {
      const c = completeCommitment(input.id, input.evidencia);
      return c ? `Compromiso ${c.id} marcado cumplido. Evidencia: ${c.evidencia}` : `No encontré ${input.id}.`;
    }
    case 'marcar_fallido': {
      const c = failCommitment(input.id, input.razon || '');
      return c ? `Compromiso ${c.id} marcado fallido.` : `No encontré ${input.id}.`;
    }
    // ── CRM ──
    // ── nextiva ──
    case 'nextiva_pendientes': {
      const r = await pendingResponses({ sinceHours: parseInt(input.horas, 10) || 168 });
      if (!r.ok) return r.reason;
      if (!r.items.length) return 'Sin SMS pendientes de respuesta — al día. ✓';
      return r.items.slice(0, 20).map((t) => {
        const name = t.contact_name || t.contact_phone || 'desconocido';
        const last = t.messages.slice().sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime())[0];
        const ago = last ? Math.round((Date.now() - new Date(last.at).getTime()) / 3600_000) : '?';
        const preview = (last?.body || '').replace(/\s+/g, ' ').slice(0, 80);
        return `${name} · esperando ${ago}h · "${preview}"`;
      }).join('\n');
    }
    case 'nextiva_actividad': {
      const r = await recentActivity({ sinceHours: parseInt(input.horas, 10) || 24, limit: parseInt(input.limite, 10) || 30 });
      if (!r.ok) return r.reason;
      if (!r.items.length) return 'Sin actividad en esa ventana.';
      return r.items.map((t) => {
        const name = t.contact_name || t.contact_phone || 'desconocido';
        const last = t.messages.slice().sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime())[0];
        return `${name} (${last?.direction || '?'}): "${(last?.body || '').slice(0, 60)}"`;
      }).join('\n');
    }
    // ── instagram ──
    case 'ig_dms_pendientes': {
      const r = await pendingDms({ limit: parseInt(input.limite, 10) || 25 });
      if (!r.ok) return r.reason;
      if (!r.items.length) return 'Sin DMs pendientes — al día. ✓';
      return r.items.slice(0, 20).map((c) => {
        const ago = Math.round((Date.now() - new Date(c.ultimo_at).getTime()) / 3600_000);
        const prev = (c.ultimo_mensaje || '').replace(/\s+/g, ' ').slice(0, 80);
        return `@${c.interlocutor} · esperando ${ago}h · "${prev}"`;
      }).join('\n');
    }
    case 'ig_comentarios_pendientes': {
      const r = await pendingComments({ postsToScan: parseInt(input.posts, 10) || 10 });
      if (!r.ok) return r.reason;
      if (!r.items.length) return 'Sin comentarios sin responder. ✓';
      return r.items.slice(0, 20).map((c) => {
        const ago = Math.round((Date.now() - new Date(c.cuando).getTime()) / 3600_000);
        return `@${c.de} (hace ${ago}h en "${c.post_caption}…"): "${(c.texto || '').slice(0, 100)}"`;
      }).join('\n');
    }
    case 'ig_actividad': {
      const r = await recentComments({
        postsToScan: parseInt(input.posts, 10) || 10,
        limit: parseInt(input.limite, 10) || 25,
      });
      if (!r.ok) return r.reason;
      if (!r.items.length) return 'Sin actividad reciente en comentarios.';
      return r.items.map((c) => {
        const ago = Math.round((Date.now() - new Date(c.cuando).getTime()) / 3600_000);
        const respondido = c.tiene_respuestas ? ' [respondido]' : '';
        return `@${c.de} (${ago}h): "${(c.texto || '').slice(0, 80)}"${respondido}`;
      }).join('\n');
    }
    case 'ig_stats': {
      const r = await igSnapshot();
      if (!r.ok) return r.reason;
      const s = r.snapshot;
      return `@${s.username}: ${s.followers_count} followers · ${s.follows_count} follows · ${s.media_count} posts.`;
    }
    // ── entidades ──
    case 'entidad_anotar': {
      try {
        const e = upsertEntity({
          canonical_name: input.persona,
          type: input.tipo || 'other',
          alias: input.alias || null,
          nota: input.nota,
          salience: input.salience,
          cliente_id: input.cliente_id,
        });
        return `Nota guardada en ${e.canonical_name} [${e.id}] (${e.type}, ${e.notas.length} nota${e.notas.length === 1 ? '' : 's'} total).`;
      } catch (err) {
        return `Error: ${err.message}`;
      }
    }
    case 'entidad_buscar': {
      const r = findEntity(input.q);
      if (!r.length) return `Sin matches para "${input.q}".`;
      return r.slice(0, 10).map((e) => {
        const aliases = e.aliases?.length ? ` (a.k.a. ${e.aliases.join(', ')})` : '';
        return `[${e.id}] ${e.canonical_name}${aliases} — ${e.type}, ${e.notas?.length || 0} nota(s)`;
      }).join('\n');
    }
    case 'entidad_expediente': {
      const e = getEntity(input.id);
      return e ? entityCard(e) : `No encontré ${input.id}.`;
    }
    case 'entidad_vincular_cliente': {
      const e = linkClient(input.entidad_id, input.cliente_id);
      return e ? `Entidad ${e.canonical_name} vinculada al cliente ${input.cliente_id}.` : `No encontré ${input.entidad_id}.`;
    }
    case 'entidad_fusionar': {
      const e = mergeEntities(input.keep_id, input.drop_id);
      return e ? `Fusionadas. ${e.canonical_name} ahora tiene ${e.notas.length} notas y aliases ${e.aliases.join(', ') || '(ninguno)'}.` : 'No encontré alguna de las dos.';
    }
    // ── compliance Medicare ──
    case 'senales_de_hoy': {
      const { signals, ts } = loadSignals();
      if (!signals?.length) return 'Sin señales computadas todavía (la reflexión nocturna corre a las 2am).';
      const byPrio = ['alto', 'aviso', 'info'];
      const sorted = signals.slice().sort((a, b) => byPrio.indexOf(a.severidad) - byPrio.indexOf(b.severidad));
      return `Señales (computadas ${ts?.slice(0, 16) || '?'}):\n` + sorted.map((s) => `[${s.severidad}] ${s.mensaje}`).join('\n');
    }

    // ─── Say-Do (Athena propio) ───
    case 'cumplido_yo': {
      const { listActive, fulfillPromise } = await import('./saydo.js');
      let id = input.id;
      if (!id && input.descripcion) {
        const q = String(input.descripcion).toLowerCase();
        const match = listActive().find((p) => p.descripcion.toLowerCase().includes(q.slice(0, 30)));
        id = match?.id;
      }
      if (!id) return 'No encontré una promesa pendiente que coincida. Usa mis_promesas para ver la lista.';
      const r = fulfillPromise(id, input.resultado || '');
      return r ? `Cumplida ✓ [${r.id}]: ${r.descripcion}` : `No pude marcar cumplida (id ${id} no existe).`;
    }
    case 'mis_promesas': {
      const { listActive, listOverdue } = await import('./saydo.js');
      const all = listActive();
      if (!all.length) return 'Sin promesas pendientes — buen trabajo cerrando el loop.';
      const overdue = listOverdue();
      const lines = all.slice(0, 15).map((p) => {
        const isOverdue = overdue.find((o) => o.id === p.id);
        const tag = isOverdue ? '🔴 vencida' : `vence ${p.vence_en.slice(0, 16)}`;
        return `  • [${p.id}] ${p.descripcion} — ${tag}`;
      });
      return `${all.length} promesa(s) pendientes:\n${lines.join('\n')}`;
    }

    // ─── AAR ───
    case 'aar_abrir': {
      const { openDecision } = await import('./aar.js');
      const r = openDecision({
        type: input.type,
        intended: input.intended,
        target: input.target || '',
        context: input.context || '',
      });
      if (!r) return `Tipo "${input.type}" no es válido (usa: outreach/delegation/consult/meeting/commitment/briefing/recommendation/call) o falta intended.`;
      return `AAR abierto [${r.id}] tipo=${r.type} target=${r.target || '—'}\nCiérralo después con aar_cerrar pasando id=${r.id}.`;
    }
    case 'aar_cerrar': {
      const { closeDecision } = await import('./aar.js');
      const r = closeDecision({
        id: input.id,
        actual: input.actual,
        gap: input.gap || '',
        learning: input.learning || '',
      });
      return r
        ? `AAR cerrado [${r.id}]. ${r.learning ? `Learning guardado: "${r.learning}"` : 'Sin learning explícito.'}`
        : `No pude cerrar el AAR ${input.id} (no existe o falta actual).`;
    }
    case 'aars_recientes': {
      const { listRecent } = await import('./aar.js');
      const limit = parseInt(input.limite, 10) || 10;
      const list = listRecent({ limit });
      if (!list.length) return 'Sin AARs todavía.';
      return list.map((d) => {
        const status = d.status === 'cerrada' ? '✓' : '⏳';
        const body = d.status === 'cerrada'
          ? `intended="${d.intended.slice(0, 60)}" → actual="${d.actual.slice(0, 60)}" — learning: ${d.learning || '(sin)'}`
          : `intended="${d.intended.slice(0, 60)}" (sin cerrar)`;
        return `${status} [${d.id}] ${d.type}/${d.target || '—'} · ${body}`;
      }).join('\n');
    }

    // ─── INBOX CLEANUP ───
    case 'inbox_remitentes_ruidosos': {
      const m = await import('./inbox_cleanup.js');
      if (!m.inboxCleanupEnabled()) return 'Gmail no está configurado en este servidor.';
      const r = await m.scanNoisySenders({
        days: parseInt(input.dias, 10) || 30,
        limit: parseInt(input.limite, 10) || 25,
      });
      if (!r.ok) return `Error: ${r.error}`;
      if (!r.senders.length) return 'INBOX limpio — ningún remitente repite en esa ventana.';
      const lines = r.senders.map((s, i) => {
        const flag = s.already_suppressed ? ' 🚫YA' : '';
        return `${i + 1}. [${s.count}× en ${input.dias || 30}d] ${s.name ? s.name + ' · ' : ''}${s.email}${flag}\n   último asunto: "${(s.last_subject || '').slice(0, 70)}"`;
      });
      return `Top ${r.senders.length} remitentes de tu INBOX:\n${lines.join('\n')}\n\nDi cuáles quieres matar y los proceso con inbox_dar_baja_bulk.`;
    }
    case 'inbox_dar_baja': {
      const m = await import('./inbox_cleanup.js');
      if (!m.inboxCleanupEnabled()) return 'Gmail no está configurado.';
      const r = await m.attemptUnsubscribe(input.remitente);
      m.addToSuppress(input.remitente, {
        via_unsubscribe: r.ok,
        note: r.status || r.error || '',
      });
      const lines = [];
      if (r.ok && r.status === 'mailto_sent') {
        lines.push(`✓ Unsubscribe mailto enviado a ${r.mailto}`);
      } else if (r.status === 'url_only') {
        lines.push(`⚠️ Solo tienen URL https para baja: ${r.urls?.[0] || '?'} (sin browser no clickeo).`);
      } else if (r.status === 'no_unsubscribe_header') {
        lines.push(`⚠️ Sin List-Unsubscribe header (sender low-effort).`);
      }
      lines.push(`✓ Agregado a supresión — próxima sweep horaria los trashea automático.`);
      return lines.join('\n');
    }
    case 'inbox_dar_baja_bulk': {
      const m = await import('./inbox_cleanup.js');
      if (!m.inboxCleanupEnabled()) return 'Gmail no está configurado.';
      const remitentes = Array.isArray(input.remitentes) ? input.remitentes : [];
      if (!remitentes.length) return 'Pasa al menos un remitente en remitentes.';
      let unsubSent = 0, urlOnly = 0, noHeader = 0, suppressed = 0;
      for (const e of remitentes) {
        const r = await m.attemptUnsubscribe(e);
        m.addToSuppress(e, { via_unsubscribe: r.ok, note: r.status || r.error || '' });
        suppressed++;
        if (r.ok && r.status === 'mailto_sent') unsubSent++;
        else if (r.status === 'url_only') urlOnly++;
        else if (r.status === 'no_unsubscribe_header') noHeader++;
      }
      const sweep = await m.sweepSuppressed();
      return `Procesados ${remitentes.length} remitentes:\n  ✓ ${unsubSent} unsubscribe mailto enviado\n  ⚠️ ${urlOnly} solo URL (sin clickear sin browser)\n  ⚠️ ${noHeader} sin header de baja\n  ✓ ${suppressed} agregados a supresión\n  🗑 ${sweep.moved} emails movidos a Trash inmediatamente`;
    }
    case 'inbox_supresion_lista': {
      const m = await import('./inbox_cleanup.js');
      const list = m.getSuppressList();
      if (!list.length) return 'Lista de supresión vacía.';
      return `${list.length} remitentes suprimidos:\n${list.map((s) => `  • ${s.email}${s.via_unsubscribe ? ' (unsuscrito)' : ''} · desde ${s.added_at.slice(0, 10)}`).join('\n')}`;
    }
    case 'inbox_quitar_supresion': {
      const m = await import('./inbox_cleanup.js');
      const removed = m.removeFromSuppress(input.remitente);
      return removed
        ? `Quitado ${input.remitente} de supresión. Sus emails futuros vuelven a llegar a INBOX.`
        : `${input.remitente} no estaba en la lista de supresión.`;
    }

    // ─── EQUIPO ───
    case 'equipo_compromete': {
      const { recordTeamCommitment } = await import('./team.js');
      const r = recordTeamCommitment({
        persona: input.persona,
        descripcion: input.descripcion,
        vence_en_horas: parseInt(input.vence_en_horas, 10) || 24,
        contexto: input.contexto || '',
        recordarle_cuando: input.recordarle_cuando || null,
      });
      if (!r.ok) return `No pude registrar: ${r.error}`;
      const c = r.commitment;
      return `Compromiso registrado [${c.id}]: ${c.persona} → ${c.descripcion} (vence ${c.vence.slice(0, 16)}).${c.recordarle_cuando ? ` Recordarle ${c.recordarle_cuando}.` : ''}`;
    }
    case 'equipo_pendientes': {
      const { listTeamCommitments } = await import('./team.js');
      const list = listTeamCommitments({
        persona: input.persona || null,
        status: input.status || 'pendiente',
      });
      if (!list.length) return input.persona ? `Sin pendientes para ${input.persona}.` : 'Sin pendientes del equipo. ✓';
      const grouped = {};
      for (const c of list) {
        if (!grouped[c.persona]) grouped[c.persona] = [];
        grouped[c.persona].push(c);
      }
      const lines = [];
      for (const [persona, items] of Object.entries(grouped)) {
        lines.push(`\n${persona}:`);
        for (const c of items) {
          const overdue = new Date(c.vence).getTime() < Date.now();
          lines.push(`  ${overdue ? '🔴' : '⏳'} [${c.id}] ${c.descripcion}${overdue ? ' (VENCIDA)' : ''}`);
        }
      }
      return `${list.length} compromiso(s) pendientes:${lines.join('')}`;
    }
    case 'equipo_cumplido': {
      const { markFulfilled } = await import('./team.js');
      const r = markFulfilled(input.id, input.evidencia || '');
      return r
        ? `✓ Cumplido [${r.id}] ${r.persona}: ${r.descripcion.slice(0, 80)}`
        : `No encontré compromiso ${input.id}.`;
    }
    case 'equipo_fallido': {
      const { markFailed } = await import('./team.js');
      const r = markFailed(input.id, input.razon || '');
      return r
        ? `✗ Fallida [${r.id}] ${r.persona}: ${r.descripcion.slice(0, 80)} — ${r.razon}`
        : `No encontré compromiso ${input.id}.`;
    }
    case 'equipo_stats': {
      const { statsByPerson } = await import('./team.js');
      const days = parseInt(input.dias, 10) || 7;
      const s = statsByPerson({ sinceDays: days });
      const names = Object.keys(s);
      if (!names.length) return `Sin actividad del equipo en los últimos ${days} días.`;
      const lines = names.map((p) => {
        const x = s[p];
        const ratio = x.ratio == null ? '—' : `${Math.round(x.ratio * 100)}%`;
        return `${p}: ${x.cumplidas}/${x.cumplidas + x.fallidas} cumplido (${ratio}) · ${x.pendientes} pendientes`;
      });
      return `Stats equipo (últimos ${days}d):\n${lines.join('\n')}`;
    }

    // ─── TEAM REVIEW & INICIATIVAS ───
    case 'revisar_borrador_equipo': {
      const { reviewTeamDraft, formatReviewResult } = await import('./team_review.js');
      const r = await reviewTeamDraft({
        persona: input.persona,
        contenido: input.contenido,
        destinatario: input.destinatario || '',
        tipo: input.tipo || 'email',
      });
      return formatReviewResult(r);
    }
    case 'equipo_iniciativa': {
      const { recordInitiative } = await import('./team_review.js');
      const r = recordInitiative({
        persona: input.persona,
        propuesta: input.propuesta,
        contexto: input.contexto || '',
      });
      if (!r.ok) return `Error: ${r.error}`;
      return `💡 Iniciativa registrada [${r.initiative.id}]: ${r.initiative.persona} → "${r.initiative.propuesta}". Aparecerá en weekly review domingo para que Isabel decida.`;
    }
    case 'equipo_iniciativas': {
      const { listInitiatives } = await import('./team_review.js');
      const list = listInitiatives({
        sinceDays: parseInt(input.dias, 10) || 14,
        persona: input.persona || null,
      });
      if (!list.length) return 'Sin iniciativas registradas en esa ventana.';
      const byP = {};
      for (const i of list) { (byP[i.persona] ||= []).push(i); }
      const lines = [];
      for (const [p, items] of Object.entries(byP)) {
        lines.push(`\n${p}:`);
        for (const it of items) {
          lines.push(`  [${it.id}] (${it.status}) ${it.propuesta.slice(0, 100)}`);
        }
      }
      return `${list.length} iniciativa(s):${lines.join('')}`;
    }
    case 'equipo_iniciativa_status': {
      const { updateInitiativeStatus } = await import('./team_review.js');
      const valid = ['propuesta', 'aprobada', 'implementada', 'descartada'];
      if (!valid.includes(input.status)) return `Status inválido. Usa: ${valid.join(' | ')}`;
      const r = updateInitiativeStatus(input.id, input.status);
      return r
        ? `Iniciativa [${r.id}] de ${r.persona} ahora: ${r.status}`
        : `No encontré iniciativa ${input.id}.`;
    }
    case 'armar_brief_sabado': {
      const { buildSaturdayBrief, sendSaturdayBrief } = await import('./saturday_brief.js');
      if (input.solo_preview) {
        return buildSaturdayBrief();
      }
      await sendSaturdayBrief();
      return 'Saturday brief enviado a Isabel por WhatsApp (cards separadas).';
    }
    case 'equipo_reporte_eod': {
      const { submitEodReport } = await import('./team_eod.js');
      const r = submitEodReport({ persona: input.persona, texto: input.texto });
      if (!r.ok) return `Error: ${r.error}`;
      const nums = Object.entries(r.entry.numeros).filter(([k, v]) => k !== '_problema' && typeof v === 'number').map(([k, v]) => `${k}=${v}`).join(' · ') || 'sin números detectados';
      return `EOD registrado [${r.entry.id}] ${r.entry.persona}${r.entry.reemplazado ? ' (reemplazo del anterior de hoy)' : ''}. Parsed: ${nums}${r.entry.numeros._problema ? ' · 🚨 flageó problema' : ''}.`;
    }
    case 'equipo_reportes_hoy': {
      const { buildEodSummary } = await import('./team_eod.js');
      const s = buildEodSummary();
      return s ? s.summary : 'Nadie del equipo ha reportado EOD hoy todavía.';
    }

    // ─── HÁBITOS ───
    case 'registrar_habito': {
      const { logHabit } = await import('./habits.js');
      const r = logHabit({
        tipo: input.tipo,
        valor: input.valor,
        nota: input.nota || '',
      });
      if (!r.ok) return `Error: ${r.error}`;
      return `✓ ${r.entry.tipo}: ${r.entry.valor}${r.entry.unidad}${r.entry.nota ? ` (${r.entry.nota})` : ''}${r.entry.reemplazado ? ' [reemplazado el de hoy]' : ''}`;
    }
    case 'mis_habitos': {
      const { buildHabitsBriefingBlock } = await import('./habits.js');
      const block = buildHabitsBriefingBlock();
      return block || 'Sin hábitos registrados todavía. Empieza con uno: peso, agua, proteína, workout, sueño.';
    }
    case 'historial_habito': {
      const { statsForType, HABIT_TYPES } = await import('./habits.js');
      const tipo = input.tipo;
      if (!HABIT_TYPES[tipo]) return `Tipo desconocido. Usa: ${Object.keys(HABIT_TYPES).join(', ')}`;
      const dias = parseInt(input.dias, 10) || 7;
      const s = statsForType(tipo, dias);
      if (!s) return `Sin datos de ${tipo} en los últimos ${dias} días.`;
      const cfg = HABIT_TYPES[tipo];
      const lines = [
        `${tipo} en últimos ${dias}d (meta ${cfg.meta}${cfg.unidad}):`,
        `  Días con data: ${s.dias_con_data}`,
        `  Promedio: ${s.promedio}${cfg.unidad}`,
        `  Min/Max: ${s.minimo} / ${s.maximo}`,
        `  Último: ${s.ultimo}${cfg.unidad}`,
      ];
      if (s.days.length >= 3) {
        const trend = s.days[s.days.length - 1].valor - s.days[0].valor;
        lines.push(`  Tendencia: ${trend > 0 ? '+' : ''}${Math.round(trend * 10) / 10}`);
      }
      return lines.join('\n');
    }

    // ─── FINANZAS ───
    case 'registrar_gasto': {
      const { registrarGasto } = await import('./finanzas.js');
      const r = registrarGasto({ monto: input.monto, categoria: input.categoria || 'otro', concepto: input.concepto || '' });
      if (!r.ok) return `Error: ${r.error}`;
      return `💸 Gasto registrado [${r.entry.id}]: $${r.entry.monto} ${r.entry.categoria}${r.entry.concepto ? ` (${r.entry.concepto})` : ''}`;
    }
    case 'registrar_ingreso': {
      const { registrarIngreso } = await import('./finanzas.js');
      const r = registrarIngreso({ monto: input.monto, categoria: input.categoria || 'comision', concepto: input.concepto || '' });
      if (!r.ok) return `Error: ${r.error}`;
      return `💰 Ingreso registrado [${r.entry.id}]: $${r.entry.monto} ${r.entry.categoria}${r.entry.concepto ? ` (${r.entry.concepto})` : ''}`;
    }
    case 'mis_finanzas': {
      const { statsMes } = await import('./finanzas.js');
      const s = statsMes(input.mes || null);
      if (!s.n_transacciones) return `Sin transacciones registradas en ${s.mes}.`;
      const lines = [
        `💰 Finanzas mes ${s.mes}:`,
        `  Ingresos: $${s.total_ingresos}`,
        `  Gastos: $${s.total_gastos}`,
        `  Neto: $${s.neto}`,
      ];
      const top = Object.entries(s.gastos_por_categoria).sort((a, b) => b[1] - a[1]).slice(0, 5);
      if (top.length) lines.push(`  Top gastos: ${top.map(([k, v]) => `${k} $${v}`).join(' · ')}`);
      return lines.join('\n');
    }

    // ─── JOURNAL ───
    case 'journal_entrada': {
      const { registrarEntrada } = await import('./journal.js');
      const r = registrarEntrada({
        texto: input.texto,
        tipo: input.tipo || 'journal',
        gratitud: input.gratitud || null,
        frustracion: input.frustracion || null,
      });
      if (!r.ok) return `Error: ${r.error}`;
      return `📓 Journal [${r.entry.id}] ${r.entry.tipo}. ${r.entry.emociones.length ? `Detecté: ${r.entry.emociones.join(', ')}` : 'Tono neutral.'}`;
    }
    case 'mis_patrones_emocionales': {
      const { emocionesPattern } = await import('./journal.js');
      const p = emocionesPattern({ dias: parseInt(input.dias, 10) || 14 });
      if (!p.n_entradas) return `Sin entradas en los últimos ${p.dias_analizados} días.`;
      const summary = Object.entries(p.counts).sort((a, b) => b[1] - a[1]).map(([k, v]) => `${k} ×${v}`).join(' · ') || 'sin emociones marcadas';
      return `Patrones (${p.dias_analizados}d, ${p.n_entradas} entradas):\n${summary}`;
    }
    case 'journal_buscar': {
      const { searchEntries } = await import('./journal.js');
      const matches = searchEntries({ query: input.query, dias: parseInt(input.dias, 10) || 90 });
      if (!matches.length) return `Sin matches para "${input.query}" en últimos ${input.dias || 90} días.`;
      return `${matches.length} entrada(s) que matchean "${input.query}":\n` +
        matches.map((e) => `  [${e.dia}] ${e.tipo}: ${(e.texto || '').slice(0, 120)}${e.emociones?.length ? ` (${e.emociones.join(', ')})` : ''}`).join('\n');
    }
    case 'journal_resumen_dia': {
      const { entriesForDay } = await import('./journal.js');
      const tz = process.env.TIMEZONE || 'America/Los_Angeles';
      const today = new Date().toLocaleDateString('en-CA', { timeZone: tz });
      const dia = input.dia || today;
      const entries = entriesForDay(dia);
      if (!entries.length) return `No hay entradas de journal el ${dia}.`;
      const emocSet = new Set();
      entries.forEach((e) => (e.emociones || []).forEach((em) => emocSet.add(em)));
      const lines = [`📓 Journal del ${dia} — ${entries.length} entrada(s)${emocSet.size ? ` · emociones: ${[...emocSet].join(', ')}` : ''}:`];
      for (const e of entries) {
        const hora = new Date(e.ts).toLocaleTimeString('es-MX', { timeZone: tz, hour: '2-digit', minute: '2-digit' });
        lines.push(`  [${hora}] ${e.tipo}: ${e.texto}`);
        if (e.gratitud) lines.push(`     🙏 ${e.gratitud}`);
        if (e.frustracion) lines.push(`     😤 ${e.frustracion}`);
      }
      return lines.join('\n');
    }
    case 'rapport_semanal': {
      const { registrarRapport, rapportTrend } = await import('./rapport.js');
      const entry = registrarRapport({
        peso_lbs: input.peso_lbs,
        medidas: input.medidas,
        foto_url: input.foto_url,
        sentires: input.sentires,
        periodo: input.periodo,
      });
      const t = rapportTrend();
      const parts = [`📸 Rapport semanal guardado [${entry.id}] semana ${entry.semana}.`];
      if (entry.peso_lbs) parts.push(`Peso: ${entry.peso_lbs} lbs`);
      if (t && t.delta_4w !== null) parts.push(`Δ4w: ${t.delta_4w > 0 ? '+' : ''}${t.delta_4w} lbs`);
      if (t && t.delta_12w !== null) parts.push(`Δ12w: ${t.delta_12w > 0 ? '+' : ''}${t.delta_12w} lbs`);
      return parts.join(' · ');
    }
    case 'reading_agregar': {
      try {
        const { addItem } = await import('./reading_list.js');
        const it = addItem({
          url: input.url,
          titulo: input.titulo,
          notas: input.notas,
          tags: input.tags,
        });
        const label = it.titulo || it.url.slice(0, 80);
        return `📚 Guardado [${it.id}] ${label} (${it.fuente || 'web'})${it.tags?.length ? ` · tags: ${it.tags.join(', ')}` : ''}`;
      } catch (err) {
        return `Error: ${err.message}`;
      }
    }
    case 'reading_lista': {
      const { listItems } = await import('./reading_list.js');
      const items = listItems({
        status: input.status || 'pending',
        tag: input.tag || null,
        limit: 30,
      });
      if (!items.length) return `Reading list (${input.status || 'pending'}): vacía.`;
      const lines = [`📚 Reading list (${input.status || 'pending'}, ${items.length} item${items.length > 1 ? 's' : ''}):`];
      for (const i of items) {
        const label = i.titulo || i.url.slice(0, 80);
        const tagsStr = i.tags?.length ? ` [${i.tags.join(', ')}]` : '';
        lines.push(`  [${i.id}] ${label} — ${i.fuente || 'web'}${tagsStr}`);
        if (i.notas) lines.push(`     nota: ${i.notas.slice(0, 100)}`);
      }
      return lines.join('\n');
    }
    case 'reading_resumen': {
      const { getItem, updateItem } = await import('./reading_list.js');
      const it = getItem(input.id);
      if (!it) return `Item ${input.id} no existe.`;
      // Si ya tiene resumen cacheado, lo devolvemos sin volver a llamar.
      if (it.resumen) return `📚 ${it.titulo || it.url}\n\n${it.resumen}\n\n(resumen cacheado — si quieres uno fresco, marca el item y vuelve a agregarlo).`;
      // Genera resumen con web_search via Anthropic.
      const { anthropic } = await import('./claude.js');
      try {
        const res = await anthropic.messages.create({
          model: 'claude-sonnet-4-6',
          max_tokens: 800,
          tools: [{ type: 'web_search_20250305', name: 'web_search', max_uses: 2 }],
          messages: [{
            role: 'user',
            content: `Necesito un resumen del contenido en esta URL: ${it.url}\n\nBusca el contenido usando web_search (max 2 búsquedas). Devuelve:\n1. TÍTULO real\n2. 4-6 bullets con los puntos clave\n3. UNA conclusión accionable para Isabel (Medicare agent, 53, espíritu emprendedor)\n\nSi web_search no devuelve contenido relevante, di claramente "no pude acceder al contenido" — no inventes.`,
          }],
        });
        const text = res.content.filter((b) => b.type === 'text').map((b) => b.text).join('\n').trim();
        if (text && !/no pude acceder/i.test(text)) {
          updateItem(input.id, { resumen: text });
        }
        return `📚 ${it.titulo || it.url}\n\n${text}`;
      } catch (err) {
        return `Error generando resumen: ${err.message}`;
      }
    }
    case 'reading_marcar': {
      try {
        const { updateItem } = await import('./reading_list.js');
        const it = updateItem(input.id, { status: input.status });
        return `📚 [${it.id}] marcado como ${it.status}.`;
      } catch (err) {
        return `Error: ${err.message}`;
      }
    }
    case 'trends_pendientes': {
      const { listTrends } = await import('./trends.js');
      const items = listTrends({ status: 'pending', limit: 15, topic_id: input.topic_id || null });
      if (!items.length) return 'Sin trends pendientes. El scout corre 11am — tal vez aún no ha encontrado nada hoy.';
      return items.map((t) => `🔥 [${t.id}] (${t.topic_nombre}, score ${t.score}/10) ${t.titulo}\n   ${t.summary}\n   → ${t.razon_isabel}`).join('\n\n');
    }
    case 'trends_scan_ahora': {
      const { runTrendScan } = await import('./trends.js');
      const r = await runTrendScan();
      if (!r.fresh.length) return 'Scan completo — sin hits nuevos esta vuelta. (Posible que ya hayamos visto lo notable, o que no hay novedad fuerte hoy.)';
      const lines = [`🔥 ${r.fresh.length} hit(s) nuevo(s) (${r.highScore.length} score≥8):`];
      for (const h of r.fresh.slice(0, 5)) {
        const icon = h.topic_id === 'chief_of_staff' ? '⚙️' : '🔥';
        lines.push(`${icon} [${h.topic_nombre}, score ${h.score}/10] ${h.titulo}\n  ${h.summary}\n  → ${h.razon_isabel}`);
      }
      return lines.join('\n\n');
    }
    case 'self_grade_correr': {
      const { gradeWeek } = await import('./self_grade.js');
      const g = await gradeWeek();
      const delta = g.deltas?.total >= 0 ? `+${g.deltas.total}` : `${g.deltas.total}`;
      return `📊 Self-grade ${g.semana}: ${g.score}/100 (${delta} vs sem prev).\n\nSubscores: response ${g.subscores.response}/20 · coverage ${g.subscores.coverage}/20 · engagement ${g.subscores.engagement}/20 · proactive ${g.subscores.proactive}/20 · team ${g.subscores.team}/20.\n\nCambio propuesto:\n${g.cambio_propuesto}`;
    }
    case 'self_grade_implementado': {
      try {
        const { markGradeImplemented } = await import('./self_grade.js');
        const g = markGradeImplemented(input.semana);
        return `✓ Self-grade de ${g.semana} marcado como implementado.`;
      } catch (err) {
        return `Error: ${err.message}`;
      }
    }
    case 'mi_self_grade': {
      const { listSelfGrades } = await import('./self_grade.js');
      const grades = listSelfGrades({ limit: 4 });
      if (!grades.length) return 'Todavía no hay self-grades. El primero corre domingo 8pm (o llamame con self_grade_correr para forzarlo).';
      const lines = [`📊 Mis últimos ${grades.length} grades:`];
      for (const g of grades) {
        const delta = g.deltas?.total >= 0 ? `+${g.deltas.total}` : `${g.deltas.total}`;
        const impl = g.implementado ? ' ✓ implementado' : '';
        lines.push(`  ${g.semana}: ${g.score}/100 (${delta})${impl}`);
      }
      const last = grades[0];
      lines.push(`\nCambio propuesto en ${last.semana}:\n${last.cambio_propuesto || '(ninguno)'}`);
      return lines.join('\n');
    }
    case 'push_notificacion': {
      try {
        const { sendToAll } = await import('./push.js');
        const r = await sendToAll({
          title: input.titulo || 'Athena',
          body: input.cuerpo,
          url: input.url || '/app/hoy',
          tag: 'directora',
        });
        if (!r.ok) return `No pude mandar push: ${r.reason}`;
        if (r.sent === 0) return 'No hay dispositivos suscritos al push. Activa primero en la PWA: Hoy → "Activar notificaciones" (requiere PWA instalada en iPhone via Safari).';
        return `🔔 Push enviado a ${r.sent} dispositivo(s)${r.removed ? `, ${r.removed} caducados purgados` : ''}.`;
      } catch (err) {
        return `Error en push: ${err.message}`;
      }
    }
    case 'brainstorm_estructurado': {
      const { anthropic } = await import('./claude.js');
      const tema = String(input.tema || '').trim();
      if (!tema) return 'Error: tema vacío.';
      const ctx = input.contexto ? `\n\nCONTEXTO:\n${input.contexto}` : '';
      try {
        const res = await anthropic.messages.create({
          model: 'claude-sonnet-4-6',
          max_tokens: 1800,
          system: 'Sos una facilitadora de brainstorm estructurado para Isabel Fuentes (53, Medicare agent en SoCal, espíritu emprendedor). Usás su filosofía "más completa, no más perfecta" — no perseguir perfección, perseguir progreso. Spanglish natural.',
          messages: [{
            role: 'user',
            content: `Brainstorm estructurado sobre: ${tema}${ctx}

FORMATO EXACTO (sin saltarte ninguna sección):

═══ FRAME ═══
Reformula la pregunta en una frase más sharp. Si la pregunta original es vaga, hazla específica. Si tiene assumption oculto, exponelo.

═══ 10 IDEAS ═══
Lista 10 ideas — diversas, incluyendo algunas obvias y algunas locas. Una línea cada una.
1. ...
2. ...
... (hasta 10)

═══ CRITERIOS DE EVALUACIÓN ═══
3-4 criterios para rankear (ej. impacto, esfuerzo, alineación con AEP, riesgo, costo).

═══ TOP 3 ═══
Las 3 mejores con 1-2 frases de por qué cada una.
1. [idea] — porque [razón]
2. ...
3. ...

═══ PLAN PARA #1 ═══
4-6 pasos accionables. Quién, cuándo, qué entregable. Incluí el primer paso que se puede hacer EN LAS PRÓXIMAS 24 HORAS.`
          }],
        });
        return res.content.filter((b) => b.type === 'text').map((b) => b.text).join('\n').trim();
      } catch (err) {
        return `Error brainstorm: ${err.message}`;
      }
    }
    case 'mi_rapport': {
      const { rapportTrend } = await import('./rapport.js');
      const t = rapportTrend();
      if (!t) return 'No hay rapports registrados todavía. Pídeme tu primero el viernes (o ahora).';
      const lines = [`📸 Último rapport (semana ${t.latest.semana}):`];
      if (t.latest.peso_lbs) lines.push(`  Peso: ${t.latest.peso_lbs} lbs`);
      if (t.latest.medidas) {
        const m = Object.entries(t.latest.medidas).map(([k, v]) => `${k} ${v}"`).join(' · ');
        if (m) lines.push(`  Medidas: ${m}`);
      }
      if (t.latest.sentires) lines.push(`  Sentires: ${t.latest.sentires}`);
      if (t.latest.periodo) lines.push(`  Periodo: ${t.latest.periodo}`);
      if (t.delta_4w !== null) lines.push(`  Δ peso 4 sem: ${t.delta_4w > 0 ? '+' : ''}${t.delta_4w} lbs`);
      if (t.delta_12w !== null) lines.push(`  Δ peso 12 sem: ${t.delta_12w > 0 ? '+' : ''}${t.delta_12w} lbs`);
      return lines.join('\n');
    }

    // ─── GOALS ───
    case 'registrar_meta': {
      const { registrarMeta } = await import('./goals.js');
      const r = registrarMeta({
        nombre: input.nombre,
        target: input.target !== undefined ? Number(input.target) : null,
        unidad: input.unidad || '',
        vence: input.vence,
        area: input.area || 'personal',
        notas: input.notas || '',
      });
      if (!r.ok) return `Error: ${r.error}`;
      return `🎯 Meta registrada [${r.entry.id}]: ${r.entry.nombre}${r.entry.target !== null ? ` (target ${r.entry.target}${r.entry.unidad})` : ''} · vence ${r.entry.vence.slice(0, 10)}`;
    }
    case 'actualizar_meta': {
      const { actualizarProgreso } = await import('./goals.js');
      const r = actualizarProgreso({ id: input.id, progreso: input.progreso, nota: input.nota || '' });
      if (!r) return `No encontré meta ${input.id}.`;
      return `Meta [${r.id}] actualizada a ${r.progreso}${r.unidad}${r.status === 'completada' ? ' 🎉 COMPLETADA' : ''}`;
    }
    case 'mis_metas': {
      const { listMetas, proyeccion } = await import('./goals.js');
      const metas = listMetas({ status: 'activa', area: input.area || null });
      if (!metas.length) return 'Sin metas activas. Cuando quieras registra una con registrar_meta.';
      const lines = [];
      for (const m of metas) {
        const p = proyeccion(m);
        let line = `[${m.id}] ${m.nombre}`;
        if (m.target !== null) line += ` — ${m.progreso}/${m.target}${m.unidad}`;
        if (p) {
          line += ` · ${p.pct_avance}% avance (${p.pct_tiempo_transcurrido}% tiempo) · ${p.dias_restantes}d`;
          if (!p.en_track) line += ` · ⚠️ OFF TRACK`;
        }
        lines.push(line);
      }
      return `${metas.length} metas activas:\n${lines.join('\n')}`;
    }

    // ─── FOCUS BLOCKS ───
    case 'crear_bloque_foco': {
      const { crearBloque } = await import('./focus_blocks.js');
      const r = crearBloque({
        titulo: input.titulo,
        inicio_hhmm: input.inicio_hhmm,
        fin_hhmm: input.fin_hhmm,
        dias_semana: input.dias_semana || null,
        modo: input.modo || 'silencio',
        notas: input.notas || '',
      });
      if (!r.ok) return `Error: ${r.error}`;
      const dias = r.bloque.dias_semana.length === 7 ? 'todos los días' : `días [${r.bloque.dias_semana.join(',')}]`;
      return `🛡️ Bloque "${r.bloque.titulo}" creado [${r.bloque.id}]: ${r.bloque.inicio_hhmm}-${r.bloque.fin_hhmm} ${dias} · modo ${r.bloque.modo}`;
    }
    case 'mis_bloques_foco': {
      const { listarBloques, bloqueActual } = await import('./focus_blocks.js');
      const blocks = listarBloques();
      const current = bloqueActual();
      if (!blocks.length) return 'No tienes focus blocks activos. Crea uno con crear_bloque_foco.';
      const lines = blocks.map((b) => {
        const dias = b.dias_semana.length === 7 ? 'diario' : b.dias_semana.join(',');
        const flag = current?.id === b.id ? ' ◀ AHORA' : '';
        return `  · ${b.titulo} (${b.modo}) — ${b.inicio_hhmm}-${b.fin_hhmm} ${dias}${flag}`;
      });
      return `${blocks.length} focus block(s):\n${lines.join('\n')}`;
    }

    // ─── TRUST SCORE ───
    case 'mi_confianza': {
      const { buildTrustBriefingBlock } = await import('./trust_score.js');
      return buildTrustBriefingBlock();
    }

    // ─── RUTINAS ───
    case 'crear_rutina': {
      const { crearRutina } = await import('./routines.js');
      const r = crearRutina({
        nombre: input.nombre,
        pasos: input.pasos,
        recurrencia: input.recurrencia,
        hora_inicio: input.hora_inicio || null,
      });
      if (!r.ok) return `Error: ${r.error}`;
      return `🔁 Rutina "${r.rutina.nombre}" creada [${r.rutina.id}]: ${r.rutina.pasos.length} pasos · ${r.rutina.recurrencia}${r.rutina.hora_inicio ? ` · ${r.rutina.hora_inicio}` : ''}`;
    }
    case 'mis_rutinas': {
      const { listarRutinas, rutinasDeHoy, progresoHoy } = await import('./routines.js');
      const list = input.hoy_solo ? rutinasDeHoy() : listarRutinas();
      if (!list.length) return input.hoy_solo ? 'Sin rutinas para hoy.' : 'Sin rutinas activas.';
      const lines = list.map((r) => {
        const done = progresoHoy(r.id).filter((c) => c.accion === 'completado').length;
        return `[${r.id}] ${r.nombre} (${r.recurrencia}${r.hora_inicio ? ` ${r.hora_inicio}` : ''}) — ${done}/${r.pasos.length} hoy\n  pasos: ${r.pasos.join(' → ')}`;
      });
      return lines.join('\n\n');
    }
    case 'rutina_paso_completado': {
      const { registrarPaso } = await import('./routines.js');
      const r = registrarPaso({
        rutina_id: input.rutina_id,
        paso_idx: parseInt(input.paso_idx, 10),
        accion: input.accion || 'completado',
        nota: input.nota || '',
      });
      return r ? `✓ Paso ${r.paso_idx} ${r.accion} en ${r.rutina_id}` : 'Error al registrar.';
    }

    // ─── LEGAL ───
    case 'registrar_obligacion_legal': {
      const { registrarObligacion } = await import('./legal.js');
      const r = registrarObligacion({
        tipo: input.tipo || 'otro',
        descripcion: input.descripcion,
        vence: input.vence,
        recurrencia: input.recurrencia || null,
        autoridad: input.autoridad || '',
        monto: input.monto !== undefined ? Number(input.monto) : null,
        notas: input.notas || '',
      });
      if (!r.ok) return `Error: ${r.error}`;
      const o = r.obligacion;
      return `⚖️ Registrada [${o.id}]: ${o.descripcion} · vence ${o.vence.slice(0, 10)}${o.recurrencia ? ` · ${o.recurrencia}` : ''}${o.monto ? ` · $${o.monto}` : ''}`;
    }
    case 'cumpli_obligacion': {
      const { marcarCumplida } = await import('./legal.js');
      const r = marcarCumplida(input.id, input.evidencia || '');
      return r ? `✓ Cumplida [${r.id}]: ${r.descripcion}${r.recurrencia ? ' · próxima ya generada' : ''}` : `No encontré ${input.id}.`;
    }
    case 'mi_calendario_legal': {
      const { buildLegalBriefingBlock, alertasActivas } = await import('./legal.js');
      const block = buildLegalBriefingBlock();
      if (block) return block;
      const a = alertasActivas();
      if (a['60'].length) return `Sin urgencias. ${a['60'].length} obligaciones en ventana 60d.`;
      return 'Sin obligaciones legales registradas. Para registrar usa registrar_obligacion_legal.';
    }

    // ─── OVERLOAD ───
    case 'mi_carga': {
      const { computeOverload } = await import('./overload.js');
      const o = computeOverload();
      const lines = [`Score sobrecarga: ${o.score} (umbral 4) · severidad: ${o.severidad}`];
      if (o.overloaded) {
        lines.push('🚨 SOBRECARGADA — propón triage, NO sumes carga.');
      } else {
        lines.push('Carga manejable — puedes seguir agregando con cuidado.');
      }
      if (o.señales.length) lines.push(`\nSeñales:\n${o.señales.map((s) => `  · ${s}`).join('\n')}`);
      return lines.join('\n');
    }
    case 'triagear_carga': {
      const { buildTriageProposal } = await import('./overload.js');
      const t = buildTriageProposal();
      if (!t) return 'No estás sobrecargada — no hay nada que triagear ahorita.';
      return t.mensaje;
    }
    case 'crear_tema_research': {
      const { crearTema } = await import('./research.js');
      const r = crearTema(input);
      if (!r.ok) return `No se pudo: ${r.error}`;
      return `Tema "${r.tema.nombre}" creado (${r.tema.id}). Athena lo investiga mañana al mediodía.`;
    }
    case 'mis_temas_research': {
      const { listarTemas } = await import('./research.js');
      const todos = listarTemas({ activos_solo: false });
      if (!todos.length) return 'No hay temas de research configurados. Pídeme "seed_temas_research" para arrancar con los defaults.';
      return todos.map((t) => `[${t.activo ? 'ON' : 'OFF'}] ${t.id} · ${t.nombre} (${t.queries.length} queries, max ${t.max_items})`).join('\n');
    }
    case 'pausar_tema_research': {
      const { pausarTema } = await import('./research.js');
      const t = pausarTema(input.id);
      if (!t) return 'No encontré ese tema.';
      return `Tema "${t.nombre}" ahora está ${t.activo ? 'ACTIVO' : 'PAUSADO'}.`;
    }
    case 'eliminar_tema_research': {
      const { eliminarTema } = await import('./research.js');
      const t = eliminarTema(input.id);
      if (!t) return 'No encontré ese tema.';
      return `Tema "${t.nombre}" eliminado.`;
    }
    case 'seed_temas_research': {
      const { seedDefaultTopics } = await import('./research.js');
      const r = seedDefaultTopics();
      const parts = [];
      if (r.created.length) parts.push(`Creados: ${r.created.join(', ')}`);
      if (r.skipped.length) parts.push(`Ya existían: ${r.skipped.join(', ')}`);
      if (!parts.length) return 'Nada que sembrar.';
      return parts.join(' · ');
    }
    case 'mi_perfect_week': {
      const { getPerfectWeek } = await import('./perfect_week.js');
      const t = getPerfectWeek();
      const dayNames = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
      const byDay = {};
      for (const s of t.slots) {
        const k = dayNames[s.dia];
        if (!byDay[k]) byDay[k] = [];
        byDay[k].push(`${s.inicio}-${s.fin} [${s.prioridad}] ${s.etiqueta}`);
      }
      return Object.entries(byDay).map(([d, slots]) => `${d}: ${slots.join(' · ')}`).join('\n');
    }
    case 'validar_horario_perfect_week': {
      const { validateEvent, describeConflicts } = await import('./perfect_week.js');
      const inicio = new Date(input.inicio);
      const fin = new Date(input.fin);
      const conflicts = validateEvent({ inicio, fin });
      if (!conflicts.length) return 'Sin conflictos con perfect week — horario verde.';
      return `Conflictos: ${conflicts.map((c) => `${c.etiqueta} (${c.prioridad})`).join(' · ')}\n→ ${describeConflicts(conflicts)}`;
    }
    case 'closing_loop_hoy': {
      const { computeClosingLoop } = await import('./closing_loop.js');
      const loop = computeClosingLoop();
      if (loop.total === 0) return `Hoy (${loop.fecha}): cero acciones cerradas todavía.`;
      const counts = Object.entries(loop.por_tool).map(([t, arr]) => `${t}:${arr.length}`).join(' · ');
      return `Hoy (${loop.fecha}): ${loop.total} acciones cerradas.\n${counts}`;
    }
    case 'configurar_cadencia_coach': {
      const { setCadence } = await import('./coach_cadence.js');
      const r = setCadence(input);
      return r.ok ? `Cadencia configurada: ${r.cadencia.coach} ${r.cadencia.cadencia}${r.cadencia.hora ? ` @${r.cadencia.hora}` : ''}` : `Error: ${r.error}`;
    }
    case 'mis_cadencias_coach': {
      const { listCadences } = await import('./coach_cadence.js');
      const list = listCadences({ activas_solo: false });
      if (!list.length) return 'Sin cadencias configuradas. Sugerencia: corre seed_cadencias_coach para arrancar con defaults.';
      return list.map((c) => `${c.pausada ? '[PAUSED]' : ''} ${c.coach} → ${c.cadencia}${c.hora ? ` @${c.hora}` : ''}`).join('\n');
    }
    case 'cadencias_de_hoy': {
      const { cadenciasDeHoy } = await import('./coach_cadence.js');
      const hoy = cadenciasDeHoy();
      if (!hoy.length) return 'Hoy no toca ningún check-in programado.';
      return hoy.map((c) => `${c.ya_hecho ? '✓' : '○'} ${c.coach}${c.hora ? ` (${c.hora})` : ''} — ${c.cadencia}`).join('\n');
    }
    case 'pausar_cadencia_coach': {
      const { pauseCadence } = await import('./coach_cadence.js');
      const r = pauseCadence(input.coach);
      if (!r) return 'No encontré esa cadencia.';
      return `${r.coach}: ${r.pausada ? 'PAUSADA' : 'reactivada'}.`;
    }
    case 'eliminar_cadencia_coach': {
      const { removeCadence } = await import('./coach_cadence.js');
      return removeCadence(input.coach) ? `Cadencia de ${input.coach} eliminada.` : 'No encontré esa cadencia.';
    }
    case 'seed_cadencias_coach': {
      const { seedDefaultCadences } = await import('./coach_cadence.js');
      const r = seedDefaultCadences();
      const parts = [];
      if (r.created.length) parts.push(`Creadas: ${r.created.join(', ')}`);
      if (r.skipped.length) parts.push(`Ya existían: ${r.skipped.join(', ')}`);
      return parts.length ? parts.join(' · ') : 'Nada que sembrar.';
    }
    case 'registrar_checkin_coach': {
      const { registrarCheckIn } = await import('./coach_cadence.js');
      const r = registrarCheckIn(input);
      return `Check-in ${r.accion} con ${r.coach} registrado.`;
    }
    case 'brand_idea_add': {
      const { ideaAdd } = await import('./brand.js');
      const r = ideaAdd(input);
      return r.ok ? `Idea guardada (${r.idea.id}): "${r.idea.titulo}"` : `No se pudo: ${r.error}`;
    }
    case 'brand_ideas_lista': {
      const { ideasList } = await import('./brand.js');
      const list = ideasList(input);
      if (!list.length) return 'Backlog vacío con ese filtro.';
      return list.slice(0, 20).map((i) =>
        `[${i.tema || '-'}/${i.plataforma || '?'}] ${i.titulo}${i.hook ? ` · hook: "${i.hook}"` : ''} (★${i.salience}, ${i.id})`
      ).join('\n');
    }
    case 'brand_calendar_add': {
      const { calendarAdd } = await import('./brand.js');
      const r = calendarAdd(input);
      return r.ok ? `Agendado ${r.item.id} para ${new Date(r.item.fecha).toLocaleDateString('es-MX')} (${r.item.plataforma})` : `No se pudo: ${r.error}`;
    }
    case 'brand_proximas': {
      const { calendarProximas } = await import('./brand.js');
      const list = calendarProximas(input);
      if (!list.length) return 'Nada agendado en ese rango.';
      return list.map((c) =>
        `${new Date(c.fecha).toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', month: 'short' })} · ${c.plataforma} · ${c.titulo} [${c.estado}] (${c.id})`
      ).join('\n');
    }
    case 'brand_estado_update': {
      const { calendarUpdateEstado } = await import('./brand.js');
      const r = calendarUpdateEstado(input.id, input.estado);
      return r ? `Estado actualizado: "${r.titulo}" → ${r.estado}` : 'No encontré ese item.';
    }
    case 'brand_post_registrar': {
      const { postRegistrar } = await import('./brand.js');
      const r = postRegistrar(input);
      return r.ok ? `Post registrado (${r.post.id}): "${r.post.titulo}" en ${r.post.plataforma}` : `No se pudo: ${r.error}`;
    }
    case 'brand_metricas': {
      const { statsLast30Days } = await import('./brand.js');
      const s = statsLast30Days();
      if (!s) return 'Sin posts en los últimos 30 días — no hay métricas aún.';
      const lines = [
        `Posts: ${s.total_posts} · Vistas total: ${s.vistas_total} (prom ${s.vistas_promedio}/post)`,
        `Seguidores nuevos: +${s.seguidores_nuevos} · Engagement prom: ${s.engagement_promedio}`,
        `Por plataforma: ${Object.entries(s.por_plataforma).map(([k, v]) => `${k}:${v}`).join(' · ')}`,
      ];
      if (s.top.length) {
        lines.push('Top:');
        for (const t of s.top) lines.push(`  · ${t.titulo} (${t.plataforma}) — ${t.vistas} vistas`);
      }
      return lines.join('\n');
    }
    case 'proponer_mejora': {
      const { proposeImprovement } = await import('./improvements.js');
      const r = await proposeImprovement(input);
      if (!r.ok) return `No se pudo proponer: ${r.error}`;
      const parts = [`Mejora guardada (${r.mejora.id}, prioridad ${r.mejora.prioridad}).`];
      if (r.github?.ok) parts.push(`GitHub issue #${r.github.number}: ${r.github.url}`);
      else if (r.github?.error) parts.push(`GitHub: NO se creó issue (${r.github.error})`);
      if (r.email?.ok) parts.push(`Email enviado a Isabel.`);
      else if (r.email?.error) parts.push(`Email: falló (${r.email.error})`);
      return parts.join(' ');
    }
    case 'mis_mejoras_propuestas': {
      const { listImprovements } = await import('./improvements.js');
      const items = listImprovements({ status: input.status || null });
      if (!items.length) return 'No hay mejoras propuestas con ese filtro.';
      return items.slice(-10).map((e) => {
        const dias = Math.floor((Date.now() - new Date(e.creado).getTime()) / 86_400_000);
        return `[${e.status}] [${e.prioridad}] ${e.titulo} (${dias}d${e.github_number ? ` — #${e.github_number}` : ''})`;
      }).join('\n');
    }
    case 'medicare_pack_seed': {
      const r = seedMedicareSkills();
      if (!r.created.length && !r.skipped.length) return 'No pude crear ni una. Revisa los logs.';
      const lines = [];
      if (r.created.length) lines.push(`Creadas (${r.created.length} drafts): ${r.created.join(', ')}`);
      if (r.skipped.length) lines.push(`Ya existían: ${r.skipped.join(', ')}`);
      lines.push('Aprueba cada una con "aprueba la skill X" cuando estés lista.');
      return lines.join('\n');
    }
    // ───── LUNA bridge ─────

    case 'buscar_huecos': {
      if (!calendarConfigured()) return 'Google Calendar no configurado. Faltan GOOGLE_CLIENT_ID/SECRET/REFRESH_TOKEN.';
      const r = await findFreeSlots({
        fecha_inicio: input.fecha_inicio,
        fecha_fin: input.fecha_fin,
        duracion_min: input.duracion_min || 30,
        horario: { inicio: input.horario_inicio || '09:00', fin: input.horario_fin || '17:00' },
        dias_semana: Array.isArray(input.dias_semana) ? input.dias_semana : [1, 2, 3, 4, 5],
        buffer_min: input.buffer_min ?? 15,
        limit: input.limite ?? 12,
      });
      if (!r.ok) return `No pude buscar huecos: ${r.reason}`;
      if (!r.slots.length) return 'No hay huecos disponibles en esa ventana con esos parámetros. Prueba ensanchar el horario o el rango.';
      return `${r.slots.length} huecos disponibles:\n${r.slots.map((s) => `  • ${s.inicio_local} (${s.duracion_min}min)`).join('\n')}`;
    }
    case 'crear_cita': {
      if (!calendarConfigured()) return 'Google Calendar no configurado. Faltan GOOGLE_CLIENT_ID/SECRET/REFRESH_TOKEN.';
      const r = await createEvent({
        ...input,
        evitar_conflicto: input.permitir_conflicto ? false : true,
      });
      if (!r.ok) {
        if (r.reason === 'conflicto' && r.conflictos?.length) {
          const lista = r.conflictos
            .map((c) => `  • "${c.titulo}" — ${c.inicio_local}`)
            .join('\n');
          return `No agendé: esa hora choca con ${r.conflictos.length} evento(s) existente(s):\n${lista}\n\nUsa buscar_huecos para encontrar otra hora, o si Isabel a propósito quiere double-booking pasa permitir_conflicto=true.`;
        }
        return `No pude crear la cita: ${r.reason}`;
      }
      // Auto-touchpoint si hay cliente_id
      let touchpointMsg = '';
      if (input.cliente_id) {
        try {
          const { addTouchpoint } = await import('./crm.js');
          addTouchpoint(input.cliente_id, {
            type: 'in_person',
            summary: `Cita agendada: ${r.event.titulo} (${r.event.inicio_local}).`,
          });
          touchpointMsg = ' (touchpoint registrado en el cliente)';
        } catch (err) { touchpointMsg = ` (no pude registrar touchpoint: ${err.message})`; }
      }
      const meetMsg = r.event.meet ? `\nGoogle Meet: ${r.event.meet}` : '';
      return `Cita creada: "${r.event.titulo}" — ${r.event.inicio_local}${touchpointMsg}.${meetMsg}\nLink: ${r.event.link}`;
    }
    case 'reagendar_cita': {
      if (!calendarConfigured()) return 'Google Calendar no configurado.';
      const r = await updateEvent(input.event_id, input);
      if (!r.ok) return `No pude reagendar: ${r.reason}`;
      return `Cita actualizada: "${r.event.titulo}" — ahora ${r.event.inicio_local}.\nLink: ${r.event.link}`;
    }
    case 'cancelar_cita': {
      if (!calendarConfigured()) return 'Google Calendar no configurado.';
      const r = await deleteEvent(input.event_id);
      if (!r.ok) return `No pude cancelar: ${r.reason}`;
      if (input.razon) {
        try { remember(`Cita cancelada (${input.event_id}): ${input.razon}`); } catch { /* ignore */ }
      }
      return `Cita ${input.event_id} cancelada. Google notificó a los asistentes.`;
    }
    case 'skill_proponer': {
      try {
        const s = proposeSkill({
          nombre: input.nombre,
          descripcion: input.descripcion,
          cuerpo: input.cuerpo,
          trigger: input.trigger,
          inputs_schema: input.inputs_schema,
          propuesto_por: 'athena',
        });
        return `Skill DRAFT creada: ${s.nombre_humano} [${s.name}] v${s.version}.\nEspera que Isabel diga "aprueba la skill ${s.name}" para activarla. Mientras tanto NO se ejecuta.`;
      } catch (err) {
        return `Error proponiendo skill: ${err.message}`;
      }
    }
    case 'skill_aprobar': {
      const s = approveSkill(input.nombre, 'isabel');
      return s ? `Skill ${s.name} aprobada (v${s.version}). Status: active. Ya la puedes invocar.` : `No encontré skill "${input.nombre}".`;
    }
    case 'skill_retirar': {
      const s = retireSkill(input.nombre);
      return s ? `Skill ${s.name} retirada (status: retired). Ya no se puede invocar — pero queda en el archivo por histórico.` : `No encontré skill "${input.nombre}".`;
    }
    case 'skill_eliminar': {
      const ok = deleteSkill(input.nombre);
      return ok ? `Skill ${input.nombre} borrada permanentemente.` : `No encontré skill "${input.nombre}".`;
    }
    case 'skills_lista': {
      const status = input.status || 'active';
      const skills = listSkills({ status });
      if (!skills.length) return `Sin skills con status "${status}".`;
      return skills.map((s) => `[${s.name}] (${s.status}, ${s.invocaciones || 0} usos): ${s.descripcion}`).join('\n');
    }
    case 'skill_ver': {
      const s = loadSkill(input.nombre);
      return s ? skillCard(s) : `No encontré skill "${input.nombre}".`;
    }
    case 'skill_invocar': {
      const s = loadSkill(input.nombre);
      if (!s) return `No encontré skill "${input.nombre}".`;
      if (s.status !== 'active') {
        return `Skill "${s.name}" está en status "${s.status}" — no se puede ejecutar. Pídele a Isabel que la apruebe primero.`;
      }
      // Anti-recursión: evitamos ciclo dentro de una sola cadena de llamadas.
      // Cada llamada arranca con depth=0; bumpamos en cada invocar; cortamos
      // en >=2 (skill puede invocar UNA sub-skill, no más).
      const depth = parseInt(process.env.__SKILL_DEPTH__ || '0', 10);
      if (depth >= 2) {
        return `Profundidad máxima de skills alcanzada (${depth}). No invoco "${s.name}" para evitar ciclo.`;
      }
      markInvoked(s.name);
      // Validamos inputs requeridos
      const provided = input.inputs || {};
      const faltantes = (s.inputs_schema || [])
        .filter((i) => i.requerido !== false && !(i.nombre in provided))
        .map((i) => i.nombre);
      if (faltantes.length) {
        return `Para invocar "${s.name}" me faltan estos inputs: ${faltantes.join(', ')}. Pídeselos a Isabel y vuelve a llamar skill_invocar con todos.`;
      }
      // Corremos la skill como sub-conversación: el cuerpo es la instrucción.
      try {
        process.env.__SKILL_DEPTH__ = String(depth + 1);
        // Dinámico para evitar ciclo: tools.js no puede importar directora.js
        // arriba porque directora.js importa tools.js.
        const { runDirectora } = await import('./directora.js');
        const skillPrompt = `[EJECUCIÓN DE SKILL: ${s.name}]
La siguiente es una skill APROBADA que Isabel quiere que ejecutes ahora. Sigue los pasos, llama las tools que indica, y al final devuelve UN resumen corto (3-4 líneas) de qué hiciste y qué pendientes quedaron.

Inputs:
${JSON.stringify(provided, null, 2)}

--- CUERPO DE LA SKILL ---
${s.cuerpo}
--- FIN DE LA SKILL ---

Empieza ya. No le mandes mensaje a Isabel hasta el resumen final.`;
        const subMessages = [{ role: 'user', content: skillPrompt }];
        const { reply } = await runDirectora(subMessages, { maxRounds: 8 });
        return `Skill "${s.name}" ejecutada.\n\n${reply}`;
      } catch (err) {
        return `Error ejecutando skill "${s.name}": ${err.message}`;
      } finally {
        process.env.__SKILL_DEPTH__ = String(depth);
      }
    }
    case 'llamar_cliente': {
      try {
        const r = await placeOutboundCall({
          to: input.telefono,
          motivo: input.motivo,
          cliente_id: input.cliente_id || null,
        });
        return `Llamada iniciada [${r.sid}] a ${r.to}. Status: ${r.status}. Cuando contesten yo hablo, grabo, y después de colgar te paso el resumen + touchpoint en el CRM.`;
      } catch (err) {
        return `No pude llamar: ${err.message}`;
      }
    }
    case 'regla_crear': {
      try {
        const { createOrder } = await import('./standing_orders.js');
        const o = createOrder({
          regla: input.regla,
          categoria: input.categoria || 'otro',
          nombre: input.nombre || null,
        });
        return `Regla creada [${o.categoria}/${o.slug}]: "${o.regla}". Desde ya la aplico en cada turno sin preguntarte.`;
      } catch (err) { return `No pude crear la regla: ${err.message}`; }
    }
    case 'reglas_lista': {
      try {
        const { listOrders } = await import('./standing_orders.js');
        const list = listOrders({ status: 'activa', categoria: input.categoria || null });
        if (!list.length) return 'Sin reglas permanentes todavía.';
        const byCat = {};
        for (const o of list) {
          if (!byCat[o.categoria]) byCat[o.categoria] = [];
          byCat[o.categoria].push(o);
        }
        return Object.entries(byCat).map(([cat, items]) =>
          `[${cat.toUpperCase()}]\n${items.map((o) => `· [${o.slug}] ${o.regla}${o.veces_aplicada ? ` (aplicada ${o.veces_aplicada}x)` : ''}`).join('\n')}`
        ).join('\n\n');
      } catch (err) { return `Error: ${err.message}`; }
    }
    case 'regla_retirar': {
      try {
        const { retireOrder } = await import('./standing_orders.js');
        const o = retireOrder(input.id);
        if (!o) return `No existe regla "${input.id}".`;
        return `Regla "${o.slug}" retirada. Ya no la aplico.`;
      } catch (err) { return `Error: ${err.message}`; }
    }
    case 'proyecto_crear': {
      try {
        const { createProject } = await import('./projects.js');
        const p = createProject({
          nombre: input.nombre,
          descripcion: input.descripcion || '',
          fecha_meta: input.fecha_meta || null,
        });
        return `Proyecto "${p.nombre}" creado [slug: ${p.slug}]. Vincúlale items con proyecto_linkear(proyecto="${p.slug}", kind=tasks|commitments|tickets_luna, item_id=...).`;
      } catch (err) { return `No pude crear proyecto: ${err.message}`; }
    }
    case 'proyecto_linkear': {
      try {
        const { linkItem } = await import('./projects.js');
        const r = linkItem(input.proyecto, input.kind, input.item_id);
        if (!r.ok) return `No pude vincular: ${r.error}`;
        return `Item ${input.kind} #${input.item_id} vinculado al proyecto "${input.proyecto}".`;
      } catch (err) { return `Error: ${err.message}`; }
    }
    case 'proyectos_lista': {
      try {
        const { listProjectsWithCounts } = await import('./projects.js');
        const list = listProjectsWithCounts().filter((p) => p.status !== 'cerrado');
        if (!list.length) return 'No hay proyectos activos. Crea uno con proyecto_crear cuando Isabel mencione un esfuerzo grande/multi-pieza.';
        return list.map((p) => `[${p.slug}] ${p.nombre} (${p.status}) · ${p.counts.total} items (${p.counts.tasks}T/${p.counts.commitments}C/${p.counts.tickets_luna}L/${p.counts.emails}E)`).join('\n');
      } catch (err) { return `Error: ${err.message}`; }
    }
    case 'vacation_modo': {
      try {
        const { setVacation } = await import('./vacation.js');
        const r = setVacation({
          activar: input.activar,
          hasta: input.hasta || null,
          timezone: input.timezone || null,
          location: input.location || '',
          notes: input.notes || '',
        });
        if (!input.activar) return 'Modo vacaciones desactivado. Bienvenida de vuelta.';
        const hasta = r.state.end_iso
          ? new Date(r.state.end_iso).toLocaleDateString('es-MX', { day: 'numeric', month: 'long' })
          : 'sin fecha';
        return `Modo vacaciones activado hasta ${hasta} (TZ: ${r.state.timezone}${r.state.location ? `, ${r.state.location}` : ''}). Solo te interrumpo con cosas URGENTES. Todo lo demás lo delego a Sami. Reportes a las 9am y 7pm tuyas.`;
      } catch (err) {
        return `No pude cambiar modo vacaciones: ${err.message}`;
      }
    }
    case 'template_listar': {
      try {
        const { listTemplates } = await import('./templates.js');
        const list = listTemplates();
        if (!list.length) return 'No hay templates pre-aprobados todavía. Crea uno con template_crear cuando Isabel te dicte uno explícitamente.';
        return list.map((t) => `[${t.slug}] ${t.nombre} (${t.canal})${t.veces_usado ? ` · usado ${t.veces_usado}x` : ''}`).join('\n');
      } catch (err) { return `Error: ${err.message}`; }
    }
    case 'template_usar': {
      try {
        const { renderTemplate } = await import('./templates.js');
        const rendered = renderTemplate(input.slug, input.vars || {});
        if (rendered.canal === 'email') {
          const { sendEmail } = await import('./email.js');
          await sendEmail({ to: input.destinatario, subject: rendered.asunto, text: rendered.cuerpo });
          return `Email enviado a ${input.destinatario} usando template "${input.slug}" (aprobado).`;
        }
        if (rendered.canal === 'sms') {
          const { sendSms } = await import('./whatsapp.js');
          await sendSms(input.destinatario, rendered.cuerpo);
          return `SMS enviado a ${input.destinatario} usando template "${input.slug}" (aprobado).`;
        }
        return `Canal no soportado: ${rendered.canal}`;
      } catch (err) { return `No pude usar template: ${err.message}`; }
    }
    case 'template_crear': {
      try {
        const { addTemplate } = await import('./templates.js');
        const t = addTemplate({
          nombre: input.nombre,
          canal: input.canal,
          asunto: input.asunto || '',
          cuerpo: input.cuerpo,
        });
        return `Template "${t.slug}" creado y aprobado. Lo puedes usar con template_usar(slug="${t.slug}", destinatario=..., vars={...}).`;
      } catch (err) { return `No pude crear template: ${err.message}`; }
    }
    default:
      return `Herramienta desconocida: ${name}`;
  }
}
