// Orquestador de captura por defecto + contexto de memoria estratificado.
// Une extract (que detecta) con entities/wiki (donde se guarda).

import * as entities from './entities.js';
import * as wiki from './wiki.js';
import { extractMemory } from './extract.js';

// Corre despues de cada turno. Nunca lanza: la captura jamas debe romper el chat.
export async function captureTurn({ userText, assistantText }) {
  let result;
  try {
    result = await extractMemory(userText, assistantText);
  } catch {
    return { saved: 0, engine: 'none' };
  }
  let saved = 0;
  for (const e of result.entities || []) {
    if (!e?.name) continue;
    entities.upsertEntity({
      name: e.name,
      type: e.type || 'member',
      attrs: e.attrs || {},
      note: e.note || null,
      gaps: e.gaps || [],
    });
    saved++;
  }
  for (const f of result.facts || []) {
    if (f) {
      wiki.addFact(f);
      saved++;
    }
  }
  return { saved, engine: result.engine };
}

// CONTEXTO ESTRATIFICADO (patron #14): temporada -> wiki -> personas relevantes
// del turno -> known unknowns rankeados. Se inyecta junto al resto del system prompt.
export function memoryContext(userText) {
  const parts = [];

  const wikiBlock = wiki.wikiContext();
  if (wikiBlock) parts.push(wikiBlock);

  // Personas relevantes: las mencionadas en el turno + la mas saliente reciente.
  const mentioned = new Map();
  for (const word of String(userText || '').split(/[^A-Za-zÁÉÍÓÚáéíóúÑñ]+/)) {
    if (word.length < 3) continue;
    const e = entities.resolveEntity(word);
    if (e) mentioned.set(e.id, e);
  }
  const cards = [...mentioned.values()].slice(0, 4).map((e) => entities.entityCard(e));
  if (cards.length) parts.push(`PERSONAS RELEVANTES (de la memoria):\n- ${cards.join('\n- ')}`);

  const gaps = entities.rankedGaps(6);
  if (gaps.length) {
    parts.push(`KNOWN UNKNOWNS (lo que falta — pidelo si hace al caso):\n- ${gaps.map((g) => `${g.entity}: ${g.what}`).join('\n- ')}`);
  }

  return parts.join('\n\n');
}
