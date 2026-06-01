// ============================================================
//  Team review — Athena revisa el trabajo del equipo antes
//  de que salga, para que sea bulletproof
//  ───────────────────────────────────────────────────────
//  Pain: Isabel pasaba años revisando manualmente cada email,
//  SMS, post que el equipo iba a mandar. Errores típicos:
//  número mal escrito, nombre mal, dato incorrecto del plan,
//  vocabulario CMS-forbidden, tono fuera de marca.
//
//  Athena se vuelve el filtro intermedio. Sami / Skarleth /
//  cualquier empleada manda un draft a Athena (vía WhatsApp
//  por su número Twilio o slash command), Athena corre:
//    - Hooks existentes (medical/financial/SOA/CMS/vocab/length)
//    - Tono Haiku ("¿suena a Isabel?")
//    - Spell-check básico (palabras Medicare más comunes
//      mal-escritas + nombres de carriers)
//    - Detecta números de teléfono inconsistentes
//
//  Devuelve: APROBADO ✓ / APROBADO CON NOTAS ⚠ / RECHAZADO 🛑
//
//  Bonus: registra iniciativa de la empleada (qué propuso
//  mejorar) para que Isabel lo vea en weekly review.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const INIT_FILE = join(DATA_DIR, 'team_initiatives.json');

// ─── Spell-check específico Medicare/SoCal ───
const COMMON_TYPOS = {
  // Carriers
  'antem': 'Anthem',
  'antehm': 'Anthem',
  'humanna': 'Humana',
  'umana': 'Humana',
  'molinas': 'Molina',
  'scancare': 'SCAN',
  'scan classic hmoo': 'SCAN Classic HMO',
  'scanclass': 'SCAN Classic',
  'health-net': 'Health Net',
  'la-care': 'LA Care',
  'alignmenthealth': 'Alignment Health Plan',
  // Términos Medicare
  'medicar': 'Medicare',
  'medicaid': null, // CUIDADO: Medicaid es real pero en CA es Medi-Cal; flag
  'medi cal': 'Medi-Cal',
  'medical': null, // flag — Medi-Cal vs medical
  'aep': null, // flag si está en lowercase, debe ser AEP
  'mapd': null, // debe ser MAPD
  'pdp': null,
  'soa': null,
  'tcpa': null,
};

const CARRIER_NAMES = ['Anthem', 'SCAN', 'Humana', 'Alignment Health Plan', 'LA Care', 'Health Net', 'Molina', 'UHC', 'UnitedHealthcare', 'Blue Shield'];

// ─── Phone number consistency check ───
// Si el draft menciona un teléfono, detectarlo y reportarlo
// para que Athena pueda cross-checkear via Pilar con LUNA.
function extractPhones(text) {
  // US format: (xxx) xxx-xxxx, xxx-xxx-xxxx, xxx.xxx.xxxx, +1xxxxxxxxxx
  const phones = String(text).match(/(?:\+?1[-\s.]?)?\(?\d{3}\)?[-\s.]?\d{3}[-\s.]?\d{4}/g) || [];
  return phones.map((p) => p.replace(/\D/g, '').slice(-10));
}

function extractEmails(text) {
  return String(text).match(/[\w.+-]+@[\w-]+\.[\w.-]+/g) || [];
}

// ─── Review principal ───
export async function reviewTeamDraft({ persona, contenido, destinatario = '', tipo = 'email' }) {
  if (!contenido || contenido.length < 5) return { ok: false, error: 'Draft muy corto o vacío.' };
  const flags = [];

  // 1. Spell-check de typos comunes Medicare
  const lower = contenido.toLowerCase();
  for (const [typo, correct] of Object.entries(COMMON_TYPOS)) {
    // Word-boundary match (no parte de otra palabra)
    const re = new RegExp(`\\b${typo.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&')}\\b`, 'i');
    if (re.test(contenido)) {
      if (correct) {
        flags.push({
          severidad: 'aviso',
          kind: 'typo_medicare',
          nota: `"${typo}" — ¿quisiste decir "${correct}"?`,
          sugerencia: `Reemplazar "${typo}" → "${correct}"`,
        });
      } else if (typo === 'medical' && !/medi.?cal/i.test(contenido)) {
        flags.push({
          severidad: 'aviso',
          kind: 'medi_cal_vs_medical',
          nota: 'Dice "medical" — ojo: en California es Medi-Cal (con guión). ¿Era eso?',
        });
      } else if (['aep', 'mapd', 'pdp', 'soa', 'tcpa'].includes(typo)) {
        // verificar que esté en MAYÚSCULAS
        const re2 = new RegExp(`\\b${typo}\\b`);
        if (re2.test(contenido)) { // matched lowercase
          flags.push({
            severidad: 'info',
            kind: 'acronimo_minusculas',
            nota: `"${typo}" debe ir en MAYÚSCULAS: "${typo.toUpperCase()}"`,
          });
        }
      }
    }
  }

  // 2. Carriers mal capitalizados
  for (const c of CARRIER_NAMES) {
    const lowerC = c.toLowerCase();
    if (lower.includes(lowerC) && !contenido.includes(c)) {
      flags.push({
        severidad: 'info',
        kind: 'carrier_capitalization',
        nota: `"${c}" debe ir con esa capitalización exacta.`,
      });
    }
  }

  // 3. Phones / emails detectados (Athena los puede cross-checkear via Pilar→LUNA)
  const phones = extractPhones(contenido);
  const emails = extractEmails(contenido);
  if (phones.length) {
    flags.push({
      severidad: 'info',
      kind: 'phone_detected',
      nota: `${phones.length} teléfono(s) detectado(s): ${phones.join(', ')}. Athena puede verificar contra LUNA via Pilar si necesitas.`,
      datos: phones,
    });
  }
  if (emails.length) {
    flags.push({
      severidad: 'info',
      kind: 'email_detected',
      nota: `${emails.length} email(s) detectado(s): ${emails.join(', ')}. Verifica que estén bien escritos.`,
      datos: emails,
    });
  }

  // 4. Delegate al review hook existente para CMS/medical/financial/disclaimer/etc.
  try {
    const { reviewOutbound } = await import('./hooks.js');
    const toolName = tipo === 'sms' ? 'enviar_sms' : tipo === 'sami' ? 'mensaje_a_sami' : 'enviar_email';
    const input = tipo === 'email'
      ? { para: destinatario, asunto: '(team draft)', cuerpo: contenido }
      : { para: destinatario, mensaje: contenido };
    const r = await reviewOutbound({ toolName, input });
    flags.push(...(r.flags || []));
  } catch (err) {
    flags.push({ severidad: 'info', kind: 'review_failed', nota: `Review hook falló: ${err.message}` });
  }

  const altos = flags.filter((f) => f.severidad === 'alto').length;
  const avisos = flags.filter((f) => f.severidad === 'aviso').length;
  const infos = flags.filter((f) => f.severidad === 'info').length;
  let veredicto;
  if (altos > 0) veredicto = '🛑 RECHAZADO';
  else if (avisos > 0) veredicto = '⚠️ APROBADO CON NOTAS';
  else veredicto = '✓ APROBADO';

  return {
    ok: true,
    veredicto,
    persona,
    altos, avisos, infos,
    flags,
    phones_detected: phones,
    emails_detected: emails,
  };
}

export function formatReviewResult(r) {
  if (!r.ok) return `Error: ${r.error}`;
  const lines = [`${r.veredicto}  (${r.persona || 'sin persona'})  · ${r.altos} altos · ${r.avisos} avisos · ${r.infos} info`];
  for (const f of r.flags) {
    const icon = f.severidad === 'alto' ? '🛑' : f.severidad === 'aviso' ? '⚠️' : 'ℹ️';
    lines.push(`  ${icon} [${f.kind}] ${f.nota}${f.sugerencia ? ` → ${f.sugerencia}` : ''}`);
  }
  return lines.join('\n');
}

// ─── Initiative tracking ───
function ensureDir() {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
}

function loadInit() {
  try {
    if (existsSync(INIT_FILE)) return JSON.parse(readFileSync(INIT_FILE, 'utf8'));
  } catch { /* ignore */ }
  return [];
}

function saveInit(data) {
  ensureDir();
  writeFileSync(INIT_FILE, JSON.stringify(data.slice(-300), null, 2));
}

function newInitId() {
  return `init_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`;
}

export function recordInitiative({ persona, propuesta, contexto = '' }) {
  if (!persona || !propuesta) return { ok: false, error: 'Falta persona o propuesta.' };
  const data = loadInit();
  const entry = {
    id: newInitId(),
    persona: String(persona).trim(),
    propuesta: String(propuesta).slice(0, 400),
    contexto: String(contexto).slice(0, 300),
    creado: new Date().toISOString(),
    status: 'propuesta', // propuesta | aprobada | implementada | descartada
  };
  data.push(entry);
  saveInit(data);
  return { ok: true, initiative: entry };
}

export function listInitiatives({ persona = null, status = null, sinceDays = 14 } = {}) {
  const cutoff = Date.now() - sinceDays * 86_400_000;
  return loadInit()
    .filter((i) => new Date(i.creado).getTime() >= cutoff)
    .filter((i) => !persona || i.persona.toLowerCase() === String(persona).toLowerCase())
    .filter((i) => !status || i.status === status)
    .slice(-50)
    .reverse();
}

export function updateInitiativeStatus(id, newStatus) {
  const data = loadInit();
  const i = data.findIndex((x) => x.id === id);
  if (i < 0) return null;
  data[i] = { ...data[i], status: newStatus, actualizado: new Date().toISOString() };
  saveInit(data);
  return data[i];
}

// Bloque para weekly review: ¿quién propuso qué esta semana?
export function buildInitiativeWeeklyBlock() {
  const recent = listInitiatives({ sinceDays: 7 });
  if (!recent.length) return null;
  const byPerson = {};
  for (const i of recent) {
    if (!byPerson[i.persona]) byPerson[i.persona] = [];
    byPerson[i.persona].push(i);
  }
  const lines = ['💡 INICIATIVAS DEL EQUIPO esta semana:'];
  for (const [p, items] of Object.entries(byPerson)) {
    lines.push(`\n${p}:`);
    for (const it of items) {
      lines.push(`  • [${it.id}] ${it.propuesta.slice(0, 100)} (${it.status})`);
    }
  }
  return lines.join('\n');
}
