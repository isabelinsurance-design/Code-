// BRIEFING MATUTINO  (Playbook patron #20)
//
// Lo que SAMIA pondria sobre el escritorio del equipo a las 6:30am: que importa hoy.
// NO es un volcado de todo — es priorizado y corto. Orden: señales ALTO, compromisos
// vencidos/de hoy, huecos top, y el resumen de la reflexion de anoche.
// Todo determinista (lee de la memoria ya computada); no necesita la red.

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { DATA_DIR } from '../config.js';
import { getSignals, refreshSignals } from './signals.js';
import { reviewCommitments } from './commitments.js';
import { healthLine } from './health.js';
import { rankedGaps } from '../memory/entities.js';
import { getSeason } from '../memory/wiki.js';
import { lastReflection } from '../memory/index.js';

const FILE = resolve(DATA_DIR, 'briefing.json');
const nowIso = () => new Date().toISOString();

// Genera el briefing como objeto + texto markdown. `now` inyectable para tests.
export function buildBriefing(now = new Date()) {
  // Refresca señales para que reflejen el estado actual.
  const signals = refreshSignals(now);
  const alto = signals.filter((s) => s.severity === 'alto');
  const aviso = signals.filter((s) => s.severity === 'aviso');
  const { due, overdue } = reviewCommitments(now);
  const gaps = rankedGaps(5);
  const season = getSeason();
  const refl = lastReflection();

  const lines = [];
  const fecha = now.toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' });
  lines.push(`☀️ Briefing — ${fecha}`);
  if (season) lines.push(`Temporada: ${season}`);
  lines.push(healthLine(now)); // salud del negocio (Athena #10)
  lines.push('');

  if (alto.length) {
    lines.push('🔴 PRIORIDAD ALTA');
    for (const s of alto) lines.push(`  • ${s.title} — ${s.detail}`);
    lines.push('');
  }

  if (overdue.length || due.length) {
    lines.push('📌 COMPROMISOS');
    for (const c of overdue) lines.push(`  • [VENCIDO] ${c.entity ? c.entity + ': ' : ''}${c.text}`);
    for (const c of due) lines.push(`  • [HOY] ${c.entity ? c.entity + ': ' : ''}${c.text}`);
    lines.push('');
  }

  if (aviso.length) {
    lines.push('🟡 AVISOS');
    for (const s of aviso) lines.push(`  • ${s.title} — ${s.detail}`);
    lines.push('');
  }

  if (gaps.length) {
    lines.push('❓ DATOS PENDIENTES (top)');
    for (const g of gaps) lines.push(`  • ${g.entity}: ${g.what}`);
    lines.push('');
  }

  if (refl?.summary) lines.push(`🌙 Anoche: ${refl.summary}`);

  const nothing = !alto.length && !aviso.length && !overdue.length && !due.length && !gaps.length;
  if (nothing) lines.push('Nada urgente. Buen dia. 🙂');

  const text = lines.join('\n').trim();
  return {
    ts: nowIso(),
    text,
    counts: { alto: alto.length, aviso: aviso.length, overdue: overdue.length, dueToday: due.length, gaps: gaps.length },
  };
}

// Genera y persiste (lo que llama el scheduler a las 6:30).
export function generateBriefing(now = new Date()) {
  const b = buildBriefing(now);
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
  writeFileSync(FILE, JSON.stringify(b, null, 1));
  return b;
}

// Ultimo briefing generado (para el endpoint / surface en chat).
export function getLatestBriefing() {
  try {
    return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch {
    return null;
  }
}
