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
  // Focus blocks bloquean proactivo incluso con force (excepto briefing crítico)
  try {
    // Sync require workaround: ESM no permite require, pero podemos check con
    // globalThis cache si focus_blocks.js lo expone. Mejor: import dinámico
    // con cache via globalThis para no romper sync nature de canSendProactive.
    const fb = globalThis.__focusBlocksCheck;
    if (typeof fb === 'function') {
      const block = fb();
      if (block && !force) {
        return { ok: false, reason: `focus block activo: "${block.titulo}" (${block.modo}) hasta ${block.fin_hhmm}` };
      }
    }
  } catch { /* ignore */ }
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
  const finalText = prefix ? `${prefix} ${reply}` : reply;
  await sendMessage(to, finalText);
  // También ping al PWA si está suscrito — notif nativa en iPhone.
  try {
    const { sendToAll, pushEnabled } = await import('./push.js');
    if (pushEnabled()) {
      // primer renglón como título; resto como body, máx 140 chars
      const lines = finalText.split('\n').filter(Boolean);
      const title = lines[0]?.slice(0, 80) || 'Athena';
      const body = lines.slice(1).join(' ').slice(0, 140);
      await sendToAll({ title, body, url: '/app/hoy', tag: 'proactive' });
    }
  } catch (e) { console.warn('[push] proactive ping falló:', e.message); }
  return reply;
}

// ---- Cierre del día (9pm aprox — ANTES de quiet hours) ----
// Phase 12: arranca con stats reales del día. El blurb concreto da
// a Athena material honesto para los wins en vez de invitarla a
// inventar.
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
  // Pide los stats antes de la conversación para que Athena los use.
  let statsBlurb = '';
  try {
    const { dailyStatsBlurb } = await import('./stats.js');
    statsBlurb = dailyStatsBlurb();
  } catch { /* ignore */ }
  // EOD del equipo — si reportaron, Athena los menciona honestos.
  let eodBlock = '';
  try {
    const { buildEodSummary } = await import('./team_eod.js');
    const s = buildEodSummary();
    if (s) {
      eodBlock = `\n\nEQUIPO HOY (REPORTES REALES, NO INVENTES):\n${s.summary}\n\nSi hay problemas flageados arriba (🚨), MENCIÓNALOS a Isabel claramente — eso requiere su atención mañana o esta noche.`;
    }
  } catch { /* ignore */ }
  await runProactive(
    `[CIERRE DE DÍA AUTOMÁTICO — ${fecha}]

DATOS DEL DÍA (úsalos para que los wins sean honestos, NO inventes):
${statsBlurb}${eodBlock}

INSTRUCCIONES:
- Salúdame brevemente y reconóceme algún dato concreto de los stats si hay (ej. "tocaste 4 clientes hoy, eso es disciplina").
- Si el equipo reportó EOD, dame el resumen agregado en UNA frase corta (ej. "3/4 reportaron, total 47 llamadas, 1 problema con Sami que Carlos quedó confundido por la SOA").
- Si hay PROBLEMAS flageados por el equipo, dilo claro al inicio — eso pesa más que los wins.
- Pregúntame 3 wins de hoy + 1 cosa para mañana.
- Tono: corto, cálido, sin presión. Sin listas — frases continuas.
- Si te respondo con wins, guárdalos con recordar (prefijo "Win: ") y la cosa de mañana como "Para mañana: ".
- Si los stats son cero todo, no me regañes — di algo como "día tranquilo en data, pero el valor no siempre está en los números".

Esto se manda solo, no esperes que yo haya dicho nada.`,
  );
  console.log('[evening] check-in enviado.');
}

// ---- Trend scan (diario 11am) ----
// Corre el scout de virales / trending en los 6 lentes de Isabel
// (Medicare, brand, salud 50+, productividad, wealth + chief_of_staff
// meta). Si encuentra hits con score ≥ 8, hace un proactive ping con
// los top 1-2. Si no, solo deja el dump para que ella lo vea en /trends.
export async function dailyTrendScan() {
  try {
    const { runTrendScan } = await import('./trends.js');
    const r = await runTrendScan();
    if (!r.highScore.length) {
      console.log('[trends] sin hits score≥8 hoy — no proactive ping.');
      return;
    }
    const gate = canSendProactive();
    if (!gate.ok) {
      console.log(`[trends] saltado proactive ping: ${gate.reason}`);
      return;
    }
    bumpProactiveCount(gate.dayKey);
    const top = r.highScore.slice(0, 2);
    const blurb = top.map((h) => {
      const icon = h.topic_id === 'chief_of_staff' ? '⚙️' : '🔥';
      return `${icon} [${h.topic_nombre}] ${h.titulo}\n${h.summary}\n→ ${h.razon_isabel}`;
    }).join('\n\n');
    const hasMeta = top.some((h) => h.topic_id === 'chief_of_staff');
    await runProactive(
      `[TREND SCAN DIARIO — ${top.length} hit(s) high score]

${blurb}

INSTRUCCIONES:
- Salúdame UNA línea y dame el digest arriba ADAPTADO a mi voz.
${hasMeta ? '- HAY un hit de la lente META (Chief of Staff — cómo MEJORAR Athena+Isabel). Mencionálo CLARO: "Encontré algo sobre cómo podríamos mejorar nuestro sistema". Si Isabel aprueba el cambio, crea tarea para Sami con responsable=sami para implementar.\n' : ''}- Pregúntame si alguno me interesa para profundizar.
- Si digo "sí el de X", abre la fuente con web_search o pásamelo a la coach relevante (Marisol para brand, Sofía para health, etc.). Para hits de Chief of Staff: propón cómo implementar el cambio (tarea concreta).
- Si digo "no" o "después", márcalos en mi /trends para revisar después.
- Sé breve. Estos son SCOUT signals, no análisis completo.`,
    );
    console.log(`[trends] proactive enviado con ${top.length} hit(s).`);
  } catch (err) {
    console.warn('[trends] dailyTrendScan error:', err.message);
  }
}

// ---- Rapport semanal (viernes 6pm) ----
// Ping con WhatsApp pidiendo peso/medidas/foto/sentires. Lo que Isabel
// responda, Athena lo procesa y lo guarda con rapport_semanal. Sin
// presión — si Isabel solo manda peso y "todo bien", igual queda
// registrado para el trend.
export async function sendWeeklyRapport() {
  const gate = canSendProactive({ force: true });
  if (!gate.ok) {
    console.log(`[rapport] saltado: ${gate.reason}`);
    return;
  }
  bumpProactiveCount(gate.dayKey);
  let trendBlurb = '';
  try {
    const { rapportTrend } = await import('./rapport.js');
    const t = rapportTrend();
    if (t && t.latest && t.latest.peso_lbs) {
      const parts = [`Última semana registraste ${t.latest.peso_lbs} lbs`];
      if (t.delta_4w !== null) parts.push(`(${t.delta_4w > 0 ? '+' : ''}${t.delta_4w} vs hace 4 sem)`);
      trendBlurb = parts.join(' ') + '.';
    }
  } catch { /* ignore */ }
  await runProactive(
    `[RAPPORT SEMANAL AUTOMÁTICO — viernes]

${trendBlurb}

INSTRUCCIONES:
- Salúdame breve y cálida — es viernes, semana cerrando.
- Pídeme rapport semanal: peso (lbs), medidas si quiero (cintura/cadera/brazo/muslo en pulgadas), foto si quiero mandar, y CÓMO ME SIENTO esta semana (energía, sueño, periodo si aplica, ánimo).
- NO me presiones — si solo te mando peso y "todo bien", está bien.
- Cuando te conteste con los datos, llama rapport_semanal con lo que recibí. Si mandé foto, pasa la URL en foto_url (si Twilio te la dio).
- Si hay delta de peso significativo vs hace 4 sem (más de 2 lbs), coméntalo SIN drama — solo señalalo.
- Después del registro, ofrécete a consultar Sofía o Rivera si quiero feedback sobre la semana.

Esto se manda solo. Tono: amiga que pregunta por ti, no doctor que checa.`,
  );
  console.log('[rapport] semanal enviado.');
}

// ---- Research digest (mediodía) ----
// Athena rotó cada tema activo de research.js, hizo web_search, y
// sintetiza top 2 items por tema. Le ahorra a Isabel ~2h/día de
// scroll. NO browser de IG terceros (Meta no lo permite vía API).
export async function sendResearchDigest() {
  const gate = canSendProactive({ force: false });
  if (!gate.ok) {
    console.log(`[research] saltado: ${gate.reason}`);
    return;
  }
  const { listarTemas, buildResearchTopicsBlock } = await import('./research.js');
  const temas = listarTemas();
  if (!temas.length) {
    console.log('[research] no hay temas activos — saltado.');
    return;
  }
  bumpProactiveCount(gate.dayKey);

  const block = buildResearchTopicsBlock();
  const msg = `[RESEARCH DIGEST AUTOMÁTICO — MEDIODÍA]

Tu trabajo: armar un digest CORTO de contenido relevante para Isabel — le ahorras horas de scroll.

${block}

INSTRUCCIONES:
1. Para CADA tema arriba, llama web_search UNA vez con la query que creas más alta-señal hoy (rota entre las queries del tema entre días — no siempre la misma). Si el tema tiene "fuente_hint", úsalo para filtrar / interpretar resultados.
2. De los resultados, escoge los TOP max_items del tema (max 2-3). Cada item: 1 línea sobre QUÉ es + por qué importa a ELLA (no resumen genérico — conexión con su vida: agente Medicare, Latina founder 53, mom, building YouTube/brand) + link.
3. Si un tema NO da resultados útiles hoy, SÁLTALO entero (no rellenes con basura).

FORMATO — VISUAL CARDS separadas por divisor exacto "═════":
  Card 1: 1-2 líneas intro ("aquí tu digest del [día], 7 min de lectura")
  Card 2..N: una card por tema con items útiles
  Card final: 1 pregunta "¿algo de aquí quieres que profundice?"

Sé seca, útil, sin floreos. Spanglish. NO leas todos los temas si no hay nada bueno — mejor mandar 2 cards útiles que 5 mediocres. Si TODO el digest sale flojo, manda solo Card 1 diciendo "hoy nada relevante, te ahorré el scroll" y ya.`;

  await runProactive(msg);
  console.log(`[research] digest enviado (${temas.length} temas).`);
}

// ---- Revisión semanal (Domingo 6pm) ----
// Pulls toda la data semanal — habits + finanzas + journal + goals +
// team + iniciativas + overload — para que Athena haga un review
// honesto, no genérico.
export async function sendWeeklyReview() {
  const gate = canSendProactive({ force: true });
  if (!gate.ok) {
    console.log(`[weekly] saltado: ${gate.reason}`);
    return;
  }
  bumpProactiveCount(gate.dayKey);

  let dataBlock = '';
  try {
    const blocks = [];
    const { buildHabitsBriefingBlock } = await import('./habits.js');
    const h = buildHabitsBriefingBlock();
    if (h) blocks.push(h);
    const { statsMes } = await import('./finanzas.js');
    const f = statsMes();
    if (f.n_transacciones) {
      blocks.push(`💰 FINANZAS MES (${f.mes}): ingresos $${f.total_ingresos} · gastos $${f.total_gastos} · neto $${f.neto}`);
    }
    const { emocionesPattern } = await import('./journal.js');
    const e = emocionesPattern({ dias: 7 });
    if (e.n_entradas) {
      const top = Object.entries(e.counts).sort((a, b) => b[1] - a[1]).map(([k, v]) => `${k} ×${v}`).join(' · ');
      blocks.push(`📓 JOURNAL 7d: ${e.n_entradas} entradas · ${top}`);
    }
    const { listMetas, proyeccion } = await import('./goals.js');
    const metas = listMetas({ status: 'activa' });
    if (metas.length) {
      const lines = metas.map((m) => {
        const p = proyeccion(m);
        return `  · ${m.nombre} — ${m.progreso}${m.target !== null ? `/${m.target}` : ''}${m.unidad}${p ? ` · ${p.pct_avance}%${p.en_track ? '' : ' ⚠️OFF TRACK'}` : ''}`;
      }).join('\n');
      blocks.push(`🎯 METAS:\n${lines}`);
    }
    const { statsByPerson } = await import('./team.js');
    const teamStats = statsByPerson({ sinceDays: 7 });
    const teamNames = Object.keys(teamStats);
    if (teamNames.length) {
      const teamLines = teamNames.map((p) => {
        const x = teamStats[p];
        const ratio = x.cumplidas + x.fallidas > 0 ? `${Math.round(100 * x.cumplidas / (x.cumplidas + x.fallidas))}%` : '—';
        return `  · ${p}: ${x.cumplidas}/${x.cumplidas + x.fallidas} (${ratio}) · ${x.pendientes} abiertos`;
      }).join('\n');
      blocks.push(`📊 EQUIPO 7d:\n${teamLines}`);
    }
    const { listInitiatives } = await import('./team_review.js');
    const inis = listInitiatives({ sinceDays: 7, status: 'propuesta' });
    if (inis.length) {
      blocks.push(`💡 INICIATIVAS PENDIENTES: ${inis.length} (esperan tu aprobación)`);
    }
    const { computeOverload } = await import('./overload.js');
    const o = computeOverload();
    if (o.score > 0) {
      blocks.push(`🚨 SOBRECARGA: score ${o.score} · ${o.señales.length} señales`);
    }
    if (blocks.length) dataBlock = '\n\nDATOS HONESTOS DE LA SEMANA (úsalos, NO inventes):\n' + blocks.join('\n\n');
  } catch (err) { console.warn('[weekly] data:', err.message); }

  await runProactive(
    `[REVISIÓN SEMANAL AUTOMÁTICA — DOMINGO] Genera tu revisión semanal usando los datos honestos abajo.${dataBlock}

INSTRUCCIONES:
- Salúdala con cariño dominical, no formal.
- Inclúye SECCIONES separadas por divisor "═════" para que se manden como cards:
  Card 1: Saludo + tono según overload/journal (si estuvo cargada, reconoce el costo)
  Card 2: 3 wins concretos (datos reales — usa habits/team/finanzas/goals, NO inventes)
  Card 3: UN patrón que notaste (puede ser bueno o problemático — sé honesta)
  Card 4: Pregunta semilla para la semana que viene + las 3 prioridades
- Si overload score > 4: empieza por reconocer la carga, no le sumes presión.
- Tono Spanglish, cálido, sin lecciones.`,
  );
  console.log('[weekly] revisión enviada (con datos honestos).');
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
  // Auto-skill detection: revisa patrones de 7 días en el audit log,
  // propone hasta 1 draft. El briefing matutino los menciona.
  try {
    const { detectPatternsAndPropose } = await import('./skills.js');
    const drafts = await detectPatternsAndPropose();
    if (drafts.length) {
      console.log(`[reflection] ${drafts.length} skill(s) auto-propuestas: ${drafts.map((d) => d.name).join(', ')}`);
    }
  } catch (err) {
    console.warn('[reflection] auto-skill detection falló:', err.message);
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
