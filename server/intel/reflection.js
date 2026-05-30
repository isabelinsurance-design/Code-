// REFLEXION NOCTURNA — 4 pasos  (Playbook patron #15)
//
// A las 2am (cron, Fase 5) o bajo demanda (POST /api/intel/reflect), SAMIA:
//   1. EXTRACT     — que paso hoy (resumen del dia desde el audit log).
//   2. ENTITIES    — que personas nuevas/actualizadas aparecieron (ya las captura
//                    el turno; aqui consolidamos).
//   3. CONSOLIDATE — funde duplicados y marca contradicciones en las notas.
//   4. SIGNALS     — recomputa las señales.
//
// El paso 1 usa Haiku si hay key; si no, un resumen determinista. Los pasos 3 y 4
// son deterministas (no dependen de la red), asi que la reflexion SIEMPRE aporta.

import { complete } from '../anthropic.js';
import { MODELS } from '../config.js';
import { getAudit, addReflection, getReflections } from '../memory/index.js';
import { autoMergeDuplicates, duplicateCandidates, listEntities, findContradictions } from '../memory/entities.js';
import { refreshSignals } from './signals.js';

const nowIso = () => new Date().toISOString();

// Paso 1 — resumen del dia.
async function extractDay(now) {
  const today = now.toISOString().slice(0, 10);
  const audit = getAudit(300).filter((a) => (a.ts || '').slice(0, 10) === today);
  const chats = audit.filter((a) => a.action === 'chat');
  if (chats.length === 0) return { summary: 'Sin actividad de chat hoy.', turns: 0 };

  const bySpec = {};
  for (const a of chats) bySpec[a.specialist || 'chat'] = (bySpec[a.specialist || 'chat'] || 0) + 1;
  const detSummary = `Hoy: ${chats.length} turnos. Por especialista: ${Object.entries(bySpec).map(([k, v]) => `${k} (${v})`).join(', ')}.`;

  // Intento LLM (Haiku) para un resumen mas rico; si no hay key, usa el determinista.
  try {
    const sample = chats.slice(-20).map((a) => `- [${a.specialist}] ${a.input}`).join('\n');
    const out = await complete({
      system: 'Resume en 2-3 frases que necesito el equipo de Medicare de Isabel hoy, segun estas consultas. Espanol, concreto, sin relleno.',
      messages: [{ role: 'user', content: sample }],
      model: MODELS.classifier,
      maxTokens: 250,
    });
    if (out?.text?.trim()) return { summary: out.text.trim(), turns: chats.length };
  } catch {
    /* fallback determinista */
  }
  return { summary: detSummary, turns: chats.length };
}

export async function runReflection(now = new Date()) {
  // 1. EXTRACT
  const day = await extractDay(now);

  // 2. ENTITIES (instantanea)
  const before = listEntities({ limit: 5000 }).length;

  // 3. CONSOLIDATE — funde solo duplicados de alta confianza; los dudosos quedan
  // para confirmacion humana (no fundir mal datos de un miembro real).
  const merged = autoMergeDuplicates();
  const mergeCandidates = duplicateCandidates();
  const contradictions = findContradictions();

  // 4. SIGNALS
  const signals = refreshSignals(now);

  const report = {
    ts: nowIso(),
    summary: day.summary,
    turns: day.turns,
    entitiesBefore: before,
    entitiesAfter: listEntities({ limit: 5000 }).length,
    merged,
    mergeCandidates,
    contradictions,
    signalCount: signals.length,
    topSignals: signals.slice(0, 5).map((s) => ({ severity: s.severity, title: s.title })),
  };
  addReflection(report);
  return report;
}

export { getReflections };
