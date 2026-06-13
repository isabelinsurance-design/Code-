// Lógica pura del auto-restore: el candado "solo si está vacío" y la
// selección del backup más reciente. (El round-trip a R2 no se prueba aquí —
// requiere credenciales; se verifica por import smoke + el patrón espejo de snapshot.)
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { rmSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { isDataEmpty, pickLatestSnapshotName } from '../src/backup.js';

test('isDataEmpty: dir inexistente → true', () => {
  assert.equal(isDataEmpty(join(tmpdir(), 'no-existe-' + Date.now())), true);
});

test('isDataEmpty: solo el marcador de persistencia → true (sigue vacío)', () => {
  const dir = mkdtempSync(join(tmpdir(), 'athena-empty-'));
  writeFileSync(join(dir, '.persistence_marker.json'), '{}');
  assert.equal(isDataEmpty(dir), true);
  rmSync(dir, { recursive: true, force: true });
});

test('isDataEmpty: con un .json de estado → false (NO restaurar encima)', () => {
  const dir = mkdtempSync(join(tmpdir(), 'athena-full-'));
  writeFileSync(join(dir, 'tasks.json'), '[]');
  assert.equal(isDataEmpty(dir), false);
  rmSync(dir, { recursive: true, force: true });
});

test('pickLatestSnapshotName: escoge el más reciente por timestamp', () => {
  const names = [
    'athena/snapshot_20260613_0900.tar.gz',
    'athena/snapshot_20260613_1430.tar.gz',
    'athena/snapshot_20260612_2300.tar.gz',
  ];
  assert.equal(pickLatestSnapshotName(names), 'athena/snapshot_20260613_1430.tar.gz');
});

test('pickLatestSnapshotName: ignora nombres que no son snapshots', () => {
  assert.equal(pickLatestSnapshotName(['athena/otro.txt', 'readme.md']), null);
});
