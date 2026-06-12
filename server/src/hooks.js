// ============================================================
//  PostToolUse review hooks
//  ────────────────────────
//  Inspirado por el setup de Boris Cherny (Anthropic) — después
//  de que Athena prepara texto que va a salir a un tercero
//  (email a cliente, SMS a cliente, mensaje a Sami), corremos
//  una pasada rápida de revisión:
//
//   1. Checks deterministas (regex): consejo médico/financiero,
//      vocabulario que Isabel no usaría, gates CMS (planes sin SOA).
//   2. Tono review con Haiku 4.5 (~1s): ¿suena a Isabel?
//
//  Severidades:
//   alto  — riesgo serio (consejo médico, sin SOA, falso premium).
//           Para mensaje_a_sami (que se manda solo), BLOQUEA.
//           Para drafts (email/SMS clientes), se marca en la cola
//           y Isabel decide.
//   aviso — tono off / vocabulario ajeno. Se marca, no bloquea.
//   info  — sugerencia (alargar, acortar). Se marca, no bloquea.
//
//  El check se ejecuta DESPUÉS de que la tool dispatch devuelve,
//  así que latency penaliza solo el flush final, no la cadena
//  de razonamiento de Athena.
// ============================================================
import { anthropic } from './claude.js';
import { findClient } from './crm.js';

// Vocabulario que Isabel no usa (de la filosofía + estilo).
const FORBIDDEN_PHRASES = [
  'no se puede',
  'imposible',
  'no hay nada que hacer',
  'es lo que es',
  'fíjate', // expresión muy mexicana específica, depende de contexto
];

// CMS / TPMO marketing forbidden absolute claims — usar SIN evidencia
// concreta es agente-killer. Si Athena los emite a un cliente Medicare,
// CMS audit los puede usar para revocar la licencia de Isabel.
const CMS_FORBIDDEN_CLAIMS = /\b(?:el\s+mejor\s+plan|the\s+best\s+plan|cheapest|el\s+más\s+barato|garantizad[oa]\s+(?:ahorro|cobertura)|guaranteed\s+(?:savings|coverage)|100\s*%\s+(?:cubierto|covered|free)|gratis\s+(?:totalmente|completo)|absolutely\s+free|sin\s+costo\s+(?:ninguno|alguno))\b/i;

// Disclaimer CMS requerido en material promocional dirigido a Medicare-eligibles:
// "Not connected with or endorsed by the U.S. government or the federal Medicare program."
const CMS_DISCLAIMER_FRAGMENTS = /(no\s+(?:est[áa])?\s+(?:afiliad[oa]|conectad[oa])\s+(?:con|al)\s+(?:gobierno|medicare)|not\s+(?:connected|affiliated)\s+with\s+.*(?:government|medicare)|agente\s+independiente\s+licenciad[oa])/i;

const MEDICAL_KEYWORDS = /\b(dosis|tómate|recetar|recet[ae]|diagnos|síntoma|toma\s+(?:dos|tres|cuatro|este|esta|esa|ese)|deberías\s+tomar)\b/i;
const FINANCIAL_KEYWORDS = /\b(invertir|inversión|rendimiento|garantizad[oa]\s+\d|\d+%\s+de\s+(?:retorno|rendimiento))\b/i;
const PLAN_DETAIL_KEYWORDS = /\b(premium|deductible|copay|formulary|red de doctores|MOOP|MAPD|PDP)\b/i;

// Heurística para extraer el body del input según el tool.
function extractText(toolName, input) {
  switch (toolName) {
    case 'enviar_email':
      return { text: `${input.asunto || ''}\n\n${input.cuerpo || ''}`, target: input.para };
    case 'enviar_sms':
      return { text: input.mensaje || '', target: input.para };
    case 'mensaje_a_sami':
      return { text: input.mensaje || '', target: 'sami' };
    default:
      return null;
  }
}

// Revisión completa. Devuelve { ok, severidad_max, flags }.
// flags: [{ severidad, kind, nota, sugerencia? }]
export async function reviewOutbound({ toolName, input }) {
  const ext = extractText(toolName, input);
  if (!ext) return { ok: true, severidad_max: null, flags: [] };
  const { text, target } = ext;
  if (!text || text.length < 5) return { ok: true, severidad_max: null, flags: [] };

  const flags = [];

  // ---- Checks deterministas (no API) ----

  // 1. Consejo médico — solo si NO incluye el disclaimer
  if (MEDICAL_KEYWORDS.test(text)) {
    const hasDisclaimer = /no soy doctora|consulta(?:r|le|s)? (?:a|con) (?:tu|su) doctor|pregunta(?:le|s)? a tu doctor/i.test(text);
    if (!hasDisclaimer) {
      flags.push({
        severidad: 'alto',
        kind: 'medical_advice',
        nota: 'Sonido a consejo médico sin disclaimer.',
        sugerencia: 'Agrega "Yo no soy doctora — confirma con tu médico antes de cambiar nada."',
      });
    }
  }

  // 2. Consejo financiero — Isabel es Medicare, no asesora financiera
  if (FINANCIAL_KEYWORDS.test(text)) {
    flags.push({
      severidad: 'alto',
      kind: 'financial_advice',
      nota: 'Suena a consejo de inversión. Isabel es Medicare agent, no asesora financiera.',
      sugerencia: 'Reformula evitando "invertir", "rendimiento", porcentajes garantizados.',
    });
  }

  // 3. SOA gate para planes específicos a un cliente
  if (PLAN_DETAIL_KEYWORDS.test(text) && target && typeof target === 'string') {
    const soaCheck = await checkSoaFor(target);
    if (soaCheck && soaCheck.ok === false) {
      flags.push({
        severidad: 'alto',
        kind: 'soa_missing',
        nota: `Mensaje habla de detalles de plan a ${soaCheck.nombre} pero su SOA está "${soaCheck.estado}". CMS lo prohíbe.`,
        sugerencia: 'Pide la SOA firmada primero. No mandes detalles de plan hasta que esté.',
      });
    }
  }

  // 4. Vocabulario que Isabel no usa
  const lower = text.toLowerCase();
  for (const phrase of FORBIDDEN_PHRASES) {
    if (lower.includes(phrase)) {
      flags.push({
        severidad: 'aviso',
        kind: 'forbidden_vocab',
        nota: `Frase ajena al estilo de Isabel: "${phrase}".`,
        sugerencia: 'Reemplaza con algo más constructivo (su filosofía: "más completa, no más perfecta").',
      });
    }
  }

  // 5. CMS absolute claims — "el mejor plan", "garantizado", "100% gratis"
  if (CMS_FORBIDDEN_CLAIMS.test(text)) {
    flags.push({
      severidad: 'alto',
      kind: 'cms_absolute_claim',
      nota: 'Mensaje contiene claim absoluto prohibido por CMS (best/cheapest/guaranteed/100% gratis sin evidencia verificable).',
      sugerencia: 'Reformula con lenguaje específico ("puede convenirte porque…", "una opción a evaluar es…"). Sin superlatives sin data.',
    });
  }

  // 6. Disclaimer CMS — si el mensaje habla de planes específicos a un cliente
  // Y va por email (material promocional escrito), debe incluir el disclaimer
  // de no afiliación con el gobierno o nota de agente independiente licenciada.
  if (toolName === 'enviar_email' && PLAN_DETAIL_KEYWORDS.test(text) && text.length > 200) {
    if (!CMS_DISCLAIMER_FRAGMENTS.test(text)) {
      flags.push({
        severidad: 'aviso',
        kind: 'cms_disclaimer_missing',
        nota: 'Email con detalles de plan SIN disclaimer CMS de no-afiliación / agente independiente.',
        sugerencia: 'Agrega: "Isabel Fuentes es agente licenciada independiente. No está afiliada al gobierno federal ni al programa Medicare."',
      });
    }
  }

  // 7. Longitud — emails kilométricos son red flag
  if (toolName === 'enviar_email' && text.length > 1500) {
    flags.push({
      severidad: 'info',
      kind: 'length',
      nota: 'Email muy largo (>1500 chars). Isabel escribe corto.',
      sugerencia: 'Cortarlo a párrafos esenciales.',
    });
  }
  if (toolName === 'enviar_sms' && text.length > 320) {
    flags.push({
      severidad: 'aviso',
      kind: 'length',
      nota: 'SMS más de 2 segmentos (>320 chars). Costo dobla y se ve raro.',
      sugerencia: 'Cortar a 320 chars max.',
    });
  }

  // ---- Tono check con Haiku (solo si no hay alto crítico ya) ----
  const yaCritico = flags.some((f) => f.severidad === 'alto');
  if (!yaCritico && text.length > 30 && process.env.ANTHROPIC_API_KEY) {
    try {
      const tone = await toneReview(text, toolName, target);
      if (tone && tone.note) {
        flags.push({ severidad: 'aviso', kind: 'tone', nota: tone.note });
      }
    } catch { /* silencio — no bloqueamos por fallo de review */ }
  }

  const severidades = ['alto', 'aviso', 'info'];
  const maxIdx = Math.min(...flags.map((f) => severidades.indexOf(f.severidad)), severidades.length);
  const severidad_max = maxIdx === severidades.length ? null : severidades[maxIdx];
  return {
    ok: flags.length === 0,
    severidad_max,
    flags,
  };
}

async function checkSoaFor(target) {
  // target podría ser teléfono o email.
  // 1. CRM local (legacy — vacío desde el retiro en Fase 13.5, pero barato).
  const matches = findClient(target);
  if (matches?.length) {
    const c = matches[0];
    const estado = c.soa?.status || 'none';
    return { ok: estado === 'signed', estado, nombre: c.nombre };
  }
  // 2. LUNA — la fuente REAL de SOAs. Sin esto el gate de CMS era un no-op
  //    con el crm.json vacío (AUDIT.md H2). Excepción de infraestructura al
  //    boundary "LUNA solo vía coach luna", igual que voice.js: esto es un
  //    gate de compliance, no capa conversacional.
  try {
    const { lunaConfigured, searchMember } = await import('./luna_client.js');
    if (!lunaConfigured()) return null;
    const r = await searchMember(target);
    if (!r.ok || !Array.isArray(r.data) || !r.data.length) return null;
    const m = r.data[0];
    const estado = String(m.soa_status || 'none');
    return {
      ok: /signed|firmad/i.test(estado),
      estado,
      nombre: `${m.nombre || ''} ${m.apellido || ''}`.trim() || `miembro ${m.id}`,
    };
  } catch (e) {
    console.warn('[hooks] SOA check vía LUNA falló:', e.message);
    return null;
  }
}

async function toneReview(text, toolName, target) {
  const r = await anthropic.messages.create({
    model: 'claude-haiku-4-5-20251001',
    max_tokens: 120,
    messages: [{
      role: 'user',
      content: `Eres editora de tono. Isabel Fuentes es Medicare agent latina, 53 años, SoCal, escribe Spanglish cálido y directo. Su filosofía: "más completa, no más perfecta". Usa frases como "vamos paso a paso", "tú puedes con esto", "no te apures". NO usa: "no se puede", "imposible", lenguaje corporativo frío, exclamaciones de venta.

Revisa este borrador que va a ${toolName} (a "${target}"). ¿Suena a Isabel? Si SÍ, responde EXACTAMENTE "OK". Si NO, responde con UNA frase concreta de cambio (máx 25 palabras).

Borrador:
${text.slice(0, 1500)}

Respuesta:`,
    }],
  });
  const verdict = (r.content?.[0]?.text || '').trim();
  if (!verdict || verdict.startsWith('OK')) return null;
  return { note: verdict.slice(0, 200) };
}

// Formato corto humano-leíble para meter en el draft que ve Isabel.
export function formatReviewForHumans(review) {
  if (!review || review.ok) return '';
  const lines = [`Revisión: ${review.flags.length} señal(es)`];
  for (const f of review.flags) {
    const prefix = f.severidad === 'alto' ? '🛑' : f.severidad === 'aviso' ? '⚠️' : 'ℹ️';
    lines.push(`  ${prefix} [${f.kind}] ${f.nota}${f.sugerencia ? ` → ${f.sugerencia}` : ''}`);
  }
  return lines.join('\n');
}
