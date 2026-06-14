// Costo REAL por tokens — protege el cálculo del dinero que ve Isabel.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { costFromTokens, realCostSummary } from '../src/usage.js';

test('Sonnet: 1M input = $3, 1M output = $15', () => {
  assert.equal(costFromTokens('claude-sonnet-4-6', { input_tokens: 1_000_000 }), 3);
  assert.equal(costFromTokens('claude-sonnet-4-6', { output_tokens: 1_000_000 }), 15);
});

test('Opus cobra más que Sonnet que Haiku (mismo uso)', () => {
  const u = { input_tokens: 100_000, output_tokens: 50_000 };
  const opus = costFromTokens('claude-opus-4-8', u);
  const sonnet = costFromTokens('claude-sonnet-4-6', u);
  const haiku = costFromTokens('claude-haiku-4-5-20251001', u);
  assert.ok(opus > sonnet && sonnet > haiku, `${opus} > ${sonnet} > ${haiku}`);
});

test('incluye cache_read (barato) y cache_write', () => {
  const c = costFromTokens('claude-sonnet-4-6', { cache_read_input_tokens: 1_000_000 });
  assert.equal(c, 0.3); // cache read sonnet = $0.30/M
});

test('modelo desconocido → tarifa Sonnet (default)', () => {
  assert.equal(costFromTokens('algo-raro', { input_tokens: 1_000_000 }), 3);
});

test('usage vacío → costo 0', () => {
  assert.equal(costFromTokens('claude-opus-4-8', {}), 0);
});

test('realCostSummary suma costo y tokens del periodo', () => {
  const now = new Date('2026-06-14T12:00:00-07:00');
  const rows = [
    { ts: '2026-06-14T09:00:00-07:00', cost: 0.10, in: 1000, out: 500 },
    { ts: '2026-06-14T08:00:00-07:00', cost: 0.05, in: 800, out: 200 },
    { ts: '2026-06-01T08:00:00-07:00', cost: 1.00, in: 9999, out: 9999 }, // este mes, no hoy
  ];
  const s = realCostSummary(rows, now);
  assert.equal(s.today.count, 2);
  assert.equal(s.today.cost, 0.15);
  assert.equal(s.today.tokens_in, 1800);
  assert.equal(s.month.count, 3);
  assert.equal(s.has_data, true);
});

test('sin datos → has_data false, ceros', () => {
  const s = realCostSummary([], new Date());
  assert.equal(s.has_data, false);
  assert.equal(s.today.cost, 0);
});
