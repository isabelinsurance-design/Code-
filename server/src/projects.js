// ============================================================
//  Projects — agrupación cross-domain (tareas + commitments +
//  tickets LUNA + emails) por meta común.
//  ────────────────────────────────────────────────────────
//  Filosofía: "AEP 2026" no vive en un solo sistema. Tiene tareas
//  tuyas, tickets de Sami en LUNA, emails de carriers, citas con
//  clientes, decisiones de marketing, etc. El "proyecto" es la
//  vista unificada de todo eso.
//
//  Modelo simple, storage en data/projects.json:
//   {
//     id, slug, nombre, descripcion, color, status (activo/pausado/cerrado),
//     fecha_inicio, fecha_meta, fecha_creacion,
//     linked_tasks: [taskId],
//     linked_commitments: [commitId],
//     linked_tickets_luna: [ticketId],
//     linked_emails: [{ messageId, asunto, from }],
//   }
//
//  Las tareas y commitments PUEDEN tener project_id en su propio
//  schema (opcional, additive). Para LUNA y emails, mantenemos el
//  link aquí porque no controlamos esos sistemas.
// ============================================================
import { writeFileSync, readFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const FILE = join(__dirname, '..', 'data', 'projects.json');

const VALID_STATUS = ['activo', 'pausado', 'cerrado'];

const COLORS = ['lino', 'amber', 'sage', 'plum', 'sienna', 'slate'];

function loadAll() {
  try {
    if (!existsSync(FILE)) return [];
    return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch { return []; }
}

function saveAll(list) {
  try {
    if (!existsSync(dirname(FILE))) mkdirSync(dirname(FILE), { recursive: true });
    writeFileSync(FILE, JSON.stringify(list, null, 2));
  } catch (e) { console.warn('[projects] save falló:', e.message); }
}

function nowIso() { return new Date().toISOString(); }

function slugify(s) {
  return String(s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
    .replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '').slice(0, 40);
}

function newId() {
  return `pr_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

export function listProjects({ status = null } = {}) {
  const all = loadAll();
  if (status) return all.filter((p) => p.status === status);
  return all;
}

export function getProject(idOrSlug) {
  const all = loadAll();
  return all.find((p) => p.id === idOrSlug || p.slug === idOrSlug) || null;
}

export function createProject({ nombre, descripcion = '', color = null, fecha_meta = null }) {
  if (!nombre) throw new Error('nombre es requerido');
  const all = loadAll();
  let slug = slugify(nombre);
  if (all.find((p) => p.slug === slug)) {
    slug = `${slug}_${Date.now().toString(36).slice(-4)}`;
  }
  const p = {
    id: newId(),
    slug,
    nombre,
    descripcion,
    color: color || COLORS[all.length % COLORS.length],
    status: 'activo',
    fecha_inicio: nowIso(),
    fecha_meta,
    fecha_creacion: nowIso(),
    linked_tasks: [],
    linked_commitments: [],
    linked_tickets_luna: [],
    linked_emails: [],
  };
  all.push(p);
  saveAll(all);
  return p;
}

export function updateProject(id, patch) {
  const all = loadAll();
  const i = all.findIndex((p) => p.id === id || p.slug === id);
  if (i < 0) return null;
  if (patch.status && !VALID_STATUS.includes(patch.status)) {
    throw new Error(`status debe ser uno de: ${VALID_STATUS.join(', ')}`);
  }
  all[i] = { ...all[i], ...patch, actualizado: nowIso() };
  saveAll(all);
  return all[i];
}

export function deleteProject(id) {
  const all = loadAll();
  const filtered = all.filter((p) => p.id !== id && p.slug !== id);
  if (filtered.length === all.length) return { ok: false, error: 'no existe' };
  saveAll(filtered);
  return { ok: true };
}

function linkArray(p, key, value) {
  if (!Array.isArray(p[key])) p[key] = [];
  if (!p[key].includes(value)) p[key].push(value);
}

function unlinkArray(p, key, value) {
  if (!Array.isArray(p[key])) p[key] = [];
  p[key] = p[key].filter((v) => v !== value);
}

export function linkItem(projectIdOrSlug, kind, itemId) {
  const all = loadAll();
  const i = all.findIndex((p) => p.id === projectIdOrSlug || p.slug === projectIdOrSlug);
  if (i < 0) return { ok: false, error: 'proyecto no existe' };
  const key = `linked_${kind}`;
  if (kind === 'emails') {
    if (!all[i].linked_emails) all[i].linked_emails = [];
    if (!all[i].linked_emails.find((e) => e.messageId === itemId.messageId)) {
      all[i].linked_emails.push(itemId);
    }
  } else if (kind === 'tasks' || kind === 'commitments' || kind === 'tickets_luna') {
    linkArray(all[i], key, itemId);
  } else {
    return { ok: false, error: `kind desconocido: ${kind}` };
  }
  all[i].actualizado = nowIso();
  saveAll(all);
  return { ok: true, project: all[i] };
}

export function unlinkItem(projectIdOrSlug, kind, itemId) {
  const all = loadAll();
  const i = all.findIndex((p) => p.id === projectIdOrSlug || p.slug === projectIdOrSlug);
  if (i < 0) return { ok: false, error: 'proyecto no existe' };
  if (kind === 'emails') {
    all[i].linked_emails = (all[i].linked_emails || []).filter((e) => e.messageId !== itemId);
  } else {
    unlinkArray(all[i], `linked_${kind}`, itemId);
  }
  all[i].actualizado = nowIso();
  saveAll(all);
  return { ok: true };
}

// Resuelve un proyecto a su vista completa: cuenta items + trae los items reales.
export async function expandProject(idOrSlug) {
  const p = getProject(idOrSlug);
  if (!p) return null;
  const view = { ...p, items: { tasks: [], commitments: [], tickets_luna: [], emails: p.linked_emails || [] } };

  // Tasks
  try {
    const { listTasks } = await import('./tasks.js');
    const all = listTasks({}) || [];
    view.items.tasks = all.filter((t) => p.linked_tasks?.includes(t.id));
  } catch { /* ignore */ }

  // Commitments
  try {
    const { listCommitments } = await import('./commitments.js');
    const all = listCommitments({}) || [];
    view.items.commitments = all.filter((c) => p.linked_commitments?.includes(c.id));
  } catch { /* ignore */ }

  // LUNA tickets — query y filtra
  if (p.linked_tickets_luna?.length) {
    try {
      const { lunaConfigured, openTickets } = await import('./luna_client.js');
      if (lunaConfigured()) {
        const r = await openTickets({ priority: '' });
        if (r.ok && Array.isArray(r.data)) {
          view.items.tickets_luna = r.data.filter((t) =>
            p.linked_tickets_luna.includes(String(t.id))
          );
        }
      }
    } catch { /* ignore */ }
  }

  // Totales
  view.counts = {
    tasks: view.items.tasks.length,
    commitments: view.items.commitments.length,
    tickets_luna: view.items.tickets_luna.length,
    emails: view.items.emails.length,
    total:
      view.items.tasks.length +
      view.items.commitments.length +
      view.items.tickets_luna.length +
      view.items.emails.length,
  };
  return view;
}

// Snapshot ligero para la lista — counts solamente.
export function listProjectsWithCounts() {
  const all = loadAll();
  return all.map((p) => ({
    ...p,
    counts: {
      tasks: (p.linked_tasks || []).length,
      commitments: (p.linked_commitments || []).length,
      tickets_luna: (p.linked_tickets_luna || []).length,
      emails: (p.linked_emails || []).length,
      total:
        (p.linked_tasks || []).length +
        (p.linked_commitments || []).length +
        (p.linked_tickets_luna || []).length +
        (p.linked_emails || []).length,
    },
  }));
}
