// ============================================================
//  storage.js — escritura atómica de los JSON de data/
//  ───────────────────────────────────────────────────
//  Problema que resuelve (AUDIT.md C1): writeFileSync directo
//  puede dejar el archivo TRUNCADO si el proceso muere a mitad
//  de escritura (deploy de Railway, OOM, crash). Un tasks.json
//  truncado = JSON.parse falla = el load() regresa [] = se
//  pierde TODO el archivo en la siguiente escritura.
//
//  Patrón: escribir a un temp en el mismo directorio y rename.
//  rename() es atómico en el mismo volumen — el archivo real
//  siempre es la versión vieja completa o la nueva completa,
//  nunca un pedazo.
// ============================================================
import { writeFileSync, renameSync, mkdirSync, existsSync, unlinkSync } from 'node:fs';
import { dirname } from 'node:path';

export function atomicWriteJson(file, data) {
  const dir = dirname(file);
  if (!existsSync(dir)) mkdirSync(dir, { recursive: true });
  const tmp = `${file}.${process.pid}.${Math.random().toString(36).slice(2, 8)}.tmp`;
  try {
    writeFileSync(tmp, JSON.stringify(data, null, 2));
    renameSync(tmp, file);
  } catch (err) {
    // Limpia el temp si el rename falló, y propaga — quien guarda
    // debe enterarse de que NO se guardó.
    try { unlinkSync(tmp); } catch { /* ya no existe */ }
    throw err;
  }
}
