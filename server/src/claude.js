import Anthropic from '@anthropic-ai/sdk';

export const anthropic = new Anthropic({
  apiKey: process.env.ANTHROPIC_API_KEY,
});

// Llamada a un coach especialista.
//
// Por default: single-turn, sin herramientas — "experta, contesta esto".
// Si `opts.tools` y `opts.toolDispatcher` están dados, corre un loop de
// tool_use hasta que la coach decide responder sin más herramientas.
// El loop tiene un cap defensivo (maxRounds, default 5).
//
// La razón de existir del tool loop: que Maria pueda leer/escribir LUNA
// durante una consulta sin requerir que Athena la directora tenga las
// tools expuestas a su nivel. Maria es la única "embajadora" a LUNA.
export async function askSpecialist(specialist, question, wikiContext = '', opts = {}) {
  const constraints = [];
  if (opts.formato) constraints.push(`Formato pedido: ${opts.formato}.`);
  if (opts.presupuesto) constraints.push(`Máximo ${opts.presupuesto} palabras.`);
  constraints.push('Termina con UNA acción concreta para Isabel.');

  const systemBlock = {
    type: 'text',
    text: specialist.system + (wikiContext ? `\n\nMEMORIA DE ISABEL:\n${wikiContext}` : ''),
    cache_control: { type: 'ephemeral' },
  };

  const initialMessages = [
    { role: 'user', content: `${question}\n\n${constraints.join(' ')}` },
  ];

  // Single-turn (sin tools) — comportamiento legacy
  if (!opts.tools || !opts.toolDispatcher) {
    const res = await anthropic.messages.create({
      model: specialist.model || 'claude-sonnet-4-6',
      max_tokens: 700,
      system: [systemBlock],
      messages: initialMessages,
    });
    return extractText(res);
  }

  // Tool loop — la especialista puede llamar herramientas internas
  const messages = [...initialMessages];
  const maxRounds = opts.maxRounds || 5;

  for (let round = 0; round < maxRounds; round++) {
    const res = await anthropic.messages.create({
      model: specialist.model || 'claude-sonnet-4-6',
      max_tokens: 1200,
      system: [systemBlock],
      tools: opts.tools,
      messages,
    });

    if (res.stop_reason !== 'tool_use') {
      return extractText(res);
    }

    // Acumular el turno de assistant (incluyendo tool_use blocks)
    messages.push({ role: 'assistant', content: res.content });

    // Ejecutar cada tool_use y armar el siguiente user turn con results
    const toolResults = [];
    for (const block of res.content) {
      if (block.type !== 'tool_use') continue;
      let resultText;
      try {
        resultText = await opts.toolDispatcher(block.name, block.input || {});
      } catch (err) {
        resultText = `Error ejecutando ${block.name}: ${err.message}`;
      }
      toolResults.push({
        type: 'tool_result',
        tool_use_id: block.id,
        content: String(resultText ?? '(sin resultado)'),
      });
    }
    messages.push({ role: 'user', content: toolResults });
  }

  // Llegamos al cap. Forzamos una respuesta final SIN tools.
  const final = await anthropic.messages.create({
    model: specialist.model || 'claude-sonnet-4-6',
    max_tokens: 600,
    system: [systemBlock],
    messages: [
      ...messages,
      { role: 'user', content: 'Resume tu respuesta final ahora, sin llamar más herramientas.' },
    ],
  });
  return extractText(final);
}

function extractText(res) {
  return res.content
    .filter((b) => b.type === 'text')
    .map((b) => b.text)
    .join('\n')
    .trim();
}
