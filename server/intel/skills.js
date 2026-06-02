// SKILLS — PLAYBOOKS APROBADOS  (Playbook Athena #9)
//
// Cuando un patron se repite (el equipo consulta el mismo tema una y otra vez),
// SAMIA no debe re-razonar desde cero cada vez. Propone una SKILL: un playbook corto
// y reusable. Un humano la revisa y la APRUEBA. Despues, cuando el patron reaparece,
// la skill se inyecta en el prompt para que SAMIA responda igual de bien y CONSISTENTE.
//
// Ciclo de vida:  draft (propuesta) -> approved (invocable) | rejected (descartada)
//
// Propuesta: manual (un humano) o automatica (desde las señales de patron de la
// Fase 4). La aprobacion es SIEMPRE humana — confirmation gate (#5): SAMIA no se
// auto-aprueba playbooks.

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { DATA_DIR } from '../config.js';
import { getSignals } from './signals.js';

const FILE = resolve(DATA_DIR, 'skills.json');
const nowIso = () => new Date().toISOString();
const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

function ensure() {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
}
function read() {
  try {
    return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch {
    return [];
  }
}
function write(list) {
  ensure();
  writeFileSync(FILE, JSON.stringify(list, null, 1));
}

export function listSkills({ status } = {}) {
  const all = read();
  return status ? all.filter((s) => s.status === status) : all;
}
export function getSkill(id) {
  return read().find((s) => s.id === id) || null;
}

// Propone una skill (queda en draft). `trigger` = palabras que la activan.
export function proposeSkill({ name, trigger = [], steps = '', source = 'manual' }) {
  if (!name) return null;
  const list = read();
  const id = 'sk_' + norm(name).replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40);
  const existing = list.find((s) => s.id === id);
  if (existing) return existing; // no duplicar
  const skill = {
    id,
    name,
    trigger: (Array.isArray(trigger) ? trigger : [trigger]).map((t) => norm(t)).filter(Boolean),
    steps,
    status: 'draft',
    source,
    invocations: 0,
    createdAt: nowIso(),
    approvedAt: null,
  };
  list.push(skill);
  write(list);
  return skill;
}

export function approveSkill(id, { steps, trigger } = {}) {
  const list = read();
  const s = list.find((x) => x.id === id);
  if (!s) return null;
  if (steps != null) s.steps = steps; // permite editar al aprobar
  if (trigger != null) s.trigger = (Array.isArray(trigger) ? trigger : [trigger]).map(norm).filter(Boolean);
  s.status = 'approved';
  s.approvedAt = nowIso();
  write(list);
  return s;
}
export function rejectSkill(id) {
  const list = read();
  const s = list.find((x) => x.id === id);
  if (!s) return null;
  s.status = 'rejected';
  write(list);
  return s;
}

// Invoca una skill aprobada: cuenta el uso y devuelve el playbook.
export function invokeSkill(id) {
  const list = read();
  const s = list.find((x) => x.id === id && x.status === 'approved');
  if (!s) return null;
  s.invocations = (s.invocations || 0) + 1;
  s.lastUsed = nowIso();
  write(list);
  return s;
}

// Empareja una pregunta con skills aprobadas (por trigger). Para inyeccion en prompt.
export function matchSkills(userText) {
  const t = norm(userText);
  if (!t) return [];
  return read().filter((s) => s.status === 'approved' && s.steps && (s.trigger || []).some((kw) => kw && t.includes(kw)));
}

// Bloque para el system prompt: la skill aprobada mas relevante (cuenta como uso).
export function skillsContext(userText) {
  const matched = matchSkills(userText);
  if (!matched.length) return '';
  const s = matched[0];
  invokeSkill(s.id);
  return `PLAYBOOK APROBADO ("${s.name}") — sigue estos pasos, ya validados por el equipo:\n${s.steps}`;
}

// PROPUESTA AUTOMATICA desde las señales de patron (Fase 4). Crea drafts para temas
// recurrentes que aun no tienen skill. Devuelve las propuestas nuevas.
export function proposeFromPatterns() {
  const { signals = [] } = getSignals();
  const patterns = signals.filter((s) => s.type === 'pattern');
  const proposed = [];
  for (const p of patterns) {
    const spec = p.ref || p.title;
    const name = `Playbook: ${spec}`;
    const id = 'sk_' + norm(name).replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 40);
    if (getSkill(id)) continue; // ya existe (draft/approved/rejected)
    const skill = proposeSkill({
      name,
      trigger: [norm(spec)],
      // Sin LLM, dejamos un esqueleto para que el humano lo complete al aprobar.
      steps: `(Borrador) Tema recurrente detectado: ${p.detail}\n\nDefine aqui los pasos del playbook y aprueba. Sugerencia: documenta el flujo que el equipo repite para "${spec}".`,
      source: `auto: ${p.title}`,
    });
    if (skill) proposed.push(skill);
  }
  return proposed;
}
