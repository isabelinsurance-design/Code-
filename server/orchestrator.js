// ORQUESTADOR — FAN-OUT PARALELO  (Playbook Anthropic multi-agente / Athena #5)
//
// La mayoria de preguntas las contesta UN especialista. Pero algunas tocan VARIOS
// dominios a la vez ("el doctor de Maria salio de la red de su IPA y ademas le llego
// un bill de $400"). En vez de hacer que el agente cambie de modo y pregunte dos
// veces, el orquestador:
//   1. ROUTER   — decide que especialistas toca la pregunta (≥2 dominios => fan-out).
//   2. FAN-OUT  — los consulta EN PARALELO (cada uno con su system prompt enfocado).
//   3. SINTESIS — un orquestador (Opus) funde las respuestas en UNA sola voz de SAMIA,
//                 aplicando el habito "sintetiza, no recites" + "UNA accion concreta".
//
// El router tiene fallback DETERMINISTA (keywords) para probarse sin red. La sintesis
// si necesita LLM; sin key, degrada a la respuesta del especialista mas relevante.

import { MODELS } from './config.js';
import { SPECIALISTS } from './specialists.js';

// Catalogo elegible para consulta en paralelo: solo especialistas de DOMINIO (no los
// modos de formato como triage/script/escalate/practica). Extender aqui al agregar
// dominios nuevos (ej. 'farmacia', 'dental') y el fan-out los toma automaticamente.
export const FANOUT_CATALOG = ['ipa', 'bill'];

// Router determinista: puntua cada especialista del catalogo por keywords del turno.
const ROUTER_KEYWORDS = {
  ipa: [/\bipa\b/i, /redes?\s+medicas?/i, /grupo\s+medico/i, /\bpcp\b/i, /medical\s+group/i, /(doctor|dr\.?|medico).{0,30}(acepta|red|plan|cambio)/i, /facey|regal|heritage|providence|alignment|healthcare partners|lakeside/i, /cambio\s+de\s+(pcp|doctor|medico)/i],
  bill: [/\bbill(s)?\b/i, /\beob\b/i, /cobro|cobr[oó]|factura|cuenta|cargo/i, /me\s+lleg[oó].{0,20}(cuenta|cobro|bill)/i, /\$\s*\d/i, /copago|deducible|coinsurance/i, /no\s+deberia\s+pagar/i],
};

// Devuelve { specialists:[ids], fanout:bool, reason } SOLO con heuristica.
export function routeDeterministic(userText) {
  const t = String(userText || '');
  const scored = [];
  for (const id of FANOUT_CATALOG) {
    const hits = (ROUTER_KEYWORDS[id] || []).reduce((n, re) => n + (re.test(t) ? 1 : 0), 0);
    if (hits > 0) scored.push({ id, hits });
  }
  scored.sort((a, b) => b.hits - a.hits);
  const specialists = scored.slice(0, 3).map((s) => s.id);
  return {
    specialists,
    fanout: specialists.length >= 2,
    reason: specialists.length ? `keywords: ${specialists.join(', ')}` : 'sin dominio claro',
  };
}

// Router LLM (Haiku barato): elige del catalogo. Cae al determinista si no hay key
// o si la salida no es usable.
const ROUTER_SYS = `Eres el router de SAMIA (equipo Medicare). Dada la pregunta de un agente, decide que especialistas de DOMINIO deberian responder. Catalogo:
- ipa: redes medicas, grupos medicos/IPA, que plan acepta que doctor, cambios de PCP.
- bill: cobros inesperados, EOB vs cobro real, copagos, facturas.
Responde SOLO un JSON: {"specialists":["ipa"]} con 1 a 3 ids del catalogo. Si la pregunta toca >1 dominio, incluye varios. Si ninguno aplica claramente, responde {"specialists":[]}.`;

export async function chooseSpecialists(userText, complete) {
  const det = routeDeterministic(userText);
  if (!complete) return det;
  try {
    const out = await complete({
      system: ROUTER_SYS,
      messages: [{ role: 'user', content: String(userText || '').slice(0, 1000) }],
      model: MODELS.classifier,
      maxTokens: 100,
    });
    const txt = (out?.text || '').trim();
    const s = txt.indexOf('{');
    const e = txt.lastIndexOf('}');
    if (s !== -1 && e !== -1) {
      const parsed = JSON.parse(txt.slice(s, e + 1));
      const ids = (Array.isArray(parsed.specialists) ? parsed.specialists : []).filter((id) => FANOUT_CATALOG.includes(id));
      if (ids.length) return { specialists: ids.slice(0, 3), fanout: ids.length >= 2, reason: 'router LLM' };
    }
  } catch {
    /* cae al determinista */
  }
  return det;
}

// Prompt de sintesis: funde las respuestas de los especialistas en UNA voz.
export const SYNTH_SYS = `Eres SAMIA orquestando. Recibiste la pregunta de un agente y las respuestas de VARIOS especialistas internos. Tu trabajo: dar UNA sola respuesta integrada, en la voz de SAMIA.
REGLAS:
- SINTETIZA, no recites: no pegues las respuestas una tras otra. Encuentra el punto donde se cruzan y resuelve el caso completo.
- Si los especialistas se contradicen, dilo y di cual gana y por que.
- Manten el formato HTML de SAMIA y termina con "Tu proximo paso:" + UNA sola accion.
- No menciones que consultaste especialistas; hablas como una sola SAMIA.`;

export function buildSynthUser(userText, parts) {
  const blocks = parts.map((p) => `--- ${SPECIALISTS[p.specialist]?.label || p.specialist} ---\n${p.text}`).join('\n\n');
  return `PREGUNTA DEL AGENTE:\n${userText}\n\nRESPUESTAS DE LOS ESPECIALISTAS:\n${blocks}\n\nIntegra todo en UNA respuesta de SAMIA.`;
}
