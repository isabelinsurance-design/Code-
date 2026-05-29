import { anthropic } from './claude.js';
import { DIRECTORA } from './agents.js';
import { toolDefinitions, runTool } from './tools.js';
import { buildWikiContext } from './memory.js';

// Corre a Athena sobre un historial de conversación.
// Maneja el "loop de herramientas": si Claude pide usar una
// herramienta, la ejecutamos, le devolvemos el resultado, y
// seguimos hasta que tenga una respuesta final para Isabel.
//
// Recibe y devuelve el array de mensajes para que index.js lo guarde.
// opts.maxRounds: máximo de vueltas de tool_use (default 6).
// opts.persistHistory: si false, el caller NO debería guardar el
// resultado a disco (lo usamos en task ticks y reflexión interna).
export async function runDirectora(messages, opts = {}) {
  const maxRounds = opts.maxRounds || 6;
  const wiki = buildWikiContext();
  const system = [
    {
      type: 'text',
      text: DIRECTORA.system,
      // TTL de 1h: el system prompt (filosofía + reglas + voz) NO cambia
      // durante el día. Anthropic dropeó el default a 5min en feb 2026
      // y eso encarece nuestro tráfico ~30-60% sin esto. La escritura
      // del cache 1h cuesta 2x, pero la lectura sigue a 0.1x → con
      // ≥3 turnos/hora ya estamos al positivo. Isabel pasa de eso fácil.
      cache_control: { type: 'ephemeral', ttl: '1h' },
    },
  ];
  if (wiki) {
    // La memoria cambia más seguido (cualquier recordar/tarea/nota la muta).
    // La cacheamos a TTL corto (default 5min) para no pagar escritura cara
    // por bursts de turnos seguidos.
    system.push({
      type: 'text',
      text: `MEMORIA ACTUAL DE ISABEL:\n${wiki}`,
      cache_control: { type: 'ephemeral' },
    });
  }

  // Loop: como máximo `maxRounds` vueltas de herramientas antes de cortar.
  for (let i = 0; i < maxRounds; i++) {
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

    // Ejecutamos en PARALELO las herramientas que pidió en este turno.
    // (Promise.all aprovecha que el modelo a veces emite varios tool_use
    // independientes a la vez — ej. revisar email + consultar coaches.)
    // Las built-in tools de Anthropic (ej. web_search) se ejecutan del lado
    // del servidor y NO aparecen aquí, así que las saltamos automáticamente.
    const toolUses = res.content.filter((b) => b.type === 'tool_use');
    const results = await Promise.all(
      toolUses.map(async (tu) => {
        let content;
        try {
          content = await runTool(tu.name, tu.input);
        } catch (err) {
          content = `Error al ejecutar ${tu.name}: ${err.message}`;
        }
        return { type: 'tool_result', tool_use_id: tu.id, content };
      })
    );
    messages.push({ role: 'user', content: results });
  }

  return {
    reply: 'Estoy procesando varias cosas a la vez — dame un momento y pregúntame de nuevo, Isabel.',
    messages,
  };
}
