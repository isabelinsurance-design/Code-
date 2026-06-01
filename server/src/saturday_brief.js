// ============================================================
//  Saturday brief — Friday 9pm Athena te prepara la semana
//  ────────────────────────────────────────────────────────
//  Cada viernes 9pm Athena compila la semana del equipo y te
//  manda un brief estructurado por WhatsApp para que lo leas
//  sábado morning. Llegas a tu review semanal en LUNA con las
//  prioridades claras, sin tener que re-armar el contexto.
//
//  Lo que arma:
//   1. Stats por empleada (% cumplido, vencidos, iniciativas)
//   2. Pendientes en riesgo ("parks" — proyectos abandonados)
//   3. Iniciativas que esperan tu decisión
//   4. AARs cerrados de la semana + learnings extraídos
//   5. Say-do propio de Athena de la semana
//   6. Tres preguntas concretas para abrir el sábado
//
//  Lo que NO compila aquí (lo pides el sábado a Pilar si
//  quieres): números de LUNA (pipeline, SOAs, retención).
//  Eso vive en LUNA y solo Pilar lo lee.
// ============================================================
import { listTeamCommitments, listOverdueTeamCommitments, statsByPerson } from './team.js';
import { listInitiatives } from './team_review.js';
import { listRecent as listRecentAars, recentLearnings } from './aar.js';
import { stats as sayDoStats, listOverdue as listOverdueSayDo } from './saydo.js';
import { sendMessage } from './whatsapp.js';
import { canSendProactive } from './proactive.js';
import { bumpProactiveCount, logActivity } from './memory.js';

const DIVIDER = '═════';

function fmt(date) {
  return new Date(date).toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', month: 'short' });
}

function buildHeader() {
  const fechaSabado = new Date(Date.now() + 12 * 3600_000); // mañana
  return `🌅 SATURDAY BRIEF — ${fechaSabado.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' })}\n\nPreparado anoche para que mañana abras LUNA ya sabiendo dónde meter foco. Léeme con calma con tu café.`;
}

function buildTeamStatsCard() {
  const s = statsByPerson({ sinceDays: 7 });
  const names = Object.keys(s);
  if (!names.length) return null;
  const lines = ['📊 EQUIPO ESTA SEMANA'];
  // Sort por % cumplido descendente
  names.sort((a, b) => (s[b].ratio || 0) - (s[a].ratio || 0));
  for (const p of names) {
    const x = s[p];
    const closed = x.cumplidas + x.fallidas;
    const ratio = closed ? `${Math.round((x.cumplidas / closed) * 100)}%` : '—';
    const flag = x.fallidas > x.cumplidas ? ' 🔴' : (x.ratio === 1 ? ' ✓' : '');
    lines.push(`${p}: ${x.cumplidas}/${closed} cumplido (${ratio})${flag} · ${x.pendientes} abiertos`);
  }
  return lines.join('\n');
}

function buildParksCard() {
  const overdue = listOverdueTeamCommitments();
  if (!overdue.length) return '🅿️ PROYECTOS PARKEADOS\nSin pendientes vencidos — equipo cerró la semana clean. 🎯';
  const byPerson = {};
  for (const c of overdue) {
    (byPerson[c.persona] ||= []).push(c);
  }
  const lines = ['🅿️ PROYECTOS PARKEADOS (vencidos sin cumplir)'];
  for (const [p, items] of Object.entries(byPerson)) {
    lines.push(`\n${p}: ${items.length} pendiente${items.length > 1 ? 's' : ''}`);
    for (const c of items.slice(0, 3)) {
      const diasVencido = Math.floor((Date.now() - new Date(c.vence).getTime()) / 86_400_000);
      lines.push(`  • ${c.descripcion.slice(0, 90)} (${diasVencido}d vencida)`);
    }
    if (items.length > 3) lines.push(`  ... +${items.length - 3} más`);
  }
  return lines.join('\n');
}

function buildInitiativesCard() {
  const propuestas = listInitiatives({ sinceDays: 7, status: 'propuesta' });
  const aprobadas = listInitiatives({ sinceDays: 7, status: 'aprobada' });
  const implementadas = listInitiatives({ sinceDays: 7, status: 'implementada' });
  if (!propuestas.length && !aprobadas.length && !implementadas.length) return null;
  const lines = ['💡 INICIATIVAS DE LA SEMANA'];
  if (propuestas.length) {
    lines.push(`\nEsperan tu decisión (${propuestas.length}):`);
    for (const i of propuestas.slice(0, 5)) {
      lines.push(`  • ${i.persona}: ${i.propuesta.slice(0, 90)}`);
    }
  }
  if (aprobadas.length) {
    lines.push(`\nAprobadas (${aprobadas.length}):`);
    for (const i of aprobadas.slice(0, 3)) {
      lines.push(`  ✓ ${i.persona}: ${i.propuesta.slice(0, 90)}`);
    }
  }
  if (implementadas.length) {
    lines.push(`\nYa rodando (${implementadas.length}):`);
    for (const i of implementadas.slice(0, 3)) {
      lines.push(`  🚀 ${i.persona}: ${i.propuesta.slice(0, 80)}`);
    }
  }
  return lines.join('\n');
}

function buildAarsCard() {
  const learnings = recentLearnings({ limit: 5 });
  if (!learnings.length) return null;
  const lines = ['🧠 APRENDIZAJES DE LA SEMANA (los AARs que cerré)'];
  for (const l of learnings) {
    lines.push(`  • ${l.learning.slice(0, 110)}`);
  }
  return lines.join('\n');
}

function buildSayDoCard() {
  const s = sayDoStats({ sinceDays: 7 });
  if (!s.total) return null;
  const ratio = s.ratio == null ? '—' : `${Math.round(s.ratio * 100)}%`;
  const overdue = listOverdueSayDo().length;
  const lines = [`🤝 ATHENA — MI SAY-DO ESTA SEMANA`];
  lines.push(`Cumplido: ${s.cumplidas}/${s.cumplidas + s.fallidas} (${ratio}) · pendientes ${s.pendientes} · vencidas sin cumplir ${overdue}`);
  if (overdue > 0) {
    lines.push(`\nDisculpa por las que dejé sin cerrar. Esta semana priorizo cerrarlas.`);
  }
  return lines.join('\n');
}

function buildQuestionsCard() {
  return `❓ TRES PREGUNTAS PARA TI HOY\n\n1. ¿Qué de la semana del equipo te sorprende — bueno o malo?\n2. De las iniciativas propuestas, ¿cuáles apruebas / descartas?\n3. ¿Hay algo "parkeado" que ya deba cancelarse en vez de seguir pretendiendo que se hará?`;
}

// Compila todo en un solo string con dividers ═════ por card.
// El sender lo splitea para mandar como cards separadas.
export function buildSaturdayBrief() {
  const cards = [
    buildHeader(),
    buildTeamStatsCard(),
    buildParksCard(),
    buildInitiativesCard(),
    buildAarsCard(),
    buildSayDoCard(),
    buildQuestionsCard(),
  ].filter(Boolean);
  return cards.join(`\n\n${DIVIDER}\n\n`);
}

// Cron Friday 9pm. Se manda como cards (split por divider).
export async function sendSaturdayBrief() {
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) {
    console.warn('[saturday] No hay ISABEL_WHATSAPP configurado.');
    return;
  }
  const gate = canSendProactive({ force: true });
  if (!gate.ok) {
    console.log(`[saturday] saltado: ${gate.reason}`);
    return;
  }
  const text = buildSaturdayBrief();
  const cards = text.split(/═{4,}/g).map((s) => s.trim()).filter(Boolean);
  for (let i = 0; i < cards.length; i++) {
    await sendMessage(to, cards[i]);
    if (i < cards.length - 1) await new Promise((r) => setTimeout(r, 1500));
  }
  bumpProactiveCount(gate.dayKey);
  logActivity({ tool: 'saturday_brief', input_summary: 'auto', result_summary: `${cards.length} cards enviadas` });
  console.log(`[saturday] Brief enviado (${cards.length} cards).`);
}

// Para CLI: node src/saturday_brief.js [send]
if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  if (process.argv[2] === 'send') {
    await sendSaturdayBrief();
  } else {
    console.log(buildSaturdayBrief());
  }
  process.exit(0);
}
