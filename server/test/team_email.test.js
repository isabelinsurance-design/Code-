// Pruebas de la lógica pura del email de equipo — el código que ya falló una
// vez (jalaba "tickets" inexistentes y salía vacío). Guarda: mapeo de agente,
// filtro por persona, y el resumen del día con conteo + URGENTE.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { agentIdOf, forPerson, daySummary, nombreCliente, horaCita } from '../src/team_morning_email.js';

test('agentIdOf: lee asignado_a como número, o null si no hay', () => {
  assert.equal(agentIdOf({ asignado_a: '7' }), 7);
  assert.equal(agentIdOf({ agente_id: 9 }), 9);
  assert.equal(agentIdOf({ nombre: 'x' }), null);
  assert.equal(agentIdOf({ asignado_a: '' }), null);
});

test('forPerson: agentMode=false → resumen del equipo (todo); true → solo lo suyo', () => {
  const list = [{ asignado_a: 7 }, { asignado_a: 9 }, { asignado_a: 7 }];
  assert.equal(forPerson(list, 7, false).length, 3, 'sin agente, todos ven todo');
  assert.equal(forPerson(list, 7, true).length, 2, 'con agente, solo los del id 7');
  assert.deepEqual(forPerson(null, 7, true), []);
});

test('daySummary: sin nada → mensaje de día tranquilo', () => {
  const s = daySummary({ citas: [], leads: [], soas: [], nombre: 'Sami' });
  assert.match(s, /nada agendado|tranquilo/i);
});

test('daySummary: cuenta arriba y marca URGENTE (lead frío 3+ días + SOA)', () => {
  const s = daySummary({
    citas: [{ fecha_hora: '2026-06-13T15:00:00', miembro_nombre: 'Maritza', tipo: 'RENOVACION' }],
    leads: [{ nombre: 'Bobby', dias_sin_contacto: 5 }],
    soas: [{ miembro_nombre: 'Doña Eva' }],
    nombre: 'Skarleth',
  });
  assert.match(s, /1 cita/);
  assert.match(s, /1 seguimiento/);
  assert.match(s, /URGENTE/);
  assert.match(s, /Bobby/);          // lead frío listado
  assert.match(s, /15:00/);          // hora de la cita formateada
  assert.match(s, /Maritza/);
});

test('daySummary: lead reciente (0 días) NO va en URGENTE', () => {
  const s = daySummary({
    citas: [], soas: [],
    leads: [{ nombre: 'Nuevo', dias_sin_contacto: 0 }],
    nombre: 'Arlette',
  });
  assert.doesNotMatch(s, /URGENTE/);
  assert.match(s, /Nuevo/); // sí aparece en SEGUIMIENTOS
});

test('nombreCliente y horaCita: formatos varios', () => {
  assert.equal(nombreCliente({ miembro_nombre: 'Ana Ruiz' }), 'Ana Ruiz');
  assert.equal(nombreCliente({ nombre: 'Ana', apellido: 'Ruiz' }), 'Ana Ruiz');
  assert.equal(horaCita({ fecha_hora: '2026-06-13T09:30:00' }), '09:30');
  assert.equal(horaCita({ hora: '14:15' }), '14:15');
  assert.equal(horaCita({}), '');
});
