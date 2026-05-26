import { anthropic } from './claude.js';
import { DIRECTORA } from './agents.js';
import { toolDefinitions, runTool } from './tools.js';
import { buildWikiContext } from './memory.js';

// Corre a La Directora sobre un historial de conversación.
// Maneja el "loop de herramientas": si Claude pide usar una
// herramienta, la ejecutamos, le devolvemos el resultado, y
// seguimos hasta que tenga una respuesta final para Isabel.
//
// Recibe y devuelve el array de mensajes para que index.js lo guarde.
export async function runDirectora(messages) {
  const wiki = buildWikiContext();
  const system = [
    {
      type: 'text',
      text: DIRECTORA.system,
      cache_control: { type: 'ephemeral' }, // el prompt grande no cambia → se cachea
    },
  ];
  if (wiki) {
    system.push({ type: 'text', text: `MEMORIA ACTUAL DE ISABEL:\n${wiki}` });
  }

  // Loop: como máximo 6 vueltas de herramientas antes de cortar.
  for (let i = 0; i < 6; i++) {
    const res = await anthropic.messages.create({
      model: DIRECTORA.model,
      max_tokens: 1500,
      thinking: { type: 'adaptive' },
      output_config: { effort: 'medium' },
      system,
      tools: toolDefinitions,
      messages,
    });

    // Guardamos la respuesta completa (incluye bloques de tool_use).
    messages.push({ role: 'assistant', content: res.content });

    if (res.stop_reason !== 'tool_use') {
      const text = res.content
        .filter((b) => b.type === 'text')
        .map((b) => b.text)
        .join('\n')
        .trim();
      return { reply: text || 'Lista, Isabel.', messages };
    }

    // Ejecutamos cada herramienta que pidió y devolvemos los resultados.
    const toolUses = res.content.filter((b) => b.type === 'tool_use');
    const results = [];
    for (const tu of toolUses) {
      let content;
      try {
        content = await runTool(tu.name, tu.input);
      } catch (err) {
        content = `Error al ejecutar ${tu.name}: ${err.message}`;
      }
      results.push({ type: 'tool_result', tool_use_id: tu.id, content });
    }
    messages.push({ role: 'user', content: results });
  }

  return {
    reply: 'Estoy procesando varias cosas a la vez — dame un momento y pregúntame de nuevo, Isabel.',
    messages,
  };
}
