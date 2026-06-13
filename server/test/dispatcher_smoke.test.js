// Red sobre el DISPATCHER de tools.js: ejecuta de verdad las herramientas de
// solo-lectura de Athena (no mandan nada, no llaman red — leen memoria local).
// Si un refactor del dispatcher rompe alguna, esto truena. Es la red que falta
// para poder partir el dispatcher con seguridad más adelante.
import { test } from 'node:test';
import assert from 'node:assert/strict';

async function loadRunTool() {
  process.env.ANTHROPIC_API_KEY ||= 'test-key';
  process.env.OPENAI_API_KEY ||= 'test-key';
  const { runTool } = await import('../src/tools.js');
  return runTool;
}

// Tools de solo-lectura: seguras de ejecutar (leen JSON local, sin red).
const READONLY = [
  ['que_recuerdas', {}],
  ['historial', {}],
  ['mis_tareas', {}],
  ['mis_compromisos', {}],
  ['skills_lista', {}],
  ['entidad_buscar', { query: 'zzz-no-existe' }],
];

for (const [name, input] of READONLY) {
  test(`runTool('${name}') no truena y devuelve texto`, async () => {
    const runTool = await loadRunTool();
    const r = await runTool(name, input);
    assert.equal(typeof r, 'string', `${name} debe devolver string`);
    assert.ok(r.length > 0, `${name} no debe devolver vacío`);
  });
}
