import { writeFileSync, readFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runDirectora } from './directora.js';
import { sendMessage } from './whatsapp.js';
import { getHistory, saveHistory } from './memory.js';
import { canSendProactive } from './proactive.js';
import { isAepNow } from './crm.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const BRIEFING_FILE = join(__dirname, '..', 'data', 'briefing_today.json');

// La fecha "hoy" tiene que ser la fecha LOCAL de Isabel (PT), no UTC.
// Bug histórico: usar toISOString().slice(0,10) producía UTC, así que
// después de las ~5pm PT el briefing del mismo día se marcaba stale y
// el PWA dejaba de mostrarlo (UTC ya había cruzado a mañana).
function todayLocal() {
  const tz = process.env.TIMEZONE || 'America/Los_Angeles';
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date()); // 'YYYY-MM-DD'
}

function saveBriefingToDisk(cards) {
  try {
    if (!existsSync(dirname(BRIEFING_FILE))) mkdirSync(dirname(BRIEFING_FILE), { recursive: true });
    writeFileSync(BRIEFING_FILE, JSON.stringify({
      date: todayLocal(),
      generated_at: new Date().toISOString(),
      cards,
    }, null, 2));
  } catch (e) { console.warn('[briefing] no se pudo guardar a disco:', e.message); }
}

export function loadTodayBriefing() {
  try {
    if (!existsSync(BRIEFING_FILE)) return null;
    const data = JSON.parse(readFileSync(BRIEFING_FILE, 'utf8'));
    if (data.date !== todayLocal()) return { ...data, stale: true };
    return data;
  } catch { return null; }
}

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

  // Equipo: si hay compromisos del equipo vencidos o por vencer hoy,
  // pasa el block al prompt para que Athena se lo presente a Isabel.
  // Es el peso de 2h/día que Isabel ya no carga.
  let teamHint = '';
  try {
    const { buildTeamBriefingBlock } = await import('./team.js');
    const teamBlock = buildTeamBriefingBlock();
    if (teamBlock) {
      teamHint = `\n\nEQUIPO HOY (CRÍTICO — esto es lo que ANTES Isabel andaba recordándoles ella misma; ahora lo cargas tú):\n${teamBlock}\n\nIncluye UNA card específica del equipo en el briefing, mencionando POR NOMBRE quién tiene qué pendiente. Si hay vencidos, ofrécete a recordárselo (mensaje_a_sami o ticket en LUNA vía consultar_especialistas con especialista='luna'). Isabel YA NO debería andar repitiendo cosas a su equipo.`;
    }
  } catch { /* ignore */ }

  // Relaciones cadence: surface family/friends que se están enfriando
  let cadenceHint = '';
  try {
    const { buildCadenceBlock } = await import('./entities.js');
    const block = buildCadenceBlock();
    if (block) {
      cadenceHint = `\n\nRELACIONES PERSONALES (cadence — UNA persona con quien NO has hablado en 14+ días):\n${block}\n\nMenciona UNA sola (la más alta salience). Pregúntale a Isabel si quiere que armes algo simple — "te llamo a tu mamá esta tarde" / "saludas a tu hijo por WhatsApp".`;
    }
  } catch { /* ignore */ }

  // Trust score: la lectura de "puedes soltarte hoy" o "necesitas estar"
  let trustHint = '';
  try {
    const { buildTrustBriefingBlock } = await import('./trust_score.js');
    const block = buildTrustBriefingBlock();
    if (block) {
      trustHint = `\n\nTRUST SCORE DE HOY (tu lectura compuesta de si el negocio se está manejando solo):\n${block}\n\nÚSALO para CALIBRAR el tono. Si veredicto = autopilot → tono ligero, "tu día es tuyo". Si = revisa puntos → enfócate en los 2-3 concretos. Si = necesita Isabel → tono serio, pídele presencia. Menciona el score Y el veredicto en Card 1.`;
    }
  } catch { /* ignore */ }

  // Focus blocks de hoy — tiempo que Isabel se protegió (lectura, piano, gym)
  let focusHint = '';
  try {
    const { buildFocusBriefingBlock } = await import('./focus_blocks.js');
    const block = buildFocusBriefingBlock();
    if (block) {
      focusHint = `\n\nBLOQUES PROTEGIDOS DE HOY (tiempo que ella se reservó — NO le sumes carga ni propongas reuniones encima):\n${block}\n\nMenciónaselos UNA línea en Card 1 o 4 como recordatorio de que respetas ese tiempo.`;
    }
  } catch { /* ignore */ }

  // Rutinas del día (morning ritual, meal prep, etc.)
  let routinesHint = '';
  try {
    const { buildRoutinesBriefingBlock } = await import('./routines.js');
    const block = buildRoutinesBriefingBlock();
    if (block) {
      routinesHint = `\n\nRUTINAS DE HOY (lo que toca por recurrencia):\n${block}\n\nSi alguna lleva 0 pasos y su hora_inicio se acerca, ofrécete a pingearla con el primer paso cuando llegue.`;
    }
  } catch { /* ignore */ }

  // Legal calendar — obligaciones regulatorias (license, AHIP, taxes, etc.)
  let legalHint = '';
  try {
    const { buildLegalBriefingBlock } = await import('./legal.js');
    const block = buildLegalBriefingBlock();
    if (block) {
      legalHint = `\n\nLEGAL — OBLIGACIONES REGULATORIAS PRÓXIMAS:\n${block}\n\nSi hay VENCIDAS o ≤7 días, INCLUYE una card específica con la más crítica y propone acción concreta (renew license / book CE / pagar tax). Esto es paz mental real para Isabel — no la dejes descubrirlo tarde.`;
    }
  } catch { /* ignore */ }

  // Planes vigentes de coaches — para que Athena pueda preguntar adherencia
  // en el briefing matutino. Ya viene en wikiContext, pero acá le damos un
  // empujón explícito para que LO USE en lugar de solo verlo.
  let plansHint = '';
  try {
    const { buildAllPlansInline } = await import('./coach_plans.js');
    const { SPECIALISTS } = await import('./agents.js');
    const block = buildAllPlansInline((id) => SPECIALISTS[id]?.name || id);
    if (block) {
      plansHint = `\n\nPLANES VIGENTES DE LAS COACHES — léelos y, si alguno tiene más de 5 días sin que Isabel mencione cómo va, INCLUYE UNA línea de adherencia en alguna card. Ejemplo: "Sofía te recomendó D3 hace 3 sem — ¿cómo te has sentido?". Una sola coach por briefing, la más relevante hoy. NO recites el plan completo.`;
    }
  } catch { /* ignore */ }

  // Coach check-ins de hoy (cadencias programadas)
  let cadenceHintCoaches = '';
  try {
    const { buildCoachCadenceBriefingBlock } = await import('./coach_cadence.js');
    const block = buildCoachCadenceBriefingBlock();
    if (block) {
      cadenceHintCoaches = `\n\nCHECK-INS DE COACHES PROGRAMADOS PARA HOY:\n${block}\n\nMenciónaselos UNA línea al final con la pregunta "¿quieres que te abra el chat con [coach] o lo dejamos para más tarde?". NO la presiones — si responde "no", queda registrado como saltado.`;
    }
  } catch { /* ignore */ }

  // Brand pipeline — qué se publica en próximas 72h
  let brandHint = '';
  try {
    const { buildBrandBriefingBlock } = await import('./brand.js');
    const block = buildBrandBriefingBlock();
    if (block) {
      brandHint = `\n\nBRAND — PRÓXIMAS 72H:\n${block}\n\nSi hay algo en estado "grabando" o "editando" hoy, recuérdaselo. Si nada está agendado y la última semana también fue blank, menciónaselo a Marisol vía consulta (la cadencia de YouTube se rompió).`;
    }
  } catch { /* ignore */ }

  // Mejoras al código que Athena propuso (esperando que Isabel apruebe/descarte)
  let improvementsHint = '';
  try {
    const { buildImprovementsBriefingBlock } = await import('./improvements.js');
    const block = buildImprovementsBriefingBlock();
    if (block) {
      improvementsHint = `\n\nMEJORAS AL CÓDIGO QUE YO (ATHENA) PROPUSE (esperando review de Isabel):\n${block}\n\nMencionaselas UNA línea al final con el número de issue. Si Isabel dice "aprueba la X" o "descarta la Y" usá mis_mejoras_propuestas y mejora_status para marcar — Claude Code recoge del GitHub.`;
    }
  } catch { /* ignore */ }

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

PRIMERO consulta senales_de_hoy (yo las computé anoche a las 2am — incluyen umbrales como "no peso en 4 días", patrones como "cansada x3 esta semana" y estados como "5 renovaciones en 30 días"). Trae arriba LAS DOS o TRES más relevantes — no todas. Usa las severidades alto > aviso > info para decidir.

SEGUNDO, del contexto del equipo y las señales, escoge LO MÁS ACCIONABLE de hoy (un pendiente de compliance, un cliente sin seguimiento, una cita que necesita prep) y méteme UNA propuesta de "ciérralo hoy" — concreta, específica, con la persona y la acción. No me leas listas — escoge lo más doloroso. Si necesitas detalle del CRM, consulta a la coach luna vía consultar_especialistas.

TERCERO, recuérdame mis prioridades pendientes (tareas mías abiertas) y los compromisos que me deben.

CUARTO, pregúntame mis Top 3 de hoy.

FORMATO — VISUAL CARDS (importante):
Quiero el briefing dividido en 3-4 CARDS scannable, separadas por el divisor exacto "═════". Cada card es UN tema, máx 4 líneas:
  Card 1: Saludo + estado (1-3 líneas, lectura de cómo estoy o cómo viene el día)
  Card 2: Señales destacadas (1-3 señales prioritarias)
  Card 3: Lo más accionable de hoy + propuesta para cerrarlo HOY
  Card 4: Tareas pendientes + tu pregunta "¿Top 3?"
Usa el divisor "═════" (5 carácteres ═) literal entre cada card. NADA antes de Card 1, NADA después de Card 4.

Sé breve, cálida, motivadora. Spanglish. Esto se manda solo — no esperes que yo haya dicho nada antes. Si hay alta señal de cansancio/estrés, baja el tono y empieza por ahí en vez de la lista.${aepHint}${teamHint}${cadenceHint}${cadenceHintCoaches}${trustHint}${focusHint}${routinesHint}${legalHint}${brandHint}${improvementsHint}${autoSkillHint}${plansHint}`,
  });

  // Briefing matutino vale Opus — es el ancla del día y la calidad importa.
  let reply;
  let updated;
  try {
    const r = await runDirectora(messages, { tier: 'default' });
    reply = r.reply;
    updated = r.messages;
    saveHistory(updated);
  } catch (e) {
    console.error('[briefing] runDirectora falló:', e.message);
    // No silencio: avisa a Isabel que el briefing se cayó en vez de
    // dejarla sin nada. Mejor SOS visible que ausencia silenciosa.
    await sendMessage(to, `Buenos días, Isabel. Mi briefing matutino se cayó (${e.message.slice(0, 80)}). Estoy aquí — pregúntame "¿qué tengo hoy?" y te respondo manual.`);
    return;
  }
  // Visual cards: separar el reply en 3-4 mensajes WhatsApp.
  // Si el split no encuentra el divisor (Athena ignoró el format),
  // cae a 1 solo mensaje — fallback graceful.
  const cards = splitCards(reply);
  if (!cards.length || cards.every((c) => !c || c.length < 10)) {
    // Athena devolvió vacío o solo divisores. No la dejes sin briefing.
    console.warn('[briefing] reply vacío o trivial — mandando SOS');
    await sendMessage(to, `Buenos días, Isabel. Mi briefing salió vacío hoy — algo se cortó en el prompt. Pregúntame "¿qué tengo hoy?" y te armo el día manual.`);
    return;
  }
  // Persistir en disco para que el PWA lo muestre en Hoy.
  saveBriefingToDisk(cards);
  for (let i = 0; i < cards.length; i++) {
    await sendMessage(to, cards[i]);
    if (i < cards.length - 1) {
      await new Promise((r) => setTimeout(r, 1500)); // 1.5s entre cards
    }
  }
  console.log(`[briefing] Enviado a Isabel (${cards.length} card${cards.length > 1 ? 's' : ''}).`);
  // Push al PWA — title con la primera línea de la card 1, body con preview
  try {
    const { sendToAll, pushEnabled } = await import('./push.js');
    if (pushEnabled()) {
      const first = (cards[0] || '').split('\n').filter(Boolean);
      const title = first[0]?.slice(0, 80) || 'Briefing matutino';
      const body = first.slice(1).join(' ').slice(0, 140) || `Tu briefing de ${fecha}`;
      await sendToAll({ title, body, url: '/app/hoy', tag: 'briefing' });
    }
  } catch (e) { console.warn('[push] briefing ping falló:', e.message); }
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
