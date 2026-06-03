// ───────────────────────────────────────────────────────────────────
//  Trend scout — busca ACTIVAMENTE qué está volviéndose viral / trending
//  / breaking en los dominios de Isabel. Distinto de research.js (que
//  es topic-based de bajo ritmo): trends.js es time-sensitive, busca
//  "last 7 days", "trending", "viral", "breakout" y surfacea agresivo.
//
//  El cron diario lanza una ronda paralela de Sonnet con web_search por
//  cada dominio configurado, ranquea por "interestingness", y guarda
//  los top hits. Si hay algo realmente notable, manda WA proactivo.
//  Si no, solo deja el dump para que Isabel lo revise cuando quiera.
//
//  Dominios default — alineados con las áreas de crecimiento de Isabel:
//   - Medicare / insurance industry
//   - Brand & content (Latina creators, video, sales)
//   - Perimenopausia / hormones / women 50+ health
//   - Productividad / entrepreneurship / solopreneur tools
//   - Real estate / personal finance / wealth building
//
//  Storage: data/trends.json — { items: [...], topics: [...], updated }
// ───────────────────────────────────────────────────────────────────

import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { anthropic } from './claude.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'trends.json');

const DEFAULT_TOPICS = [
  {
    id: 'medicare',
    nombre: 'Medicare & Insurance',
    queries: [
      'Medicare news trending this week CMS broker',
      'Medicare Advantage 2027 plan changes breaking',
    ],
    contexto_isabel: 'Soy licensed Medicare agent en SoCal con 60-70 clientes. Necesito saber news CMS, cambios de carrier (SCAN, Anthem, Humana, Alignment, LA Care, Health Net, Molina, UHC), reglas nuevas brokers, AEP/OEP shifts.',
  },
  {
    id: 'brand',
    nombre: 'Brand & Content',
    queries: [
      'viral Latina founder content creator strategy this week',
      'trending Instagram Reels hook formula 2026',
      'YouTube short form video growth tactics breaking',
    ],
    contexto_isabel: 'Author de "Más completa, no más perfecta". Voz: Latina 53, espíritu emprendedor, espiritualidad práctica, anti-perfeccionismo. Audiencia: mujeres latinas 40-60. Plataformas: Instagram, YouTube.',
  },
  {
    id: 'health',
    nombre: 'Health 50+ / Perimenopausia',
    queries: [
      'perimenopause research new study breakthrough',
      'women 50 hormones HRT trending insights',
      'menopause longevity nutrition viral',
    ],
    contexto_isabel: '53 años, meta 168 lbs (de 178), 110g proteína/día, 80oz agua, workout 4x/sem. Sofía (Dra. Hormones) y Carmen (Chef Nutrición) son tus coaches.',
  },
  {
    id: 'productividad',
    nombre: 'Productividad / Solopreneur',
    queries: [
      'solopreneur AI tools breaking 2026',
      'time management chief of staff system viral',
      'productivity tools insurance broker trending',
    ],
    contexto_isabel: 'Maneja un equipo de 3 (Skarleth, Arlette, Samia) + asistente humana Sami. Usa Athena (AI chief of staff). Quiere automatizar repeticiones, no agregar capas.',
  },
  {
    id: 'wealth',
    nombre: 'Wealth & Personal Finance',
    queries: [
      'real estate investing trending 2026 women',
      'personal finance women 50+ wealth building viral',
    ],
    contexto_isabel: 'Construyendo wealth post-50. Negocio Medicare estable. CFO Elena la asesora en cashflow + decisiones financieras.',
  },
  {
    id: 'chief_of_staff',
    nombre: 'Chief of Staff — cómo mejoramos Athena+Isabel',
    queries: [
      'AI personal assistant chief of staff trending 2026',
      'executive operating system entrepreneur breakthrough',
      'AI agent workflow chief of staff framework viral',
    ],
    // Este texto se enriquece dinámicamente en scanTopic con el
    // snapshot interno de actividad real de Athena. Así el scout
    // correlaciona tendencias externas con patrones reales internos
    // y puede proponer cambios accionables ("nadie usa X — quítalo",
    // "feature Y del mundo nos falta", etc).
    contexto_isabel: `Esta lente es META — soy Athena (AI Chief of Staff de Isabel) mirando MI PROPIA práctica. Quiero saber:
1. Qué hacen otros sistemas AI chief-of-staff que YO no hago todavía.
2. Frameworks de operaciones / executive support que están emergiendo.
3. Patrones en human-AI work / agentic workflows trending.

FILTRO HARD: skip listicles "10 AI tools", skip "ChatGPT prompts", skip generic productivity content. Solo cosas que cambien CÓMO opero como chief-of-staff.

FORMATO ESPECIAL para esta lente: cada hit debe proponer un CAMBIO CONCRETO a Athena. Ej. "agregar feature X", "deprecar feature Y", "cambiar cadencia Z". El campo razon_isabel debe ser una RECOMENDACIÓN para Sami/Isabel, no solo una observación.

[CONTEXTO INTERNO se inserta dinámicamente abajo]`,
  },
];

// Snapshot interno de Athena — últimos 7 días — para alimentar la
// lente "chief_of_staff" del Radar con contexto real. Sin esto, el
// scout solo ve tendencias externas; CON esto puede correlacionar
// "feature X está trending afuera Y nosotros usamos X mucho" o al
// revés ("feature Z nadie usa, considerar deprecarla").
function buildAthenaInternalSnapshot() {
  const activityFile = join(DATA_DIR, 'activity.json');
  const signalsFile = join(DATA_DIR, 'signals.json');
  const tasksFile = join(DATA_DIR, 'tasks.json');
  let activity = [];
  let signals = { signals: [] };
  let tasks = [];
  try { if (existsSync(activityFile)) activity = JSON.parse(readFileSync(activityFile, 'utf8')); } catch { /* ignore */ }
  try { if (existsSync(signalsFile)) signals = JSON.parse(readFileSync(signalsFile, 'utf8')); } catch { /* ignore */ }
  try { if (existsSync(tasksFile)) tasks = JSON.parse(readFileSync(tasksFile, 'utf8')); } catch { /* ignore */ }

  const sevenDaysAgo = Date.now() - 7 * 86_400_000;
  const recent = (activity || []).filter((a) => new Date(a.ts || 0).getTime() >= sevenDaysAgo);

  // Tool counts
  const byTool = {};
  let errors = 0;
  for (const a of recent) {
    byTool[a.tool] = (byTool[a.tool] || 0) + 1;
    const blob = `${a.result_summary || ''} ${a.input_summary || ''}`.toLowerCase();
    if (/error|falló|fail|timeout|no pude/.test(blob)) errors += 1;
  }
  const topUsed = Object.entries(byTool).sort((a, b) => b[1] - a[1]).slice(0, 10);

  // Tools que existen pero NO se usaron — candidatos a deprecar / refactor.
  // Inferimos del set de tool names disponibles vs los que se invocaron.
  // (Si no tenemos lista canónica, lo skipeamos.)
  let unusedHint = '';
  try {
    // No queremos hard-require — fallback silencioso si tools.js cambia API.
    const usedSet = new Set(Object.keys(byTool));
    const knownCommonTools = [
      'recordar', 'olvidar', 'crear_tarea', 'consultar_especialistas',
      'enviar_email', 'enviar_sms', 'web_search', 'journal_entrada',
      'journal_buscar', 'rapport_semanal', 'brainstorm_estructurado',
      'reading_agregar', 'reading_resumen', 'trends_pendientes',
      'trends_scan_ahora', 'coach_notes_actualizar',
    ];
    const noUso = knownCommonTools.filter((t) => !usedSet.has(t));
    if (noUso.length) unusedHint = `Tools NO usadas en 7 días (candidatas a revisar discoverability): ${noUso.join(', ')}`;
  } catch { /* ignore */ }

  // Tareas atrasadas por dueño
  const atrasadas = (tasks || []).filter((t) => t.status !== 'lista' && t.status !== 'cancelada' && t.vence && new Date(t.vence) < new Date());
  const atrasadasPorDueno = {};
  for (const t of atrasadas) atrasadasPorDueno[t.responsable] = (atrasadasPorDueno[t.responsable] || 0) + 1;

  // Señales actuales por severidad
  const sigsByPrio = { alto: 0, aviso: 0, info: 0 };
  for (const s of signals.signals || []) sigsByPrio[s.severidad] = (sigsByPrio[s.severidad] || 0) + 1;

  return [
    `=== SNAPSHOT INTERNO DE ATHENA (últimos 7 días) ===`,
    `Tool calls totales: ${recent.length}. Errores detectados: ${errors}.`,
    `Top 10 tools más usadas: ${topUsed.map(([t, n]) => `${t}×${n}`).join(', ') || 'ninguna'}`,
    unusedHint,
    `Tareas atrasadas: ${Object.entries(atrasadasPorDueno).map(([k, v]) => `${k}=${v}`).join(', ') || 'ninguna'}`,
    `Señales activas: ${sigsByPrio.alto} alto, ${sigsByPrio.aviso} aviso, ${sigsByPrio.info} info`,
    `=== fin snapshot ===`,
  ].filter(Boolean).join('\n');
}

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() {
  try {
    if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch { /* ignore */ }
  return { items: [], topics: DEFAULT_TOPICS, updated: null };
}
function save(d) { ensureDir(); writeFileSync(FILE, JSON.stringify(d, null, 2)); }
function newId() { return `trend_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

export function getTrendTopics() {
  const d = load();
  const stored = d.topics?.length ? d.topics : [];
  // Merge: si DEFAULT_TOPICS tiene topics nuevos (ej. la lente chief_of_staff
  // agregada después), los agregamos sin perder ediciones del usuario en
  // topics existentes. Identificación por id.
  const byId = new Map(stored.map((t) => [t.id, t]));
  for (const def of DEFAULT_TOPICS) {
    if (!byId.has(def.id)) byId.set(def.id, def);
  }
  return [...byId.values()];
}

// Corre UN topic con Sonnet + web_search. Devuelve array de hits
// estructurados con titulo, summary, url (si Anthropic lo expone),
// score (1-10 — qué tan accionable / único), y razon (por qué importa
// para Isabel específicamente).
async function scanTopic(topic) {
  // Lente meta (chief_of_staff): inyectamos snapshot interno real para
  // que el scout correlacione tendencias externas con uso real interno.
  let contextoEnriquecido = topic.contexto_isabel;
  if (topic.id === 'chief_of_staff') {
    try {
      const snap = buildAthenaInternalSnapshot();
      contextoEnriquecido = `${topic.contexto_isabel}\n\n${snap}`;
    } catch { /* fallback al contexto base */ }
  }

  const prompt = `Tu trabajo: encontrar 1-3 cosas REALMENTE NOTABLES (trending, viral, breaking, breakthrough) en el dominio "${topic.nombre}" en los ÚLTIMOS 7 DÍAS.

CONTEXTO DE ISABEL:
${contextoEnriquecido}

QUERIES SUGERIDAS (úsalas o adapta):
${topic.queries.map((q) => `- ${q}`).join('\n')}

INSTRUCCIONES:
1. Usa web_search (max 2 búsquedas) — busca lo RECIENTE y lo TRENDING, no evergreen.
2. Filtra: skip listicles genéricos, "5 ways to...", contenido viejo reciclado.
3. Solo trae cosas accionables o intelectualmente notables PARA ISABEL ESPECÍFICAMENTE.
4. Si NO encontraste nada notable hoy, regresa array vacío. NO INVENTES contenido.

FORMATO DE SALIDA (JSON puro, sin markdown ni código blocks):
[
  {
    "titulo": "string — el titular real (no inventes)",
    "summary": "string — 2-3 frases. Qué es, por qué importa AHORA, qué la diferencia.",
    "razon_isabel": "string — 1 frase: cómo Isabel puede usarlo / aplicarlo / aprender de ello",
    "url": "string o null",
    "score": "integer 1-10 — qué tan accionable y único"
  }
]

Si no hay nada, regresa: []`;

  try {
    const res = await anthropic.messages.create({
      model: 'claude-sonnet-4-6',
      max_tokens: 1500,
      tools: [{ type: 'web_search_20250305', name: 'web_search', max_uses: 2 }],
      messages: [{ role: 'user', content: prompt }],
    });
    const text = res.content.filter((b) => b.type === 'text').map((b) => b.text).join('\n').trim();
    // Intentar parsear JSON. Tolerar wrappers comunes (code blocks).
    const cleaned = text.replace(/^```json\s*/i, '').replace(/^```\s*/, '').replace(/\s*```\s*$/, '').trim();
    let parsed;
    try {
      parsed = JSON.parse(cleaned);
    } catch {
      // Fallback: buscar el primer [ y último ]
      const start = cleaned.indexOf('[');
      const end = cleaned.lastIndexOf(']');
      if (start >= 0 && end > start) {
        parsed = JSON.parse(cleaned.slice(start, end + 1));
      } else {
        return [];
      }
    }
    if (!Array.isArray(parsed)) return [];
    return parsed.filter((h) => h && h.titulo && h.summary).map((h) => ({
      id: newId(),
      ts: new Date().toISOString(),
      topic_id: topic.id,
      topic_nombre: topic.nombre,
      titulo: String(h.titulo).slice(0, 200),
      summary: String(h.summary).slice(0, 600),
      razon_isabel: String(h.razon_isabel || '').slice(0, 300),
      url: h.url ? String(h.url) : null,
      score: Math.min(Math.max(parseInt(h.score, 10) || 5, 1), 10),
      status: 'pending',
    }));
  } catch (err) {
    console.warn(`[trends] scan ${topic.id} falló:`, err.message);
    return [];
  }
}

// Cron diario: corre todos los topics en paralelo, agrega hits, dedup,
// guarda. Si hay hits con score >= 8, devuelve esos para que el caller
// decida si surfacear proactivo en WhatsApp.
export async function runTrendScan() {
  const data = load();
  // Usa getTrendTopics() para auto-merge de nuevas DEFAULT_TOPICS — así
  // si agregamos lentes nuevas (ej. chief_of_staff) entran sin que el
  // usuario tenga que limpiar trends.json.
  const topics = getTrendTopics();
  const allHits = await Promise.all(topics.map((t) => scanTopic(t)));
  const flatHits = allHits.flat();

  // Dedup vs items existentes por título (case-insensitive, primeros 80 chars)
  const existing = new Set((data.items || []).map((i) => (i.titulo || '').toLowerCase().slice(0, 80)));
  const fresh = flatHits.filter((h) => !existing.has((h.titulo || '').toLowerCase().slice(0, 80)));

  // Cap a últimos 200 items totales
  const merged = [...(data.items || []), ...fresh].slice(-200);
  data.items = merged;
  data.topics = topics;
  data.updated = new Date().toISOString();
  save(data);

  const highScore = fresh.filter((h) => h.score >= 8);
  console.log(`[trends] scan completo: ${fresh.length} nuevos hits (${highScore.length} score≥8).`);
  return { fresh, highScore, total: merged.length };
}

export function listTrends({ status = 'pending', limit = 50, topic_id = null } = {}) {
  const data = load();
  let items = data.items || [];
  if (status) items = items.filter((i) => i.status === status);
  if (topic_id) items = items.filter((i) => i.topic_id === topic_id);
  return items.slice(-limit).reverse();
}

export function updateTrendStatus(id, status) {
  const data = load();
  const item = (data.items || []).find((i) => i.id === id);
  if (!item) throw new Error(`trend ${id} no existe`);
  if (!['pending', 'aplicado', 'archivado'].includes(status)) {
    throw new Error(`status inválido: ${status}`);
  }
  item.status = status;
  save(data);
  return item;
}

export function clearOldTrends({ olderThanDays = 30 } = {}) {
  const data = load();
  const cutoff = Date.now() - olderThanDays * 86400 * 1000;
  const before = (data.items || []).length;
  data.items = (data.items || []).filter((i) => new Date(i.ts).getTime() >= cutoff);
  save(data);
  return { borrados: before - data.items.length, restantes: data.items.length };
}

// Inline para contexto base de Athena — cuántos trends nuevos
// pendientes hay + top 1 high-score, para que pueda surfacear en
// evening recap.
export function buildTrendsInline() {
  const pending = listTrends({ status: 'pending', limit: 100 });
  if (!pending.length) return '';
  const topHit = pending.slice().sort((a, b) => b.score - a.score)[0];
  return `TRENDS: ${pending.length} sin revisar. Top hit (score ${topHit.score}/10) en ${topHit.topic_nombre}: "${topHit.titulo}"`;
}
