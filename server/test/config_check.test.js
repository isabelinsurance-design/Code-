// Autodiagnóstico de configuración — lógica pura con un env falso.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkConfig } from '../src/config_check.js';

test('env vacío → faltan los críticos', () => {
  const r = checkConfig({});
  assert.equal(r.allCriticalOk, false);
  const names = r.missingCritical.map((c) => c.name);
  assert.ok(names.includes('Cerebro (Anthropic)'));
  assert.ok(names.includes('WhatsApp (Twilio)'));
});

test('env completo (críticos) → allCriticalOk true', () => {
  const env = {
    ANTHROPIC_API_KEY: 'x',
    TWILIO_ACCOUNT_SID: 'x', TWILIO_AUTH_TOKEN: 'x', TWILIO_WHATSAPP_FROM: 'x',
    PUBLIC_URL: 'https://x', ISABEL_WHATSAPP: 'whatsapp:+1',
  };
  const r = checkConfig(env);
  assert.equal(r.allCriticalOk, true);
  assert.equal(r.missingCritical.length, 0);
});

test('valores en blanco cuentan como faltantes', () => {
  const r = checkConfig({ ANTHROPIC_API_KEY: '   ' });
  assert.ok(r.missingCritical.some((c) => c.name === 'Cerebro (Anthropic)'));
});

test('firma de Twilio: ON por defecto, OFF solo si es "false"', () => {
  const on = checkConfig({}).checks.find((c) => c.name === 'Firma de Twilio en prod');
  assert.equal(on.ok, true); // ausente = ON
  const off = checkConfig({ TWILIO_REQUIRE_SIGNATURE: 'false' }).checks.find((c) => c.name === 'Firma de Twilio en prod');
  assert.equal(off.ok, false);
});
