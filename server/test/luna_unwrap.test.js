// Prueba de regresión del bug que vació el team email (AUDIT.md H1):
// una respuesta de LUNA con forma inesperada NO debe disfrazarse de
// "lista vacía" — debe reportar shape_error.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { unwrapArrayResponse } from '../src/luna_shape.js';

test('array bajo una key conocida → array directo', () => {
  const r = unwrapArrayResponse({ ok: true, data: { tickets: [{ id: 1 }] } });
  assert.equal(r.ok, true);
  assert.deepEqual(r.data, [{ id: 1 }]);
});

test('data ya es array → pasa tal cual', () => {
  const r = unwrapArrayResponse({ ok: true, data: [1, 2] });
  assert.equal(r.ok, true);
  assert.deepEqual(r.data, [1, 2]);
});

test('forma desconocida → shape_error, NO vacío silencioso', () => {
  const r = unwrapArrayResponse({ ok: true, data: { weird: { x: 1 }, status: 'ok' } });
  assert.equal(r.ok, false, 'debe marcar error, no ok');
  assert.equal(r.kind, 'shape_error');
});

test('data null → lista vacía legítima', () => {
  const r = unwrapArrayResponse({ ok: true, data: null });
  assert.equal(r.ok, true);
  assert.deepEqual(r.data, []);
});

test('respuesta con ok:false pasa sin tocar', () => {
  const r = unwrapArrayResponse({ ok: false, error: 'boom', kind: 'auth' });
  assert.equal(r.ok, false);
  assert.equal(r.error, 'boom');
});
