// Cliente Anthropic (sin dependencias — usa fetch nativo de Node 22).
// La API key vive en el servidor (config.js), nunca en el navegador.

import { ANTHROPIC_API_KEY, ANTHROPIC_VERSION, ANTHROPIC_BASE_URL, MODELS, MAX_TOKENS } from './config.js';

// tools: arreglo de tools de Anthropic (ej. web_search). Si webSearch=true se
// adjunta la tool nativa web_search (patron #18: buscar antes de inventar).
export async function complete({ system, messages, model = MODELS.specialist, maxTokens = MAX_TOKENS, tools = null, webSearch = false }) {
  if (!ANTHROPIC_API_KEY) {
    const err = new Error('Falta ANTHROPIC_API_KEY en el entorno del servidor.');
    err.code = 'NO_API_KEY';
    throw err;
  }

  const allTools = [...(tools || [])];
  if (webSearch) allTools.push({ type: 'web_search_20250305', name: 'web_search', max_uses: 5 });

  const payload = { model, max_tokens: maxTokens, system, messages };
  if (allTools.length) payload.tools = allTools;

  const r = await fetch(`${ANTHROPIC_BASE_URL}/v1/messages`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'x-api-key': ANTHROPIC_API_KEY,
      'anthropic-version': ANTHROPIC_VERSION,
    },
    body: JSON.stringify(payload),
  });

  if (!r.ok) {
    const body = await r.text().catch(() => '');
    const err = new Error(`Anthropic ${r.status}: ${body.slice(0, 300)}`);
    err.status = r.status;
    throw err;
  }

  const data = await r.json();
  const content = data.content || [];
  const text = content.map((b) => b.text || '').join('');
  // Devolvemos content completo para que la UI extraiga citas / resultados de busqueda.
  return { text, content, usage: data.usage || null, model: data.model || model };
}
