import { runDirectora } from './directora.js';
import { sendMessage } from './whatsapp.js';
import { getHistory, saveHistory } from './memory.js';
import { canSendProactive } from './proactive.js';
import { isAepNow } from './crm.js';

// Devuelve true si estamos a 30 días (o menos) del inicio del AEP
// (15 oct), del OEP (1 ene), o ya dentro del AEP. Sirve para activar
// el digest de noticias Medicare en el briefing.
function isAepWindow() {
  const now = new Date();
  if (isAepNow(now)) return true;
  const year = now.getFullYear();
  const aepStart = new Date(year, 9, 15);
  const daysToAep = (aepStart.getTime() - now.getTime()) / 86_400_000;
  if (daysToAep > 0 && daysToAep <= 30) return true;
  const oepStart = new Date(year + 1, 0, 1);
  const daysToOep = (oepStart.getTime() - now.getTime()) / 86_400_000;
  if (daysToOep > 0 && daysToOep <= 14) return true;
  return false;
}

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
  const aep = isAepWindow();
  const aepHint = aep ? `

CONTEXTO AEP: estamos en o cerca de AEP. INCLUYE un mini-digest Medicare hoy. Llama web_search con UNA query enfocada (ej: "Medicare news SCAN Humana Anthem ${new Date().getFullYear()} ${new Date().toLocaleString('en-US',{month:'short'})}" o "CMS Final Rule 2027 brokers"). Trae 1-2 datos accionables (cambio de tarifa, plan nuevo, regla nueva) — no un resumen genérico. Si web_search no devuelve nada relevante, salta el digest.` : '';

  // Phase 12: si anoche auto-propusiste alguna skill nueva, menciónala
  // en el briefing para que Isabel la apruebe o descarte conscientemente.
  let autoSkillHint = '';
  try {
    const { recentAutoDrafts } = await import('./skills.js');
    const drafts = recentAutoDrafts({ hoursBack: 24 });
    if (drafts.length) {
      const list = drafts.map((d) => `[${d.name}] — ${d.descripcion}`).join('\n  ');
      autoSkillHint = `

NUEVAS SKILLS AUTO-PROPUESTAS (anoche detecté patrones y armé drafts — mencionaselos a Isabel UNA vez, breve, al final, para que apruebe o descarte):
  ${list}`;
    }
  } catch { /* ignore */ }

  messages.push({
    role: 'user',
    content: `[BRIEFING AUTOMÁTICO DE LA MAÑANA — ${fecha}] Salúdame con energía y dame tu mejor lectura del día.

PRIMERO consulta señales_de_hoy (yo las computé anoche a las 2am — incluyen umbrales como "no peso en 4 días", patrones como "cansada x3 esta semana" y estados como "5 renovaciones en 30 días"). Trae arriba LAS DOS o TRES más relevantes — no todas. Usa las severidades alto > aviso > info para decidir.

SEGUNDO, consulta gaps_overview con solo_severidad="alto" — son cosas QUE NO SÉ todavía pero debería (MBI no verificado, SOA faltante, TCPA sin consentir, sin touchpoint en 12+ meses). Si hay 1-3 huecos altos, méteme UNO como propuesta de "cerralo hoy" — concreto, específico, con la persona y la acción. No me leas la lista — escoge el más doloroso.

TERCERO, recuérdame mis prioridades pendientes (tareas mías abiertas) y los compromisos que me deben.

CUARTO, pregúntame mis Top 3 de hoy.

FORMATO — VISUAL CARDS (importante):
Quiero el briefing dividido en 3-4 CARDS scannable, separadas por el divisor exacto "═════". Cada card es UN tema, máx 4 líneas:
  Card 1: Saludo + estado (1-3 líneas, lectura de cómo estoy o cómo viene el día)
  Card 2: Señales destacadas (1-3 señales prioritarias)
  Card 3: El gap más doloroso + propuesta para cerrarlo HOY
  Card 4: Tareas pendientes + tu pregunta "¿Top 3?"
Usa el divisor "═════" (5 carácteres ═) literal entre cada card. NADA antes de Card 1, NADA después de Card 4.

Sé breve, cálida, motivadora. Spanglish. Esto se manda solo — no esperes que yo haya dicho nada antes. Si hay alta señal de cansancio/estrés, baja el tono y empieza por ahí en vez de la lista.${aepHint}${autoSkillHint}`,
  });

  const { reply, messages: updated } = await runDirectora(messages);
  saveHistory(updated);
  // Visual cards: separar el reply en 3-4 mensajes WhatsApp.
  // Si el split no encuentra el divisor (Athena ignoró el format),
  // cae a 1 solo mensaje — fallback graceful.
  const cards = splitCards(reply);
  for (let i = 0; i < cards.length; i++) {
    await sendMessage(to, cards[i]);
    if (i < cards.length - 1) {
      await new Promise((r) => setTimeout(r, 1500)); // 1.5s entre cards
    }
  }
  console.log(`[briefing] Enviado a Isabel (${cards.length} card${cards.length > 1 ? 's' : ''}).`);
}

// Divide la respuesta en cards por el divisor ═════.
// Limpia espacios, descarta cards vacías, fallback a 1 si no hay split.
function splitCards(text) {
  if (!text) return [];
  const parts = text
    .split(/═{4,}/g)
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
  return parts.length ? parts : [text.trim()];
}

// Permite correrlo directo: `node src/briefing.js`
if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  await sendMorningBriefing();
  process.exit(0);
}
