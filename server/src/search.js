// ───────────────────────────────────────────────────────────────────
//  Search global — busca un keyword en todas las fuentes de memoria
//  de Athena en una sola call. Categorizado para la UI.
//
//  Fuentes escaneadas:
//   - Wiki (notas largas sobre Isabel)
//   - Entities (personas que Athena conoce)
//   - Journal (entradas reflexivas)
//   - Reading list (URLs guardados)
//   - Coach plans (recomendaciones activas)
//   - Coach notes (expedientes)
//   - Coach threads (conversaciones por coach)
//   - Tasks
//   - Commitments
//
//  Implementación: importa dinámico para evitar ciclos y no inflar
//  el bundle si no se usa.
// ───────────────────────────────────────────────────────────────────

import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');

function loadJsonSafe(file, fallback) {
  try {
    if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8'));
  } catch { /* ignore */ }
  return fallback;
}

function matchesQuery(q, ...fields) {
  const haystack = fields.filter(Boolean).join(' ').toLowerCase();
  return haystack.includes(q);
}

export async function globalSearch(query, { limit = 20 } = {}) {
  const q = String(query || '').toLowerCase().trim();
  if (!q || q.length < 2) {
    return { query, total: 0, results: {} };
  }

  const results = {};
  let total = 0;

  // ---- Wiki ----
  try {
    const wiki = loadJsonSafe(join(DATA_DIR, 'isabel_wiki.json'), { notas: [] });
    const matches = (wiki.notas || [])
      .filter((n) => matchesQuery(q, n.nota))
      .slice(0, limit)
      .map((n) => ({ texto: n.nota, fecha: n.fecha }));
    if (matches.length) { results.wiki = matches; total += matches.length; }
  } catch { /* ignore */ }

  // ---- Entities ----
  try {
    const ents = loadJsonSafe(join(DATA_DIR, 'entities.json'), []);
    const matches = ents
      .filter((e) => {
        const aliasBlob = (e.aliases || []).join(' ');
        const notesBlob = (e.notas || []).map((n) => n.texto || '').join(' ');
        return matchesQuery(q, e.canonical_name, aliasBlob, notesBlob, e.type);
      })
      .slice(0, limit)
      .map((e) => ({
        id: e.id,
        canonical_name: e.canonical_name,
        type: e.type,
        notas_count: (e.notas || []).length,
        top_note: (e.notas || []).slice().sort((a, b) => (b.salience || 5) - (a.salience || 5))[0]?.texto?.slice(0, 150) || null,
      }));
    if (matches.length) { results.entities = matches; total += matches.length; }
  } catch { /* ignore */ }

  // ---- Journal ----
  try {
    const { searchEntries } = await import('./journal.js');
    const matches = searchEntries({ query: q, dias: 180 }).slice(0, limit);
    if (matches.length) { results.journal = matches; total += matches.length; }
  } catch { /* ignore */ }

  // ---- Reading list ----
  try {
    const reading = loadJsonSafe(join(DATA_DIR, 'reading_list.json'), []);
    const matches = reading
      .filter((r) => matchesQuery(q, r.url, r.titulo, r.notas, r.resumen, (r.tags || []).join(' ')))
      .slice(0, limit)
      .map((r) => ({
        id: r.id,
        url: r.url,
        titulo: r.titulo,
        fuente: r.fuente,
        status: r.status,
        notas: r.notas,
      }));
    if (matches.length) { results.reading = matches; total += matches.length; }
  } catch { /* ignore */ }

  // ---- Tasks ----
  try {
    const tasks = loadJsonSafe(join(DATA_DIR, 'tasks.json'), []);
    const matches = tasks
      .filter((t) => matchesQuery(q, t.descripcion))
      .slice(0, limit)
      .map((t) => ({ id: t.id, descripcion: t.descripcion, status: t.status, responsable: t.responsable, vence: t.vence }));
    if (matches.length) { results.tasks = matches; total += matches.length; }
  } catch { /* ignore */ }

  // ---- Commitments ----
  try {
    const commits = loadJsonSafe(join(DATA_DIR, 'commitments.json'), []);
    const matches = commits
      .filter((c) => matchesQuery(q, c.descripcion, c.persona))
      .slice(0, limit)
      .map((c) => ({ id: c.id, persona: c.persona, descripcion: c.descripcion, status: c.status, vence: c.vence }));
    if (matches.length) { results.commitments = matches; total += matches.length; }
  } catch { /* ignore */ }

  // ---- Coach plans (across all coaches) ----
  try {
    const plansDir = join(DATA_DIR, 'coach_plans');
    const planMatches = [];
    if (existsSync(plansDir)) {
      for (const f of readdirSync(plansDir).filter((x) => x.endsWith('.json'))) {
        const coachId = f.slice(0, -5);
        const plan = loadJsonSafe(join(plansDir, f), { items: [] });
        for (const item of plan.items || []) {
          if (matchesQuery(q, item.text)) {
            planMatches.push({ coach_id: coachId, item_id: item.id, text: item.text, status: item.status });
          }
        }
      }
    }
    if (planMatches.length) { results.coach_plans = planMatches.slice(0, limit); total += planMatches.length; }
  } catch { /* ignore */ }

  // ---- Coach notes (expedientes) ----
  try {
    const notesDir = join(DATA_DIR, 'coach_notes');
    const noteMatches = [];
    if (existsSync(notesDir)) {
      for (const f of readdirSync(notesDir).filter((x) => x.endsWith('.json'))) {
        const coachId = f.slice(0, -5);
        const blob = loadJsonSafe(join(notesDir, f), { notes: '' });
        if (matchesQuery(q, blob.notes)) {
          // Encuentra la línea con el match
          const lines = (blob.notes || '').split('\n');
          const matchingLines = lines.filter((l) => l.toLowerCase().includes(q)).slice(0, 3);
          noteMatches.push({ coach_id: coachId, snippet: matchingLines.join(' · ').slice(0, 300) });
        }
      }
    }
    if (noteMatches.length) { results.coach_notes = noteMatches; total += noteMatches.length; }
  } catch { /* ignore */ }

  // ---- Coach threads ----
  try {
    const threadsDir = join(DATA_DIR, 'coach_threads');
    const threadMatches = [];
    if (existsSync(threadsDir)) {
      for (const f of readdirSync(threadsDir).filter((x) => x.endsWith('.json'))) {
        const coachId = f.slice(0, -5);
        const thread = loadJsonSafe(join(threadsDir, f), []);
        const hits = (thread || [])
          .filter((m) => matchesQuery(q, m.content))
          .slice(-5) // últimos 5 hits por coach
          .map((m) => ({
            role: m.role,
            ts: m.ts,
            snippet: (m.content || '').slice(0, 150),
          }));
        if (hits.length) threadMatches.push({ coach_id: coachId, hits });
      }
    }
    if (threadMatches.length) { results.coach_threads = threadMatches; total += threadMatches.reduce((acc, t) => acc + t.hits.length, 0); }
  } catch { /* ignore */ }

  return { query, total, results };
}
