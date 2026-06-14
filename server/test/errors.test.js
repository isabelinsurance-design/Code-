// Visibilidad de fallas — el resumen puro que alimenta el dashboard.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { errorsSummary } from '../src/errors.js';

const now = new Date('2026-06-14T12:00:00-07:00');
const ROWS = [
  { ts: '2026-06-14T09:00:00-07:00', source: 'cron:briefing', message: 'boom' },
  { ts: '2026-06-14T08:00:00-07:00', source: 'cron:briefing', message: 'boom2' },
  { ts: '2026-06-14T01:00:00-07:00', source: 'bridge:luna', message: '403' },
  { ts: '2026-06-10T10:00:00-07:00', source: 'cron:triage', message: 'viejo' },
];

test('cuenta total, de hoy, y de últimas 24h', () => {
  const s = errorsSummary(ROWS, now);
  assert.equal(s.total, 4);
  assert.equal(s.today, 3, 'tres son del 14 jun');
  assert.equal(s.last24h, 3, 'el del 10 jun queda fuera de 24h');
});

test('agrupa por fuente', () => {
  const s = errorsSummary(ROWS, now);
  assert.equal(s.by_source['cron:briefing'], 2);
  assert.equal(s.by_source['bridge:luna'], 1);
  assert.equal(s.by_source['cron:triage'], 1);
});

test('recent trae los más recientes (máx 10)', () => {
  const s = errorsSummary(ROWS, now);
  assert.ok(s.recent.length <= 10);
  assert.equal(s.recent[0].message, 'boom');
});

test('sin errores → resumen en ceros, no truena', () => {
  const s = errorsSummary([], now);
  assert.equal(s.total, 0);
  assert.equal(s.today, 0);
  assert.deepEqual(s.by_source, {});
});

test('filas basura (ts inválido) se ignoran sin tronar', () => {
  const s = errorsSummary([{ source: 'x' }, { ts: 'nope', source: 'y' }], now);
  assert.equal(s.today, 0);
});
