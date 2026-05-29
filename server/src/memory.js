import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const WIKI_FILE = join(DATA_DIR, 'isabel_wiki.json');
const HISTORY_FILE = join(DATA_DIR, 'conversation.json');
const SEASON_FILE = join(DATA_DIR, 'season.json');

// NOTA: esto guarda en un archivo JSON en el disco del servidor.
// Funciona perfecto para una sola usuaria. En Railway el disco se
// reinicia al re-desplegar — para memoria 100% permanente, conecta
// un volumen de Railway o una base de datos (ver README).

function load(file, fallback) {
  try {
    if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8'));
  } catch {
    /* archivo corrupto o vacío — usamos el fallback */
  }
  return fallback;
}

function save(file, data) {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
  writeFileSync(file, JSON.stringify(data, null, 2));
}

// ---- Isabel Wiki: memoria de largo plazo compartida ----
export function getWiki() {
  return load(WIKI_FILE, { notas: [], perfil: {}, actualizado: null });
}

export function remember(nota) {
  const wiki = getWiki();
  wiki.notas.unshift({ nota, fecha: new Date().toISOString() });
  wiki.notas = wiki.notas.slice(0, 100); // máximo 100 notas recientes
  wiki.actualizado = new Date().toISOString();
  save(WIKI_FILE, wiki);
  return wiki;
}

// Borra entradas de la wiki que matcheen el query (substring, case-insensitive).
// Devuelve cuántas se borraron.
export function forget(query) {
  const wiki = getWiki();
  const q = String(query || '').toLowerCase().trim();
  if (!q) return { borradas: 0, restantes: wiki.notas.length };
  const before = wiki.notas.length;
  wiki.notas = wiki.notas.filter((n) => !n.nota.toLowerCase().includes(q));
  wiki.actualizado = new Date().toISOString();
  save(WIKI_FILE, wiki);
  return { borradas: before - wiki.notas.length, restantes: wiki.notas.length };
}

// Devuelve un listado plano de lo que Athena recuerda (para Isabel).
export function listMemories(limit = 30) {
  const wiki = getWiki();
  return wiki.notas.slice(0, limit);
}

// ---- "Temporada actual" — el foco de Isabel ahora mismo. 1-2 frases. ----
export function getSeason() {
  return load(SEASON_FILE, { texto: '', actualizado: null });
}

export function setSeason(texto) {
  const s = { texto: String(texto || '').trim(), actualizado: new Date().toISOString() };
  save(SEASON_FILE, s);
  return s;
}

export function buildWikiContext() {
  const season = getSeason();
  const wiki = getWiki();
  const parts = [];
  if (season.texto) parts.push(`TEMPORADA ACTUAL (en qué está enfocada Isabel ahora): ${season.texto}`);
  if (wiki.notas.length) {
    parts.push(wiki.notas.slice(0, 25).map((n) => `- ${n.nota}`).join('\n'));
  }
  return parts.join('\n\n');
}

// ---- Historial de la conversación de WhatsApp con Athena ----
// La API de Anthropic es sin estado: hay que mandarle el historial
// completo cada vez. Lo guardamos aquí entre mensajes.
export function getHistory() {
  return load(HISTORY_FILE, []);
}

export function saveHistory(messages) {
  // Guardamos solo los últimos 40 turnos para no crecer sin límite.
  save(HISTORY_FILE, messages.slice(-40));
}
