// ============================================================
//  Brand & Content Pipeline — YouTube / IG / TikTok de Isabel
//  ────────────────────────────────────────────────────────────
//  Isabel quiere construir su YouTube y brand sin que se le
//  vuelva otro full-time job. Athena (vía Marisol) le mantiene
//  tres cosas vivas:
//
//   1. IDEAS — backlog de temas / hooks (descartables sin culpa)
//   2. CALENDAR — qué se publica cuándo, en qué plataforma
//   3. POSTS — log de lo publicado + métricas (cuando llegan)
//
//  El loop semanal: viernes Marisol propone 2-3 piezas para la
//  siguiente semana del backlog. Isabel aprueba/edita. Athena
//  agenda focus blocks de recording. Después de publicar, Isabel
//  pega métricas → Marisol detecta qué funciona.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'brand.json');

export const PLATAFORMAS = ['youtube', 'instagram_reel', 'instagram_carrusel', 'instagram_post', 'tiktok', 'blog', 'short'];
export const FORMATOS = ['educativo', 'storytelling', 'testimonio', 'q_and_a', 'detrás_escenas', 'tendencia', 'lista', 'tutorial'];
export const ESTADOS = ['idea', 'aprobada', 'grabando', 'editando', 'lista_publicar', 'publicada', 'archivada'];

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() {
  try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {}
  return { ideas: [], calendar: [], posts: [] };
}
function save(d) {
  ensureDir();
  // capa por capa, mantenemos lo último 500 / 300 / 500
  const trimmed = {
    ideas: (d.ideas || []).slice(-500),
    calendar: (d.calendar || []).slice(-300),
    posts: (d.posts || []).slice(-500),
  };
  atomicWriteJson(FILE, trimmed);
}
function newId(prefix) { return `${prefix}_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

// ---- IDEAS (backlog) ----
export function ideaAdd({ titulo, hook = '', notas = '', plataforma = '', formato = '', tema = '' }) {
  if (!titulo) return { ok: false, error: 'Falta título' };
  const data = load();
  const entry = {
    id: newId('bi'),
    titulo: String(titulo).slice(0, 140),
    hook: String(hook).slice(0, 200),
    notas: String(notas).slice(0, 600),
    plataforma: PLATAFORMAS.includes(plataforma) ? plataforma : '',
    formato: FORMATOS.includes(formato) ? formato : '',
    tema: String(tema).slice(0, 80), // ej: "Medicare", "Latina founder", "menopausia"
    estado: 'idea',
    salience: 1, // sube cuando Isabel la marca interesante
    creado: new Date().toISOString(),
  };
  data.ideas.push(entry);
  save(data);
  return { ok: true, idea: entry };
}

export function ideasList({ tema = null, plataforma = null, estado = 'idea' } = {}) {
  const data = load();
  let list = data.ideas;
  if (estado) list = list.filter((i) => i.estado === estado);
  if (tema) list = list.filter((i) => i.tema?.toLowerCase().includes(tema.toLowerCase()));
  if (plataforma) list = list.filter((i) => i.plataforma === plataforma);
  // ordenadas por salience desc luego fecha desc
  return list.sort((a, b) => (b.salience - a.salience) || (new Date(b.creado) - new Date(a.creado)));
}

export function ideaBump(id) {
  const data = load();
  const i = data.ideas.findIndex((x) => x.id === id);
  if (i < 0) return null;
  data.ideas[i].salience++;
  save(data);
  return data[i];
}

export function ideaArchivar(id) {
  const data = load();
  const i = data.ideas.findIndex((x) => x.id === id);
  if (i < 0) return null;
  data.ideas[i].estado = 'archivada';
  data.ideas[i].archivada = new Date().toISOString();
  save(data);
  return data.ideas[i];
}

// ---- CALENDAR (qué se publica cuándo) ----
export function calendarAdd({ titulo, plataforma, fecha, hook = '', idea_id = '', notas = '' }) {
  if (!titulo || !fecha || !plataforma) return { ok: false, error: 'Falta título, fecha o plataforma' };
  const data = load();
  const entry = {
    id: newId('bc'),
    titulo: String(titulo).slice(0, 140),
    plataforma,
    fecha: new Date(fecha).toISOString(),
    hook: String(hook).slice(0, 200),
    idea_id: String(idea_id).slice(0, 40),
    notas: String(notas).slice(0, 400),
    estado: 'aprobada',
    creado: new Date().toISOString(),
  };
  // Si vino de una idea, marca la idea como aprobada
  if (idea_id) {
    const i = data.ideas.findIndex((x) => x.id === idea_id);
    if (i >= 0) {
      data.ideas[i].estado = 'aprobada';
      data.ideas[i].calendar_id = entry.id;
    }
  }
  data.calendar.push(entry);
  save(data);
  return { ok: true, item: entry };
}

export function calendarProximas({ dias = 14 } = {}) {
  const data = load();
  const now = Date.now();
  const hasta = now + dias * 86400000;
  return data.calendar
    .filter((c) => {
      const t = new Date(c.fecha).getTime();
      return t >= now - 86400000 && t <= hasta && c.estado !== 'publicada' && c.estado !== 'archivada';
    })
    .sort((a, b) => new Date(a.fecha) - new Date(b.fecha));
}

export function calendarUpdateEstado(id, estado) {
  if (!ESTADOS.includes(estado)) return null;
  const data = load();
  const i = data.calendar.findIndex((c) => c.id === id);
  if (i < 0) return null;
  data.calendar[i].estado = estado;
  data.calendar[i].actualizado = new Date().toISOString();
  save(data);
  return data.calendar[i];
}

// ---- POSTS (publicado + métricas) ----
export function postRegistrar({ titulo, plataforma, fecha_publicacion, url = '', calendar_id = '', metricas = {} }) {
  if (!titulo || !plataforma) return { ok: false, error: 'Falta título o plataforma' };
  const data = load();
  const entry = {
    id: newId('bp'),
    titulo: String(titulo).slice(0, 140),
    plataforma,
    fecha_publicacion: fecha_publicacion ? new Date(fecha_publicacion).toISOString() : new Date().toISOString(),
    url: String(url).slice(0, 300),
    calendar_id: String(calendar_id).slice(0, 40),
    metricas: {
      vistas: metricas.vistas != null ? Number(metricas.vistas) : null,
      likes: metricas.likes != null ? Number(metricas.likes) : null,
      comentarios: metricas.comentarios != null ? Number(metricas.comentarios) : null,
      saves: metricas.saves != null ? Number(metricas.saves) : null,
      compartidos: metricas.compartidos != null ? Number(metricas.compartidos) : null,
      seguidores_nuevos: metricas.seguidores_nuevos != null ? Number(metricas.seguidores_nuevos) : null,
    },
    creado: new Date().toISOString(),
  };
  // Marca el calendar como publicado
  if (calendar_id) {
    const i = data.calendar.findIndex((c) => c.id === calendar_id);
    if (i >= 0) {
      data.calendar[i].estado = 'publicada';
      data.calendar[i].post_id = entry.id;
    }
  }
  data.posts.push(entry);
  save(data);
  return { ok: true, post: entry };
}

export function postsList({ desde = null, plataforma = null } = {}) {
  const data = load();
  let list = data.posts;
  if (plataforma) list = list.filter((p) => p.plataforma === plataforma);
  if (desde) {
    const t = new Date(desde).getTime();
    list = list.filter((p) => new Date(p.fecha_publicacion).getTime() >= t);
  }
  return list.sort((a, b) => new Date(b.fecha_publicacion) - new Date(a.fecha_publicacion));
}

export function postUpdateMetricas(id, metricas) {
  const data = load();
  const i = data.posts.findIndex((p) => p.id === id);
  if (i < 0) return null;
  data.posts[i].metricas = { ...data.posts[i].metricas, ...metricas };
  data.posts[i].metricas_actualizadas = new Date().toISOString();
  save(data);
  return data.posts[i];
}

// ---- Métricas agregadas (semana / mes) ----
export function statsLast30Days() {
  const data = load();
  const cutoff = Date.now() - 30 * 86400000;
  const recientes = data.posts.filter((p) => new Date(p.fecha_publicacion).getTime() >= cutoff);
  if (!recientes.length) return null;

  const sumar = (key) => recientes.reduce((s, p) => s + (p.metricas?.[key] || 0), 0);
  const promedio = (key) => recientes.length ? Math.round(sumar(key) / recientes.length) : 0;
  // Top 3 por vistas
  const top = [...recientes]
    .filter((p) => p.metricas?.vistas != null)
    .sort((a, b) => (b.metricas.vistas || 0) - (a.metricas.vistas || 0))
    .slice(0, 3);

  return {
    total_posts: recientes.length,
    por_plataforma: recientes.reduce((acc, p) => { acc[p.plataforma] = (acc[p.plataforma] || 0) + 1; return acc; }, {}),
    vistas_total: sumar('vistas'),
    vistas_promedio: promedio('vistas'),
    seguidores_nuevos: sumar('seguidores_nuevos'),
    engagement_promedio: promedio('likes') + promedio('comentarios') + promedio('saves'),
    top: top.map((p) => ({ titulo: p.titulo, plataforma: p.plataforma, vistas: p.metricas.vistas })),
  };
}

// ---- Context inline para memory.js ----
export function buildBrandInline() {
  const proximas = calendarProximas({ dias: 7 });
  const ideas = ideasList({ estado: 'idea' });
  if (!proximas.length && !ideas.length) return '';
  const parts = [];
  if (proximas.length) parts.push(`brand: ${proximas.length} publicaciones próximas 7d`);
  if (ideas.length) parts.push(`${ideas.length} ideas backlog`);
  return parts.join(' · ');
}

// ---- Context aumentado para Marisol cuando es consultada ----
export function buildBrandForMarisol() {
  const proximas = calendarProximas({ dias: 14 });
  const ideas = ideasList({ estado: 'idea' }).slice(0, 10);
  const stats = statsLast30Days();
  const lines = ['\n\n# CONTEXTO BRAND DE ISABEL'];
  if (proximas.length) {
    lines.push('\n## Próximo en calendario:');
    for (const c of proximas.slice(0, 5)) {
      lines.push(`- ${c.fecha.slice(0, 10)} · ${c.plataforma} · ${c.titulo}${c.hook ? ` ("${c.hook}")` : ''} [${c.estado}]`);
    }
  }
  if (ideas.length) {
    lines.push('\n## Backlog de ideas (top por salience):');
    for (const i of ideas.slice(0, 8)) {
      lines.push(`- [${i.tema || 'sin tema'}/${i.plataforma || '?'}] ${i.titulo}${i.hook ? ` — hook: "${i.hook}"` : ''}`);
    }
  }
  if (stats) {
    lines.push('\n## Performance últimos 30d:');
    lines.push(`${stats.total_posts} posts · ${stats.vistas_total} vistas total · prom ${stats.vistas_promedio} por post · +${stats.seguidores_nuevos} seguidores`);
    if (stats.top.length) {
      lines.push('Top performers:');
      for (const t of stats.top) lines.push(`- ${t.titulo} (${t.plataforma}) — ${t.vistas} vistas`);
    }
  }
  return lines.join('\n');
}

// ---- Briefing block ----
export function buildBrandBriefingBlock() {
  const proximas = calendarProximas({ dias: 3 });
  if (!proximas.length) return null;
  const lines = ['🎬 BRAND — próximas 72h'];
  for (const c of proximas) {
    const dia = new Date(c.fecha).toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric' });
    lines.push(`  · ${dia} · ${c.plataforma} · ${c.titulo} [${c.estado}]`);
  }
  return lines.join('\n');
}
