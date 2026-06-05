// ============================================================
//  Vacation Mode — filtro proactivo + delegación automática
//  ────────────────────────────────────────────────────────
//  Cuando Isabel está de vacaciones:
//   - Solo se le interrumpe con cosas URGENTES (clasificadas por Haiku)
//   - Todo lo no-urgente se delega automático a Sami
//   - Crons proactivos respetan SU timezone, no la de SoCal
//   - Reporte 2x día (mañana + tarde) en su timezone
//   - Auto-responder de email (si Gmail config)
//
//  Persistencia en data/vacation.json:
//    { active: bool, start_iso, end_iso, timezone, location, notes }
//
//  Comando: vacation_modo(activar=true, hasta='2026-07-15', timezone='Europe/Madrid')
//  vacation_modo(activar=false) → vuelve a normal
// ============================================================
import { writeFileSync, readFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const VACATION_FILE = join(__dirname, '..', 'data', 'vacation.json');

function load() {
  try {
    if (!existsSync(VACATION_FILE)) return { active: false };
    return JSON.parse(readFileSync(VACATION_FILE, 'utf8'));
  } catch { return { active: false }; }
}

function save(state) {
  try {
    if (!existsSync(dirname(VACATION_FILE))) mkdirSync(dirname(VACATION_FILE), { recursive: true });
    writeFileSync(VACATION_FILE, JSON.stringify(state, null, 2));
  } catch (e) { console.warn('[vacation] save falló:', e.message); }
}

export function getVacationState() {
  const s = load();
  if (!s.active) return { active: false };
  // Auto-desactiva si ya pasó la fecha de regreso
  if (s.end_iso && Date.now() > new Date(s.end_iso).getTime()) {
    save({ active: false });
    return { active: false };
  }
  return s;
}

export function isOnVacation() {
  return getVacationState().active === true;
}

export function setVacation({ activar, hasta = null, desde = null, timezone = null, location = '', notes = '' }) {
  if (!activar) {
    save({ active: false, ended_at: new Date().toISOString() });
    return { ok: true, state: 'desactivado' };
  }
  const state = {
    active: true,
    start_iso: desde || new Date().toISOString(),
    end_iso: hasta || null,
    timezone: timezone || process.env.TIMEZONE || 'America/Los_Angeles',
    location,
    notes,
    activated_at: new Date().toISOString(),
  };
  save(state);
  return { ok: true, state };
}

// Genera el bloque de contexto que se inyecta a Athena cuando hay vacación.
export function vacationContextBlock() {
  const v = getVacationState();
  if (!v.active) return '';
  const hasta = v.end_iso ? new Date(v.end_iso).toLocaleDateString('es-MX', { day: 'numeric', month: 'long' }) : 'sin fecha';
  const lines = [
    `🌴 MODO VACACIONES ACTIVO — Isabel está fuera hasta ${hasta}.`,
    v.location ? `Ubicación: ${v.location}.` : '',
    v.timezone && v.timezone !== process.env.TIMEZONE ? `Su timezone: ${v.timezone} (no la despiertes a su 3am).` : '',
    'REGLAS MIENTRAS:',
    '- Solo interrúmpela con cosas URGENTES (cliente Medicare en crisis, CMS audit, emergencia familiar, decisión que SOLO ella puede tomar).',
    '- Para todo lo demás: delega INMEDIATO a Sami via mensaje_a_sami O crea ticket LUNA asignado a 10. NO esperes su confirmación.',
    '- Email a clientes: usa templates pre-aprobados (template_usar) sin pasar por drafts queue. Si no hay template, redacta y pide aprobación a Sami (que actúe como gate, no Isabel).',
    '- Reportes: dáselos en su timezone, no la de SoCal.',
    '- Cuando reportes algo, sé CONCISA. Ella está descansando.',
    v.notes ? `Notas extra: ${v.notes}` : '',
  ].filter(Boolean);
  return lines.join('\n');
}
