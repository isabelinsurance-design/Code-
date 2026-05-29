// ============================================================
//  Skills — playbooks reusables que Athena puede crecer sola
//  ──────────────────────────────────────────────────────────
//  Inspirado por Pepper (Caleb Sima) — pero con un trade-off
//  deliberado: NO le damos a Athena ejecución de código
//  arbitrario. Las skills son markdown que orquesta las tools
//  que ya tiene. Mismo loop ("aprende un patrón, codifícalo,
//  re-úsalo"), cero attack surface adicional.
//
//  Flujo:
//   1. Isabel + Athena descubren un patrón ("cada AEP hago
//      esto mismo con cada cliente").
//   2. Athena llama skill_proponer(...) → queda en status
//      "draft" en data/skills/.
//   3. Isabel dice "aprueba la skill AEP outreach" → status
//      pasa a "active".
//   4. La próxima vez que Isabel diga "prepara AEP de María",
//      Athena llama skill_invocar("aep_outreach_secuencia",
//      {cliente_id: "..."}) — Athena ejecuta el cuerpo como
//      sub-conversación con sus tools normales.
//
//  Las skills viven en data/skills/<nombre>.json. Una por archivo
//  para que Isabel o Sami las puedan abrir, leer, borrar manual.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync, readdirSync, unlinkSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const SKILLS_DIR = join(__dirname, '..', 'data', 'skills');

const STATUSES = ['draft', 'active', 'retired'];

// Normaliza un nombre a slug seguro (lo usamos como filename).
// No puede tener path-traversal ni espacios.
function safeName(name) {
  return String(name || '')
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9_]/g, '_')
    .replace(/_+/g, '_')
    .replace(/^_|_$/g, '')
    .slice(0, 60);
}

function ensureDir() {
  if (!existsSync(SKILLS_DIR)) mkdirSync(SKILLS_DIR, { recursive: true });
}

function pathFor(name) {
  return join(SKILLS_DIR, `${safeName(name)}.json`);
}

const nowIso = () => new Date().toISOString();

// ---- CRUD ----
export function proposeSkill({ nombre, descripcion, cuerpo, trigger = '', inputs_schema = [], propuesto_por = 'athena' }) {
  if (!nombre || !String(nombre).trim()) throw new Error('Falta nombre.');
  if (!descripcion) throw new Error('Falta descripción.');
  if (!cuerpo || cuerpo.length < 30) throw new Error('Cuerpo demasiado corto — describe pasos concretos.');
  const slug = safeName(nombre);
  if (!slug) throw new Error('Nombre inválido (solo letras, números, _).');
  ensureDir();

  const existing = loadSkill(slug);
  if (existing && existing.status === 'active') {
    throw new Error(`Ya existe una skill activa "${slug}". Para reemplazarla, primero retírala (skill_retirar).`);
  }

  const skill = {
    name: slug,
    nombre_humano: String(nombre).trim(),
    descripcion: String(descripcion).trim(),
    trigger: String(trigger || '').trim(),
    inputs_schema: Array.isArray(inputs_schema) ? inputs_schema : [],
    cuerpo: String(cuerpo).trim(),
    status: 'draft',
    propuesto_por,
    creado: nowIso(),
    aprobado_at: null,
    aprobado_por: null,
    version: existing ? (existing.version || 0) + 1 : 1,
    invocaciones: 0,
    ultima_invocacion: null,
  };
  writeFileSync(pathFor(slug), JSON.stringify(skill, null, 2));
  return skill;
}

export function approveSkill(nombre, aprobado_por = 'isabel') {
  const slug = safeName(nombre);
  const s = loadSkill(slug);
  if (!s) return null;
  s.status = 'active';
  s.aprobado_at = nowIso();
  s.aprobado_por = aprobado_por;
  writeFileSync(pathFor(slug), JSON.stringify(s, null, 2));
  return s;
}

export function retireSkill(nombre) {
  const slug = safeName(nombre);
  const s = loadSkill(slug);
  if (!s) return null;
  s.status = 'retired';
  writeFileSync(pathFor(slug), JSON.stringify(s, null, 2));
  return s;
}

export function deleteSkill(nombre) {
  const slug = safeName(nombre);
  const p = pathFor(slug);
  if (!existsSync(p)) return false;
  unlinkSync(p);
  return true;
}

export function loadSkill(nombre) {
  const slug = safeName(nombre);
  const p = pathFor(slug);
  if (!existsSync(p)) return null;
  try { return JSON.parse(readFileSync(p, 'utf8')); }
  catch { return null; }
}

export function listSkills({ status = null } = {}) {
  if (!existsSync(SKILLS_DIR)) return [];
  const files = readdirSync(SKILLS_DIR).filter((f) => f.endsWith('.json'));
  const out = [];
  for (const f of files) {
    try {
      const s = JSON.parse(readFileSync(join(SKILLS_DIR, f), 'utf8'));
      if (status && s.status !== status) continue;
      out.push(s);
    } catch { /* skip bad files */ }
  }
  return out.sort((a, b) => (b.invocaciones || 0) - (a.invocaciones || 0));
}

// Registra que se invocó (no ejecuta — la ejecución vive en tools.js
// donde tenemos acceso a runDirectora sin ciclo de imports).
export function markInvoked(nombre) {
  const slug = safeName(nombre);
  const s = loadSkill(slug);
  if (!s) return null;
  s.invocaciones = (s.invocaciones || 0) + 1;
  s.ultima_invocacion = nowIso();
  writeFileSync(pathFor(slug), JSON.stringify(s, null, 2));
  return s;
}

// Vista corta para el contexto persistente: Athena necesita saber
// qué skills tiene a la mano sin pagar tokens por cada cuerpo.
export function buildSkillsContext() {
  const skills = listSkills({ status: 'active' });
  if (!skills.length) return '';
  const lines = skills.slice(0, 20).map((s) => {
    const trig = s.trigger ? ` (trigger: "${s.trigger.slice(0, 50)}")` : '';
    return `  - [${s.name}] ${s.descripcion}${trig}`;
  });
  return `SKILLS ACTIVAS (playbooks aprobados — llama skill_invocar(nombre, inputs) para correr):\n${lines.join('\n')}`;
}

// Resumen humano para listar
export function skillCard(s) {
  if (!s) return '';
  const lines = [
    `${s.nombre_humano} [${s.name}]   v${s.version} · ${s.status}`,
    `Descripción: ${s.descripcion}`,
  ];
  if (s.trigger) lines.push(`Trigger: ${s.trigger}`);
  if (s.inputs_schema?.length) {
    lines.push(`Inputs: ${s.inputs_schema.map((i) => `${i.nombre}${i.requerido === false ? '?' : ''}: ${i.descripcion || ''}`).join(', ')}`);
  }
  lines.push(`Creado: ${s.creado?.slice(0, 10)} por ${s.propuesto_por}${s.aprobado_at ? ` · aprobado ${s.aprobado_at.slice(0, 10)} por ${s.aprobado_por}` : ''}`);
  lines.push(`Usos: ${s.invocaciones || 0}${s.ultima_invocacion ? ` (última ${s.ultima_invocacion.slice(0, 10)})` : ''}`);
  lines.push('');
  lines.push('--- CUERPO ---');
  lines.push(s.cuerpo);
  return lines.join('\n');
}
