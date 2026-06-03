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
];

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
  return d.topics?.length ? d.topics : DEFAULT_TOPICS;
}

// Corre UN topic con Sonnet + web_search. Devuelve array de hits
// estructurados con titulo, summary, url (si Anthropic lo expone),
// score (1-10 — qué tan accionable / único), y razon (por qué importa
// para Isabel específicamente).
async function scanTopic(topic) {
  const prompt = `Tu trabajo: encontrar 1-3 cosas REALMENTE NOTABLES (trending, viral, breaking, breakthrough) en el dominio "${topic.nombre}" en los ÚLTIMOS 7 DÍAS.

CONTEXTO DE ISABEL:
${topic.contexto_isabel}

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
  const topics = data.topics?.length ? data.topics : DEFAULT_TOPICS;
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
