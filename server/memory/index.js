// MEMORIA LIGERA + AUDIT LOG  (Playbook patrones #11, #12, #13, #6)
//
// Fase 1 deja la base de la memoria por capas (la Fase 3 la profundiza):
//   - agents:   ficha por agente del equipo (quien es, en que se atora). Captura
//               por defecto: lo que el agente dice se guarda sin pedir permiso.
//   - sessions: ultimos ~40 turnos por sesion (historial de conversacion).
//   - audit:    caja negra append-only de cada accion (ultimas 500).
//
// Almacenamiento: archivos JSON (como dicta el stack del playbook a baja escala).
// Migrable a Postgres cuando crezca, sin tocar los llamadores.

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { DATA_DIR } from '../config.js';

const FILES = {
  agents: resolve(DATA_DIR, 'agents.json'),
  sessions: resolve(DATA_DIR, 'sessions.json'),
  audit: resolve(DATA_DIR, 'audit.json'),
  reflections: resolve(DATA_DIR, 'reflections.json'),
};

const MAX_TURNS = 40; // historial de conversacion (patron #12)
const MAX_AUDIT = 500; // caja negra (patron #6)

function ensure() {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
}
function read(file, fallback) {
  try {
    return JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    return fallback;
  }
}
function write(file, data) {
  ensure();
  writeFileSync(file, JSON.stringify(data, null, 1));
}

const nowIso = () => new Date().toISOString();

// --- AGENTES (memoria por persona / patron #11 + captura por defecto / #13) ---
export function getAgent(agentId) {
  if (!agentId) return null;
  const all = read(FILES.agents, {});
  return all[agentId] || null;
}

export function touchAgent(agentId, name) {
  if (!agentId) return null;
  const all = read(FILES.agents, {});
  const a = all[agentId] || { id: agentId, name: name || agentId, firstSeen: nowIso(), notes: [], topics: {} };
  if (name) a.name = name;
  a.lastSeen = nowIso();
  all[agentId] = a;
  write(FILES.agents, all);
  return a;
}

// Captura por defecto: registra de que trato el turno (especialista + temas) sin
// pedir permiso. La extraccion de hechos salientes con Haiku llega en la Fase 4.
export function captureTurn(agentId, { specialist, userText } = {}) {
  if (!agentId) return;
  const all = read(FILES.agents, {});
  const a = all[agentId];
  if (!a) return;
  a.topics = a.topics || {};
  if (specialist) a.topics[specialist] = (a.topics[specialist] || 0) + 1;
  a.lastTopic = userText ? String(userText).slice(0, 140) : a.lastTopic;
  all[agentId] = a;
  write(FILES.agents, all);
}

export function addAgentNote(agentId, note) {
  if (!agentId || !note) return null;
  const all = read(FILES.agents, {});
  const a = all[agentId] || { id: agentId, name: agentId, firstSeen: nowIso(), notes: [], topics: {} };
  a.notes.push({ ts: nowIso(), note: String(note).slice(0, 500) });
  all[agentId] = a;
  write(FILES.agents, all);
  return a;
}

// Bloque de memoria que se inyecta en el system prompt del turno.
export function agentContext(agentId) {
  const a = getAgent(agentId);
  if (!a) return '';
  const top = Object.entries(a.topics || {}).sort((x, y) => y[1] - x[1]).slice(0, 3).map(([k]) => k);
  const lines = [`AGENTE: ${a.name}.`];
  if (top.length) lines.push(`Suele consultar: ${top.join(', ')}.`);
  if (a.notes?.length) lines.push(`Notas: ${a.notes.slice(-3).map((n) => n.note).join(' | ')}`);
  return `MEMORIA DEL AGENTE (con quien hablas):\n${lines.join(' ')}`;
}

// --- SESIONES (historial / patron #12) ---
export function getSession(sessionId) {
  if (!sessionId) return [];
  const all = read(FILES.sessions, {});
  return all[sessionId]?.turns || [];
}

export function appendTurns(sessionId, turns) {
  if (!sessionId) return;
  const all = read(FILES.sessions, {});
  const s = all[sessionId] || { id: sessionId, turns: [] };
  s.turns.push(...turns);
  if (s.turns.length > MAX_TURNS) s.turns = s.turns.slice(-MAX_TURNS);
  s.updated = nowIso();
  all[sessionId] = s;
  write(FILES.sessions, all);
}

// --- AUDIT LOG (caja negra / patron #6) ---
function redact(s) {
  return String(s || '')
    .replace(/\b\d{3}[-.\s]?\d{2}[-.\s]?\d{4}\b/g, '[SSN]')
    .replace(/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/g, '[email]')
    .slice(0, 300);
}

export function audit({ action, specialist, agentId, input, outputSummary }) {
  const log = read(FILES.audit, []);
  log.push({
    ts: nowIso(),
    action,
    specialist: specialist || null,
    agentId: agentId || null,
    input: redact(input),
    output: redact(outputSummary),
  });
  write(FILES.audit, log.slice(-MAX_AUDIT));
}

export function getAudit(n = 50) {
  return read(FILES.audit, []).slice(-n).reverse();
}

// --- REFLEXIONES (historial de la reflexion nocturna / patron #15) ---
export function addReflection(report) {
  const all = read(FILES.reflections, []);
  all.push(report);
  write(FILES.reflections, all.slice(-60)); // ~2 meses
  return report;
}

export function getReflections(n = 14) {
  return read(FILES.reflections, []).slice(-n).reverse();
}

export function lastReflection() {
  const all = read(FILES.reflections, []);
  return all[all.length - 1] || null;
}
