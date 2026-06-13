// El chequeo de persistencia: detecta si data/ sobrevive reinicios.
// Usa SOLO un directorio temporal — nunca toca data/ real.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { rmSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { checkPersistence } from '../src/persistence_check.js';

test('primer arranque: persisted=false, boots=1', () => {
  const dir = mkdtempSync(join(tmpdir(), 'athena-persist-'));
  const r = checkPersistence(dir);
  assert.equal(r.persisted, false);
  assert.equal(r.boots, 1);
  rmSync(dir, { recursive: true, force: true });
});

test('segundo arranque sobre el MISMO dir: persisted=true, boots sube', () => {
  const dir = mkdtempSync(join(tmpdir(), 'athena-persist-'));
  checkPersistence(dir, '2026-01-01T00:00:00Z'); // boot 1
  const r = checkPersistence(dir, '2026-01-02T00:00:00Z'); // boot 2 (disco persistió)
  assert.equal(r.persisted, true);
  assert.equal(r.boots, 2);
  assert.equal(r.previousBoot, '2026-01-01T00:00:00Z');
  rmSync(dir, { recursive: true, force: true });
});

test('disco efímero (dir nuevo cada vez): siempre persisted=false, boots=1', () => {
  // Simula que el marcador desaparece entre reinicios (volumen no montado).
  for (let i = 0; i < 3; i++) {
    const dir = mkdtempSync(join(tmpdir(), 'athena-ephem-'));
    const r = checkPersistence(dir);
    assert.equal(r.persisted, false, 'sin volumen, nunca debe verse como persistido');
    assert.equal(r.boots, 1);
    rmSync(dir, { recursive: true, force: true });
  }
});
