// Resolución de personas — el corazón de "¿quién es Alan?". Verifica que
// Athena encuentre a alguien por nombre, apodo, con o sin acento. Pura, sin
// tocar datos reales.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { matchEntities } from '../src/entities.js';

const ROWS = [
  { id: 'e1', canonical_name: 'Alan García', aliases: ['Alancito'] },
  { id: 'e2', canonical_name: 'Inés', aliases: [] },
  { id: 'e3', canonical_name: 'Samia', aliases: ['Sami'] },
  { id: 'e4', canonical_name: 'Maritza López', aliases: ['la del SCAN'] },
];

const ids = (r) => r.map((e) => e.id);

test('match por nombre exacto', () => {
  assert.deepEqual(ids(matchEntities(ROWS, 'Alan García')), ['e1']);
});

test('match parcial — solo el primer nombre', () => {
  assert.deepEqual(ids(matchEntities(ROWS, 'Alan')), ['e1']);
});

test('ignora acentos y mayúsculas — "ines" encuentra "Inés"', () => {
  assert.deepEqual(ids(matchEntities(ROWS, 'ines')), ['e2']);
  assert.deepEqual(ids(matchEntities(ROWS, 'INÉS')), ['e2']);
});

test('match por alias — "Sami" encuentra a Samia', () => {
  assert.deepEqual(ids(matchEntities(ROWS, 'Sami')), ['e3']);
});

test('alias con frase — "del SCAN" encuentra a Maritza', () => {
  assert.deepEqual(ids(matchEntities(ROWS, 'del SCAN')), ['e4']);
});

test('búsqueda vacía → sin resultados (no devuelve todo)', () => {
  assert.deepEqual(matchEntities(ROWS, ''), []);
  assert.deepEqual(matchEntities(ROWS, '   '), []);
});

test('sin coincidencia → vacío', () => {
  assert.deepEqual(matchEntities(ROWS, 'Bobby'), []);
});

test('rows nulo no truena', () => {
  assert.deepEqual(matchEntities(null, 'Alan'), []);
});
