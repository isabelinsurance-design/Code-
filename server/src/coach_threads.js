// ───────────────────────────────────────────────────────────────────
//  Coach threads — memoria persistente por coach.
//
//  Cada coach especialista (Sofía, Carmen, Rivera, Pilar, etc.) tiene
//  su propio hilo de conversación con Isabel, guardado en disco. Cuando
//  Isabel abre Chat con esa coach en la PWA, el hilo se carga; cuando
//  manda un mensaje, se appendea. Así cada coach "recuerda" lo que han
//  hablado entre sesiones — base para planes, rutinas, seguimiento.
//
//  Decisión deliberada: este sistema NO toca a la directora (Athena en
//  WhatsApp). Athena sigue con su `conversation.json`. Cuando Athena
//  delega a un coach via `consultar_especialistas`, ese flujo sigue
//  siendo single-turn sin historial — por simplicidad y para no inflar
//  tokens en WhatsApp. Si más adelante queremos unificar, se hace en
//  Phase B.
//
//  Storage: data/coach_threads/<coach_id>.json
//  Shape: array de { role: 'user'|'assistant', content: string, ts: ISO }
// ───────────────────────────────────────────────────────────────────

import { readFileSync, writeFileSync, existsSync, mkdirSync, unlinkSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const THREADS_DIR = join(DATA_DIR, 'coach_threads');

// Cap de turnos por hilo. 60 = ~30 idas y vueltas. Suficiente para
// continuidad de varias semanas sin volar tokens en cada llamada.
const MAX_TURNS = 60;

// IDs válidos: alfanuméricos + guión bajo. Bloquea path traversal
// como ../, paths absolutos, etc. — el coachId viene de la URL.
const VALID_ID = /^[a-z0-9_]+$/;

function ensureDir() {
  if (!existsSync(THREADS_DIR)) mkdirSync(THREADS_DIR, { recursive: true });
}

function fileFor(coachId) {
  if (!VALID_ID.test(coachId)) {
    throw new Error(`coach_id inválido: ${coachId}`);
  }
  return join(THREADS_DIR, `${coachId}.json`);
}

export function loadCoachThread(coachId) {
  try {
    const f = fileFor(coachId);
    if (!existsSync(f)) return [];
    const raw = JSON.parse(readFileSync(f, 'utf8'));
    return Array.isArray(raw) ? raw : [];
  } catch {
    return [];
  }
}

export function appendCoachTurn(coachId, role, content) {
  ensureDir();
  const f = fileFor(coachId);
  const thread = loadCoachThread(coachId);
  thread.push({ role, content: String(content || ''), ts: new Date().toISOString() });
  const trimmed = thread.slice(-MAX_TURNS);
  writeFileSync(f, JSON.stringify(trimmed, null, 2));
  return trimmed;
}

export function clearCoachThread(coachId) {
  const f = fileFor(coachId);
  if (existsSync(f)) unlinkSync(f);
  return [];
}

// Convierte el thread persistido al shape que espera la API de Anthropic
// (sin el campo ts). El último mensaje DEBE ser role:'user' para que la
// API pueda generar la siguiente respuesta de assistant.
export function toApiMessages(thread) {
  return (thread || []).map((m) => ({ role: m.role, content: m.content }));
}
