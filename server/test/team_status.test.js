// Modo "de licencia": pausa temporal de un miembro, con auto-reactivación.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { isOnLeave, leaveUntil, reassignIfOnLeave } from '../src/team_status.js';

const ENV = { SAMI_ON_LEAVE_UNTIL: '2026-07-13' };

test('de licencia ANTES de la fecha → true', () => {
  assert.equal(isOnLeave('Sami', ENV, new Date('2026-06-20')), true);
  assert.equal(isOnLeave('sami', ENV, new Date('2026-06-20')), true); // case-insensitive
});

test('se reactiva SOLA al pasar la fecha → false', () => {
  assert.equal(isOnLeave('Sami', ENV, new Date('2026-07-14')), false);
});

test('sin variable → no está de licencia', () => {
  assert.equal(isOnLeave('Skarleth', ENV, new Date('2026-06-20')), false);
});

test('fecha mal escrita → no se trata como licencia (no rompe)', () => {
  assert.equal(isOnLeave('Sami', { SAMI_ON_LEAVE_UNTIL: 'cuando sea' }, new Date('2026-06-20')), false);
});

test('leaveUntil devuelve la fecha o null', () => {
  assert.equal(leaveUntil('Sami', ENV), '2026-07-13');
  assert.equal(leaveUntil('Arlette', ENV), null);
});

test('reassignIfOnLeave: tarea para Sami de licencia → rebota a Isabel con nota', () => {
  const r = reassignIfOnLeave('sami', ENV, new Date('2026-06-20'));
  assert.equal(r.responsable, 'isabel');
  assert.equal(r.reasignado_de, 'sami');
  assert.match(r.note, /Sami.*licencia/i);
});

test('reassignIfOnLeave: Sami ya regresó → tarea se queda con Sami', () => {
  const r = reassignIfOnLeave('sami', ENV, new Date('2026-07-20'));
  assert.equal(r.responsable, 'sami');
  assert.equal(r.reasignado_de, null);
});

test('reassignIfOnLeave: tareas de otros no se tocan', () => {
  const r = reassignIfOnLeave('athena', ENV, new Date('2026-06-20'));
  assert.equal(r.responsable, 'athena');
  assert.equal(r.note, null);
});
