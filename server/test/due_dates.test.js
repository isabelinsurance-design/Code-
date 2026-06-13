// Lógica de fechas/recordatorios — si esto falla, Athena recuerda cosas a la
// hora equivocada. La propiedad clave: "en N días a las 9am HORA LOCAL", robusto
// a horario de verano (el AUDIT marcó un posible edge de DST).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseDueDate, nineAmLocalInDays } from '../src/tasks.js';

const TZ = 'America/Los_Angeles';
function horaLocal(iso, tz = TZ) {
  const p = new Intl.DateTimeFormat('en-US', {
    timeZone: tz, hour: '2-digit', minute: '2-digit', hour12: false,
  }).formatToParts(new Date(iso));
  return `${p.find((x) => x.type === 'hour').value}:${p.find((x) => x.type === 'minute').value}`;
}

test('vence explícito (ISO) se conserva', () => {
  const r = parseDueDate({ vence: '2026-08-01T17:00:00Z' });
  assert.equal(new Date(r).toISOString(), '2026-08-01T17:00:00.000Z');
});

test('vence_en_horas ≈ ahora + N horas', () => {
  const r = parseDueDate({ vence_en_horas: 3 });
  const diffMin = (new Date(r).getTime() - Date.now()) / 60000;
  assert.ok(diffMin > 175 && diffMin < 185, `esperaba ~180 min, dio ${diffMin}`);
});

test('vence_en_dias cae a las 09:00 HORA LOCAL (robusto a DST)', () => {
  process.env.TIMEZONE = TZ;
  // Probamos varios offsets, incluyendo cruzar el cambio de horario:
  for (const days of [1, 30, 120, 250]) {
    const iso = nineAmLocalInDays(days);
    assert.equal(horaLocal(iso), '09:00', `a ${days} días debería ser 09:00 local, dio ${horaLocal(iso)}`);
  }
});

test('sin fecha → null', () => {
  assert.equal(parseDueDate({}), null);
  assert.equal(parseDueDate({ vence_en_dias: 0 }), null);
});
