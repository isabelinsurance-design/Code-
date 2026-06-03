// CRECIMIENTO — MOTOR DE INVESTIGACION CONTINUA  (Playbook Athena #18: buscar antes de inventar)
//
// SAMIA no solo OPERA el negocio; cada semana sale a buscar como hacerlo MEJOR.
// Investiga (con busqueda web real) un tema del negocio por turno y devuelve
// 2-3 ideas CONCRETAS y accionables, cada una con su fuente. Nada de relleno:
// si no hay key/web, NO inventa — lo dice (misma honestidad que el resto de SAMIA).
//
// Las ideas viven en data/growth.json con estado new -> doing -> done | dismissed.
// Se muestran en el dashboard y la mejor sale en el briefing matutino.
//
// Agenda ROTATIVA: un tema por semana (enfocado y barato), cubriendo todo lo que
// mueve la aguja de una agencia de Medicare de habla hispana.

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { DATA_DIR, MODELS } from '../config.js';
import { complete } from '../anthropic.js';
import { getAudit, getReflections } from '../memory/index.js';
import { listCommitments, reviewCommitments } from './commitments.js';
import { listSkills } from './skills.js';
import { computeHealth } from './health.js';
import { rankedGaps } from '../memory/entities.js';
import { specialistList } from '../specialists.js';

const FILE = resolve(DATA_DIR, 'growth.json');
const nowIso = () => new Date().toISOString();

// Quien es el negocio (contexto para que la investigacion sea util, no generica).
const NEGOCIO = `Isabel Fuentes Medicare (withisabelfuentes.com): agencia/brokerage de Medicare en EE.UU.
enfocada en la comunidad HISPANA. Vende y da servicio a planes Medicare Advantage, mucho
Full Dual / DSNP (miembros con Medicare + Medicaid). El equipo son agentes (muchos nuevos).
Todo bajo reglas de marketing de CMS (compliance no opcional).`;

// AGENDA: cada tema con su angulo de investigacion. Rota por semana del año.
export const TOPICS = [
  {
    key: 'marketing-viral',
    label: 'Marketing y contenido viral',
    ask: `Que formatos/temas de contenido estan funcionando AHORA en redes (TikTok, Reels, YouTube)
para llegar a adultos mayores hispanos y sus familias sobre Medicare/seguros. Tendencias virales
recientes, ganchos, ejemplos reales. Da ideas de contenido que el equipo pueda grabar esta semana.`,
  },
  {
    key: 'lead-gen',
    label: 'Generacion de prospectos',
    ask: `Tacticas NUEVAS y efectivas de generacion de prospectos (leads) para agentes de Medicare
en mercados hispanos: alianzas comunitarias, eventos, referidos, canales digitales. Que estan
haciendo las agencias que mas crecen.`,
  },
  {
    key: 'cms-reglas',
    label: 'Reglas CMS y cumplimiento',
    ask: `Cambios RECIENTES o proximos en las reglas de marketing/ventas de CMS para Medicare
Advantage y DSNP (Final Rule, fechas de AEP/OEP, disclaimers, TPMO, grabacion de llamadas).
Que debe ajustar una agencia para no arriesgarse. Solo cambios reales y verificables.`,
  },
  {
    key: 'planes-beneficios',
    label: 'Planes y beneficios',
    ask: `Tendencias en beneficios de planes Medicare Advantage / DSNP que mas le importan a
miembros Full Dual hispanos (comida/SSBCI, transporte, dental, OTC, Part B giveback).
Que beneficios nuevos estan destacando los planes y como explicarlos simple.`,
  },
  {
    key: 'herramientas',
    label: 'Herramientas y automatizacion',
    ask: `Herramientas, software o automatizaciones que ayudan a agencias de Medicare a atender
mejor y mas rapido (CRM, quoting, recordatorios, IA para servicio al cliente). Que vale la pena
y por que. Enfocado en equipos chicos.`,
  },
  {
    key: 'retencion',
    label: 'Retencion y servicio',
    ask: `Mejores practicas RECIENTES para retener miembros de Medicare y subir satisfaccion
(reduce disenrollment): seguimiento, educacion de beneficios, manejo de quejas, recordatorios
de citas. Que esta moviendo la aguja en retencion.`,
  },
];

function read() {
  try {
    return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch {
    return { ideas: [], runs: [] };
  }
}
function write(d) {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
  writeFileSync(FILE, JSON.stringify(d, null, 1));
}

// Tema que toca esta semana (rotacion deterministica por semana del año).
export function topicForWeek(now = new Date()) {
  const start = new Date(now.getFullYear(), 0, 1);
  const week = Math.floor((now - start) / (7 * 24 * 3600 * 1000));
  return TOPICS[week % TOPICS.length];
}

// Extrae el primer arreglo/objeto JSON de un texto (defensivo ante prosa o ```).
function parseJsonLoose(text) {
  if (!text) return null;
  const fenced = text.match(/```(?:json)?\s*([\s\S]*?)```/);
  const raw = fenced ? fenced[1] : text;
  const start = raw.indexOf('[');
  const end = raw.lastIndexOf(']');
  if (start === -1 || end === -1) return null;
  try {
    return JSON.parse(raw.slice(start, end + 1));
  } catch {
    return null;
  }
}

const SYS = `Eres el analista de crecimiento de ${NEGOCIO}
Investigas con busqueda web y devuelves SOLO ideas accionables y reales, con fuente.
Reglas:
- USA busqueda web; NO inventes datos, fechas ni estadisticas. Si no lo verificas, no lo digas.
- Cada idea debe ser algo que un equipo chico pueda EJECUTAR, no teoria.
- Responde en español.
- Devuelve SOLO un arreglo JSON (sin texto extra) con 2 o 3 objetos:
  [{"title": "...", "insight": "que descubriste, 1-2 frases", "action": "el paso concreto a hacer", "effort": "bajo|medio|alto", "source": "URL real"}]`;

// Corre una investigacion. Devuelve {ok, topic, ideas[]} o {ok:false, reason}.
export async function runResearch(now = new Date(), { topicKey = null } = {}) {
  // La 5a lente (jefe de gabinete) es INTERNA: no busca en la web, lee a SAMIA misma.
  if (topicKey === CHIEF.key) return runChiefReview(now);
  const topic = topicKey ? TOPICS.find((t) => t.key === topicKey) || topicForWeek(now) : topicForWeek(now);
  const d = read();
  let ideas = [];
  let error = null;
  try {
    const { text } = await complete({
      system: SYS,
      messages: [{ role: 'user', content: `Tema de esta semana: ${topic.label}.\n${topic.ask}\n\nDevuelve el arreglo JSON con 2-3 ideas.` }],
      model: MODELS.specialist,
      webSearch: true,
      maxTokens: 2000,
    });
    const parsed = parseJsonLoose(text) || [];
    ideas = parsed
      .filter((x) => x && x.title)
      .map((x) => ({
        id: 'g_' + Math.random().toString(36).slice(2, 9),
        topic: topic.key,
        topicLabel: topic.label,
        title: String(x.title).slice(0, 160),
        insight: String(x.insight || '').slice(0, 600),
        action: String(x.action || '').slice(0, 400),
        effort: ['bajo', 'medio', 'alto'].includes(x.effort) ? x.effort : 'medio',
        source: typeof x.source === 'string' ? x.source : '',
        status: 'new',
        createdAt: nowIso(),
      }));
  } catch (e) {
    error = e?.code === 'NO_API_KEY' ? 'Falta ANTHROPIC_API_KEY — la investigacion necesita la key y busqueda web.' : String(e?.message || e);
  }

  // NO inventamos: si no hubo ideas reales, registramos el intento y salimos honesto.
  if (ideas.length) d.ideas.unshift(...ideas);
  d.runs.unshift({ at: nowIso(), topic: topic.key, found: ideas.length, error });
  d.runs = d.runs.slice(0, 50);
  d.ideas = d.ideas.slice(0, 200);
  write(d);
  return { ok: !error && ideas.length > 0, topic: topic.key, topicLabel: topic.label, ideas, error };
}

// ─────────────────────────────────────────────────────────────────────────────
// 5a LENTE — JEFE DE GABINETE (Chief of Staff)  [petición de Isabel]
//
// El Radar no solo mira AFUERA (mercado). Esta lente mira ADENTRO: cómo va SAMIA y
// el equipo, qué está funcionando, qué cambiar. NO usa búsqueda web — lee los datos
// PROPIOS de SAMIA (chats, compromisos, skills, salud, overrides, adopción de ideas).
// Como sale de datos REALES (no inventa), funciona incluso SIN key: produce
// observaciones deterministas; con key, el LLM las afina y prioriza.
// ─────────────────────────────────────────────────────────────────────────────

export const CHIEF = { key: 'chief-of-staff', label: 'Jefe de gabinete', internal: true };

// Foto del estado interno de SAMIA en los últimos 7 días (todo deterministico).
export function chiefSnapshot(now = new Date()) {
  const audit = getAudit(800);
  const wk = new Date(now.getTime() - 7 * 86400000).toISOString();
  const prevWk = new Date(now.getTime() - 14 * 86400000).toISOString();
  const chats = audit.filter((a) => a.action === 'chat');
  const chats7 = chats.filter((a) => (a.ts || '') >= wk);
  const chatsPrev7 = chats.filter((a) => (a.ts || '') >= prevWk && (a.ts || '') < wk);

  // USO POR MODO: cuántas veces se usó cada especialista esta semana, sobre el catálogo
  // completo. Así "Mejora" ve qué NO se está usando ("prueba X") y qué se repite
  // ("automatízalo"). 'chat' es el modo libre por defecto: no cuenta como "feature a probar".
  const catalog = specialistList().filter((s) => s.id !== 'chat');
  const bySpec = {};
  for (const a of chats7) bySpec[a.specialist || '—'] = (bySpec[a.specialist || '—'] || 0) + 1;
  const specsUsed = catalog.filter((s) => bySpec[s.id]).map((s) => ({ id: s.id, label: s.label, n: bySpec[s.id] }));
  const specsUnused = catalog.filter((s) => !bySpec[s.id]).map((s) => ({ id: s.id, label: s.label }));
  const topSpec = Object.entries(bySpec).filter(([k]) => k !== '—').sort((a, b) => b[1] - a[1])[0] || null;

  const skills = listSkills({});
  const approved = skills.filter((s) => s.status === 'approved');
  const unusedSkills = approved.filter((s) => !s.invocations);
  const draftSkills = skills.filter((s) => s.status === 'draft');

  // FLUJO LENTO/REPETIDO sin automatizar: un modo muy consultado que NO tiene skill
  // que lo cubra (por id en triggers/nombre). Candidato a "automatízalo".
  const norm2 = (x) => String(x || '').toLowerCase();
  const hotSpec = topSpec ? { id: topSpec[0], n: topSpec[1] } : null;
  const hotCovered = hotSpec
    ? approved.some((s) => (s.trigger || []).some((t) => norm2(t).includes(hotSpec.id)) || norm2(s.name).includes(hotSpec.id))
    : true;

  const { overdue, due } = reviewCommitments(now);
  const allCommit = listCommitments({});
  const doneCommit = allCommit.filter((c) => c.status === 'done').length;

  const health = computeHealth(now);
  const gaps = rankedGaps(100).length;
  const overrides7 = audit.filter((a) => a.action === 'compliance_override' && (a.ts || '') >= wk).length;

  const ideas = read().ideas.filter((i) => i.topic !== CHIEF.key); // adopción de ideas EXTERNAS
  const ideasNew = ideas.filter((i) => i.status === 'new').length;
  const ideasDone = ideas.filter((i) => i.status === 'done' || i.status === 'doing').length;
  const ideasDismissed = ideas.filter((i) => i.status === 'dismissed').length;

  return {
    chats7: chats7.length,
    chatsPrev7: chatsPrev7.length,
    catalogSize: catalog.length,
    specsUsed,
    specsUnused,
    topSpec: topSpec ? { name: topSpec[0], n: topSpec[1] } : null,
    hotUnautomated: hotSpec && !hotCovered && hotSpec.n >= 4 ? hotSpec : null,
    overdue: overdue.length,
    dueToday: due.length,
    commitDone: doneCommit,
    approvedSkills: approved.length,
    unusedSkills: unusedSkills.map((s) => s.name),
    draftSkills: draftSkills.length,
    health: { score: health.score, band: health.band },
    gaps,
    overrides7,
    ideasNew,
    ideasDone,
    ideasDismissed,
    reflections: getReflections(14).length,
  };
}

// Observaciones deterministas (sin LLM) a partir de la foto. Reglas simples y honestas.
function chiefObservations(s) {
  const out = [];
  const idea = (title, insight, action, effort = 'bajo') => out.push({ title, insight, action, effort });

  // "No estás usando X, pruébalo" — modos del catálogo sin uso esta semana.
  if (s.specsUnused.length)
    idea('No estás usando todo SAMIA',
      `Esta semana no se usó: ${s.specsUnused.map((x) => x.label).slice(0, 4).join(', ')} (${s.specsUnused.length} de ${s.catalogSize} modos sin tocar).`,
      `Prueba "${s.specsUnused[0].label}" en la próxima sesión; si no aporta, lo sabremos.`);
  // "Este flujo se repite, automatízalo" — modo muy consultado sin skill que lo cubra.
  if (s.hotUnautomated)
    idea('Un flujo se repite — automatízalo',
      `"${s.hotUnautomated.id}" se consultó ${s.hotUnautomated.n} veces y no hay skill que lo cubra.`,
      `Crea/aprueba una skill para "${s.hotUnautomated.id}" y deja que SAMIA lo resuelva en automático.`, 'medio');
  if (s.unusedSkills.length)
    idea('Skills aprobadas sin uso',
      `${s.unusedSkills.length} skill(s) aprobada(s) nunca se han invocado: ${s.unusedSkills.slice(0, 3).join(', ')}.`,
      'Promociónalas con el equipo (o ajústales los triggers); si no sirven, retíralas.');
  if (s.draftSkills)
    idea('Propuestas de skill pendientes',
      `Hay ${s.draftSkills} propuesta(s) de playbook esperando tu revisión.`,
      'Revísalas en la pestaña Skills: aprueba las útiles, descarta el resto.');
  if (s.overdue >= 3)
    idea('Compromisos venciéndose',
      `${s.overdue} compromiso(s) vencido(s) — el seguimiento se está quedando atrás.`,
      'Cierra o reagenda los vencidos hoy; revisa por qué se acumulan.', 'medio');
  if (s.overrides7)
    idea('Overrides de cumplimiento esta semana',
      `Hubo ${s.overrides7} override(s) de compliance en 7 días — riesgo regulatorio asumido.`,
      'Revisa cada uno: ¿fue justificado? ¿hace falta entrenar o ajustar una regla?', 'medio');
  if (s.ideasNew >= 4)
    idea('Ideas del Radar sin accionar',
      `${s.ideasNew} idea(s) de crecimiento siguen en "nuevo" sin decisión.`,
      'Prioriza 1-2 para hacer esta semana y descarta el resto; un backlog muerto no ayuda.');
  if (s.topSpec && s.topSpec.n >= 6)
    idea('Tema que domina las consultas',
      `"${s.topSpec.name}" fue lo más consultado (${s.topSpec.n} veces en 7 días).`,
      'Considera un playbook o una mini-capacitación de ese tema para descargar a SAMIA.');
  if (s.chatsPrev7 > 0 && s.chats7 < s.chatsPrev7 * 0.5)
    idea('Bajó el uso de SAMIA',
      `Los chats cayeron de ${s.chatsPrev7} a ${s.chats7} esta semana.`,
      '¿El equipo dejó de usarla? Pregunta qué le falta o dónde no está ayudando.', 'medio');
  if (s.health.band === 'necesita')
    idea('Salud del negocio en rojo',
      `El puntaje de salud está en "${s.health.band}" (${s.health.score}/100).`,
      'Ataca primero el componente más bajo (ver Briefing/Salud) antes de crecer.', 'medio');

  if (!out.length)
    idea('Todo en orden',
      'Sin focos internos: skills en uso, compromisos al día, sin overrides, salud sana.',
      'Buen momento para empujar una idea de crecimiento del Radar.');
  return out;
}

const CHIEF_SYS = `Eres el JEFE DE GABINETE (chief of staff) de SAMIA, la asistente IA de un equipo
de Medicare. Te doy una foto REAL del estado interno de SAMIA y del equipo (últimos 7 días) y unas
observaciones deterministas. Tu trabajo: decir 2-3 recomendaciones AGUDAS y concretas sobre cómo
SAMIA y el equipo pueden mejorar — qué está funcionando, qué cambiar, qué dejar de hacer.
Reglas:
- Básate SOLO en los datos dados. NO inventes métricas ni hechos.
- Acciones EJECUTABLES esta semana, no teoría.
- Español. Devuelve SOLO un arreglo JSON con 2-3 objetos:
  [{"title":"...","insight":"qué viste en los datos","action":"el paso concreto","effort":"bajo|medio|alto"}]`;

// AUTOEVALUACIÓN — SAMIA se pone nota a sí misma cada semana y propone UN cambio.
// Mide su PROPIO desempeño/higiene (no la salud del negocio): si la usaron, si cubrió
// su menú, si sus skills sirven, si dio seguimiento, si fue segura, si sus ideas se
// accionan. 0-100. Guarda histórico por semana para comparar con la semana pasada.

const clampN = (n, lo, hi) => Math.max(lo, Math.min(hi, Math.round(n)));
function weekKey(now = new Date()) {
  const start = new Date(now.getFullYear(), 0, 1);
  const week = Math.floor((now - start) / (7 * 24 * 3600 * 1000));
  return `${now.getFullYear()}-W${week}`;
}

export function selfGrade(now = new Date(), snap = null) {
  const s = snap || chiefSnapshot(now);
  const usedCount = s.specsUsed.length;
  const comps = [
    { name: 'Uso', max: 25, score: clampN((s.chats7 / 20) * 25, 0, 25),
      fix: `El equipo te usó poco (${s.chats7} chats). Recuérdales para qué sirves o pregunta qué les falta.` },
    { name: 'Cobertura del menú', max: 20, score: clampN((usedCount / Math.max(1, s.catalogSize)) * 20, 0, 20),
      fix: `Solo se usaron ${usedCount} de ${s.catalogSize} modos. Promueve los que faltan${s.specsUnused[0] ? `, empezando por "${s.specsUnused[0].label}"` : ''}.` },
    { name: 'Skills activas', max: 15, score: s.approvedSkills ? clampN(((s.approvedSkills - s.unusedSkills.length) / s.approvedSkills) * 15, 0, 15) : 15,
      fix: s.unusedSkills.length ? `Skills aprobadas sin uso: ${s.unusedSkills.slice(0, 3).join(', ')}. Promuévelas o retíralas.` : 'Aprueba alguna propuesta de skill para automatizar lo repetido.' },
    { name: 'Seguimiento', max: 20, score: clampN(20 - s.overdue * 5, 0, 20),
      fix: `${s.overdue} compromiso(s) vencido(s). Cierra o reagenda y ajusta el seguimiento.` },
    { name: 'Seguridad', max: 10, score: clampN(10 - s.overrides7 * 5, 0, 10),
      fix: `Hubo ${s.overrides7} override(s) de compliance. Revisa cada uno y si hace falta entrenar.` },
    { name: 'Adopción de ideas', max: 10, score: (s.ideasNew + s.ideasDone + s.ideasDismissed) ? clampN(((s.ideasDone + s.ideasDismissed) / (s.ideasNew + s.ideasDone + s.ideasDismissed)) * 10, 0, 10) : 10,
      fix: `${s.ideasNew} idea(s) del Radar sin decidir. Prioriza 1-2 o descártalas.` },
  ];
  const score = comps.reduce((a, c) => a + c.score, 0);
  const weakest = comps.slice().sort((a, b) => (a.score / a.max) - (b.score / b.max))[0];
  return { week: weekKey(now), score, comps, weakest, ts: nowIso() };
}

// Guarda la nota de la semana (upsert) y devuelve {grade, prev} para comparar.
function recordGrade(grade) {
  const d = read();
  if (!Array.isArray(d.grades)) d.grades = [];
  const prev = d.grades.filter((g) => g.week !== grade.week).slice(-1)[0] || null;
  d.grades = d.grades.filter((g) => g.week !== grade.week);
  d.grades.push({ week: grade.week, score: grade.score, weakest: grade.weakest.name, ts: grade.ts });
  d.grades = d.grades.slice(-26);
  write(d);
  return { grade, prev };
}
export function getGrades() {
  return read().grades || [];
}

// La idea-autoevaluación: nota, comparación con la semana pasada, y UN cambio concreto.
function gradeIdea(grade, prev) {
  let delta;
  if (prev) {
    const d = grade.score - prev.score;
    delta = d === 0 ? `igual que la semana pasada (${prev.score})` : `${d > 0 ? '▲' : '▼'} ${Math.abs(d)} vs la semana pasada (${prev.score} → ${grade.score})`;
  } else {
    delta = 'primera semana medida — esta es la línea base';
  }
  return {
    title: `📊 Autoevaluación: ${grade.score}/100`,
    insight: `${delta}. Lo más flojo: ${grade.weakest.name} (${grade.weakest.score}/${grade.weakest.max}).`,
    action: `Cambio de esta semana: ${grade.weakest.fix}`,
    effort: 'medio',
  };
}

// Corre la revisión de jefe de gabinete. Determinista de base; LLM la afina si hay key.
// CoS va PRIMERO y con MÁS PESO: nota propia + hasta 4 observaciones (más que las externas).
export async function runChiefReview(now = new Date()) {
  const snap = chiefSnapshot(now);
  const grade = selfGrade(now, snap);
  const { prev } = recordGrade(grade);
  const selfIdea = gradeIdea(grade, prev); // SIEMPRE primero, siempre determinista
  let observations = chiefObservations(snap).filter((o) => o.title !== 'Todo en orden');
  let usedLLM = false;
  let error = null;

  try {
    const { text } = await complete({
      system: CHIEF_SYS,
      messages: [{ role: 'user', content: `Estado interno (JSON):\n${JSON.stringify(snap, null, 1)}\n\nAutoevaluación: ${grade.score}/100, más flojo: ${grade.weakest.name}.\n\nObservaciones base:\n${observations.map((o) => `- ${o.title}: ${o.action}`).join('\n')}\n\nDevuelve el arreglo JSON con 3-4 recomendaciones (NO repitas la autoevaluación).` }],
      model: MODELS.specialist,
      maxTokens: 1400,
    });
    const parsed = parseJsonLoose(text);
    if (parsed && parsed.length) {
      observations = parsed.filter((x) => x && x.title);
      usedLLM = true;
    }
  } catch (e) {
    // Sin key (u otro fallo): nos quedamos con las observaciones deterministas. NO es invento.
    error = e?.code === 'NO_API_KEY' ? null : String(e?.message || e);
  }

  // Autoevaluación pinneada al frente + hasta 4 observaciones = hasta 5 (más peso que las externas).
  const ideas = [selfIdea, ...observations].slice(0, 5).map((x) => ({
    id: 'g_' + Math.random().toString(36).slice(2, 9),
    topic: CHIEF.key,
    topicLabel: CHIEF.label,
    title: String(x.title).slice(0, 160),
    insight: String(x.insight || '').slice(0, 600),
    action: String(x.action || '').slice(0, 400),
    effort: ['bajo', 'medio', 'alto'].includes(x.effort) ? x.effort : 'bajo',
    source: '', // interno: no hay URL
    status: 'new',
    createdAt: nowIso(),
  }));

  const d = read();
  // Reemplaza recomendaciones CoS previas aún "nuevas" (no acumular duplicados semana a semana).
  d.ideas = d.ideas.filter((i) => !(i.topic === CHIEF.key && i.status === 'new'));
  d.ideas.unshift(...ideas);
  d.runs.unshift({ at: nowIso(), topic: CHIEF.key, found: ideas.length, error, llm: usedLLM });
  d.runs = d.runs.slice(0, 50);
  d.ideas = d.ideas.slice(0, 200);
  write(d);
  return { ok: true, topic: CHIEF.key, topicLabel: CHIEF.label, ideas, grade, snapshot: snap, llm: usedLLM, error };
}

// El barrido completo del Radar: la lente externa de la semana + la lente interna (CoS).
export async function runRadar(now = new Date()) {
  const external = await runResearch(now);
  const chief = await runChiefReview(now);
  return { external, chief };
}

export function listIdeas({ status } = {}) {
  const { ideas } = read();
  return status ? ideas.filter((i) => i.status === status) : ideas;
}
export function lastRun() {
  return read().runs[0] || null;
}

export function setIdeaStatus(id, status) {
  if (!['new', 'doing', 'done', 'dismissed'].includes(status)) return null;
  const d = read();
  const idea = d.ideas.find((i) => i.id === id);
  if (!idea) return null;
  idea.status = status;
  idea.updatedAt = nowIso();
  write(d);
  return idea;
}

// Una linea para el briefing matutino. La lente de jefe de gabinete va PRIMERO (más peso).
export function growthBriefLine() {
  const fresh = listIdeas({ status: 'new' });
  if (!fresh.length) return '';
  const i = fresh.find((x) => x.topic === CHIEF.key) || fresh[0];
  return `💡 Idea (${i.topicLabel}): ${i.title} — ${i.action}`;
}
