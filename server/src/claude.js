import Anthropic from '@anthropic-ai/sdk';

export const anthropic = new Anthropic({
  apiKey: process.env.ANTHROPIC_API_KEY,
});

// Una llamada simple a un coach especialista: un system prompt + una pregunta.
// Sin herramientas, sin historial — solo "experta, contesta esto".
export async function askSpecialist(specialist, question, wikiContext = '') {
  const res = await anthropic.messages.create({
    model: 'claude-sonnet-4-6',
    max_tokens: 700,
    system: [
      {
        type: 'text',
        text: specialist.system + (wikiContext ? `\n\nMEMORIA DE ISABEL:\n${wikiContext}` : ''),
        cache_control: { type: 'ephemeral' },
      },
    ],
    messages: [{ role: 'user', content: question }],
  });
  return res.content
    .filter((b) => b.type === 'text')
    .map((b) => b.text)
    .join('\n')
    .trim();
}
