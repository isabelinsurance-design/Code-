// ============================================================
//  Mensajes proactivos de Athena (los que ELLA inicia)
//  - Quiet hours: 9pm-7am no se manda nada (TZ de Isabel)
//  - Tope diario: 1 briefing programado + máx 3 mensajes sin solicitar
//  - Todos los jobs proactivos pasan por canSendProactive()
// ============================================================
import { runDirectora } from './directora.js';
import { sendMessage } from './whatsapp.js';
import {
  getHistory,
  saveHistory,
  getProactiveCount,
  bumpProactiveCount,
  remember,
} from './memory.js';

const TZ = () => process.env.TIMEZONE || 'America/Los_Angeles';
const QUIET_START = 21; // 9pm
const QUIET_END = 7;    // 7am
const DAILY_CAP = 4;    // 1 briefing + 3 más

function nowParts() {
  // hora local en la TZ de Isabel
  const fmt = new Intl.DateTimeFormat('en-US', {
    timeZone: TZ(),
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    hour12: false,
  });
  const parts = Object.fromEntries(fmt.formatToParts(new Date()).map((p) => [p.type, p.value]));
  return {
    dayKey: `${parts.year}-${parts.month}-${parts.day}`,
    hour: parseInt(parts.hour, 10),
  };
}

function isQuietHour(hour) {
  // 21,22,23,0,1,2,3,4,5,6
  return hour >= QUIET_START || hour < QUIET_END;
}

// Decide si Athena puede mandar un mensaje proactivo AHORA.
// `force` = el job es un horario fijo crítico (briefing/evening),
// se ignora el cap pero NO las quiet hours.
export function canSendProactive({ force = false } = {}) {
  const { dayKey, hour } = nowParts();
  if (isQuietHour(hour)) {
    return { ok: false, reason: `quiet hours (${hour}:00 en ${TZ()})` };
  }
  if (force) return { ok: true, dayKey };
  const count = getProactiveCount(dayKey);
  if (count >= DAILY_CAP) {
    return { ok: false, reason: `tope diario (${count}/${DAILY_CAP}) alcanzado` };
  }
  return { ok: true, dayKey };
}

// Helper compartido: corre a Athena con un mensaje sintético y manda el reply.
async function runProactive(syntheticUserMessage, prefix = '') {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) {
    console.warn('[proactive] No hay ISABEL_WHATSAPP configurado.');
    return null;
  }
  const messages = getHistory();
  messages.push({ role: 'user', content: syntheticUserMessage });
  const { reply, messages: updated } = await runDirectora(messages);
  saveHistory(updated);
  await sendMessage(to, prefix ? `${prefix} ${reply}` : reply);
  return reply;
}

// ---- Cierre del día (9pm aprox — ANTES de quiet hours) ----
// Pregunta 3 wins + 1 para mañana. Captura logros, siembra prioridades.
export async function sendEveningCheckin() {
  const gate = canSendProactive({ force: true });
  if (!gate.ok) {
    console.log(`[evening] saltado: ${gate.reason}`);
    return;
  }
  bumpProactiveCount(gate.dayKey);
  const fecha = new Date().toLocaleDateString('es-MX', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: TZ(),
  });
  await runProactive(
    `[CIERRE DE DÍA AUTOMÁTICO — ${fecha}] Pregúntale 3 wins de hoy + 1 cosa para mañana. Corto, cálido, sin presión. Si responde, guarda los wins con la herramienta recordar (con el prefijo "Win: ") y la cosa de mañana como "Para mañana: ". Esto se manda solo — no esperes que ella haya hablado primero.`,
  );
  console.log('[evening] check-in enviado.');
}

// ---- Revisión semanal (Domingo 6pm) ----
export async function sendWeeklyReview() {
  const gate = canSendProactive({ force: true });
  if (!gate.ok) {
    console.log(`[weekly] saltado: ${gate.reason}`);
    return;
  }
  bumpProactiveCount(gate.dayKey);
  await runProactive(
    `[REVISIÓN SEMANAL AUTOMÁTICA] Es domingo. Genera una revisión semanal corta basada en tu memoria + lo que conversamos esta semana: (1) 3 wins concretos de la semana, (2) 1 patrón que notaste en mí, (3) 1 pregunta para la semana que viene. Termina con "¿Cuáles son tus 3 prioridades para la semana que entra?". Esto se manda solo, no esperes input.`,
  );
  console.log('[weekly] revisión enviada.');
}

// ---- Reflexión nocturna (2am — NO se manda a Isabel) ----
// 2026 best practice ("Dreaming"): la fase profunda no solo
// captura — también CONSOLIDA. Tres trabajos:
//   1) Extract: wins/decisiones/preferencias nuevas → recordar
//   2) Entities: nombres de personas mencionadas → entidad_anotar
//   3) Consolidate: revisa la wiki, marca contradicciones y
//      pide bajar saliencia de notas obsoletas
//   4) Signals: corre computeSignals() al final
// No manda mensaje. Trabajo interno de memoria + cómputo de
// señales para el briefing de mañana.
export async function nightlyReflection() {
  const messages = getHistory();
  if (!messages.length) {
    console.log('[reflection] sin historial — solo cómputo de señales.');
    const { computeSignals } = await import('./signals.js');
    const sigs = computeSignals();
    console.log(`[reflection] ${sigs.length} señales computadas.`);
    return;
  }
  messages.push({
    role: 'user',
    content: `[REFLEXIÓN NOCTURNA — NO le respondas a Isabel] Es 2am, fase profunda.
TRABAJOS QUE TIENES QUE HACER (en este orden):

1) EXTRACT — Revisa los últimos turnos. Identifica MÁXIMO 5 cosas para memoria de largo plazo: wins concretos, decisiones, preferencias nuevas, patrones emocionales, pendientes. Llama recordar para cada uno con una frase clara. Si solo fue small talk: no guardes y dilo.

2) ENTIDADES — Identifica nombres de PERSONAS que se mencionaron (clientes, familia, vendors, brokers, doctores, amigas). Para cada una llama entidad_anotar con tipo y una nota corta sobre qué pasó hoy con esa persona. Si Isabel ya tiene un cliente en el CRM con ese nombre, la entidad se vincula automáticamente — no te preocupes por duplicar.

3) CONTRADICCIONES — Mira tu wiki actual (lo tienes en el contexto). ¿Algo que aprendiste hoy contradice algo viejo? Ej: peso anterior 178, hoy 174 → guarda el nuevo y NO toques el viejo (el histórico importa). Pero si una preferencia cambió ("ya no le gusta X"), llama olvidar con la nota vieja y recordar con la nueva. Sé conservadora: solo olvidar si Isabel lo dijo EXPLÍCITAMENTE.

4) Termina con un resumen de 3-4 líneas de qué guardaste, qué entidades reconociste, y qué consolidaste. Eso es todo. NO le mandes mensaje a Isabel.`,
  });
  const { reply, messages: updated } = await runDirectora(messages);
  // Recorte agresivo del historial: nos quedamos con los últimos 5 días.
  saveHistory(updated.slice(-40));

  // Computa señales DESPUÉS de la reflexión para que las nuevas notas
  // y entidades alimenten los thresholds y patterns.
  try {
    const { computeSignals } = await import('./signals.js');
    const sigs = computeSignals();
    console.log(`[reflection] ${sigs.length} señales computadas para el briefing.`);
  } catch (err) {
    console.warn('[reflection] cómputo de señales falló:', err.message);
  }
  console.log('[reflection] resumen:', String(reply).slice(0, 200));
}

// Permite probarlos a mano:
//   node src/proactive.js evening
//   node src/proactive.js weekly
//   node src/proactive.js reflection
if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  const cmd = process.argv[2];
  const map = { evening: sendEveningCheckin, weekly: sendWeeklyReview, reflection: nightlyReflection };
  const fn = map[cmd];
  if (!fn) {
    console.error('Uso: node src/proactive.js [evening|weekly|reflection]');
    process.exit(1);
  }
  await fn();
  process.exit(0);
}
