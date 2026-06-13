// Fechas de los COMPROMISOS (lo que otros le prometen a Isabel). Si la fecha
// falla, Athena cobra a destiempo o nunca. Pura, sin tocar datos.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseDue } from '../src/commitments.js';

test('vence explícito (ISO) se conserva', () => {
  assert.equal(new Date(parseDue({ vence: '2026-08-01T17:00:00Z' })).toISOString(), '2026-08-01T17:00:00.000Z');
});

test('vence_en_horas ≈ ahora + N horas', () => {
  const diffMin = (new Date(parseDue({ vence_en_horas: 2 })).getTime() - Date.now()) / 60000;
  assert.ok(diffMin > 115 && diffMin < 125, `esperaba ~120 min, dio ${diffMin}`);
});

test('vence_en_dias ≈ ahora + N días', () => {
  const diffH = (new Date(parseDue({ vence_en_dias: 3 })).getTime() - Date.now()) / 3600000;
  assert.ok(diffH > 71 && diffH < 73, `esperaba ~72 h, dio ${diffH}`);
});

test('sin fecha o valores no positivos → null', () => {
  assert.equal(parseDue({}), null);
  assert.equal(parseDue({ vence_en_horas: 0 }), null);
  assert.equal(parseDue({ vence_en_dias: -1 }), null);
});

test('vence inválido cae al siguiente criterio o null', () => {
  assert.equal(parseDue({ vence: 'no es fecha' }), null);
  // si hay vence inválido PERO horas válidas, usa las horas
  assert.ok(parseDue({ vence: 'xx', vence_en_horas: 1 }));
});
