// ============================================================
//  Compromisos hacia Isabel
//  ─────────────────────────
//  Tareas tiene cosas que ELLOS (athena/isabel/sami) van a hacer.
//  Compromisos tiene promesas que OTRAS PERSONAS le hicieron a
//  ELLA: reportes, follow-ups, callbacks, entregas. Si la fecha
//  pasa y no llegó la evidencia, Athena se la cobra y la avisa.
// ============================================================
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { atomicWriteJson } from './storage.js';
import { sendMessage } from './whatsapp.js';
import { sendEmail } from './email.js';
import { canSendProactive } from './proactive.js';
import { bumpProactiveCount, logActivity } from './memory.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'commitments.json');

const STATUSES = ['pendiente', 'cumplido', 'fallido', 'cancelado'];
const CHANNELS = ['email', 'sms', 'whatsapp', 'callback', 'reporte', 'otro'];

function load() {
  try {
    if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch (e) {
    console.error(`[commitments] commitments.json ilegible (${e.message}) — usando lista vacía. Hay backup horario en R2.`);
  }
  return [];
}
function save(rows) {
  atomicWriteJson(FILE, rows);
}
function newId() {
  return `c${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
}
const nowIso = () => new Date().toISOString();

function parseDue({ vence, vence_en_horas, vence_en_dias }) {
  if (vence) {
    const d = new Date(vence);
    if (!isNaN(d.getTime())) return d.toISOString();
  }
  if (typeof vence_en_horas === 'number' && vence_en_horas > 0) {
    return new Date(Date.now() + vence_en_horas * 3600_000).toISOString();
  }
  if (typeof vence_en_dias === 'number' && vence_en_dias > 0) {
    return new Date(Date.now() + vence_en_dias * 86_400_000).toISOString();
  }
  return null;
}

export function createCommitment(input) {
  const { persona, descripcion, canal = 'otro', persona_contacto = '', notas = '' } = input;
  if (!persona || !String(persona).trim()) throw new Error('Falta "persona".');
  if (!descripcion || !String(descripcion).trim()) throw new Error('Falta "descripcion".');
  if (!CHANNELS.includes(canal)) throw new Error(`Canal inválido. Usa: ${CHANNELS.join(', ')}.`);
  const rows = load();
  const c = {
    id: newId(),
    persona: String(persona).trim(),
    persona_contacto: String(persona_contacto).trim(), // teléfono/email opcional
    descripcion: String(descripcion).trim(),
    canal,
    vence: parseDue(input),
    status: 'pendiente',
    recordatorios_enviados: 0,
    notas: notas ? [{ ts: nowIso(), texto: String(notas) }] : [],
    evidencia: null,
    creado: nowIso(),
    actualizado: nowIso(),
    avisada_isabel: false, // ya le avisamos a Isabel que se atrasó?
  };
  rows.unshift(c);
  save(rows);
  return c;
}

export function listCommitments({ status = null, persona = null } = {}) {
  return load().filter((c) => (status ? c.status === status : c.status === 'pendiente'))
    .filter((c) => (persona ? c.persona.toLowerCase().includes(String(persona).toLowerCase()) : true));
}

export function getCommitment(id) {
  return load().find((c) => c.id === id) || null;
}

function patch(id, patchObj) {
  const rows = load();
  const i = rows.findIndex((c) => c.id === id);
  if (i < 0) return null;
  rows[i] = { ...rows[i], ...patchObj, actualizado: nowIso() };
  save(rows);
  return rows[i];
}

export function completeCommitment(id, evidencia = '') {
  return patch(id, { status: 'cumplido', evidencia: String(evidencia || '').trim() });
}
export function failCommitment(id, razon = '') {
  return patch(id, { status: 'fallido', evidencia: razon ? `Fallido: ${razon}` : 'Fallido' });
}
export function cancelCommitment(id) {
  return patch(id, { status: 'cancelado' });
}
export function bumpReminder(id) {
  const c = getCommitment(id);
  if (!c) return null;
  return patch(id, { recordatorios_enviados: (c.recordatorios_enviados || 0) + 1 });
}
export function noteCommitment(id, texto) {
  const c = getCommitment(id);
  if (!c) return null;
  c.notas.push({ ts: nowIso(), texto: String(texto).trim() });
  return patch(id, { notas: c.notas });
}

// Vista corta para el bloque de contexto (visible a Athena siempre).
export function buildCommitmentsContext() {
  const tz = process.env.TIMEZONE || 'America/Los_Angeles';
  const pending = load().filter((c) => c.status === 'pendiente');
  if (!pending.length) return '';
  const lines = pending.slice(0, 15).map((c) => {
    const due = c.vence
      ? ` (vence ${new Date(c.vence).toLocaleString('es-MX', { timeZone: tz, month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })})`
      : '';
    const overdue = c.vence && new Date(c.vence).getTime() < Date.now() ? ' ⏰VENCIDO' : '';
    return `  - [${c.id}] ${c.persona} → ${c.descripcion} via ${c.canal}${due}${overdue}`;
  });
  return `COMPROMISOS DE TERCEROS HACIA ISABEL (lo que otros le deben — recuerda perseguirlos cuando venzan):\n${lines.join('\n')}`;
}

// ============================================================
//  Tick de cobranza — corre cada 2h en horas despiertas.
//  Para cada compromiso vencido sin evidencia: (1) si tenemos
//  contacto de la persona, le mandamos un nudge cordial; (2) le
//  avisamos a Isabel (una sola vez por vencimiento, respetando
//  quiet hours y cap).
// ============================================================
const NUDGE_GAP_MS = 24 * 3600_000; // máx 1 nudge a la persona cada 24h

function lastNudgeTooRecent(c) {
  if (!c.recordatorios_enviados) return false;
  const last = c.notas?.slice().reverse().find((n) => n.texto.startsWith('Nudge enviado'));
  if (!last) return false;
  return Date.now() - new Date(last.ts).getTime() < NUDGE_GAP_MS;
}

export async function commitmentChaseTick() {
  const rows = load();
  const now = Date.now();
  const overdue = rows.filter(
    (c) => c.status === 'pendiente' && c.vence && new Date(c.vence).getTime() < now,
  );
  if (!overdue.length) return;

  for (const c of overdue) {
    // 1) Nudge a la persona si tenemos cómo
    if (c.persona_contacto && !lastNudgeTooRecent(c)) {
      const sent = await nudgeContact(c).catch((e) => {
        console.warn('[commitments] nudge failed:', e.message);
        return false;
      });
      if (sent) {
        c.notas.push({ ts: nowIso(), texto: `Nudge enviado a ${c.persona_contacto} via ${c.canal}` });
        patch(c.id, { recordatorios_enviados: (c.recordatorios_enviados || 0) + 1, notas: c.notas });
        logActivity({ tool: 'commitment_nudge', input_summary: c.id, result_summary: c.persona });
      }
    }
    // 2) Avisar a Isabel (solo una vez)
    if (!c.avisada_isabel) {
      const gate = canSendProactive();
      if (gate.ok) {
        const due = c.vence
          ? new Date(c.vence).toLocaleString('es-MX', { timeZone: process.env.TIMEZONE || 'America/Los_Angeles', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
          : 'sin fecha';
        const to = process.env.ISABEL_WHATSAPP;
        if (to) {
          await sendMessage(
            to,
            `Heads-up: ${c.persona} no ha cumplido — "${c.descripcion}". Vencía ${due}. ${c.persona_contacto ? `Le mandé un recordatorio.` : 'No tenía cómo contactarl@ directo.'} Quieres que insista o lo escalo?`,
          );
          patch(c.id, { avisada_isabel: true });
          bumpProactiveCount(gate.dayKey);
          logActivity({ tool: 'commitment_alert_isabel', input_summary: c.id, result_summary: c.persona });
        }
      }
    }
  }
}

async function nudgeContact(c) {
  const persona = c.persona;
  const what = c.descripcion;
  if (c.canal === 'sms' && c.persona_contacto) {
    await sendMessage(
      c.persona_contacto.startsWith('+') ? c.persona_contacto : `+${c.persona_contacto}`,
      `Hola ${persona}, soy la asistente de Isabel Fuentes. Quería confirmar: ${what}. ¿Lo podemos cerrar hoy? Gracias.`,
    );
    return true;
  }
  if (c.canal === 'whatsapp' && c.persona_contacto) {
    const to = c.persona_contacto.startsWith('whatsapp:') ? c.persona_contacto : `whatsapp:${c.persona_contacto}`;
    await sendMessage(to, `Hola ${persona}, soy la asistente de Isabel. Recordatorio amable: ${what}. ¿Lo podemos cerrar hoy?`);
    return true;
  }
  if ((c.canal === 'email' || c.canal === 'reporte') && c.persona_contacto.includes('@')) {
    await sendEmail(
      c.persona_contacto,
      `Recordatorio: ${what}`,
      `Hola ${persona},\n\nUn recordatorio amable de parte de Isabel: ${what}.\n\n¿Lo podemos tener hoy?\n\nGracias,\nAthena (en nombre de Isabel)`,
    );
    return true;
  }
  return false; // no sabemos cómo contactar
}

// CLI: node src/commitments.js [tick|list]
if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  const cmd = process.argv[2];
  if (cmd === 'tick') {
    await commitmentChaseTick();
  } else if (cmd === 'list') {
    console.log(JSON.stringify(listCommitments(), null, 2));
  } else {
    console.error('Uso: node src/commitments.js [tick|list]');
    process.exit(1);
  }
  process.exit(0);
}
