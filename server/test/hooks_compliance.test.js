// Candados de compliance de CMS — protegen la licencia de Isabel.
// Probamos la lógica determinista pura (sin API ni LUNA).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { deterministicFlags } from '../src/hooks.js';

const has = (flags, kind) => flags.some((f) => f.kind === kind);

test('claim absoluto de CMS ("el mejor plan") → flag alto', () => {
  const f = deterministicFlags('Este es el mejor plan para usted, sin duda.', 'enviar_sms');
  assert.ok(has(f, 'cms_absolute_claim'));
  assert.equal(f.find((x) => x.kind === 'cms_absolute_claim').severidad, 'alto');
});

test('"100% gratis" / "garantizado" → flag CMS', () => {
  assert.ok(has(deterministicFlags('cobertura 100% gratis', 'enviar_sms'), 'cms_absolute_claim'));
  assert.ok(has(deterministicFlags('ahorro garantizado para ti', 'enviar_sms'), 'cms_absolute_claim'));
});

test('consejo médico sin disclaimer → flag alto; con disclaimer → no', () => {
  assert.ok(has(deterministicFlags('Tómate dos pastillas en la mañana.', 'enviar_sms'), 'medical_advice'));
  assert.ok(!has(deterministicFlags('Tómate dos pastillas, pero yo no soy doctora — confirma con tu médico.', 'enviar_sms'), 'medical_advice'));
});

test('consejo financiero → flag alto', () => {
  assert.ok(has(deterministicFlags('Deberías invertir para un rendimiento del 8%.', 'enviar_sms'), 'financial_advice'));
});

test('email con detalles de plan SIN disclaimer CMS → aviso; con disclaimer → no', () => {
  const detalle = 'Tu premium es $0 y el deductible de tu MAPD baja. ' + 'x'.repeat(220);
  assert.ok(has(deterministicFlags(detalle, 'enviar_email'), 'cms_disclaimer_missing'));
  const conDisc = detalle + ' Isabel Fuentes es agente independiente licenciada, no afiliada al gobierno ni a Medicare.';
  assert.ok(!has(deterministicFlags(conDisc, 'enviar_email'), 'cms_disclaimer_missing'));
});

test('mensaje limpio → sin flags', () => {
  assert.equal(deterministicFlags('Hola, ¿cómo te fue en tu cita de ayer?', 'enviar_sms').length, 0);
});

test('SMS larguísimo → flag de longitud', () => {
  assert.ok(has(deterministicFlags('a'.repeat(400), 'enviar_sms'), 'length'));
});
