// COMMITMENT TRACKER  (Playbook patron #19)
//
// Una promesa es algo que ALGUIEN debe hacer para ALGUIEN, idealmente con fecha:
//   "le voy a enviar el SOA a Maria el lunes"  -> el equipo debe, vence el lunes
//   "el grupo medico dijo que llamaria mañana" -> un tercero debe, vence mañana
// SAMIA las detecta del turno (heuristico, sin red), les pone fecha, y luego avisa
// las que vencen hoy o ya vencieron. Es la diferencia entre "anote la tarea" y
// "te recuerdo que prometiste X y vence hoy".

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { DATA_DIR } from '../config.js';
import { resolveEntity } from '../memory/entities.js';

const FILE = resolve(DATA_DIR, 'commitments.json');
const nowIso = () => new Date().toISOString();
const dayKey = (d) => d.toISOString().slice(0, 10);

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

// --- PARSEO DE FECHAS EN ESPAÑOL (determinista) ---
const DOW = { domingo: 0, lunes: 1, martes: 2, miercoles: 3, 'miércoles': 3, jueves: 4, viernes: 5, sabado: 6, 'sábado': 6 };
const MONTHS = { enero: 0, febrero: 1, marzo: 2, abril: 3, mayo: 4, junio: 5, julio: 6, agosto: 7, septiembre: 8, setiembre: 8, octubre: 9, noviembre: 10, diciembre: 11 };

// Devuelve { iso, text } o null. `now` inyectable para tests.
export function parseDueDate(text, now = new Date()) {
  const t = String(text || '').toLowerCase();
  const base = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const mk = (d, label) => ({ iso: dayKey(d), text: label });

  if (/\bpasado\s+mañana\b/.test(t)) {
    base.setDate(base.getDate() + 2);
    return mk(base, 'pasado mañana');
  }
  if (/\bmañana\b/.test(t)) {
    base.setDate(base.getDate() + 1);
    return mk(base, 'mañana');
  }
  if (/\bhoy\b/.test(t)) return mk(base, 'hoy');

  const enDias = t.match(/\ben\s+(\d{1,2})\s+d[ií]as?\b/);
  if (enDias) {
    base.setDate(base.getDate() + Number(enDias[1]));
    return mk(base, `en ${enDias[1]} dias`);
  }

  // "el 7 de diciembre" / "7 de diciembre"
  const dm = t.match(/\b(\d{1,2})\s+de\s+([a-záéíóú]+)\b/);
  if (dm && MONTHS[dm[2]] != null) {
    const d = new Date(now.getFullYear(), MONTHS[dm[2]], Number(dm[1]));
    if (d < base) d.setFullYear(d.getFullYear() + 1); // si ya paso, el del año que viene
    return mk(d, `${dm[1]} de ${dm[2]}`);
  }

  // dia de la semana ("el lunes", "este viernes") -> proxima ocurrencia
  for (const [name, dow] of Object.entries(DOW)) {
    if (new RegExp(`\\b${name}\\b`).test(t)) {
      const d = new Date(base);
      let add = (dow - d.getDay() + 7) % 7;
      if (add === 0) add = 7; // "el lunes" cuando hoy es lunes = el proximo
      d.setDate(d.getDate() + add);
      return mk(d, name);
    }
  }
  return null;
}

// --- DETECCION DE PROMESAS (heuristico, sin red) ---
const TEAM_TRIGGERS = /\b(voy a|vamos a|le voy a|tengo que|debo|me comprometo a|quedamos en|prometi|promet[ií]|hay que enviar|hay que llamar)\b/i;
const THIRD_TRIGGERS = /\b(dijo que|me dijo que|prometio|prometió|qued[oó] en|va a|enviar[aá]|llamar[aá]|confirmar[aá])\b/i;

export function detectCommitments(userText, now = new Date()) {
  const text = String(userText || '');
  const found = [];
  for (const raw of text.split(/(?<=[.!?\n])/)) {
    const s = raw.trim();
    if (s.length < 8) continue;
    const team = TEAM_TRIGGERS.test(s);
    const third = !team && THIRD_TRIGGERS.test(s);
    if (!team && !third) continue;
    const due = parseDueDate(s, now);
    // entidad asociada (primera persona mencionada en la frase)
    let entity = null;
    for (const w of s.split(/[^A-Za-zÁÉÍÓÚáéíóúÑñ]+/)) {
      if (w.length < 3) continue;
      const e = resolveEntity(w);
      if (e) {
        entity = e;
        break;
      }
    }
    found.push({
      text: s.slice(0, 160),
      who: team ? 'equipo' : 'tercero',
      due: due?.iso || null,
      dueText: due?.text || null,
      entityId: entity?.id || null,
      entity: entity?.canonicalName || null,
    });
  }
  return found;
}

// --- STORE ---
export function addCommitment(c) {
  const list = read();
  const item = {
    id: `c_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 6)}`,
    text: c.text,
    who: c.who || 'equipo',
    due: c.due || null,
    dueText: c.dueText || null,
    entityId: c.entityId || null,
    entity: c.entity || null,
    status: 'open',
    createdAt: nowIso(),
  };
  list.push(item);
  write(list);
  return item;
}

// Captura las promesas de un turno (llamado desde capture). Evita duplicar texto
// identico aun abierto. Nunca lanza.
export function captureCommitments(userText, now = new Date()) {
  try {
    const open = read().filter((c) => c.status === 'open').map((c) => c.text);
    let n = 0;
    for (const c of detectCommitments(userText, now)) {
      if (open.includes(c.text)) continue;
      addCommitment(c);
      n++;
    }
    return n;
  } catch {
    return 0;
  }
}

export function setStatus(id, status) {
  const list = read();
  const c = list.find((x) => x.id === id);
  if (!c) return null;
  c.status = status;
  c.closedAt = status === 'open' ? null : nowIso();
  write(list);
  return c;
}

export function listCommitments({ status } = {}) {
  let list = read();
  if (status) list = list.filter((c) => c.status === status);
  return list;
}

// Recalcula 'overdue' para las abiertas con fecha pasada. Devuelve {due,overdue,open}.
export function reviewCommitments(now = new Date()) {
  const list = read();
  const today = dayKey(now);
  let changed = false;
  const due = [];
  const overdue = [];
  for (const c of list) {
    if (c.status === 'done') continue;
    if (!c.due) continue;
    if (c.due < today) {
      if (c.status !== 'overdue') {
        c.status = 'overdue';
        changed = true;
      }
      overdue.push(c);
    } else if (c.due === today) {
      due.push(c);
    }
  }
  if (changed) write(list);
  return { due, overdue, open: list.filter((c) => c.status === 'open' || c.status === 'overdue') };
}

// Bloque para briefing / prompt.
export function commitmentsContext(now = new Date()) {
  const { due, overdue } = reviewCommitments(now);
  if (!due.length && !overdue.length) return '';
  const fmt = (c) => `${c.entity ? c.entity + ': ' : ''}${c.text}${c.dueText ? ` (${c.dueText})` : ''}`;
  const lines = [];
  if (overdue.length) lines.push(`VENCIDAS:\n- ${overdue.map(fmt).join('\n- ')}`);
  if (due.length) lines.push(`VENCEN HOY:\n- ${due.map(fmt).join('\n- ')}`);
  return `COMPROMISOS:\n${lines.join('\n')}`;
}
