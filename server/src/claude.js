import Anthropic from '@anthropic-ai/sdk';

export const anthropic = new Anthropic({
  apiKey: process.env.ANTHROPIC_API_KEY,
});

// Llamada a un coach especialista. Sin herramientas, sin historial —
// solo "experta, contesta esto". Usa el `model` configurado por coach
// (Opus/Sonnet/Haiku) y acepta opciones de formato/presupuesto.
export async function askSpecialist(specialist, question, wikiContext = '', opts = {}) {
  const constraints = [];
  if (opts.formato) constraints.push(`Formato pedido: ${opts.formato}.`);
  if (opts.presupuesto) constraints.push(`Máximo ${opts.presupuesto} palabras.`);
  constraints.push('Termina con UNA acción concreta para Isabel.');

  const res = await anthropic.messages.create({
    model: specialist.model || 'claude-sonnet-4-6',
    max_tokens: 700,
    system: [
      {
        type: 'text',
        text: specialist.system + (wikiContext ? `\n\nMEMORIA DE ISABEL:\n${wikiContext}` : ''),
        cache_control: { type: 'ephemeral' },
      },
    ],
    messages: [
      {
        role: 'user',
        content: `${question}\n\n${constraints.join(' ')}`,
      },
    ],
  });
  return res.content
    .filter((b) => b.type === 'text')
    .map((b) => b.text)
    .join('\n')
    .trim();
}
