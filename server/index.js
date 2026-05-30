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
import { SPECIALISTS, resolveSpecialist, specialistList } from './specialists.js';
import { complete } from './anthropic.js';
import * as mem from './memory/index.js';
import * as entities from './memory/entities.js';
import * as wiki from './memory/wiki.js';
import { captureTurn, memoryContext } from './memory/capture.js';
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

  if (passthroughContext) {
    parts.push(String(passthroughContext).slice(0, 60000));
  } else {
    if (spec.knowledge) parts.push(`CONOCIMIENTO DE DOMINIO:\n${KNOWLEDGE}`);
    if (spec.extra) parts.push(spec.extra);
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
    if (specId !== 'practica') captureTurn({ userText, assistantText: text }).catch(() => {});

    return json(res, 200, { reply: text, content, specialist: specId, usage });
  } catch (e) {
    mem.audit({ action: 'chat_error', specialist: specId, agentId, input: userText, outputSummary: e.message });
    const code = e.code === 'NO_API_KEY' ? 503 : 502;
    return json(res, code, { error: e.message });
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
});
