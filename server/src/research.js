// ============================================================
//  Research — digest diario de contenido relevante para Isabel
//  ────────────────────────────────────────────────────────────
//  Isabel pierde mucho tiempo scrolleando IG/YouTube buscando:
//    · Medicare news / CMS / carriers
//    · Brand-building / Latina founders / content creators
//    · Lo que ella defina (piano, lectura, recetas, etc.)
//
//  Athena ahora corre un digest diario al mediodía:
//    1. Para cada TEMA activo, hace 1 web_search con sus queries.
//    2. Sintetiza top 2-3 items por tema (link + 1-line por qué importa).
//    3. Manda UN solo WhatsApp con cards (1 por tema).
//
//  10 min de lectura vs 2 horas de scroll.
//
//  Browsear Instagram de TERCEROS NO se puede vía API oficial.
//  Athena solo lee tu IG (DMs/comentarios) — Phase 5.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'research_topics.json');

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() { try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {} return []; }
function save(d) { ensureDir(); atomicWriteJson(FILE, d.slice(-50)); }
function newId() { return `rt_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

// Crear / actualizar tema. queries es array de strings — Athena los rotará.
export function crearTema({ nombre, queries, fuente_hint = '', max_items = 2 }) {
  if (!nombre || !Array.isArray(queries) || !queries.length) {
    return { ok: false, error: 'Falta nombre o queries (array).' };
  }
  const data = load();
  const entry = {
    id: newId(),
    nombre: String(nombre).slice(0, 80),
    queries: queries.map((q) => String(q).slice(0, 200)).slice(0, 5),
    fuente_hint: String(fuente_hint).slice(0, 200), // ej: "prefiere YouTube y blogs especializados, no listicles"
    max_items: Math.min(5, Math.max(1, max_items)),
    activo: true,
    creado: new Date().toISOString(),
  };
  data.push(entry);
  save(data);
  return { ok: true, tema: entry };
}

export function listarTemas({ activos_solo = true } = {}) {
  return load().filter((t) => !activos_solo || t.activo);
}

export function pausarTema(id) {
  const data = load();
  const i = data.findIndex((t) => t.id === id);
  if (i < 0) return null;
  data[i].activo = !data[i].activo;
  save(data);
  return data[i];
}

export function eliminarTema(id) {
  const data = load();
  const i = data.findIndex((t) => t.id === id);
  if (i < 0) return null;
  const removed = data.splice(i, 1)[0];
  save(data);
  return removed;
}

// Seed inicial de temas relevantes para Isabel (idempotente — si ya existen, salta)
export function seedDefaultTopics() {
  const existing = load();
  const haveByName = (n) => existing.some((t) => t.nombre.toLowerCase() === n.toLowerCase());
  const seeds = [
    {
      nombre: 'Medicare News',
      queries: [
        'Medicare news CMS Final Rule brokers',
        'SCAN Anthem Humana Medicare Advantage news',
        'Medicare AEP 2027 changes plan',
      ],
      fuente_hint: 'prefiere fuentes oficiales (CMS, NAHU, carrier press releases). Skip listicles genéricos.',
      max_items: 2,
    },
    {
      nombre: 'Brand & Content Latina',
      queries: [
        'Latina founder content creator strategy',
        'personal brand 50+ women authentic',
        'YouTube channel growth tips solopreneur',
      ],
      fuente_hint: 'YouTube videos y blogs honestos > artículos de listicle. Busca creadoras 40+.',
      max_items: 2,
    },
    {
      nombre: 'Insurance Industry',
      queries: [
        'insurance agent productivity tools',
        'Medicare broker technology trends',
      ],
      fuente_hint: 'práctico — herramientas, tech, regulación. No marketing fluff.',
      max_items: 2,
    },
  ];
  const created = [];
  const skipped = [];
  for (const s of seeds) {
    if (haveByName(s.nombre)) { skipped.push(s.nombre); continue; }
    const r = crearTema(s);
    if (r.ok) created.push(r.tema.nombre);
  }
  return { created, skipped };
}

// Snapshot inline para context base (cuenta de temas activos)
export function buildResearchInline() {
  const activos = listarTemas().length;
  if (!activos) return '';
  return `research: ${activos} temas activos`;
}

// Bloque para el system context cuando se corre el digest:
// le da a Athena la lista exacta de temas y sus queries.
export function buildResearchTopicsBlock() {
  const temas = listarTemas();
  if (!temas.length) return null;
  const lines = ['TEMAS DE RESEARCH ACTIVOS:'];
  for (const t of temas) {
    lines.push(`\n[${t.nombre}] (max ${t.max_items} items)`);
    lines.push(`  Queries a rotar: ${t.queries.map((q) => `"${q}"`).join(' / ')}`);
    if (t.fuente_hint) lines.push(`  Hint: ${t.fuente_hint}`);
  }
  return lines.join('\n');
}
