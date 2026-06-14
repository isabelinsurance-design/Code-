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
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { anthropic } from './claude.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'trends.json');

const DEFAULT_TOPICS = [
  {
    id: 'chief_of_staff',
    nombre: 'Chief of Staff — cómo mejoramos Athena+Isabel',
    queries: [
      'AI personal assistant chief of staff trending 2026',
      'executive operating system entrepreneur breakthrough',
      'AI agent workflow chief of staff framework viral',
    ],
    // Esta es la lente META — vive en primer lugar y pesa más
    // (max_items: 5 vs 2-3 de las otras). Su prompt es DIFERENTE
    // al resto: 60% análisis de uso interno propio, 40% trends
    // externos correlacionados. Ver scanTopic para el comportamiento
    // diferenciado.
    max_items: 5,
    is_meta: true,
    contexto_isabel: `Esta lente es META — soy Athena (AI Chief of Staff) mirando MI PROPIA práctica. Mi job aquí es 60% AUTO-REFLEXIÓN sobre cómo opero, 40% scout de tendencias externas en sistemas tipo chief-of-staff.

PRIORIZACIÓN: PRIMERO el análisis interno (qué estoy usando, qué no, qué workflows son lentos), DESPUÉS lo externo. Cada hit debe proponer un CAMBIO CONCRETO a Athena o a la forma en que Isabel y yo trabajamos juntas.

TIPOS DE HIT (mezcla):
- "No usás X" — feature existente que ha quedado sin uso → reactivar o deprecar
- "Workflow lento Y" — patrón repetitivo detectado en activity → automatizar
- "Falta Z" — capacidad que otros sistemas tienen y nosotras no
- "Sobra W" — feature que añade carga sin valor → quitar

El campo razon_isabel es una RECOMENDACIÓN para Sami/Isabel ("crea tarea para Sami: implementar X", "borrar tool Y", "agregar prompt Z al briefing").

[CONTEXTO INTERNO se inserta dinámicamente abajo]`,
  },
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

// Snapshot interno de Athena — últimos 7 días — para alimentar la
// lente "chief_of_staff" del Radar con contexto real. Sin esto, el
// scout solo ve tendencias externas; CON esto puede correlacionar
// "feature X está trending afuera Y nosotros usamos X mucho" o al
// revés ("feature Z nadie usa, considerar deprecarla").
// Lista canónica de tools que Athena puede usar — para detectar las
// no-usadas. Mantener sincronizado vagamente con tools.js, pero no es
// crítico (un missing tool aquí solo significa que no aparece en
// "candidates a revisar"; no rompe nada).
const KNOWN_TOOLS = [
  // Memoria
  'recordar', 'olvidar', 'que_recuerdas', 'actualizar_temporada', 'historial',
  // Tasks / commitments
  'crear_tarea', 'mis_tareas', 'completar_tarea', 'cancelar_tarea',
  'comprometer_entrega', 'mis_compromisos', 'marcar_cumplido', 'marcar_fallido',
  // Entidades
  'entidad_anotar', 'entidad_buscar', 'entidad_expediente',
  // Coaches
  'consultar_especialistas',
  // Communications
  'enviar_email', 'enviar_sms', 'mensaje_a_sami', 'confirmar_envio', 'revisar_emails',
  'llamar_cliente',
  // Calendar
  'proximos_eventos', 'crear_cita', 'reagendar_cita', 'cancelar_cita', 'buscar_huecos',
  // Built-in
  'web_search',
  // Journal / rapport / reading / trends / brainstorm
  'journal_entrada', 'journal_buscar', 'journal_resumen_dia',
  'rapport_semanal', 'mi_rapport',
  'reading_agregar', 'reading_lista', 'reading_resumen', 'reading_marcar',
  'trends_pendientes', 'trends_scan_ahora',
  'brainstorm_estructurado',
  // Skills
  'skill_proponer', 'skill_invocar', 'skills_lista',
  // Señales
  'senales_de_hoy',
];

function buildAthenaInternalSnapshot() {
  const activityFile = join(DATA_DIR, 'activity.json');
  const signalsFile = join(DATA_DIR, 'signals.json');
  const tasksFile = join(DATA_DIR, 'tasks.json');
  const gradesFile = join(DATA_DIR, 'self_grades.json');
  let activity = [];
  let signals = { signals: [] };
  let tasks = [];
  let grades = [];
  try { if (existsSync(activityFile)) activity = JSON.parse(readFileSync(activityFile, 'utf8')); } catch { /* ignore */ }
  try { if (existsSync(signalsFile)) signals = JSON.parse(readFileSync(signalsFile, 'utf8')); } catch { /* ignore */ }
  try { if (existsSync(tasksFile)) tasks = JSON.parse(readFileSync(tasksFile, 'utf8')); } catch { /* ignore */ }
  try { if (existsSync(gradesFile)) grades = JSON.parse(readFileSync(gradesFile, 'utf8')); } catch { /* ignore */ }

  const sevenDaysAgo = Date.now() - 7 * 86_400_000;
  const fourteenDaysAgo = Date.now() - 14 * 86_400_000;
  const recent7 = (activity || []).filter((a) => new Date(a.ts || 0).getTime() >= sevenDaysAgo);
  const recent14 = (activity || []).filter((a) => new Date(a.ts || 0).getTime() >= fourteenDaysAgo);

  // Tool counts ventana 7d
  const byTool7 = {};
  let errors7 = 0;
  for (const a of recent7) {
    byTool7[a.tool] = (byTool7[a.tool] || 0) + 1;
    const blob = `${a.result_summary || ''} ${a.input_summary || ''}`.toLowerCase();
    if (/error|falló|fail|timeout|no pude/.test(blob)) errors7 += 1;
  }
  const topUsed = Object.entries(byTool7).sort((a, b) => b[1] - a[1]).slice(0, 10);

  // Coaches consultados (de consultar_especialistas calls)
  const coachUsage = {};
  for (const a of recent7) {
    if (a.tool === 'consultar_especialistas') {
      // input_summary suele tener pista del coach — heurística simple
      const m = (a.input_summary || '').match(/\b(carmen|rivera|sofia|pilar|elena|alma|rosa|marisol|lucia|catalina|beatriz|esperanza|victoria|luna|aurora|valentina|dolores|paloma|nora|vida|ines)\b/i);
      if (m) {
        let key = m[1].toLowerCase();
        if (key === 'pilar') key = 'luna'; // legacy log entries
        coachUsage[key] = (coachUsage[key] || 0) + 1;
      }
    }
  }
  const allCoaches = ['carmen', 'rivera', 'sofia', 'luna', 'elena', 'alma', 'rosa', 'marisol', 'lucia', 'catalina', 'beatriz', 'esperanza', 'victoria', 'aurora', 'valentina', 'dolores', 'paloma', 'nora', 'vida', 'ines'];
  const coachesNoUsados = allCoaches.filter((c) => !coachUsage[c]);
  const topCoaches = Object.entries(coachUsage).sort((a, b) => b[1] - a[1]).slice(0, 5);

  // Tools no usadas en 7d (de la lista conocida)
  const usedSet = new Set(Object.keys(byTool7));
  const toolsNoUsadas = KNOWN_TOOLS.filter((t) => !usedSet.has(t));

  // Tareas atrasadas por dueño
  const atrasadas = (tasks || []).filter((t) => t.status !== 'lista' && t.status !== 'cancelada' && t.vence && new Date(t.vence) < new Date());
  const atrasadasPorDueno = {};
  for (const t of atrasadas) atrasadasPorDueno[t.responsable] = (atrasadasPorDueno[t.responsable] || 0) + 1;

  // Señales activas
  const sigsByPrio = { alto: 0, aviso: 0, info: 0 };
  for (const s of signals.signals || []) sigsByPrio[s.severidad] = (sigsByPrio[s.severidad] || 0) + 1;

  // Comparación vs semana previa
  const recent7_14 = recent14.filter((a) => new Date(a.ts || 0).getTime() < sevenDaysAgo);
  const delta = recent7.length - recent7_14.length;
  const deltaPct = recent7_14.length > 0 ? Math.round((delta / recent7_14.length) * 100) : null;

  // Patrones de workflow: detectar repetición de la misma tool >5 veces seguidas
  // (sugiere fricción — podría ser una skill / automation)
  const workflowFriction = [];
  let lastTool = null;
  let streak = 1;
  for (const a of recent7) {
    if (a.tool === lastTool) {
      streak += 1;
      if (streak === 5) workflowFriction.push(a.tool);
    } else {
      streak = 1;
      lastTool = a.tool;
    }
  }

  // Último self-grade (si existe) para self-awareness comparativa
  const lastGrade = (grades || []).length ? (grades || [])[grades.length - 1] : null;

  return [
    `=== SNAPSHOT INTERNO DE ATHENA (últimos 7 días) ===`,
    `Tool calls: ${recent7.length} (${deltaPct !== null ? `${delta >= 0 ? '+' : ''}${deltaPct}% vs sem prev` : 'sin comparación'}). Errores: ${errors7}.`,
    `Top 10 tools usadas: ${topUsed.map(([t, n]) => `${t}×${n}`).join(', ') || 'ninguna'}`,
    toolsNoUsadas.length ? `Tools NO usadas en 7d (revisar discoverability o deprecar): ${toolsNoUsadas.join(', ')}` : '',
    `Coaches consultados: ${topCoaches.length ? topCoaches.map(([c, n]) => `${c}×${n}`).join(', ') : 'NINGUNO'}.`,
    coachesNoUsados.length > 8 ? `⚠ Coaches NO usadas en 7d (${coachesNoUsados.length}/16 — Isabel solo usa una fracción de su equipo): ${coachesNoUsados.join(', ')}` : '',
    workflowFriction.length ? `⚠ Posible workflow lento detectado: tool(s) ${workflowFriction.join(', ')} usadas >5 veces seguidas — candidato a skill/automation.` : '',
    `Tareas atrasadas: ${Object.entries(atrasadasPorDueno).map(([k, v]) => `${k}=${v}`).join(', ') || 'ninguna'}`,
    `Señales activas: ${sigsByPrio.alto} alto, ${sigsByPrio.aviso} aviso, ${sigsByPrio.info} info`,
    lastGrade ? `Último self-grade (${lastGrade.semana}): ${lastGrade.score}/100. Cambio propuesto: "${lastGrade.cambio_propuesto || '(ninguno)'}". ¿Se implementó? ${lastGrade.implementado ? 'sí' : 'pendiente'}.` : '',
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
function save(d) { ensureDir(); atomicWriteJson(FILE, d); }
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
  const isMeta = topic.is_meta === true || topic.id === 'chief_of_staff';
  const maxItems = topic.max_items || (isMeta ? 5 : 3);

  // Lente meta: inyectamos snapshot interno y usamos prompt distinto
  // que prioriza auto-reflexión sobre scout externo.
  let contextoEnriquecido = topic.contexto_isabel;
  if (isMeta) {
    try {
      const snap = buildAthenaInternalSnapshot();
      contextoEnriquecido = `${topic.contexto_isabel}\n\n${snap}`;
    } catch { /* fallback al contexto base */ }
  }

  const promptMeta = `Tu trabajo: producir ${maxItems} propuestas concretas de MEJORA al sistema Athena, basadas en (a) análisis del snapshot interno arriba y (b) tendencias externas cuando aplique.

PRIORIDAD: 60% del valor viene del análisis INTERNO (qué muestra el snapshot), 40% del scout externo correlativo. NO traigas hits que solo sean "trend afuera" sin conexión a un patrón interno propio.

CONTEXTO + SNAPSHOT INTERNO:
${contextoEnriquecido}

QUERIES SUGERIDAS PARA SCOUT EXTERNO (úsalas SOLO si correlacionan con algo del snapshot):
${topic.queries.map((q) => `- ${q}`).join('\n')}

INSTRUCCIONES:
1. Empieza por el snapshot: ¿qué te dice sobre coaches no usadas, tools sin uso, workflows lentos, errores, deltas vs semana previa?
2. Para cada propuesta, identifica: el patrón observado → el cambio concreto → el dueño (Athena code change, Sami operacional, Isabel comportamental).
3. Si el snapshot muestra "X no se usa en 7d" → propón reactivar o deprecar.
4. Si el snapshot muestra workflow friction → propón skill o automation.
5. Si el snapshot muestra error rate → propón fix o detección.
6. Usa web_search SOLO para validar/enriquecer 1-2 propuestas con cómo otros sistemas resuelven el mismo problema.

FORMATO DE SALIDA (JSON puro, sin markdown ni código blocks):
[
  {
    "titulo": "string — el cambio propuesto en una línea",
    "summary": "string — 2-3 frases. Patrón observado en el snapshot + cómo resolverlo. Sé específico con qué tool/feature/cron afectado.",
    "razon_isabel": "string — la acción concreta. Ej: 'crear tarea para Sami: deprecar tool X', 'agregar prompt Y al briefing', 'Isabel: probar feature Z'.",
    "url": "string o null (URL externa si correlaciona con un trend)",
    "score": "integer 1-10 — qué tan impactante y bajo-esfuerzo es la propuesta"
  }
]

Si NO hay patrón claro en el snapshot ni trend útil afuera, regresa [].`;

  const promptDominio = `Tu trabajo: encontrar 1-3 cosas REALMENTE NOTABLES (trending, viral, breaking, breakthrough) en el dominio "${topic.nombre}" en los ÚLTIMOS 7 DÍAS.

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

  const prompt = isMeta ? promptMeta : promptDominio;

  try {
    const res = await anthropic.messages.create({
      model: 'claude-sonnet-4-6',
      // Lente META obtiene más tokens (5 items vs 3) y más búsquedas
      // permitidas porque puede validar varias propuestas con externos.
      max_tokens: isMeta ? 2500 : 1500,
      tools: [{ type: 'web_search_20250305', name: 'web_search', max_uses: isMeta ? 3 : 2 }],
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
