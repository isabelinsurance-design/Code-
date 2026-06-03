// ───────────────────────────────────────────────────────────────────
//  Coach notes — el "expediente" estable que cada coach mantiene sobre
//  Isabel desde su lente de dominio.
//
//  Diferencia con las otras capas (todas son útiles, sirven cosas
//  distintas):
//   - coach_threads = historial conversacional (Phase A)
//   - coach_plans   = recomendaciones activas con estado (Phase B)
//   - coach_notes   = hechos estables sobre Isabel desde el dominio
//                     de esa coach (Phase C — smart coaches C)
//
//  Ej. Dra. Sofía:
//    "Último DEXA scan: 2024-09, t-score lumbar -1.3 (osteopenia leve).
//     Labs: TSH 2.1, vitamin D 28 (low), B12 normal. Ciclo: irregular
//     desde 2023, perimenopausia. No tolera fish oil (eructos).
//     Probó HRT 2024 — paró por dolor de cabeza."
//
//  Cada coach mantiene su propio blob markdown via la tool
//  coach_notes_actualizar. Lo lee al inicio de cada conversación.
//
//  Storage: data/coach_notes/<coach_id>.json
//  Shape: { coach_id, notes: string (markdown), actualizado: ISO }
// ───────────────────────────────────────────────────────────────────

import { readFileSync, writeFileSync, existsSync, mkdirSync, unlinkSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const NOTES_DIR = join(DATA_DIR, 'coach_notes');

const VALID_ID = /^[a-z0-9_]+$/;
const MAX_NOTES_CHARS = 8000; // ~2000 tokens — más que suficiente

function ensureDir() { if (!existsSync(NOTES_DIR)) mkdirSync(NOTES_DIR, { recursive: true }); }

function fileFor(coachId) {
  if (!VALID_ID.test(coachId)) throw new Error(`coach_id inválido: ${coachId}`);
  return join(NOTES_DIR, `${coachId}.json`);
}

function emptyNotes(coachId) {
  return { coach_id: coachId, notes: '', actualizado: null };
}

export function loadCoachNotes(coachId) {
  try {
    const f = fileFor(coachId);
    if (!existsSync(f)) return emptyNotes(coachId);
    const raw = JSON.parse(readFileSync(f, 'utf8'));
    return {
      coach_id: coachId,
      notes: typeof raw.notes === 'string' ? raw.notes : '',
      actualizado: raw.actualizado || null,
    };
  } catch {
    return emptyNotes(coachId);
  }
}

export function saveCoachNotes(coachId, notes) {
  ensureDir();
  const f = fileFor(coachId);
  const trimmed = String(notes || '').slice(0, MAX_NOTES_CHARS);
  const data = {
    coach_id: coachId,
    notes: trimmed,
    actualizado: new Date().toISOString(),
  };
  writeFileSync(f, JSON.stringify(data, null, 2));
  return data;
}

export function clearCoachNotes(coachId) {
  const f = fileFor(coachId);
  if (existsSync(f)) unlinkSync(f);
  return emptyNotes(coachId);
}

// Para inyectar en el system prompt de la coach al inicio de cada
// conversación. Si está vacío, devuelve string vacío.
export function notesAsContext(coachId, coachName) {
  const n = loadCoachNotes(coachId);
  if (!n.notes.trim()) return '';
  return `EXPEDIENTE QUE TÚ (${coachName}) MANTIENES SOBRE ISABEL — datos estables que ya sabes de ella desde tu lente. Lee antes de responder; actualiza con coach_notes_actualizar cuando aprendas algo nuevo importante.\n\n${n.notes}`;
}
