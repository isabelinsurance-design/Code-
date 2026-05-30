// CAPTURA POR DEFECTO  (Playbook patron #13 — el mas importante)
//
// Despues de CADA turno, SAMIA intenta guardar lo que podria perderse (un nombre,
// un plan, una promesa, un dato del miembro) SIN pedir permiso. Si algo queda mal,
// el agente dira "olvidalo". El sistema asume: mas memoria > menos.
//
// Dos motores:
//   1) llmExtract  -> Haiku (model tier barato) devuelve JSON estructurado. Es el
//      preferido; entiende contexto. Requiere ANTHROPIC_API_KEY.
//   2) heuristicExtract -> determinista, sin red. Conservador (solo captura con
//      disparadores claros) para no meter ruido. Sirve de fallback y se puede testear.
//
// Devuelven la misma forma: { entities:[{name,type,attrs,note,gaps}], facts:[], gaps:[] }

import { complete } from '../anthropic.js';
import { MODELS } from '../config.js';
import { entitiesMentioned } from '../kb/index.js';

const CONDITIONS = ['diabetes', 'prediabetes', 'copd', 'epoc', 'osteoporosis', 'hipertension', 'cancer', 'asma', 'artritis', 'colesterol'];

// --- MOTOR DETERMINISTA (sin red) ---
export function heuristicExtract(userText) {
  const text = String(userText || '');
  const empty = { entities: [], facts: [], gaps: [] };
  if (!text.trim()) return empty;

  // Persona: requiere un disparador (miembro/cliente/Sr./member/...) seguido de
  // un nombre Capitalizado. Conservador a proposito.
  const NAME = '[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+(?:\\s+[A-ZÁÉÍÓÚÑ][a-záéíóúñ]+){0,2}';
  const trigger = new RegExp(
    `(?:miembro|cliente|prospecto|lead|paciente|se[ñn]or(?:a)?|sr\\.?|sra\\.?|member|client)\\s+(?:que\\s+se\\s+llama\\s+|llamad[oa]\\s+|se\\s+llama\\s+)?(${NAME})`,
    'g'
  );
  const named = new Set();
  let m;
  while ((m = trigger.exec(text))) named.add(m[1].trim());
  const seLlama = new RegExp(`se\\s+llama\\s+(${NAME})`, 'g');
  while ((m = seLlama.exec(text))) named.add(m[1].trim());

  // Atributos del turno (compartidos por las personas mencionadas).
  const ent = entitiesMentioned(text);
  const attrs = {};
  if (ent.plans[0]) attrs.plan = ent.plans[0].name;
  if (ent.groups[0]) attrs.medicalGroup = ent.groups[0].name;
  if (ent.doctors[0]) attrs.doctor = ent.doctors[0].name.replace(/^(Dr|Dra)\.?\s+/i, '');
  if (/full\s*dual/i.test(text)) attrs.mediCal = 'Full Dual';
  else if (/medi-?cal/i.test(text)) attrs.mediCal = 'menciona Medi-Cal';
  const phone = text.match(/\b(?:\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b/);
  if (phone) attrs.phone = phone[0];
  const conditions = CONDITIONS.filter((c) => new RegExp(`\\b${c}\\b`, 'i').test(text));
  if (conditions.length) attrs.conditions = conditions;

  // Gaps: frases con "falta / pendiente / no tiene / no sabemos / missing".
  const gaps = [];
  for (const s of text.split(/(?<=[.!?\n])/)) {
    if (/\b(falta|pendiente|no\s+tiene|no\s+sabemos|missing|hace\s+falta)\b/i.test(s)) {
      const g = s.trim().slice(0, 140);
      if (g) gaps.push(g);
    }
  }

  const entities = [...named].map((name) => ({ name, type: 'member', attrs, note: null, gaps }));
  // Si hay datos de dominio o gaps pero ninguna persona nombrada, no creamos
  // entidad (evita ruido); esos datos ya viven en el contexto del KB del turno.
  return { entities, facts: [], gaps: entities.length ? [] : gaps };
}

// --- MOTOR LLM (Haiku) ---
const EXTRACT_SYS = `Extraes memoria de una conversacion del equipo de Medicare de Isabel.
Devuelve SOLO un JSON valido (sin texto extra) con esta forma:
{"entities":[{"name":"nombre de persona (miembro/lead/prospecto/staff)","type":"member|lead|prospect|staff","attrs":{"plan":"","medicalGroup":"","doctor":"","mediCal":"Full Dual|...","phone":"","conditions":[]},"note":"hecho corto y util","gaps":["lo que falta saber de esta persona"]}],
"facts":["hechos del equipo/negocio que no caducan"],
"gaps":["huecos generales sin persona"]}
Reglas: incluye SOLO datos realmente presentes. Si no hay nada que guardar, devuelve {"entities":[],"facts":[],"gaps":[]}. No inventes. Omite campos vacios.`;

export async function llmExtract(userText, assistantText) {
  const convo = `AGENTE: ${userText}\n\nSAMIA: ${String(assistantText || '').slice(0, 1500)}`;
  let out;
  try {
    out = await complete({
      system: EXTRACT_SYS,
      messages: [{ role: 'user', content: convo }],
      model: MODELS.classifier,
      maxTokens: 600,
    });
  } catch (e) {
    if (e.code === 'NO_API_KEY') return null; // sin key -> deja que el fallback actue
    throw e;
  }
  const txt = (out.text || '').trim();
  const start = txt.indexOf('{');
  const end = txt.lastIndexOf('}');
  if (start === -1 || end === -1) return { entities: [], facts: [], gaps: [] };
  try {
    const parsed = JSON.parse(txt.slice(start, end + 1));
    return {
      entities: Array.isArray(parsed.entities) ? parsed.entities : [],
      facts: Array.isArray(parsed.facts) ? parsed.facts : [],
      gaps: Array.isArray(parsed.gaps) ? parsed.gaps : [],
    };
  } catch {
    return { entities: [], facts: [], gaps: [] };
  }
}

// Orquestador: intenta LLM; si no hay key (o falla suave) usa el heuristico.
export async function extractMemory(userText, assistantText) {
  try {
    const llm = await llmExtract(userText, assistantText);
    if (llm) return { ...llm, engine: 'llm' };
  } catch {
    // cae al heuristico
  }
  return { ...heuristicExtract(userText), engine: 'heuristic' };
}
