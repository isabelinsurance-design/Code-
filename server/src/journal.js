// ============================================================
//  Journal — Alma Mindset ahora puede detectar patrones
//  ────────────────────────────────────────────────────
//  Isabel captura emocionalmente lo que está sintiendo, en
//  texto o voz transcrita. Athena:
//   - Lo guarda con timestamp
//   - Detecta palabras clave de estado emocional
//   - Cuenta menciones por categoría en últimos 7-30 días
//   - Inyecta el panorama a Alma cuando es consultada
//
//  Resultado: Alma deja de adivinar. Ve "llevas 4 días
//  mencionando estrés en el contexto de Skarleth — ¿es ella
//  o es algo más?"
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'journal.json');

const EMOCIONES = {
  estres: /\b(estr[eé]s|ansie?dad|abrumad[oa]|presion(?:ada)?|cansad[oa]|agotad[oa]|quemad[oa]|burnt?\s?out)\b/i,
  alegria: /\b(content[oa]|feliz|alegr[ií]a|emocionad[oa]|happy|grat(?:itud|a))\b/i,
  frustracion: /\b(frustrad[oa]|enojad[oa]|harta?|fed up|no aguanto)\b/i,
  tristeza: /\b(triste|deprimid[oa]|baj[oa]neada|sad)\b/i,
  miedo: /\b(miedo|asustad[oa]|preocupad[oa]|worry|worried)\b/i,
  paz: /\b(tranquil[oa]|en paz|calm[ao]|relajad[oa])\b/i,
};

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() { try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {} return []; }
function save(d) { ensureDir(); writeFileSync(FILE, JSON.stringify(d.slice(-1000), null, 2)); }
function newId() { return `j_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }

function detectEmociones(texto) {
  const found = [];
  for (const [emocion, re] of Object.entries(EMOCIONES)) {
    if (re.test(texto)) found.push(emocion);
  }
  return found;
}

export function registrarEntrada({ texto, tipo = 'journal', gratitud = null, frustracion = null }) {
  if (!texto || texto.length < 3) return { ok: false, error: 'Falta texto.' };
  const data = load();
  const entry = {
    id: newId(),
    tipo, // journal | gratitud | win | frustracion
    texto: String(texto).slice(0, 2000),
    gratitud: gratitud ? String(gratitud).slice(0, 300) : null,
    frustracion: frustracion ? String(frustracion).slice(0, 300) : null,
    emociones: detectEmociones(texto),
    dia: new Date().toISOString().slice(0, 10),
    ts: new Date().toISOString(),
  };
  data.push(entry); save(data);
  return { ok: true, entry };
}

export function listRecent({ dias = 7, tipo = null } = {}) {
  const cutoff = Date.now() - dias * 86_400_000;
  return load()
    .filter((e) => new Date(e.ts).getTime() >= cutoff)
    .filter((e) => !tipo || e.tipo === tipo)
    .slice(-100)
    .reverse();
}

// Cuenta menciones de cada emoción en últimos N días.
export function emocionesPattern({ dias = 7 } = {}) {
  const recientes = listRecent({ dias });
  const counts = {};
  for (const e of recientes) {
    for (const em of e.emociones || []) {
      counts[em] = (counts[em] || 0) + 1;
    }
  }
  return { dias_analizados: dias, n_entradas: recientes.length, counts };
}

export function buildJournalInline() {
  const p = emocionesPattern({ dias: 7 });
  if (!p.n_entradas) return '';
  const top = Object.entries(p.counts).sort((a, b) => b[1] - a[1]).slice(0, 3);
  if (!top.length) return `journal: ${p.n_entradas} entradas 7d`;
  return `journal 7d: ${p.n_entradas} entradas · ${top.map(([k, v]) => `${k}×${v}`).join(' · ')}`;
}

export function buildJournalForCoach() {
  const recientes = listRecent({ dias: 14 });
  if (!recientes.length) return '';
  const p = emocionesPattern({ dias: 14 });
  const lines = [`📓 JOURNAL — últimos 14 días (${p.n_entradas} entradas)`];
  if (Object.keys(p.counts).length) {
    const summary = Object.entries(p.counts).sort((a, b) => b[1] - a[1]).map(([k, v]) => `${k} ×${v}`).join(' · ');
    lines.push(`Emociones detectadas: ${summary}`);
  }
  const lastEntries = recientes.slice(0, 5);
  lines.push(`\nÚltimas entradas:`);
  for (const e of lastEntries) {
    lines.push(`  [${e.dia}] ${e.tipo}: "${e.texto.slice(0, 120)}"`);
  }
  return `\n\nDATOS REALES DEL JOURNAL DE ISABEL (últimos 14 días):\n${lines.join('\n')}\n\nUsa estos patrones para coachear. Si una emoción aparece repetidamente, NÓMBRALA con cariño. Si hay gratitud constante, refleja eso de vuelta. Si hay frustración con UNA persona / situación recurrente, ayuda a Isabel a ver el patrón.`;
}
