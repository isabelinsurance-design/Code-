import { runDirectora } from './directora.js';
import { sendMessage } from './whatsapp.js';
import { getHistory, saveHistory } from './memory.js';

// El briefing de la mañana: La Directora le escribe a Isabel SIN que
// Isabel tenga que abrir nada. Esto es lo que la hace "autónoma".
// Lo dispara el cron en index.js (o puedes correrlo a mano con
// `npm run briefing`).
export async function sendMorningBriefing() {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) {
    console.warn('[briefing] No hay ISABEL_WHATSAPP configurado.');
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
    content: `[BRIEFING AUTOMÁTICO DE LA MAÑANA — ${fecha}] Salúdame con energía, recuérdame mis prioridades y pregúntame mis Top 3 de hoy. Si tienes contexto de días anteriores en tu memoria, úsalo. Sé breve y motivadora. Esto se manda solo, así que no esperes que yo haya dicho nada antes.`,
  });

  const { reply, messages: updated } = await runDirectora(messages);
  saveHistory(updated);
  await sendMessage(to, `☀️ ${reply}`);
  console.log('[briefing] Enviado a Isabel.');
}

// Permite correrlo directo: `node src/briefing.js`
if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  await sendMorningBriefing();
  process.exit(0);
}
