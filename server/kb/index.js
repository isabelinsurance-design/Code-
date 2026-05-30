// CAPA DE CONOCIMIENTO (Knowledge Base)
//
// SAMIA tiene dos formas de conocimiento:
//   1) knowledge.es.md  -> el cuerpo narrativo (redes, reglas IPA, procesos). Se
//      inyecta en los especialistas que lo necesitan.
//   2) entidades estructuradas (plans / medical-groups / doctors) + cases.json ->
//      busquedas rapidas y deterministas ("KB-backed lookups"), para que SAMIA
//      diga datos reales o admita "no tengo ese" en vez de inventar.
//
// Connecture sigue siendo la fuente OFICIAL para cotizar. Esto es para orientar.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const load = (f) => readFileSync(resolve(__dirname, f), 'utf8');
const loadJson = (f) => JSON.parse(load(f));

export const KNOWLEDGE = load('knowledge.es.md');
export const CASES = loadJson('cases.json');
export const MEDICAL_GROUPS = loadJson('medical-groups.json').groups;
export const DOCTORS = loadJson('doctors.json').doctors;
export const PLANS = loadJson('plans.json').plans;

// --- utilidades de texto ---
const norm = (s) =>
  String(s || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, ''); // quita acentos para comparar

const tokens = (s) => norm(s).split(/[^a-z0-9]+/).filter((t) => t.length > 2);

// --- LOOKUPS deterministas ---

export function lookupDoctor(query) {
  const q = norm(query);
  return DOCTORS.filter((d) => norm(d.name).includes(q) || q.includes(norm(d.name)));
}

export function lookupMedicalGroup(query) {
  const q = norm(query);
  return MEDICAL_GROUPS.filter(
    (g) => norm(g.name).includes(q) || (g.aka || []).some((a) => norm(a).includes(q) || q.includes(norm(a)))
  );
}

export function lookupPlan(query) {
  const q = norm(query);
  return PLANS.filter((p) => norm(p.name).includes(q) || norm(p.carrier).includes(q));
}

// Busqueda en la libreria de casos (2,233 tickets, anonimizados): ranking simple
// por overlap de tokens entre la consulta y q+a del caso.
export function searchCases(query, limit = 4) {
  const qt = new Set(tokens(query));
  if (qt.size === 0) return [];
  return CASES.map((c) => {
    const ct = tokens(`${c.q} ${c.a}`);
    let score = 0;
    for (const t of ct) if (qt.has(t)) score++;
    return { c, score };
  })
    .filter((x) => x.score > 0)
    .sort((a, b) => b.score - a.score)
    .slice(0, limit)
    .map((x) => x.c);
}

// Detecta entidades mencionadas en el texto del usuario para inyectar fichas
// estructuradas (mas confiable que dejar que el modelo recuerde de memoria).
export function entitiesMentioned(text) {
  const t = norm(text);
  const hit = (name, aka = []) =>
    [name, ...aka].some((n) => {
      const nn = norm(n);
      return nn.length > 2 && t.includes(nn);
    });

  return {
    plans: PLANS.filter((p) => hit(p.name, [p.carrier])),
    groups: MEDICAL_GROUPS.filter((g) => hit(g.name, g.aka)),
    doctors: DOCTORS.filter((d) => hit(d.name, [d.name.replace(/^(Dr|Dra)\.?\s+/i, '')])),
  };
}

// --- CONSTRUCTOR DE CONTEXTO ---
// Devuelve un bloque de texto con: fichas de entidades detectadas + casos
// similares. Esto es lo que el orquestador inyecta ademas del knowledge narrativo.
export function buildKbContext(userText) {
  const parts = [];
  const ent = entitiesMentioned(userText);

  for (const p of ent.plans) {
    parts.push(
      `PLAN ${p.name} (${p.carrier}) — acepta: ${p.acceptedGroups.join(', ') || 'verificar en Connecture'}. ${p.notes}`
    );
  }
  for (const g of ent.groups) {
    parts.push(
      `GRUPO ${g.name}${g.aka?.length ? ` (${g.aka.join('/')})` : ''} — planes que lo aceptan: ${g.acceptedByPlans.join(', ') || 'verificar'}. ${g.notes}`
    );
  }
  for (const d of ent.doctors) {
    parts.push(`DOCTOR ${d.name} — grupo: ${d.group || 'ninguno de los nuestros'}. ${d.location ? d.location + '. ' : ''}${d.notes}`);
  }

  const cases = searchCases(userText, 3);
  for (const c of cases) {
    parts.push(`CASO SIMILAR [${c.lbl}] P: ${c.q} -> R: ${c.a}`);
  }

  if (parts.length === 0) return '';
  return `DATOS RELEVANTES DEL KB (usalos; si un dato pudo cambiar, manda a verificar en Connecture):\n- ${parts.join('\n- ')}`;
}

export const kbStats = () => ({
  cases: CASES.length,
  medicalGroups: MEDICAL_GROUPS.length,
  doctors: DOCTORS.length,
  plans: PLANS.length,
  knowledgeChars: KNOWLEDGE.length,
});
