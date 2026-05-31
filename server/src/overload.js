// ============================================================
//  Overload detector — Athena se da cuenta antes que tú
//  ────────────────────────────────────────────────────
//  Patrón documentado en investigación CoS: el principal
//  no nota su propia sobrecarga hasta que ya está quemado.
//  Un CoS real cruza 6-8 señales y detecta la inflexión:
//
//   1. Tareas activas > umbral
//   2. Borradores en cola sin envía
//   3. Compromisos vencidos (otros a Isabel)
//   4. Equipo commitments vencidos (que requieren follow-up)
//   5. Journal con estrés > X menciones / 7d
//   6. Hábitos críticos cayendo (sueño < 6h promedio 3+ días)
//   7. Metas off-track activas
//   8. Tareas vencidas
//
//  Cada señal aporta puntos. Total ≥ 4 = sobrecarga detectada.
//  Athena entonces propone triage proactivo: aquí están las
//  3-5 cosas más fáciles de delegar a Sami o mover a la
//  semana siguiente. Tú decides en una palabra.
// ============================================================
import { listTasks } from './tasks.js';
import { listCommitments } from './commitments.js';
import { listOverdueTeamCommitments } from './team.js';
import { emocionesPattern } from './journal.js';
import { statsForType, weeklyWorkouts } from './habits.js';
import { listMetas, proyeccion } from './goals.js';
import { getPendingOutbound } from './memory.js';

const UMBRAL_OVERLOAD = 4; // puntos para detectar sobrecarga

// Devuelve { score, señales: [], detalle: {} }
export function computeOverload() {
  const señales = [];
  const detalle = {};
  let score = 0;

  // 1. Tareas activas (de Isabel)
  try {
    const tasks = listTasks({ status: 'pendiente', responsable: 'isabel' });
    detalle.tareas_activas = tasks.length;
    if (tasks.length > 8) {
      score += 1;
      señales.push(`${tasks.length} tareas activas tuyas (umbral 8)`);
    }
    // Vencidas suben más score
    const now = Date.now();
    const vencidas = tasks.filter((t) => t.vence && new Date(t.vence).getTime() < now);
    if (vencidas.length > 2) {
      score += 2;
      señales.push(`${vencidas.length} tareas tuyas VENCIDAS`);
      detalle.tareas_vencidas = vencidas;
    }
  } catch { /* ignore */ }

  // 2. Borradores en cola
  try {
    const pending = getPendingOutbound();
    detalle.borradores = pending.length;
    if (pending.length > 5) {
      score += 1;
      señales.push(`${pending.length} borradores esperando "envía"`);
    }
  } catch { /* ignore */ }

  // 3. Compromisos vencidos (otros a Isabel)
  try {
    const now = Date.now();
    const overdue = listCommitments({ status: 'pendiente' })
      .filter((c) => c.vence && new Date(c.vence).getTime() < now);
    detalle.compromisos_vencidos = overdue.length;
    if (overdue.length > 2) {
      score += 1;
      señales.push(`${overdue.length} promesas externas vencidas`);
    }
  } catch { /* ignore */ }

  // 4. Equipo vencidos (Athena debería estar empujando)
  try {
    const equipoVenc = listOverdueTeamCommitments();
    detalle.equipo_vencidos = equipoVenc.length;
    if (equipoVenc.length > 2) {
      score += 1;
      señales.push(`${equipoVenc.length} pendientes vencidos del equipo`);
    }
  } catch { /* ignore */ }

  // 5. Journal con estrés repetido
  try {
    const p = emocionesPattern({ dias: 7 });
    const estresCount = p.counts.estres || 0;
    const frustracionCount = p.counts.frustracion || 0;
    detalle.estres_7d = estresCount;
    detalle.frustracion_7d = frustracionCount;
    if (estresCount + frustracionCount >= 3) {
      score += 1;
      señales.push(`estrés/frustración ×${estresCount + frustracionCount} en journal 7d`);
    }
    if (estresCount >= 5) {
      score += 1; // peso extra si es muy frecuente
      señales.push(`patrón crónico de estrés (${estresCount} menciones 7d)`);
    }
  } catch { /* ignore */ }

  // 6. Hábitos críticos cayendo — sueño
  try {
    const sueno = statsForType('sueno', 7);
    if (sueno && sueno.dias_con_data >= 3 && sueno.promedio < 6) {
      score += 1;
      señales.push(`sueño promedio ${sueno.promedio}h (bajo 6h)`);
      detalle.sueno_promedio = sueno.promedio;
    }
  } catch { /* ignore */ }

  // 7. Workouts cayendo
  try {
    const wks = weeklyWorkouts();
    detalle.workouts_semana = wks;
    if (wks === 0) {
      score += 1;
      señales.push('0 workouts esta semana (meta 4)');
    }
  } catch { /* ignore */ }

  // 8. Metas off-track
  try {
    const offTrack = listMetas({ status: 'activa' }).filter((m) => {
      const p = proyeccion(m);
      return p && !p.en_track;
    });
    detalle.metas_off_track = offTrack.length;
    if (offTrack.length >= 2) {
      score += 1;
      señales.push(`${offTrack.length} metas off-track`);
    }
  } catch { /* ignore */ }

  return {
    score,
    overloaded: score >= UMBRAL_OVERLOAD,
    severidad: score >= 6 ? 'alto' : score >= 4 ? 'medio' : 'bajo',
    señales,
    detalle,
  };
}

// Para el cron: si está sobrecargada, prepara triage proactivo.
// Devuelve sugerencias específicas — qué delegar, qué posponer, qué cancelar.
export function buildTriageProposal() {
  const o = computeOverload();
  if (!o.overloaded) return null;
  const proposals = [];

  // Borradores en cola: ofrece descartar viejos
  if (o.detalle.borradores > 5) {
    proposals.push({
      tipo: 'borradores',
      accion: 'revisar cola',
      detalle: `${o.detalle.borradores} drafts esperando — quizá unos ya no aplican. Llama pendientes para verlos y descarta los que no.`,
    });
  }

  // Tareas vencidas: ofrece reagendar o cancelar
  if (o.detalle.tareas_vencidas?.length) {
    const top3 = o.detalle.tareas_vencidas.slice(0, 3);
    proposals.push({
      tipo: 'tareas_vencidas',
      accion: 'reagendar/cancelar',
      detalle: `${o.detalle.tareas_vencidas.length} tareas tuyas vencidas. Top 3: ${top3.map((t) => t.descripcion?.slice(0, 50)).join(' | ')}. Las muevo a próxima semana o las cancelas?`,
    });
  }

  // Equipo vencidos: Athena los toma — manda recordatorio
  if (o.detalle.equipo_vencidos > 2) {
    proposals.push({
      tipo: 'equipo_vencidos',
      accion: 'recordar al equipo',
      detalle: `${o.detalle.equipo_vencidos} compromisos del equipo vencidos. Le mando mensaje a Sami YO para que les recuerde, no tú.`,
    });
  }

  // Compromisos externos: chase auto
  if (o.detalle.compromisos_vencidos > 2) {
    proposals.push({
      tipo: 'compromisos_externos',
      accion: 'chase automático',
      detalle: `${o.detalle.compromisos_vencidos} terceros te deben cosas vencidas. ¿Mando follow-up a cada uno? Tú apruebas el envío.`,
    });
  }

  // Estrés alto: propone delegación física
  if (o.detalle.estres_7d >= 3 || o.detalle.estres_7d >= 5) {
    proposals.push({
      tipo: 'estres',
      accion: 'liberar carga',
      detalle: 'Tu journal lleva varios días de estrés. ¿Hay una cosa concreta esta semana que delegues a Skarleth (o Sami) HOY para liberar 1-2 horas? Yo la armo y mando.',
    });
  }

  // Sueño bajo: protección agresiva
  if (o.detalle.sueno_promedio && o.detalle.sueno_promedio < 6) {
    proposals.push({
      tipo: 'sueno',
      accion: 'protección',
      detalle: `Sueño ${o.detalle.sueno_promedio}h promedio. Bloqueo tu calendar de 8pm a 6am hoy y rechazo cualquier llamada Twilio? Tú decides.`,
    });
  }

  return {
    overload: o,
    proposals,
    mensaje: armarMensajeProactivo(o, proposals),
  };
}

function armarMensajeProactivo(o, proposals) {
  const sevWord = o.severidad === 'alto' ? 'sobrecargada de verdad' : 'cargada';
  const lines = [
    `🚨 Te leí ${sevWord}. ${o.señales.length} señales activas:`,
    '',
    ...o.señales.map((s) => `  · ${s}`),
    '',
    `Tengo ${proposals.length} propuestas para aliviarte. Tú dices sí o no — yo ejecuto lo que apruebes:`,
    '',
  ];
  proposals.forEach((p, i) => {
    lines.push(`${i + 1}. ${p.detalle}`);
  });
  lines.push('');
  lines.push('Responde con los números que quieres que haga (ej. "1 y 3") o "todo" / "nada".');
  return lines.join('\n');
}

// Snapshot inline para contexto base
export function buildOverloadInline() {
  const o = computeOverload();
  if (!o.overloaded) return '';
  return `🚨 sobrecarga score=${o.score} (umbral ${UMBRAL_OVERLOAD}) · ${o.señales.length} señales activas`;
}
