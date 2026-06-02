// SALUD DEL NEGOCIO  (Playbook Athena #10 — el trust score)
//
// Un solo numero 0-100 que responde "¿el negocio rueda solo hoy, o necesito meter
// mano?". Se computa DETERMINISTA desde la memoria que SAMIA ya tiene (señales,
// compromisos, gaps, audit) — no inventa, no necesita la red.
//
// Bandas (igual que Athena):
//   >=80 piloto automatico  -> el negocio rueda; revisa solo si surge algo.
//   50-79 revisa puntos     -> hay 2-3 cosas concretas que mirar hoy.
//   <50 necesita atencion   -> hoy si hay que estar presente.
//
// Y, como Athena (#11), NO devuelve la lista de problemas — devuelve EL foco mas
// doloroso con una propuesta de "ciérralo hoy".

import { refreshSignals } from './signals.js';
import { reviewCommitments } from './commitments.js';
import { rankedGaps } from '../memory/entities.js';
import { getAudit } from '../memory/index.js';

const clamp = (n, lo, hi) => Math.max(lo, Math.min(hi, n));

// Componentes: cada uno arranca lleno y pierde puntos por problemas.
// Recomputa señales primero para que el score refleje el estado ACTUAL (no señales
// viejas en disco) — asi el endpoint y el briefing siempre coinciden.
export function computeHealth(now = new Date()) {
  const signals = refreshSignals(now);
  const { due, overdue } = reviewCommitments(now);
  const gaps = rankedGaps(100);

  // señales de SEGURIDAD = state/compliance en alto (ej. Full Dual sin SOA).
  const safetyHits = signals.filter((s) => s.severity === 'alto' && (s.type === 'state' || s.type === 'pattern'));
  const altoOther = signals.filter((s) => s.severity === 'alto' && s.type !== 'state' && s.type !== 'pattern');
  const avisos = signals.filter((s) => s.severity === 'aviso');
  const calendar = signals.find((s) => s.type === 'calendar');

  // overrides de cumplimiento en las ultimas 24h (riesgo regulatorio asumido).
  const dayAgo = new Date(now.getTime() - 86400000).toISOString();
  const overrides = getAudit(200).filter((a) => a.action === 'compliance_override' && (a.ts || '') >= dayAgo);

  const components = [
    {
      name: 'Seguridad / cumplimiento',
      max: 30,
      score: clamp(30 - safetyHits.length * 10 - overrides.length * 8, 0, 30),
      note: safetyHits.length ? `${safetyHits.length} señal(es) alto de estado` : (overrides.length ? `${overrides.length} override(s) hoy` : 'sin riesgos abiertos'),
    },
    {
      name: 'Compromisos',
      max: 25,
      score: clamp(25 - overdue.length * 8 - due.length * 2, 0, 25),
      note: overdue.length ? `${overdue.length} vencido(s)` : (due.length ? `${due.length} vence(n) hoy` : 'al dia'),
    },
    {
      name: 'Datos pendientes',
      max: 20,
      score: clamp(20 - gaps.length * 2, 0, 20),
      note: gaps.length ? `${gaps.length} hueco(s) abiertos` : 'memoria completa',
    },
    {
      name: 'Carga de señales',
      max: 15,
      score: clamp(15 - altoOther.length * 5 - avisos.length * 2, 0, 15),
      note: altoOther.length || avisos.length ? `${altoOther.length} alto · ${avisos.length} aviso` : 'tranquilo',
    },
    {
      name: 'Presion de calendario',
      max: 10,
      score: calendar ? (calendar.severity === 'alto' ? 5 : 7) : 10,
      note: calendar ? calendar.title : 'sin ventanas urgentes',
    },
  ];

  const score = components.reduce((s, c) => s + c.score, 0);
  const band = score >= 80 ? 'autopilot' : score >= 50 ? 'revisa' : 'necesita';
  const headline = {
    autopilot: 'El negocio rueda solo hoy. Revisa solo si surge algo.',
    revisa: 'Hay un par de cosas concretas que mirar hoy.',
    necesita: 'Hoy si necesitas meter mano — hay tension que destrabar.',
  }[band];

  return { score, band, headline, components, focus: pickFocus(safetyHits, overdue, gaps, calendar), ts: now.toISOString() };
}

// EL foco mas doloroso + que hacer hoy (no la lista entera).
function pickFocus(safetyHits, overdue, gaps, calendar) {
  if (safetyHits[0]) return { title: safetyHits[0].title, why: safetyHits[0].detail, doToday: 'Resolver esto antes de avanzar — es riesgo de cumplimiento.' };
  if (overdue[0]) return { title: `Compromiso vencido: ${overdue[0].text}`, why: overdue[0].entity ? `Con ${overdue[0].entity}.` : 'Una promesa quedo sin cumplir.', doToday: 'Cumplirlo o avisar hoy; no dejarlo colgado.' };
  if (gaps[0]) return { title: `Falta dato: ${gaps[0].entity}`, why: gaps[0].what, doToday: 'Conseguir ese dato (Connecture / llamada / preguntar al miembro).' };
  if (calendar) return { title: calendar.title, why: calendar.detail, doToday: 'Planear la semana alrededor de esta ventana.' };
  return { title: 'Sin focos urgentes', why: 'Nada abierto que duela.', doToday: 'Adelantar pipeline o cerrar pendientes menores.' };
}

// Linea compacta para el briefing.
export function healthLine(now = new Date()) {
  const h = computeHealth(now);
  const icon = h.band === 'autopilot' ? '🟢' : h.band === 'revisa' ? '🟡' : '🔴';
  return `${icon} Salud del negocio: ${h.score}/100 — ${h.headline}`;
}
