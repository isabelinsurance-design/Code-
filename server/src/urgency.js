// ============================================================
//  Urgency Classifier — decide si Isabel debe ser interrumpida
//  ───────────────────────────────────────────────────────────
//  Cuando está de vacaciones, NO procesamos cada mensaje normal.
//  Usamos Haiku 4.5 (barato, rápido — $0.0008 por clasificación)
//  para decidir:
//
//    URGENT  → procesar normal, Isabel ve la respuesta
//    NORMAL  → auto-delegar a Sami via mensaje_a_sami, log,
//              NO despertar a Isabel
//
//  Criterios URGENT (cualquiera basta):
//    - Cliente Medicare en crisis (denial de medicamento crítico,
//      problema con doctor primario, AEP enrollment a punto de cerrar)
//    - CMS / regulatory issue (audit, complaint formal)
//    - Emergencia familiar
//    - Decisión que SOLO Isabel puede tomar (no delegable)
//    - Carrier rep que necesita respuesta urgente (deadline <24h)
//
//  Todo lo demás (rutinario, info general, follow-up) → NORMAL.
// ============================================================
import Anthropic from '@anthropic-ai/sdk';

const HAIKU = process.env.HAIKU_MODEL || 'claude-haiku-4-5-20251001';

const SYSTEM = `Eres un clasificador de urgencia para Isabel Fuentes, agente de Medicare en SoCal. Ella está DE VACACIONES y NO debe ser interrumpida con cosas normales.

Tu trabajo: clasificar el mensaje entrante como URGENT o NORMAL.

URGENT (cualquiera califica):
- Cliente Medicare con crisis activa (denial de medicamento crítico, problema serio con doctor primario, AEP enrollment a punto de cerrar HOY, queja CMS)
- Carrier rep (SCAN/Anthem/Humana/Alignment/LA Care/Health Net/Molina/UHC) pidiendo respuesta con deadline <24h
- Audit / regulatory letter / compliance issue formal
- Emergencia familiar (hijo, papás, esposo)
- Decisión estratégica que SOLO Isabel puede tomar (acuerdo legal, hire/fire, gran inversión)
- Cliente VIP histórico (referenced explicitly as long-time / family / friend)

NORMAL (delegable a Sami):
- Pregunta rutinaria de cliente sobre plan, copay, formulary
- Solicitud de cita / reagendar
- Recordatorio de SOA / MBI
- Marketing / vendor / cold outreach
- Update de status sin acción urgente
- Pregunta de tipo "¿cómo funciona X?"
- Confirmación de información
- Email genérico de carrier (newsletter, update)

Responde SOLO con JSON exacto:
{"clasificacion":"URGENT"|"NORMAL", "razon":"<1-frase breve>", "delegable_a":"sami"|"skarleth"|"arlette"|null}`;

export async function classifyUrgency({ from = '', body = '', subject = '', context = '' }) {
  if (!process.env.ANTHROPIC_API_KEY) {
    // Sin API key, defaulteamos a NORMAL — más seguro durante vacaciones.
    return { clasificacion: 'NORMAL', razon: 'sin API key — default seguro', delegable_a: 'sami' };
  }

  const client = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY });
  const userMsg = [
    from ? `De: ${from}` : '',
    subject ? `Asunto: ${subject}` : '',
    body ? `Mensaje: ${body}` : '',
    context ? `Contexto: ${context}` : '',
  ].filter(Boolean).join('\n');

  try {
    const r = await client.messages.create({
      model: HAIKU,
      max_tokens: 200,
      system: SYSTEM,
      messages: [{ role: 'user', content: userMsg }],
    });
    const text = r.content?.[0]?.text || '{}';
    const match = text.match(/\{[\s\S]*\}/);
    if (!match) return { clasificacion: 'NORMAL', razon: 'parse falló', delegable_a: 'sami' };
    const parsed = JSON.parse(match[0]);
    if (parsed.clasificacion !== 'URGENT' && parsed.clasificacion !== 'NORMAL') {
      parsed.clasificacion = 'NORMAL';
    }
    return parsed;
  } catch (e) {
    console.warn('[urgency] clasificación falló:', e.message);
    // Failsafe: si el clasificador falla, mejor despertarla que perderse algo.
    return { clasificacion: 'URGENT', razon: `clasificador falló: ${e.message}`, delegable_a: null };
  }
}
