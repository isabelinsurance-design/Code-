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
// Athena se sienta a "procesar" el día: lee la conversación reciente,
// identifica wins/aprendizajes/patrones, y los guarda con la herramienta
// recordar. No manda ningún mensaje. Es trabajo interno de memoria.
export async function nightlyReflection() {
  // Cuidado: este corre a las 2am, dentro de quiet hours.
  // Por diseño NO manda mensajes — solo actualiza la memoria
  // a través de la herramienta `recordar`. canSendProactive
  // no aplica aquí.
  const messages = getHistory();
  if (!messages.length) {
    console.log('[reflection] sin historial — nada que reflexionar.');
    return;
  }
  messages.push({
    role: 'user',
    content: `[REFLEXIÓN NOCTURNA — NO le respondas a Isabel] Es 2am y estás procesando el día. Revisa los últimos turnos de nuestra conversación. Identifica MÁXIMO 5 datos que valga la pena consolidar en la memoria de largo plazo: wins concretos, decisiones, preferencias nuevas, patrones emocionales, pendientes que mencionó. Para cada uno llama la herramienta recordar con una frase clara. Si no hay nada nuevo de fondo (solo small talk), no guardes nada y dilo. NO mandes mensaje a Isabel — esta reflexión es solo para ti. Responde con un resumen corto de qué guardaste.`,
  });
  // OJO: corremos runDirectora pero NO mandamos el reply al WhatsApp.
  // El side-effect importante son los logActivity + remember.
  const { reply, messages: updated } = await runDirectora(messages);
  // Recortamos historial: nos quedamos con los últimos 7 días aproximados.
  // (40 turnos = unos 5 días de uso normal, suficiente.)
  saveHistory(updated.slice(-40));
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
