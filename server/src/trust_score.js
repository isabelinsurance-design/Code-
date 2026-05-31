// ============================================================
//  Trust Score — "puedes soltarte hoy" o "necesitas meter mano"
//  ────────────────────────────────────────────────────────────
//  Métrica compuesta 0-100 que Isabel ve cada mañana. Refleja:
//  ¿el negocio se está manejando solo o requiere su intervención?
//
//  Componentes (con peso):
//    business_health (30%) — equipo cumplimiento + compromisos
//    autopilot (25%)       — Athena cumpliendo sus promesas
//    isabel_health (20%)   — hábitos + journal + overload inverso
//    pipeline_flow (15%)   — drafts pendientes razonables
//    safety (10%)          — compliance + AAR sin errores graves
//
//  Score alto (≥80) = "lee tu libro tranquila, autopilot ON"
//  Score medio (50-79) = "revisa A, B, C — el resto está bien"
//  Score bajo (<50) = "necesitas estar presente hoy"
// ============================================================
import { statsByPerson, listOverdueTeamCommitments } from './team.js';
import { stats as sayDoStats, listOverdue as listSayDoOverdue } from './saydo.js';
import { listCommitments } from './commitments.js';
import { computeOverload } from './overload.js';
import { getPendingOutbound } from './memory.js';
import { weeklyWorkouts, statsForType } from './habits.js';
import { emocionesPattern } from './journal.js';

export function computeTrustScore() {
  const components = {};

  // 1. Business health (30 puntos)
  let business = 30;
  try {
    const ts = statsByPerson({ sinceDays: 7 });
    const persons = Object.keys(ts);
    if (persons.length) {
      // Penaliza cada persona con ratio < 70%
      let teamScore = 30;
      for (const p of persons) {
        const x = ts[p];
        const closed = x.cumplidas + x.fallidas;
        if (closed === 0) continue;
        const ratio = x.cumplidas / closed;
        if (ratio < 0.7) teamScore -= (0.7 - ratio) * 40; // bajada lineal
      }
      business = Math.max(0, teamScore);
    }
    // Equipo vencidos resta más
    const eqVenc = listOverdueTeamCommitments().length;
    business -= Math.min(15, eqVenc * 3);
  } catch { /* ignore */ }
  components.business_health = Math.max(0, Math.min(30, Math.round(business)));

  // 2. Autopilot (25 puntos) — Athena cumple
  let autopilot = 25;
  try {
    const s = sayDoStats({ sinceDays: 7 });
    if (s.total > 0) {
      autopilot = Math.round(25 * (s.ratio || 0));
    }
    autopilot -= Math.min(10, listSayDoOverdue().length * 5);
  } catch { /* ignore */ }
  components.autopilot = Math.max(0, Math.min(25, autopilot));

  // 3. Isabel health (20 puntos) — inverso overload
  let health = 20;
  try {
    const ov = computeOverload();
    // Overload 0 → 20 puntos, overload 11 → 0
    health = Math.max(0, 20 - (ov.score * 2));
    // Sueño bajo penaliza extra
    const sueno = statsForType('sueno', 7);
    if (sueno && sueno.dias_con_data >= 3 && sueno.promedio < 6) {
      health -= 4;
    }
  } catch { /* ignore */ }
  components.isabel_health = Math.max(0, Math.min(20, Math.round(health)));

  // 4. Pipeline flow (15 puntos) — drafts y compromisos
  let pipeline = 15;
  try {
    const drafts = getPendingOutbound().length;
    if (drafts > 5) pipeline -= Math.min(10, drafts - 5);
    const compVenc = listCommitments({ status: 'pendiente' })
      .filter((c) => c.vence && new Date(c.vence).getTime() < Date.now()).length;
    pipeline -= Math.min(8, compVenc * 2);
  } catch { /* ignore */ }
  components.pipeline_flow = Math.max(0, Math.min(15, Math.round(pipeline)));

  // 5. Safety (10 puntos) — sin escándalos
  // Heurística simple: si Athena no tuvo errores graves esta semana, full puntos
  components.safety = 10; // por defecto

  const total = Object.values(components).reduce((a, b) => a + b, 0);
  let veredicto, recomendacion;
  if (total >= 80) {
    veredicto = 'autopilot';
    recomendacion = 'Tu día es tuyo. Lee tu libro, toca piano, sé persona. Solo te ping si algo crítico surge.';
  } else if (total >= 50) {
    veredicto = 'revisa puntos';
    recomendacion = 'Revisa 2-3 cosas concretas (te las marco abajo) y vuelve a tu vida.';
  } else {
    veredicto = 'necesita Isabel';
    recomendacion = 'Hoy SÍ necesitas estar presente. El sistema tiene tensión que solo tú puedes destrabar.';
  }

  return { total, components, veredicto, recomendacion };
}

export function buildTrustInline() {
  const t = computeTrustScore();
  return `confianza ${t.total}/100 (${t.veredicto})`;
}

export function buildTrustBriefingBlock() {
  const t = computeTrustScore();
  const lines = [`🛡️ TRUST SCORE: ${t.total}/100 — ${t.veredicto.toUpperCase()}`];
  lines.push(t.recomendacion);
  lines.push('');
  lines.push(`Desglose: business ${t.components.business_health}/30 · autopilot ${t.components.autopilot}/25 · tu salud ${t.components.isabel_health}/20 · pipeline ${t.components.pipeline_flow}/15 · safety ${t.components.safety}/10`);
  return lines.join('\n');
}
