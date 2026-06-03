// SAMIA — servidor (Fase 1: Fundacion)
//
// Endpoints:
//   GET  /api/health        -> estado + stats del KB
//   GET  /api/specialists   -> lista de especialistas (para la UI)
//   POST /api/chat          -> orquesta: constitucion + especialista + KB + memoria -> Anthropic
//   GET  /api/kb/lookup      -> busqueda directa (doctor / grupo / plan / caso)
//   GET  /api/audit          -> ultimas N acciones (semilla del dashboard, patron #33)
//   *                        -> archivos estaticos (index.html, samia.html, tools/)

import { createServer } from 'node:http';
import { PORT, MODELS } from './config.js';
import { CONSTITUCION } from './constitucion.js';
import { KNOWLEDGE, buildKbContext, lookupDoctor, lookupMedicalGroup, lookupPlan, searchCases, kbStats } from './kb/index.js';
import { SPECIALISTS, resolveSpecialist, specialistList, vozBlock } from './specialists.js';
import { chooseSpecialists, routeDeterministic, SYNTH_SYS, buildSynthUser } from './orchestrator.js';
import * as skills from './intel/skills.js';
import * as growth from './intel/growth.js';
import { complete } from './anthropic.js';
import * as mem from './memory/index.js';
import * as entities from './memory/entities.js';
import * as wiki from './memory/wiki.js';
import { captureTurn, memoryContext } from './memory/capture.js';
import { getSignals, refreshSignals, signalsContext } from './intel/signals.js';
import { runReflection, getReflections } from './intel/reflection.js';
import * as commitments from './intel/commitments.js';
import { buildBriefing, generateBriefing, getLatestBriefing } from './intel/briefing.js';
import { computeHealth } from './intel/health.js';
import { startScheduler, schedulerStatus, tick } from './intel/scheduler.js';
import { review as complianceReview, scanPII } from './security/compliance.js';
import { evaluate as gateEvaluate, rewrite as complianceRewrite } from './security/gate.js';
import { serveStatic } from './static.js';

const json = (res, code, obj) => {
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(obj));
};

function readBody(req, limit = 1_000_000) {
  return new Promise((resolve, reject) => {
    let data = '';
    req.on('data', (c) => {
      data += c;
      if (data.length > limit) reject(new Error('payload too large'));
    });
    req.on('end', () => {
      try {
        resolve(data ? JSON.parse(data) : {});
      } catch (e) {
        reject(e);
      }
    });
    req.on('error', reject);
  });
}

// Arma el system prompt estratificado del turno (patron #14, version Fase 1):
//   constitucion -> [conocimiento] -> instruccion del especialista
//   -> memoria del agente -> datos del KB relevantes al turno.
//
// passthroughContext: contexto dinamico que envia la UI (ej. memoria del cliente
// del surface "Asesor"). Siempre se antepone la CONSTITUCION para que el
// compliance aplique aunque el prompt venga del navegador.
function buildSystem(specId, userText, agentId, passthroughContext) {
  const spec = SPECIALISTS[specId];
  const parts = [CONSTITUCION];

  // Capa de memoria estratificada (patron #14): temporada -> wiki -> personas -> gaps.
  const memCtx = memoryContext(userText);
  if (memCtx) parts.push(memCtx);

  // Señales activas (#16) + compromisos que vencen (#19) — solo en modos operativos.
  if (spec.lookups) {
    const sig = signalsContext();
    if (sig) parts.push(sig);
    const com = commitments.commitmentsContext();
    if (com) parts.push(com);
  }

  if (passthroughContext) {
    parts.push(String(passthroughContext).slice(0, 60000));
  } else {
    if (spec.knowledge) parts.push(`CONOCIMIENTO DE DOMINIO:\n${KNOWLEDGE}`);
    if (spec.extra) parts.push(spec.extra);
  }
  // Voz del modo (patron Athena #12): palabras prohibidas + cuando rebotar.
  const voz = vozBlock(spec);
  if (voz) parts.push(voz);

  // Skill aprobada relevante (patron Athena #9): playbook ya validado por el equipo.
  if (spec.lookups) {
    const sk = skills.skillsContext(userText);
    if (sk) parts.push(sk);
  }
  const amem = mem.agentContext(agentId);
  if (amem) parts.push(amem);
  if (spec.lookups) {
    const ctx = buildKbContext(userText);
    if (ctx) parts.push(ctx);
  }
  return parts.join('\n\n---\n\n');
}

async function handleChat(req, res) {
  let body;
  try {
    body = await readBody(req);
  } catch {
    return json(res, 400, { error: 'JSON invalido' });
  }

  const { mode = 'chat', sessionId = null, agentId = null, agentName = null, webSearch = false, context = null } = body;
  // Acepta historial completo (messages) o un solo mensaje.
  let messages = Array.isArray(body.messages) ? body.messages : null;
  if (!messages && body.message) messages = [{ role: 'user', content: String(body.message) }];
  if (!messages || messages.length === 0) return json(res, 400, { error: 'Falta messages o message' });

  const specId = resolveSpecialist(mode);
  const lastUser = [...messages].reverse().find((m) => m.role === 'user');
  const userText = lastUser ? String(lastUser.content) : '';

  // Modo 'auto': el orquestador elige especialistas y, si la pregunta toca >1
  // dominio, hace fan-out paralelo + sintesis (patron multi-agente / Athena #5).
  if (mode === 'auto') {
    return handleOrchestrate({ res, messages, userText, agentId, agentName, sessionId, context });
  }

  // Tier de modelo: el surface "Asesor" pide opus + web search; el resto, sonnet.
  const wantsOpus = body.model === 'opus' || webSearch;
  const primaryModel = wantsOpus ? MODELS.orchestrator : MODELS.specialist;

  if (agentId) mem.touchAgent(agentId, agentName);
  const system = buildSystem(specId, userText, agentId, context);

  // Llama con fallback: si el modelo primario falla por modelo no disponible
  // (400/404), reintenta con el especialista (sonnet), como hacia la UI antes.
  async function callWithFallback() {
    try {
      return await complete({ system, messages, model: primaryModel, webSearch });
    } catch (e) {
      if ((e.status === 404 || e.status === 400) && primaryModel !== MODELS.specialist) {
        return await complete({ system, messages, model: MODELS.specialist, webSearch });
      }
      throw e;
    }
  }

  try {
    const { text, content, usage } = await callWithFallback();

    if (sessionId) {
      mem.appendTurns(sessionId, [
        { role: 'user', content: userText },
        { role: 'assistant', content: text },
      ]);
    }
    if (agentId) mem.captureTurn(agentId, { specialist: specId, userText });
    mem.audit({ action: 'chat', specialist: specId, agentId, input: userText, outputSummary: text });

    // CAPTURA POR DEFECTO (#13): guarda personas/datos del turno sin pedir permiso.
    // No bloquea la respuesta ni la rompe si falla. EXCEPCION: en 'practica' los
    // prospectos son FICTICIOS (role-play de ventas) — no contaminar la memoria real.
    if (specId !== 'practica') {
      captureTurn({ userText, assistantText: text }).catch(() => {});
      commitments.captureCommitments(userText); // promesas con fecha (#19)
    }

    // Aviso ligero de PII (#7): si el agente pego un SSN/MBI/tarjeta, recordar
    // mantenerlo en sistemas seguros. No bloquea; solo avisa.
    const pii = scanPII(userText);
    const compliance = pii.length ? { piiAdvisory: pii } : undefined;

    return json(res, 200, { reply: text, content, specialist: specId, usage, compliance });
  } catch (e) {
    mem.audit({ action: 'chat_error', specialist: specId, agentId, input: userText, outputSummary: e.message });
    const code = e.code === 'NO_API_KEY' ? 503 : 502;
    return json(res, code, { error: e.message });
  }
}

// Orquestacion con fan-out paralelo. Reusa buildSystem + complete del chat normal.
async function handleOrchestrate({ res, messages, userText, agentId, agentName, sessionId, context }) {
  if (agentId) mem.touchAgent(agentId, agentName);
  const route = await chooseSpecialists(userText, complete);
  const specialists = route.specialists.length ? route.specialists : ['chat'];

  try {
    // 1+2. FAN-OUT: cada especialista responde en paralelo, con su propio prompt.
    const settled = await Promise.allSettled(
      specialists.map((id) =>
        complete({ system: buildSystem(id, userText, agentId, context), messages, model: MODELS.specialist }).then((r) => ({ specialist: id, text: r.text }))
      )
    );
    const parts = settled.filter((s) => s.status === 'fulfilled').map((s) => s.value);
    if (!parts.length) throw settled[0].reason || new Error('fan-out vacio');

    // 3. SINTESIS: si fue >1 dominio, Opus funde en una voz. Si fue 1, esa respuesta.
    let reply, usage;
    if (parts.length >= 2) {
      const out = await complete({ system: SYNTH_SYS, messages: [{ role: 'user', content: buildSynthUser(userText, parts) }], model: MODELS.orchestrator });
      reply = out.text;
      usage = out.usage;
    } else {
      reply = parts[0].text;
    }

    if (sessionId) mem.appendTurns(sessionId, [{ role: 'user', content: userText }, { role: 'assistant', content: reply }]);
    if (agentId) mem.captureTurn(agentId, { specialist: 'auto', userText });
    mem.audit({ action: 'orchestrate', specialist: parts.map((p) => p.specialist).join('+'), agentId, input: userText, outputSummary: reply });
    captureTurn({ userText, assistantText: reply }).catch(() => {});
    commitments.captureCommitments(userText);

    const pii = scanPII(userText);
    const compliance = pii.length ? { piiAdvisory: pii } : undefined;
    return json(res, 200, { reply, specialists: parts.map((p) => p.specialist), routedBy: route.reason, parts, usage, compliance });
  } catch (e) {
    mem.audit({ action: 'orchestrate_error', specialist: specialists.join('+'), agentId, input: userText, outputSummary: e.message });
    return json(res, e.code === 'NO_API_KEY' ? 503 : 502, { error: e.message });
  }
}

function handleLookup(res, url) {
  const type = url.searchParams.get('type');
  const q = url.searchParams.get('q') || '';
  const map = {
    doctor: lookupDoctor,
    group: lookupMedicalGroup,
    plan: lookupPlan,
    case: (x) => searchCases(x, 6),
  };
  const fn = map[type];
  if (!fn) return json(res, 400, { error: 'type debe ser doctor|group|plan|case' });
  return json(res, 200, { type, q, results: fn(q) });
}

const server = createServer(async (req, res) => {
  const url = new URL(req.url, 'http://x');
  const path = url.pathname;

  if (path === '/api/health') return json(res, 200, { ok: true, service: 'samia', kb: kbStats() });
  if (path === '/api/specialists') return json(res, 200, { specialists: specialistList() });

  // --- MEMORIA (Fase 3) ---
  if (path === '/api/memory/entities' && req.method === 'GET')
    return json(res, 200, { entities: entities.listEntities({ q: url.searchParams.get('q') || '' }) });
  if (path === '/api/memory/entity' && req.method === 'GET') {
    const e = entities.getEntity(url.searchParams.get('id'));
    return e ? json(res, 200, { entity: e }) : json(res, 404, { error: 'no encontrada' });
  }
  if (path === '/api/memory/gaps' && req.method === 'GET') return json(res, 200, { gaps: entities.rankedGaps(20) });

  // --- INTELIGENCIA (Fase 4) ---
  if (path === '/api/intel/signals' && req.method === 'GET') return json(res, 200, getSignals());
  if (path === '/api/intel/signals/refresh' && req.method === 'POST') return json(res, 200, { signals: refreshSignals() });
  if (path === '/api/intel/reflections' && req.method === 'GET') return json(res, 200, { reflections: getReflections(Number(url.searchParams.get('n')) || 14) });
  if (path === '/api/intel/reflect' && req.method === 'POST') {
    const report = await runReflection();
    return json(res, 200, { report });
  }
  // Candidatos de fusion dudosos + confirmacion humana (confirmation gate).
  if (path === '/api/memory/merge-candidates' && req.method === 'GET')
    return json(res, 200, { candidates: entities.duplicateCandidates() });
  if (path === '/api/memory/merge' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    if (!body.into || !body.from) return json(res, 400, { error: 'into y from requeridos' });
    const e = entities.mergeEntities(body.into, body.from);
    return e ? json(res, 200, { entity: e }) : json(res, 404, { error: 'entidad no encontrada' });
  }

  // --- AUTONOMIA (Fase 5) ---
  // --- SKILLS / playbooks aprobados (Fase 12) ---
  if (path === '/api/skills' && req.method === 'GET')
    return json(res, 200, { skills: skills.listSkills({ status: url.searchParams.get('status') || undefined }) });
  if (path === '/api/skills' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    const s = skills.proposeSkill({ name: body.name, trigger: body.trigger, steps: body.steps, source: body.source });
    return s ? json(res, 200, { skill: s }) : json(res, 400, { error: 'name requerido' });
  }
  if (path === '/api/skills/approve' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    const s = skills.approveSkill(body.id, { steps: body.steps, trigger: body.trigger });
    return s ? json(res, 200, { skill: s }) : json(res, 404, { error: 'skill no encontrada' });
  }
  if (path === '/api/skills/reject' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    const s = skills.rejectSkill(body.id);
    return s ? json(res, 200, { skill: s }) : json(res, 404, { error: 'skill no encontrada' });
  }
  if (path === '/api/skills/invoke' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    const s = skills.invokeSkill(body.id);
    return s ? json(res, 200, { skill: s }) : json(res, 404, { error: 'skill aprobada no encontrada' });
  }

  // --- CRECIMIENTO / investigacion continua (Fase 13) ---
  if (path === '/api/growth' && req.method === 'GET')
    return json(res, 200, {
      ideas: growth.listIdeas({ status: url.searchParams.get('status') || undefined }),
      lastRun: growth.lastRun(),
      // 5 lentes: las externas (rotan) + la interna de jefe de gabinete.
      topics: [...growth.TOPICS.map((t) => ({ key: t.key, label: t.label })), { key: growth.CHIEF.key, label: growth.CHIEF.label, internal: true }],
      nextTopic: growth.topicForWeek().key,
    });
  if (path === '/api/growth/chief' && req.method === 'GET')
    return json(res, 200, { snapshot: growth.chiefSnapshot(new Date()) });
  if (path === '/api/growth/research' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    const r = await growth.runResearch(new Date(), { topicKey: body.topic });
    return json(res, r.ok ? 200 : 200, r); // 200 aun sin ideas: el reason explica
  }
  if (path === '/api/growth/idea' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    const i = growth.setIdeaStatus(body.id, body.status);
    return i ? json(res, 200, { idea: i }) : json(res, 404, { error: 'idea no encontrada o status invalido' });
  }

  // Vista del router determinista (sin LLM): que especialistas tocaria una pregunta.
  if (path === '/api/orchestrate/route' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    return json(res, 200, routeDeterministic(body.text || body.message || ''));
  }
  if (path === '/api/intel/health' && req.method === 'GET') return json(res, 200, { health: computeHealth() });
  if (path === '/api/intel/briefing' && req.method === 'GET')
    return json(res, 200, { briefing: getLatestBriefing() || buildBriefing() });
  if (path === '/api/intel/briefing' && req.method === 'POST')
    return json(res, 200, { briefing: generateBriefing() });
  if (path === '/api/intel/commitments' && req.method === 'GET') {
    commitments.reviewCommitments();
    return json(res, 200, { commitments: commitments.listCommitments({ status: url.searchParams.get('status') || undefined }) });
  }
  if (path === '/api/intel/commitments' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    if (body.id && body.status) {
      const c = commitments.setStatus(body.id, body.status);
      return c ? json(res, 200, { commitment: c }) : json(res, 404, { error: 'no encontrado' });
    }
    if (body.text) return json(res, 200, { commitment: commitments.addCommitment(body) });
    return json(res, 400, { error: 'text (nuevo) o id+status (actualizar) requeridos' });
  }
  // --- SEGURIDAD / CUMPLIMIENTO (Fase 7) ---
  // Revisa un draft dirigido al miembro. Pasa por el confirmation gate; opcional
  // reescritura compliant (?rewrite=1 o body.rewrite).
  if (path === '/api/security/review' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    if (!body.text) return json(res, 400, { error: 'text requerido' });
    const decision = gateEvaluate({ text: body.text, acknowledged: !!body.acknowledged, agentId: body.agentId || null });
    let rewrite = null;
    if (body.rewrite && !decision.pass && decision.level !== 'ok') {
      const r = await complianceRewrite(body.text);
      rewrite = r.rewrite;
    }
    return json(res, 200, { ...decision, rewrite });
  }
  if (path === '/api/intel/scheduler' && req.method === 'GET') return json(res, 200, schedulerStatus());
  if (path === '/api/intel/run-jobs' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    const ran = await tick(new Date(), { force: body.force !== false, only: body.only || null });
    return json(res, 200, { ran });
  }
  if (path === '/api/memory/season' && req.method === 'GET') return json(res, 200, { season: wiki.getSeason() });
  if (path === '/api/memory/wiki' && req.method === 'GET') return json(res, 200, { facts: wiki.getFacts() });
  if (path === '/api/memory/season' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    return json(res, 200, { season: wiki.setSeason(body.text || '') });
  }
  if (path === '/api/memory/fact' && req.method === 'POST') {
    const body = await readBody(req).catch(() => ({}));
    wiki.addFact(body.fact || '');
    return json(res, 200, { ok: true, facts: wiki.getFacts() });
  }
  if (path === '/api/kb/lookup' && req.method === 'GET') return handleLookup(res, url);
  if (path === '/api/audit' && req.method === 'GET')
    return json(res, 200, { audit: mem.getAudit(Number(url.searchParams.get('n')) || 50) });
  if (path === '/api/chat' && req.method === 'POST') return handleChat(req, res);
  if (path.startsWith('/api/')) return json(res, 404, { error: 'endpoint no encontrado' });

  return serveStatic(req, res);
});

server.listen(PORT, () => {
  const s = kbStats();
  console.log(`SAMIA backend -> http://localhost:${PORT}`);
  console.log(`KB: ${s.cases} casos · ${s.medicalGroups} grupos · ${s.doctors} doctores · ${s.plans} planes`);
  // Latido de autonomia (#21). Inofensivo si el proceso es efimero; en always-on
  // dispara reflexion 02:00, briefing 06:30, repaso semanal lun 07:00, tick horario.
  startScheduler();
  console.log('Scheduler activo: reflexion 02:00 · briefing 06:30 · investigacion lun 05:00 · repaso lun 07:00 · tick :00');
});
