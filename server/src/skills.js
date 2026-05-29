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

// ============================================================
//  Auto-skill detection (Phase 12)
//  ────────────────────────────────
//  La reflexión nocturna llama detectPatternsAndPropose. Revisa
//  el audit log de los últimos 7 días, agrupa por "sesiones"
//  (entradas consecutivas dentro de 30 min), saca secuencias de
//  4-8 tools, encuentra las que se repiten 3+ veces, y pasa las
//  3 mejores a Haiku para que proponga draft skills. El briefing
//  matutino las menciona para que Isabel apruebe o descarte.
//
//  Filtros (alto bar para evitar spam):
//   - Sesión mínima: 4 tools dentro de 30 min
//   - Sequence longitud: 4-8 tools
//   - Repetición: aparece 3+ veces en la ventana
//   - Diversidad: al menos 2 categorías de tools distintas
//   - Max 1 propuesta auto-creada por noche
// ============================================================
import { anthropic } from './claude.js';
import { getActivity } from './memory.js';

const SESSION_GAP_MS = 30 * 60_000;  // sesiones se cortan tras 30 min de silencio
const MIN_SEQ = 4;
const MAX_SEQ = 8;
const MIN_REPEATS = 3;
const LOOKBACK_DAYS = 7;
const MAX_PROPOSALS_PER_NIGHT = 1;

// Categorías de tools — para filtro de diversidad
function toolCategory(name) {
  if (/^(recordar|olvidar|que_recuerdas|actualizar_temporada|consultar_temporada|historial)$/.test(name)) return 'memory';
  if (/^(crear_tarea|mis_tareas|completar_tarea|cancelar_tarea|actualizar_tarea)$/.test(name)) return 'tasks';
  if (/^(comprometer_entrega|mis_compromisos|marcar_cumplido|marcar_fallido)$/.test(name)) return 'commitments';
  if (/^entidad_/.test(name)) return 'entities';
  if (/^cliente_|^crear_cliente|^actualizar_cliente|^nota_cliente|^buscar_cliente|^expediente_cliente|^lista_clientes|^clientes_descuidados|^proximas_renovaciones|^proximos_cumples/.test(name)) return 'crm';
  if (/^compliance_|^pipeline_t65/.test(name)) return 'compliance';
  if (/^(enviar_email|enviar_sms|mensaje_a_sami|confirmar_envio|descartar_envio|revisar_emails)$/.test(name)) return 'outbound';
  if (/^(crear_cita|reagendar_cita|cancelar_cita|proximos_eventos|detalles_cita)$/.test(name)) return 'calendar';
  if (/^skill_/.test(name)) return 'skill';
  if (/^señales|^gaps_/.test(name)) return 'insight';
  if (/^(nextiva_|ig_)/.test(name)) return 'social';
  if (name === 'llamar_cliente') return 'voice';
  return 'other';
}

function groupBySessions(entries) {
  // entries is sorted DESC by ts (newest first); flip for chronological
  const chrono = entries.slice().reverse();
  const sessions = [];
  let current = [];
  let lastTs = null;
  for (const e of chrono) {
    const t = new Date(e.ts).getTime();
    if (lastTs && t - lastTs > SESSION_GAP_MS) {
      if (current.length >= MIN_SEQ) sessions.push(current);
      current = [];
    }
    current.push(e);
    lastTs = t;
  }
  if (current.length >= MIN_SEQ) sessions.push(current);
  return sessions;
}

function extractSequences(session) {
  // Generate all sequences of length MIN_SEQ..MAX_SEQ
  const out = [];
  for (let len = MIN_SEQ; len <= Math.min(MAX_SEQ, session.length); len += 1) {
    for (let i = 0; i + len <= session.length; i += 1) {
      out.push(session.slice(i, i + len));
    }
  }
  return out;
}

function sigOf(seq) {
  return seq.map((e) => e.tool).join(' → ');
}

function isDiverseEnough(seq) {
  const cats = new Set(seq.map((e) => toolCategory(e.tool)));
  return cats.size >= 2;
}

function findRepeats(sequences) {
  const counts = new Map();
  for (const seq of sequences) {
    if (!isDiverseEnough(seq)) continue;
    const key = sigOf(seq);
    if (!counts.has(key)) counts.set(key, { count: 0, sample: seq });
    counts.get(key).count += 1;
  }
  return [...counts.entries()]
    .filter(([, v]) => v.count >= MIN_REPEATS)
    .sort((a, b) => (b[1].count * b[1].sample.length) - (a[1].count * a[1].sample.length))
    .map(([sig, v]) => ({ signature: sig, count: v.count, sample: v.sample }));
}

async function generateSkillProposalFromPattern(pattern) {
  if (!process.env.ANTHROPIC_API_KEY) return null;
  const seq = pattern.sample.map((e, i) => `${i + 1}. ${e.tool}(${(e.input_summary || '').slice(0, 60)})`).join('\n');
  const prompt = `Eres Athena. He notado que ejecuté esta secuencia ${pattern.count} veces en los últimos ${LOOKBACK_DAYS} días:

${seq}

Si crees que ES un patrón reusable y bien estructurado (no solo coincidencia), proponme una skill draft. Si NO crees que sea útil reificarlo, responde solo "SKIP" y nada más.

Si lo propones, devuelve un JSON EXACTO con estas llaves:
{
  "nombre": "Nombre humano corto (max 40 chars)",
  "descripcion": "Una frase de cuándo se usa",
  "trigger": "Frase típica de Isabel que lo invoca",
  "cuerpo": "Markdown con 4-6 pasos concretos referenciando las tools",
  "inputs_schema": [{"nombre":"...","descripcion":"...","requerido":true}]
}

Tu respuesta (JSON o SKIP):`;
  try {
    const r = await anthropic.messages.create({
      model: 'claude-haiku-4-5-20251001',
      max_tokens: 800,
      messages: [{ role: 'user', content: prompt }],
    });
    const text = (r.content?.[0]?.text || '').trim();
    if (text.startsWith('SKIP')) return null;
    const m = text.match(/\{[\s\S]*\}/);
    if (!m) return null;
    const parsed = JSON.parse(m[0]);
    if (!parsed.nombre || !parsed.descripcion || !parsed.cuerpo) return null;
    return parsed;
  } catch (err) {
    console.warn('[auto-skill] Haiku falló:', err.message);
    return null;
  }
}

// Devuelve array de drafts auto-creados (puede estar vacío). Caller
// los menciona en el briefing matutino.
export async function detectPatternsAndPropose() {
  // Lookback 7d
  const cutoff = Date.now() - LOOKBACK_DAYS * 86_400_000;
  const recent = getActivity().filter((e) => new Date(e.ts).getTime() >= cutoff);
  if (recent.length < MIN_SEQ * MIN_REPEATS) return [];

  const sessions = groupBySessions(recent);
  if (!sessions.length) return [];

  const allSeqs = sessions.flatMap(extractSequences);
  const repeated = findRepeats(allSeqs);
  if (!repeated.length) return [];

  // Filtra los que ya son skills propuestas para no duplicar
  const existing = listSkills({}).map((s) => s.cuerpo + ' ' + s.descripcion);
  const candidates = repeated.slice(0, 5);
  const proposals = [];

  for (const pat of candidates) {
    if (proposals.length >= MAX_PROPOSALS_PER_NIGHT) break;
    // Salta si una skill activa ya contiene esta misma secuencia textual
    if (existing.some((e) => e.includes(pat.signature))) continue;
    const draft = await generateSkillProposalFromPattern(pat);
    if (!draft) continue;
    try {
      const created = proposeSkill({
        nombre: draft.nombre,
        descripcion: draft.descripcion,
        cuerpo: draft.cuerpo,
        trigger: draft.trigger || '',
        inputs_schema: draft.inputs_schema || [],
        propuesto_por: 'athena_auto',
      });
      proposals.push(created);
    } catch (err) {
      // Probable: skill activa con ese nombre. Pasamos.
      console.log('[auto-skill] skip:', err.message);
    }
  }
  return proposals;
}

// Lista los drafts NUEVOS desde HACE N horas (para el briefing).
export function recentAutoDrafts({ hoursBack = 24 } = {}) {
  const cutoff = Date.now() - hoursBack * 3600_000;
  return listSkills({ status: 'draft' })
    .filter((s) => s.propuesto_por === 'athena_auto' && new Date(s.creado).getTime() >= cutoff);
}

// ============================================================
//  Medicare workflow pack (Phase 13)
//  ──────────────────────────────────
//  6 skills seed que Isabel aprueba una vez y luego María/Athena
//  invoca a través del año. Idempotente — si ya existe el skill
//  (en cualquier estado), no se sobrescribe.
// ============================================================
const MEDICARE_PACK = [
  {
    nombre: 'AEP outreach',
    descripcion: 'Plan completo de outreach AEP para UN cliente: verifica SOA, revisa gaps, redacta email + SMS personalizados, crea tarea de follow-up, registra touchpoint.',
    trigger: 'cuando Isabel diga "prepara AEP de X" / "ataca AEP de X"',
    inputs_schema: [{ nombre: 'cliente_id', descripcion: 'ID del cliente', requerido: true }],
    cuerpo: `# AEP Outreach — un cliente

## Pasos

1. expediente_cliente(id={cliente_id}) — necesito el estado completo.
2. gaps_de_cliente(id={cliente_id}) — si hay altos de compliance (SOA / MBI / TCPA), prioriza eso primero.
3. Si SOA NO firmada: redacta email pidiendo SOA, PARA AQUÍ. Resume "Primero cierra SOA, luego mando AEP".
4. Si SOA firmada y touchpoint en últimos 11 meses: redacta SMS amable de check-in, sin urgencia.
5. Si SOA firmada y touchpoint >11m: redacta email personalizado (su nombre + plan actual + invitación a review pre-AEP) + SMS corto con link a calendario. Crea tarea responsable=isabel "AEP review con [nombre]" vence_en_dias=5.
6. cliente_touchpoint(id, tipo="email", resumen="AEP outreach iniciado [fecha]").
7. Resume en 3 líneas qué quedó en cola.`,
  },
  {
    nombre: 'Intake cliente Medicare',
    descripcion: 'Guía un lead nuevo por 12 preguntas conversacionales (Spanglish), auto-popula CRM con MBI, drug list, providers, TCPA. Termina con resumen.',
    trigger: 'cuando Isabel diga "agarré un lead nuevo X" / "hazle el intake a X"',
    inputs_schema: [
      { nombre: 'nombre', descripcion: 'Nombre del lead', requerido: true },
      { nombre: 'contacto', descripcion: 'Teléfono o WhatsApp', requerido: true },
    ],
    cuerpo: `# Intake — cliente Medicare nuevo

## Pasos

1. buscar_cliente({nombre}) — evita duplicados. Si existe, ACTUALIZAR, no crear.
2. Si no existe: crear_cliente con status="lead", nombre={nombre}, telefono={contacto}.
3. Ejecuta el cuestionario UNA pregunta a la vez por WhatsApp/SMS. Cada respuesta del cliente la procesa Athena para popular el campo correcto.

## Cuestionario (Spanglish cálido, no formal)

1. "Hola [nombre], gracias por contactarme. Para ayudarte mejor con tu Medicare, te paso unas preguntas rápidas. ¿Cuál es tu nombre completo como aparece en tu Medicare card?"
2. "¿Fecha de nacimiento?"  → actualizar_cliente(fecha_nacimiento)
3. "¿Tienes tu Medicare card a la mano? Pásame el MBI — la línea con letras y números." → actualizar_cliente(mbi), cliente_mbi_estado(status="pending", source="verbal")
4. "¿Tienes plan ahorita o estás aging-in?" → notas
5. Si tiene plan: "¿Con quién? SCAN, Anthem, Humana, etc.?" → actualizar_cliente(carrier, plan)
6. "¿Sabes la fecha de renovación?" → actualizar_cliente(renewal_date)
7. "¿Tomas medicamentos diario? Si sí, pásame nombre y dosis de cada uno." → cliente_medicamento_agregar por cada uno
8. "¿Quién es tu doctor principal? ¿Algún especialista que veas seguido?" → cliente_doctor_agregar por cada uno
9. "Para mandarte info por SMS o llamarte, ¿está bien?" → si SÍ: cliente_tcpa(idioma="es")
10. "¿Cómo me conociste?" → actualizar_cliente(fuente)
11. "¿Cuándo es buen momento para hablar 20 min?" → crear_tarea o proponer cita
12. "¿Algo importante de tu salud o tu situación que deba saber?" → nota_cliente

## Cierre

- nota_cliente con resumen ("Intake completo: tomó SCAN HMO, X medicamentos, Dr. Y. Quiere review en 3 días.").
- Si el cliente NO completó algunas preguntas, marca los gaps en el resumen y proponme follow-up para la próxima vez.`,
  },
  {
    nombre: 'Check-in 12 meses (CMS)',
    descripcion: 'Para clientes cerca de cumplir 12 meses sin touchpoint. Cubre la regla CMS de contacto anual con SMS personalizado + oferta de cita.',
    trigger: 'cuando Isabel diga "hazle check-in 12m a X" o cuando el briefing surface a alguien en este estado',
    inputs_schema: [{ nombre: 'cliente_id', descripcion: 'ID del cliente', requerido: true }],
    cuerpo: `# Check-in anual 12 meses (CMS)

## Pasos

1. expediente_cliente(id={cliente_id}).
2. gaps_de_cliente(id={cliente_id}) — si hay otros bloqueadores (SOA vencida, MBI sin verificar), súmalos a la conversación.
3. Redacta SMS cálido + personalizado al teléfono del cliente: "Hola [nombre], soy Isabel — ya casi cumplimos un año desde que te tengo en [carrier]. ¿Quieres que platiquemos 15 min de cómo va todo? Sin compromiso."
4. enviar_sms (queda en draft hasta envía).
5. Cuando Isabel diga envía y el cliente conteste, llama cliente_touchpoint(id, tipo="sms", resumen="Check-in anual CMS — cliente {confirmó / desistió / pidió otra fecha}").
6. Si el cliente confirma cita: propon crear_cita con su carrier o aseguradora, marca cliente_id.`,
  },
  {
    nombre: 'Seguimiento renovación 30d',
    descripcion: 'Para clientes con renovación próxima en 30 días. Web search cambios del plan, brief comparativo, email personalizado, tarea de llamada.',
    trigger: 'cuando Isabel diga "renovación próxima de X" o el briefing surface renewals',
    inputs_schema: [{ nombre: 'cliente_id', descripcion: 'ID del cliente', requerido: true }],
    cuerpo: `# Seguimiento renovación (30 días antes)

## Pasos

1. expediente_cliente(id={cliente_id}) — necesito carrier, plan, renewal_date, drug_list.
2. gaps_de_cliente(id={cliente_id}) — si drug_list o providers vacíos, márcame esos huecos PRIMERO (los necesito para comparar).
3. web_search "[carrier] [plan] 2026 changes premium deductible formulary" — trae 1-2 datos accionables.
4. Redacta email personalizado: "Tu plan [X] renueva [fecha]. Estos son los cambios para 2026: [insertar]. Te propongo [reseña / cambio / quedarse]. ¿Cuándo te llamo?". Encóla con enviar_email.
5. crear_tarea responsable=isabel "Llamar a [cliente] antes de [renewal_date menos 7 días] para renovación", vence_en_dias=7.
6. cliente_touchpoint(id, tipo="email", resumen="Renovación notificada — [carrier] [plan]").`,
  },
  {
    nombre: 'Chase SOA pendiente',
    descripcion: 'Cliente lleva días sin firmar la SOA mandada. Chase amable escalado: nada los primeros 3d, recordatorio 3-7d, llamada vía Sami >7d.',
    trigger: 'cuando Isabel diga "todavía no firma SOA X" o el briefing surface SOA pending',
    inputs_schema: [
      { nombre: 'cliente_id', descripcion: 'ID del cliente', requerido: true },
      { nombre: 'dias_desde_ultimo', descripcion: 'Días desde que mandaste la SOA', requerido: true },
    ],
    cuerpo: `# Chase SOA pendiente

## Pasos

1. expediente_cliente(id={cliente_id}) — para tono y contexto.
2. Si dias_desde_ultimo < 3: NO chase, todavía no. Cierra. Sugiere esperar.
3. Si dias_desde_ultimo entre 3 y 7: redacta SMS recordatorio amable + relink. enviar_sms.
4. Si dias_desde_ultimo > 7: redacta SMS final + mensaje_a_sami "Llamar a [cliente] hoy para confirmar SOA" + crear_tarea responsable=isabel "Si Sami no consigue SOA en 48h, escalo yo".
5. cliente_touchpoint(id, tipo="sms", resumen="Chase SOA día [N]").
6. comprometer_entrega(persona="[nombre cliente]", descripcion="firmar SOA", canal="email", vence_en_dias=3) — para que el chase futuro corra solo.`,
  },
  {
    nombre: 'Brief comparar planes',
    descripcion: 'Cliente quiere comparar 2-3 planes Medicare. Arma side-by-side con premium / deductible / MOOP / cobertura de SUS medicamentos.',
    trigger: 'cuando Isabel diga "compárame [plan A] vs [plan B] para X"',
    inputs_schema: [
      { nombre: 'cliente_id', descripcion: 'ID del cliente', requerido: true },
      { nombre: 'planes', descripcion: 'Lista de planes a comparar (ej. ["SCAN Classic HMO", "Anthem MediBlue HMO"])', requerido: true },
    ],
    cuerpo: `# Comparativa de planes

## Pasos

1. expediente_cliente(id={cliente_id}) — necesito drug_list y providers.
2. Si drug_list vacía: PARA. "No puedo comparar formulary sin saber qué toma. Llenémoslo primero."
3. Si providers vacíos: WARN — la comparación de red será estimada.
4. Para cada plan en {planes}: web_search "[plan] 2026 premium deductible MOOP formulary".
5. Arma tabla 4 columnas: Plan / Premium / Deductible+MOOP / Cobertura de SUS medicamentos.
6. Para cobertura de medicamentos, verifica cada uno del drug_list contra el formulary del plan (asume Tier 1-5).
7. Resumen de 4 líneas: "Para [nombre], [carrier+plan] sale mejor porque [razón]. Pero ojo con [riesgo]. Próximo paso: [accion]".
8. enviar_email al cliente con la tabla y resumen.
9. cliente_touchpoint(id, tipo="email", resumen="Brief de comparación entre [planes]").`,
  },
];

// Crea las skills como DRAFTS. Idempotente: si ya existe con ese
// slug (cualquier status), salta y no la sobrescribe.
export function seedMedicareSkills() {
  const created = [];
  const skipped = [];
  for (const tpl of MEDICARE_PACK) {
    const existing = loadSkill(tpl.nombre);
    if (existing) {
      skipped.push(existing.name);
      continue;
    }
    try {
      const s = proposeSkill({
        nombre: tpl.nombre,
        descripcion: tpl.descripcion,
        trigger: tpl.trigger,
        cuerpo: tpl.cuerpo,
        inputs_schema: tpl.inputs_schema,
        propuesto_por: 'athena_seed',
      });
      created.push(s.name);
    } catch (err) {
      console.warn('[seed] falló', tpl.nombre, ':', err.message);
    }
  }
  return { created, skipped };
}
