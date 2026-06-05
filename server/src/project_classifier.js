// ============================================================
//  Project Classifier — auto-grupa items en proyectos
//  ────────────────────────────────────────────────────
//  Cuando Athena (u otro flujo) crea una tarea, compromiso, o
//  ticket LUNA, este módulo evalúa si pertenece a un proyecto
//  activo y lo vincula automáticamente.
//
//  Usa Haiku 4.5 (~$0.0008 por clasificación) — barato y rápido.
//
//  Reglas:
//   - Solo proyectos status='activo' son candidatos
//   - Si no hay proyectos activos → no hace nada
//   - Si confidence < 70% → no vincula (mejor errar por omitir)
//   - Si vincula, lo marca como `auto_grouped=true` para que se
//     pueda distinguir de los manuales
//
//  El override siempre es de Isabel: standing order "no auto-group"
//  lo desactiva globalmente.
// ============================================================
import Anthropic from '@anthropic-ai/sdk';

const HAIKU = process.env.HAIKU_MODEL || 'claude-haiku-4-5-20251001';
const MIN_CONFIDENCE = 0.7;

const SYSTEM = `Eres un clasificador silencioso. Tu único job: dado un item nuevo y la lista de proyectos activos de Isabel, decidir si pertenece a UN proyecto.

Reglas:
- Solo agrupas si la pertenencia es CLARA. Si dudas, devuelve null.
- "Llamar Anthem por Maritza" + proyecto "AEP 2026" → agrupar (cliente Medicare en AEP)
- "Comprar leche" → null (no es de ningún proyecto laboral)
- Si un item podría caber en 2 proyectos, escoge el más específico
- NUNCA inventes proyectos. Solo escoges de la lista que te dan.

Devuelve SOLO JSON exacto:
{"project_slug": "<slug>" | null, "confidence": 0.0-1.0, "reason": "<1 frase>"}`;

export async function classifyToProject({ kind, title, description = '', context = '' }) {
  if (!process.env.ANTHROPIC_API_KEY) return null;

  // Trae proyectos activos
  let activeProjects = [];
  try {
    const { listProjects } = await import('./projects.js');
    activeProjects = (listProjects({ status: 'activo' }) || []);
  } catch { return null; }
  if (!activeProjects.length) return null;

  const projectList = activeProjects
    .map((p) => `[${p.slug}] ${p.nombre}${p.descripcion ? ` — ${p.descripcion}` : ''}`)
    .join('\n');

  const userMsg = `ITEM NUEVO:
Tipo: ${kind}
Título: ${title}
${description ? `Descripción: ${description}\n` : ''}${context ? `Contexto: ${context}\n` : ''}

PROYECTOS ACTIVOS DE ISABEL:
${projectList}

¿Este item pertenece a alguno?`;

  const client = new Anthropic({ apiKey: process.env.ANTHROPIC_API_KEY });
  try {
    const r = await client.messages.create({
      model: HAIKU,
      max_tokens: 150,
      system: SYSTEM,
      messages: [{ role: 'user', content: userMsg }],
    });
    const text = r.content?.[0]?.text || '{}';
    const match = text.match(/\{[\s\S]*\}/);
    if (!match) return null;
    const parsed = JSON.parse(match[0]);
    if (!parsed.project_slug) return null;
    if (typeof parsed.confidence !== 'number' || parsed.confidence < MIN_CONFIDENCE) return null;
    // Verifica que el slug exista
    const proj = activeProjects.find((p) => p.slug === parsed.project_slug);
    if (!proj) return null;
    return {
      project_id: proj.id,
      project_slug: proj.slug,
      project_nombre: proj.nombre,
      confidence: parsed.confidence,
      reason: parsed.reason || '',
    };
  } catch (e) {
    console.warn('[project_classifier] falló:', e.message);
    return null;
  }
}

// Chequea si el auto-grouping está desactivado globalmente por standing order
async function autoGroupDisabled() {
  try {
    const { listOrders } = await import('./standing_orders.js');
    const orders = listOrders({ status: 'activa' });
    return orders.some((o) =>
      /no\s+auto[-_\s]*group|desactivar?\s+auto[-_\s]*group|don.?t\s+auto[-_\s]*group/i.test(o.regla)
    );
  } catch { return false; }
}

// Wrapper que se llama después de crear un item. Auto-linkea si aplica.
// Retorna { auto_grouped: bool, project_slug: string|null, reason: string }
export async function autoGroupItem({ kind, itemId, title, description = '', context = '' }) {
  // Standing order override
  if (await autoGroupDisabled()) {
    return { auto_grouped: false, project_slug: null, reason: 'auto-group desactivado por standing order' };
  }

  const cls = await classifyToProject({ kind, title, description, context });
  if (!cls) return { auto_grouped: false, project_slug: null, reason: 'no match' };

  // Vincula
  try {
    const { linkItem } = await import('./projects.js');
    const projectKind =
      kind === 'task' || kind === 'tarea' ? 'tasks' :
      kind === 'commitment' || kind === 'compromiso' ? 'commitments' :
      kind === 'ticket_luna' || kind === 'luna_ticket' ? 'tickets_luna' :
      null;
    if (!projectKind) return { auto_grouped: false, project_slug: null, reason: `kind ${kind} no mapeado` };
    const r = linkItem(cls.project_id, projectKind, String(itemId));
    if (!r.ok) return { auto_grouped: false, project_slug: null, reason: r.error };
    // Log para activity feed
    try {
      const { logActivity } = await import('./memory.js');
      logActivity({
        tool: 'auto_grouped',
        input_summary: `${kind} #${itemId} → ${cls.project_slug}`,
        result_summary: `${(cls.confidence * 100).toFixed(0)}% · ${cls.reason}`,
      });
    } catch { /* ignore */ }
    return {
      auto_grouped: true,
      project_slug: cls.project_slug,
      project_nombre: cls.project_nombre,
      confidence: cls.confidence,
      reason: cls.reason,
    };
  } catch (e) {
    return { auto_grouped: false, project_slug: null, reason: e.message };
  }
}

// === RECLASIFICAR — pasa por items sin proyecto y trata de agruparlos
// Útil para meter en una cron diaria, o para que Isabel lo dispare manualmente.
export async function reclassifyOrphans({ kinds = ['tasks', 'commitments'] } = {}) {
  const results = { processed: 0, grouped: 0, items: [] };
  try {
    const { listProjects } = await import('./projects.js');
    const projects = listProjects({ status: 'activo' }) || [];
    if (!projects.length) return results;

    // Set de IDs ya linkeados (para saber cuáles son huerfanos)
    const linked = { tasks: new Set(), commitments: new Set(), tickets_luna: new Set() };
    for (const p of projects) {
      (p.linked_tasks || []).forEach((id) => linked.tasks.add(String(id)));
      (p.linked_commitments || []).forEach((id) => linked.commitments.add(String(id)));
      (p.linked_tickets_luna || []).forEach((id) => linked.tickets_luna.add(String(id)));
    }

    if (kinds.includes('tasks')) {
      const { listTasks } = await import('./tasks.js');
      const tasks = (listTasks({ status: 'pendiente' }) || []).filter((t) => !linked.tasks.has(String(t.id)));
      for (const t of tasks.slice(0, 30)) {  // máx 30 por corrida (cost guard)
        results.processed++;
        const r = await autoGroupItem({
          kind: 'task',
          itemId: t.id,
          title: t.descripcion || t.titulo,
          description: t.contexto || '',
        });
        if (r.auto_grouped) {
          results.grouped++;
          results.items.push({ kind: 'task', id: t.id, project: r.project_slug });
        }
      }
    }

    if (kinds.includes('commitments')) {
      const { listCommitments } = await import('./commitments.js');
      const cs = (listCommitments({ status: 'pendiente' }) || []).filter((c) => !linked.commitments.has(String(c.id)));
      for (const c of cs.slice(0, 30)) {
        results.processed++;
        const r = await autoGroupItem({
          kind: 'commitment',
          itemId: c.id,
          title: `${c.persona}: ${c.descripcion}`,
          description: c.descripcion,
        });
        if (r.auto_grouped) {
          results.grouped++;
          results.items.push({ kind: 'commitment', id: c.id, project: r.project_slug });
        }
      }
    }
  } catch (e) {
    results.error = e.message;
  }
  return results;
}
