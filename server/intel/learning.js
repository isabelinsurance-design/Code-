// APRENDIZAJE — LO QUE SAMIA NECESITA APRENDER  (la maestra nota sus propios huecos)
//
// SAMIA es la escuela. Una buena maestra se da cuenta de lo que NO sabe enseñar todavía.
// Cada vez que SAMIA tiene que admitir honestamente que no sabe algo ("no estoy segura",
// "no sé", "habría que investigar") eso es un HUECO DE CURRÍCULO: un tema que la escuela
// debería poder enseñar y aún no. Lo registramos y, cuando un tema se repite, lo
// proponemos como "necesito aprender/enseñar X mejor" para llenar la base de conocimiento.
//
// IMPORTANTE — distinción honesta: decir "verifica en Connecture si ese doctor está en la
// red" NO es un hueco; es lo correcto (esa es data EN VIVO del miembro). El hueco es cuando
// SAMIA no domina el CONCEPTO de Medicare. Por eso los patrones son de incertidumbre de
// CONOCIMIENTO, no de verificación de datos.
//
// Ciclo del hueco: new -> doing (llenándolo) -> done (ya en la KB) | dismissed.

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { DATA_DIR } from '../config.js';
import { specialistList } from '../specialists.js';

const FILE = resolve(DATA_DIR, 'learning.json');
const nowIso = () => new Date().toISOString();
const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');

// Frases que delatan que SAMIA NO sabía (incertidumbre de conocimiento, no de data viva).
const PUNT_PATTERNS = [
  'no estoy segur', 'no estoy seguro', 'no se ', 'no lo se', 'no sabria', 'no sabria',
  'no tengo claro', 'no me queda claro', 'no estoy familiariz', 'no manejo ese tema',
  'no domino', 'habria que investigar', 'tendria que investigar', 'no podria asegurar',
  'no puedo asegurar', 'no estoy al tanto', 'desconozco',
];

function read() {
  try {
    return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch {
    return { events: [], gaps: [] };
  }
}
function write(d) {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
  writeFileSync(FILE, JSON.stringify(d, null, 1));
}

function areaLabel(specialist) {
  const s = specialistList().find((x) => x.id === specialist);
  return s ? s.label : (specialist || 'General');
}

// Llamado tras cada respuesta (no en 'practica'). Si SAMIA admitió no saber, registra el hueco.
export function noteTurn({ userText, reply, specialist } = {}) {
  const r = ' ' + norm(reply) + ' ';
  const phrase = PUNT_PATTERNS.find((p) => r.includes(p));
  if (!phrase) return null; // SAMIA respondió con seguridad — no hay hueco
  const d = read();
  d.events.unshift({
    ts: nowIso(),
    specialist: specialist || 'chat',
    q: String(userText || '').slice(0, 200),
    phrase: phrase.trim(),
  });
  d.events = d.events.slice(0, 500);
  write(d);
  return true;
}

// Agrupa los eventos recientes por área; un tema con 2+ punts = hueco de currículo.
// Preserva el estado humano de huecos ya existentes (no pisa lo que ya marcaste).
export function aggregateGaps({ days = 60, threshold = 2 } = {}) {
  const d = read();
  const since = new Date(Date.now() - days * 86400000).toISOString();
  const recent = d.events.filter((e) => (e.ts || '') >= since);
  const byArea = {};
  for (const e of recent) (byArea[e.specialist] ||= []).push(e);

  const prevById = Object.fromEntries((d.gaps || []).map((g) => [g.id, g]));
  const gaps = [];
  for (const [specialist, evs] of Object.entries(byArea)) {
    if (evs.length < threshold) continue;
    const id = 'lg_' + specialist;
    const prev = prevById[id];
    // Si ya lo marcaste done/dismissed y no hay punts NUEVOS desde entonces, respétalo.
    if (prev && (prev.status === 'done' || prev.status === 'dismissed')) {
      const newer = evs.some((e) => (e.ts || '') > (prev.updatedAt || prev.createdAt || ''));
      if (!newer) { gaps.push(prev); continue; }
    }
    gaps.push({
      id,
      area: areaLabel(specialist),
      specialist,
      count: evs.length,
      examples: evs.slice(0, 3).map((e) => e.q).filter(Boolean),
      proposal: `SAMIA tuvo que admitir que no sabía ${evs.length} vez(ces) en "${areaLabel(specialist)}". Llena ese hueco: agrégalo a la base de conocimiento (server/kb) o crea una guía/quiz.`,
      status: prev && prev.status === 'doing' ? 'doing' : 'new',
      createdAt: prev?.createdAt || nowIso(),
      updatedAt: nowIso(),
    });
  }
  gaps.sort((a, b) => b.count - a.count);
  d.gaps = gaps;
  write(d);
  return gaps;
}

export function listGaps({ status } = {}) {
  aggregateGaps();
  const { gaps } = read();
  return status ? gaps.filter((g) => g.status === status) : gaps;
}
export function openGapCount() {
  return listGaps({ status: 'new' }).length + listGaps({ status: 'doing' }).length;
}
export function setGapStatus(id, status) {
  if (!['new', 'doing', 'done', 'dismissed'].includes(status)) return null;
  const d = read();
  const g = (d.gaps || []).find((x) => x.id === id);
  if (!g) return null;
  g.status = status;
  g.updatedAt = nowIso();
  write(d);
  return g;
}

// Línea para el briefing: el hueco de currículo más repetido y aún abierto.
export function learningBriefLine() {
  const open = listGaps({ status: 'new' });
  if (!open.length) return '';
  const g = open[0];
  return `🎓 Aprender: SAMIA no supo responder ${g.count}x en "${g.area}". Llena ese hueco de la escuela.`;
}
