// Parser de vuelos (Amadeus) — lógica pura, sin llamar a la red.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { parseFlightOffers, formatFlights, prettyDuration } from '../src/travel.js';

const MOCK = {
  data: [
    { // con escala, más caro
      price: { grandTotal: '450.30', currency: 'USD' },
      itineraries: [{
        duration: 'PT8H30M',
        segments: [
          { departure: { iataCode: 'LAX', at: '2026-06-30T08:00:00' }, arrival: { iataCode: 'IAH', at: '2026-06-30T13:30:00' }, carrierCode: 'UA' },
          { departure: { iataCode: 'IAH', at: '2026-06-30T15:00:00' }, arrival: { iataCode: 'XPL', at: '2026-06-30T17:30:00' }, carrierCode: 'UA' },
        ],
      }],
    },
    { // directo, más barato
      price: { grandTotal: '320.00', currency: 'USD' },
      itineraries: [{
        duration: 'PT12H',
        segments: [
          { departure: { iataCode: 'LAX', at: '2026-06-30T06:00:00' }, arrival: { iataCode: 'XPL', at: '2026-06-30T18:00:00' }, carrierCode: 'AA' },
        ],
      }],
    },
  ],
  dictionaries: { carriers: { UA: 'UNITED AIRLINES', AA: 'AMERICAN AIRLINES' } },
};

test('prettyDuration: ISO 8601 → legible', () => {
  assert.equal(prettyDuration('PT8H30M'), '8h 30m');
  assert.equal(prettyDuration('PT12H'), '12h');
  assert.equal(prettyDuration('PT45M'), '45m');
  assert.equal(prettyDuration(''), '');
});

test('parseFlightOffers ordena del más barato al más caro', () => {
  const f = parseFlightOffers(MOCK);
  assert.equal(f.length, 2);
  assert.equal(f[0].price, 320);
  assert.equal(f[1].price, 450.3);
});

test('detecta escalas y mapea aerolíneas a nombre', () => {
  const f = parseFlightOffers(MOCK);
  assert.equal(f[0].legs[0].stops, 0, 'el barato es directo');
  assert.deepEqual(f[0].legs[0].airlines, ['AMERICAN AIRLINES']);
  assert.equal(f[1].legs[0].stops, 1, 'el caro tiene 1 escala');
  assert.deepEqual(f[1].legs[0].airlines, ['UNITED AIRLINES']);
});

test('respuesta vacía → lista vacía', () => {
  assert.deepEqual(parseFlightOffers({}), []);
  assert.deepEqual(parseFlightOffers({ data: [] }), []);
});

test('formatFlights muestra precios y avisa si son datos de prueba', () => {
  const out = formatFlights(parseFlightOffers(MOCK), { origin: 'LAX', destination: 'XPL', date: '2026-06-30', host: 'test.api.amadeus.com' });
  assert.match(out, /\$320 USD/);
  assert.match(out, /directo/);
  assert.match(out, /datos de PRUEBA/i);
});

test('formatFlights sin vuelos → mensaje claro', () => {
  assert.match(formatFlights([], { origin: 'LAX', destination: 'XPL', date: '2026-06-30' }), /No encontré vuelos/);
});
