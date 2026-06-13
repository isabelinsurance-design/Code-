// Pruebas de la escritura atómica — el helper crítico que evita que un
// crash a mitad de escritura trunque los archivos de datos.
// Usa SOLO directorios temporales — nunca toca data/ real.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync, rmSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { atomicWriteJson } from '../src/storage.js';

function tmp() {
  return mkdtempSync(join(tmpdir(), 'athena-test-'));
}

test('atomicWriteJson: escribe y se lee de vuelta igual', () => {
  const dir = tmp();
  const f = join(dir, 'x.json');
  atomicWriteJson(f, { hola: 'isabel', n: [1, 2, 3] });
  const back = JSON.parse(readFileSync(f, 'utf8'));
  assert.equal(back.hola, 'isabel');
  assert.deepEqual(back.n, [1, 2, 3]);
  rmSync(dir, { recursive: true, force: true });
});

test('atomicWriteJson: sobreescribe sin dejar .tmp huérfanos', () => {
  const dir = tmp();
  const f = join(dir, 'x.json');
  atomicWriteJson(f, { v: 1 });
  atomicWriteJson(f, { v: 2 });
  assert.equal(JSON.parse(readFileSync(f, 'utf8')).v, 2);
  const temps = readdirSync(dir).filter((x) => x.endsWith('.tmp'));
  assert.equal(temps.length, 0, 'no debe quedar ningún archivo temporal');
  rmSync(dir, { recursive: true, force: true });
});

test('atomicWriteJson: crea el directorio si no existe', () => {
  const dir = tmp();
  const f = join(dir, 'sub', 'deep', 'y.json');
  atomicWriteJson(f, { ok: true });
  assert.equal(JSON.parse(readFileSync(f, 'utf8')).ok, true);
  rmSync(dir, { recursive: true, force: true });
});
