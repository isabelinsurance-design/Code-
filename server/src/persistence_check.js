// ============================================================
//  persistence_check.js — ¿data/ sobrevive los deploys?
//  ──────────────────────────────────────────────────────
//  La causa #1 de "Athena no guarda mis cosas": en Railway el disco
//  del contenedor es EFÍMERO. data/ solo persiste si hay un volumen
//  montado en su ruta (ver DEPLOY.md → Volumes, /app/server/data).
//  Si el volumen no está, cada deploy borra wiki/tareas/memoria y nadie
//  se entera porque falla en SILENCIO.
//
//  Este chequeo escribe un marcador al arrancar. Si al siguiente boot el
//  marcador NO está, el disco es efímero → grita en el log. No arregla el
//  volumen (eso es config de Railway), pero hace VISIBLE el problema.
// ============================================================
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { atomicWriteJson } from './storage.js';

const DEFAULT_DATA_DIR = join(dirname(fileURLToPath(import.meta.url)), '..', 'data');

// Lógica pura/testeable: lee el marcador previo, calcula estado, lo reescribe.
export function checkPersistence(dataDir = DEFAULT_DATA_DIR, now = new Date().toISOString()) {
  const marker = join(dataDir, '.persistence_marker.json');
  let previous = null;
  try {
    if (existsSync(marker)) previous = JSON.parse(readFileSync(marker, 'utf8'));
  } catch { /* marcador corrupto → tratamos como ausente */ }

  const persisted = Boolean(previous && previous.lastBoot);
  const boots = (previous?.boots || 0) + 1;
  atomicWriteJson(marker, { lastBoot: now, boots, dataDir });
  return { persisted, previousBoot: previous?.lastBoot || null, boots, dataDir };
}

// Lo que llama el arranque: corre el chequeo y loggea fuerte si hay riesgo.
export function logPersistenceStatus(dataDir = DEFAULT_DATA_DIR) {
  try {
    const r = checkPersistence(dataDir);
    if (r.persisted) {
      console.log(`[persistencia] OK — data/ sobrevivió un reinicio (boot #${r.boots}, anterior ${r.previousBoot}).`);
    } else if (r.boots === 1) {
      console.log(`[persistencia] primer arranque (sin marcador previo). Path: ${r.dataDir}`);
    } else {
      console.warn(`[persistencia] ⚠️ data/ parece EFÍMERO — no sobrevivió el reinicio anterior. Si esto sale en CADA deploy, el volumen de Railway NO está montado en ${r.dataDir} y Athena PIERDE su memoria cada vez. Arréglalo en Railway → Volumes (ver DEPLOY.md).`);
    }
    return r;
  } catch (e) {
    console.error('[persistencia] no se pudo verificar:', e.message);
    return null;
  }
}
