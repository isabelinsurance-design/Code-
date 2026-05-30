// SEÑALES CON SEVERIDAD  (Playbook patron #16)
//
// Una señal es algo que el sistema NOTA y que merece atencion. Cuatro tipos:
//   - threshold: algo supero un umbral (ej. 5+ gaps abiertos)
//   - pattern:   algo ocurrio N veces (ej. el mismo tipo de ticket 4 veces hoy)
//   - state:     algo esta en un estado raro (ej. Full Dual sin SOA firmado)
//   - calendar:  algo se acerca (ej. AEP, fecha limite Dic 7)
// Cada una con severidad: 'alto' | 'aviso' | 'info'.
//
// Las señales se COMPUTAN a partir de la memoria (entidades/gaps) + el audit log.
// Son derivadas (no se editan a mano); el briefing matutino las muestra ordenadas.

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { DATA_DIR } from '../config.js';
import { listEntities, rankedGaps } from '../memory/entities.js';
import { getAudit } from '../memory/index.js';

const FILE = resolve(DATA_DIR, 'signals.json');
const nowIso = () => new Date().toISOString();
const SEV_ORDER = { alto: 0, aviso: 1, info: 2 };

function ensure() {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
}
function read() {
  try {
    return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch {
    return { computedAt: null, signals: [] };
  }
}
function write(data) {
  ensure();
  writeFileSync(FILE, JSON.stringify(data, null, 1));
}

// --- COMPUTO DE SEÑALES ---
// now: inyectable para tests deterministas.
export function computeSignals(now = new Date()) {
  const signals = [];
  const push = (type, severity, title, detail, ref = null) =>
    signals.push({ id: `${type}:${title}`.slice(0, 80), type, severity, title, detail, ref, ts: nowIso() });

  const entities = listEntities({ limit: 1000 });
  const gaps = rankedGaps(1000);

  // CALENDAR — ventanas de Medicare.
  // AEP: 15 oct - 7 dic. IEP/SEP no tienen fecha global. Avisamos cercania de AEP
  // y de la fecha limite de cierre.
  const y = now.getFullYear();
  const aepStart = new Date(y, 9, 15); // 15 oct
  const aepEnd = new Date(y, 11, 7); // 7 dic
  const days = (d) => Math.ceil((d - now) / 86400000);
  if (now >= aepStart && now <= aepEnd) {
    const left = days(aepEnd);
    push('calendar', left <= 14 ? 'alto' : 'aviso', 'AEP en curso', `Quedan ${left} dias para el cierre de AEP (7 dic). Prioridad: cerrar Full Duals y cambios de plan.`);
  } else if (now < aepStart && days(aepStart) <= 30) {
    push('calendar', 'aviso', 'AEP se acerca', `Faltan ${days(aepStart)} dias para que abra AEP (15 oct).`);
  }

  // STATE — personas en estado riesgoso segun sus atributos/gaps.
  for (const e of entities) {
    const a = e.attrs || {};
    const gapText = (e.gaps || []).map((g) => g.what.toLowerCase()).join(' ');
    // Full Dual sin SOA firmado = no se puede hablar de planes -> alto.
    if (/full\s*dual/i.test(a.mediCal || '') && /soa/.test(gapText)) {
      push('state', 'alto', `${e.canonicalName}: Full Dual sin SOA`, 'No se puede presentar planes sin SOA firmado. Conseguir el SOA antes de avanzar.', e.id);
    }
    // Miembro con condicion cronica + Full Dual sin beneficio de comida registrado.
    if (a.conditions?.length && /full\s*dual/i.test(a.mediCal || '') && !/ssbci|comida|grocery/i.test(gapText + JSON.stringify(a))) {
      push('state', 'info', `${e.canonicalName}: posible beneficio de comida`, `Tiene ${a.conditions.join(', ')} + Full Dual. Verificar cuestionario SSBCI (grocery).`, e.id);
    }
  }

  // THRESHOLD — demasiados huecos abiertos.
  if (gaps.length >= 5) {
    push('threshold', gaps.length >= 12 ? 'alto' : 'aviso', `${gaps.length} huecos abiertos`, `Hay ${gaps.length} datos pendientes en la memoria. Top: ${gaps.slice(0, 3).map((g) => `${g.entity} (${g.what})`).join('; ')}.`);
  }

  // PATTERN — el mismo tipo de consulta repetido hoy (del audit log).
  const audit = getAudit(200).filter((a) => a.action === 'chat');
  const today = now.toISOString().slice(0, 10);
  const counts = {};
  for (const a of audit) {
    if ((a.ts || '').slice(0, 10) !== today) continue;
    if (a.specialist) counts[a.specialist] = (counts[a.specialist] || 0) + 1;
  }
  for (const [spec, n] of Object.entries(counts)) {
    if (n >= 4) push('pattern', n >= 8 ? 'aviso' : 'info', `Patron: ${spec} x${n} hoy`, `El equipo consulto "${spec}" ${n} veces hoy. Posible tema recurrente o candidato a skill/playbook.`, spec);
  }

  signals.sort((a, b) => SEV_ORDER[a.severity] - SEV_ORDER[b.severity]);
  return signals;
}

export function refreshSignals(now = new Date()) {
  const signals = computeSignals(now);
  write({ computedAt: nowIso(), signals });
  return signals;
}

export function getSignals() {
  return read();
}

// Bloque para el briefing / prompt.
export function signalsContext() {
  const { signals } = read();
  if (!signals?.length) return '';
  const top = signals.slice(0, 8).map((s) => `[${s.severity.toUpperCase()}] ${s.title} — ${s.detail}`);
  return `SEÑALES ACTIVAS:\n- ${top.join('\n- ')}`;
}
