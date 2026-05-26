import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const WIKI_FILE = join(DATA_DIR, 'isabel_wiki.json');
const HISTORY_FILE = join(DATA_DIR, 'conversation.json');

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

export function buildWikiContext() {
  const wiki = getWiki();
  if (!wiki.notas.length) return '';
  return wiki.notas
    .slice(0, 25)
    .map((n) => `- ${n.nota}`)
    .join('\n');
}

// ---- Historial de la conversación de WhatsApp con La Directora ----
// La API de Anthropic es sin estado: hay que mandarle el historial
// completo cada vez. Lo guardamos aquí entre mensajes.
export function getHistory() {
  return load(HISTORY_FILE, []);
}

export function saveHistory(messages) {
  // Guardamos solo los últimos 40 turnos para no crecer sin límite.
  save(HISTORY_FILE, messages.slice(-40));
}
