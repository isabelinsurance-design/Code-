// Regresión del bug que rompía la búsqueda web (el del viaje de Alan):
// los bloques de server tools (web_search) no deben quedar en el historial.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { stripEphemeralBlocks as stripServerToolBlocks } from '../src/memory.js';

test('quita server_tool_use y web_search_tool_result, deja el texto', () => {
  const msgs = [
    { role: 'user', content: [{ type: 'text', text: 'busca un vuelo' }] },
    { role: 'assistant', content: [
      { type: 'server_tool_use', id: 'srvtoolu_1', name: 'web_search' },
      { type: 'web_search_tool_result', tool_use_id: 'srvtoolu_1', content: [] },
      { type: 'text', text: 'Encontré estos vuelos...' },
    ] },
  ];
  const out = stripServerToolBlocks(msgs);
  assert.equal(out.length, 2);
  assert.deepEqual(out[1].content, [{ type: 'text', text: 'Encontré estos vuelos...' }]);
});

test('mensaje que SOLO tenía server blocks se descarta (no queda vacío)', () => {
  const msgs = [
    { role: 'assistant', content: [{ type: 'server_tool_use', id: 's1', name: 'web_search' }] },
    { role: 'user', content: [{ type: 'text', text: 'hola' }] },
  ];
  const out = stripServerToolBlocks(msgs);
  assert.equal(out.length, 1);
  assert.equal(out[0].role, 'user');
});

test('NO toca tool_use/tool_result de client tools', () => {
  const msgs = [
    { role: 'assistant', content: [{ type: 'tool_use', id: 't1', name: 'mis_tareas', input: {} }] },
    { role: 'user', content: [{ type: 'tool_result', tool_use_id: 't1', content: 'ok' }] },
  ];
  const out = stripServerToolBlocks(msgs);
  assert.equal(out.length, 2);
  assert.equal(out[0].content[0].type, 'tool_use');
  assert.equal(out[1].content[0].type, 'tool_result');
});

test('también quita code_execution_tool_result (otros server tools)', () => {
  const out = stripServerToolBlocks([
    { role: 'assistant', content: [{ type: 'code_execution_tool_result', tool_use_id: 'x' }, { type: 'text', text: 'hi' }] },
  ]);
  assert.deepEqual(out[0].content, [{ type: 'text', text: 'hi' }]);
});

test('texto plano y strings no truenan', () => {
  const msgs = [{ role: 'user', content: 'string plano' }];
  assert.deepEqual(stripServerToolBlocks(msgs), msgs);
});

test('quita bloques thinking y redacted_thinking del historial', () => {
  const msgs = [
    { role: 'assistant', content: [
      { type: 'thinking', thinking: 'déjame pensar...', signature: 'abc' },
      { type: 'text', text: 'Mi respuesta.' },
    ] },
    { role: 'assistant', content: [
      { type: 'redacted_thinking', data: 'xxx' },
      { type: 'text', text: 'Otra.' },
    ] },
  ];
  const out = stripServerToolBlocks(msgs);
  assert.deepEqual(out[0].content, [{ type: 'text', text: 'Mi respuesta.' }]);
  assert.deepEqual(out[1].content, [{ type: 'text', text: 'Otra.' }]);
});

test('assistant con SOLO thinking se descarta', () => {
  const out = stripServerToolBlocks([
    { role: 'assistant', content: [{ type: 'thinking', thinking: 'x', signature: 's' }] },
    { role: 'user', content: [{ type: 'text', text: 'hola' }] },
  ]);
  assert.equal(out.length, 1);
  assert.equal(out[0].role, 'user');
});
