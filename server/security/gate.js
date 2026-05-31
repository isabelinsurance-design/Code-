// CONFIRMATION GATE  (Playbook patron #5 / #26)
//
// Un guardrail no sirve si pasa en silencio. El gate intercepta contenido dirigido
// al miembro y NO lo deja pasar como "listo para usar" si hay riesgo de cumplimiento:
// obliga a una decision humana explicita y deja rastro en el audit log.
//
//   pass     -> limpio, puede usarse.
//   review   -> avisos; un agente con licencia puede aprobar con `acknowledged`.
//   block    -> prohibido por CMS; requiere override explicito (acknowledged) que
//               queda AUDITADO con responsable. El gate no puede impedir fisicamente
//               que el agente lo diga, pero no lo bendice en silencio y deja huella.
//
// La REESCRITURA compliant es lo accionable: convierte "no hagas esto" en "di esto".
// Usa LLM si hay key; si no, ofrece los arreglos deterministas de cada regla.

import { review } from './compliance.js';
import { complete } from '../anthropic.js';
import { MODELS } from '../config.js';
import { audit } from '../memory/index.js';

// Evalua un draft. `acknowledged` = el agente asume la responsabilidad del override.
export function evaluate({ text, acknowledged = false, agentId = null } = {}) {
  const r = review(text);
  let status;
  if (r.level === 'ok') status = 'pass';
  else if (r.level === 'info' || r.level === 'warn') status = acknowledged ? 'pass' : 'review';
  else status = acknowledged ? 'override' : 'block'; // block

  const decision = {
    status,
    pass: status === 'pass',
    level: r.level,
    requiresAck: status === 'review' || status === 'block',
    overridden: status === 'override',
    flags: r.flags,
    summary: r.summary,
  };

  // Auditar cualquier cosa que no sea trivialmente limpia (especialmente overrides).
  if (status !== 'pass' || r.flags.length) {
    audit({
      action: status === 'override' ? 'compliance_override' : 'compliance_gate',
      agentId,
      input: String(text || '').slice(0, 200),
      outputSummary: `${status} · ${r.level} · ${r.flags.map((f) => f.id).join(',')}`,
    });
  }
  return decision;
}

// Reescritura compliant. Devuelve { rewrite, engine }. Sin key: null + los fixes.
const REWRITE_SYS = `Eres un revisor de cumplimiento de Medicare (CMS). Reescribe el texto dirigido a un miembro para que sea 100% compliant con las reglas de marketing de CMS, SIN superlativos sin sustento, sin implicar respaldo del gobierno, sin presion/urgencia, sin garantizar aceptacion/cobertura, sin prometer beneficios no confirmados, respetando el SOA. Manten el mensaje y el tono calido. Responde SOLO con el texto reescrito, en el mismo idioma del original.`;

export async function rewrite(text) {
  try {
    const out = await complete({
      system: REWRITE_SYS,
      messages: [{ role: 'user', content: String(text || '').slice(0, 2000) }],
      model: MODELS.specialist,
      maxTokens: 700,
    });
    if (out?.text?.trim()) return { rewrite: out.text.trim(), engine: 'llm' };
  } catch {
    /* sin key o fallo: cae a los fixes deterministas */
  }
  return { rewrite: null, engine: 'none' };
}
