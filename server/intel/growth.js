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

// Una linea para el briefing matutino: la mejor idea nueva pendiente.
export function growthBriefLine() {
  const fresh = listIdeas({ status: 'new' });
  if (!fresh.length) return '';
  const i = fresh[0];
  return `💡 Idea (${i.topicLabel}): ${i.title} — ${i.action}`;
}
