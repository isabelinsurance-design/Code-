// RED DE SEGURIDAD del refactor de tools.js: fotografía la superficie de
// herramientas de Athena. Si un refactor pierde, duplica o malforma una sola
// tool, esto se pone rojo. (Importa dinámico con env dummy para no romper en
// CI donde no hay llaves — solo lee definiciones, no llama a nadie.)
import { test } from 'node:test';
import assert from 'node:assert/strict';

async function loadTools() {
  process.env.ANTHROPIC_API_KEY ||= 'test-key';
  process.env.OPENAI_API_KEY ||= 'test-key';
  return import('../src/tools.js');
}

test('superficie: exactamente 142 tools, nombres únicos', async () => {
  const { toolDefinitions } = await loadTools();
  assert.equal(toolDefinitions.length, 142, 'el número de tools no debe cambiar con el refactor');
  const names = toolDefinitions.map((t) => t.name);
  assert.equal(new Set(names).size, names.length, 'nombres deben ser únicos');
  for (const t of toolDefinitions) assert.ok(typeof t.name === 'string' && t.name.length, 'cada tool con name');
});

test('tools normales: description + input_schema; server-tools (web_search): type', async () => {
  const { toolDefinitions } = await loadTools();
  for (const t of toolDefinitions) {
    if (t.type) continue; // server tool de Anthropic (web_search) — forma válida distinta
    assert.ok(t.description, `${t.name} sin description`);
    assert.ok(t.input_schema && typeof t.input_schema === 'object', `${t.name} sin input_schema`);
  }
});

test('runTool y getDynamicToolDefinitions siguen siendo funciones', async () => {
  const m = await loadTools();
  assert.equal(typeof m.runTool, 'function');
  assert.equal(typeof m.getDynamicToolDefinitions, 'function');
});
