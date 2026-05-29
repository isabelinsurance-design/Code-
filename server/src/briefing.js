import { runDirectora } from './directora.js';
import { sendMessage } from './whatsapp.js';
import { getHistory, saveHistory } from './memory.js';
import { canSendProactive } from './proactive.js';

// El briefing de la mañana: Athena le escribe a Isabel SIN que
// Isabel tenga que abrir nada. Esto es lo que la hace "autónoma".
// Lo dispara el cron en index.js (o puedes correrlo a mano con
// `npm run briefing`).
export async function sendMorningBriefing() {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) {
    console.warn('[briefing] No hay ISABEL_WHATSAPP configurado.');
    return;
  }
  // El briefing es "force": pasa por encima del cap diario porque es
  // el único mensaje crítico del día. Pero sigue respetando quiet hours.
  const gate = canSendProactive({ force: true });
  if (!gate.ok) {
    console.log(`[briefing] saltado: ${gate.reason}`);
    return;
  }

  const fecha = new Date().toLocaleDateString('es-MX', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: process.env.TIMEZONE || 'America/Los_Angeles',
  });

  const messages = getHistory();
  messages.push({
    role: 'user',
    content: `[BRIEFING AUTOMÁTICO DE LA MAÑANA — ${fecha}] Salúdame con energía y dame tu mejor lectura del día.

PRIMERO consulta señales_de_hoy (yo las computé anoche a las 2am — incluyen umbrales como "no peso en 4 días", patrones como "cansada x3 esta semana" y estados como "5 renovaciones en 30 días"). Trae arriba LAS DOS o TRES más relevantes — no todas. Usa las severidades alto > aviso > info para decidir.

SEGUNDO, recuérdame mis prioridades pendientes (tareas mías abiertas) y los compromisos que me deben.

TERCERO, pregúntame mis Top 3 de hoy.

Sé breve, cálida, motivadora. Spanglish. Esto se manda solo — no esperes que yo haya dicho nada antes. Si hay alta señal de cansancio/estrés, baja el tono y empieza por ahí en vez de la lista.`,
  });

  const { reply, messages: updated } = await runDirectora(messages);
  saveHistory(updated);
  await sendMessage(to, reply);
  console.log('[briefing] Enviado a Isabel.');
}

// Permite correrlo directo: `node src/briefing.js`
if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  await sendMorningBriefing();
  process.exit(0);
}
