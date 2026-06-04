// ───────────────────────────────────────────────────────────────
//  BACKEND SEGURO — Proxy hacia la API de Anthropic (Claude)
//  Esta función se ejecuta en el SERVIDOR de Vercel, no en el navegador.
//  Tu API Key vive aquí como secreto (process.env.ANTHROPIC_API_KEY)
//  y NUNCA se envía al navegador del usuario.
//
//  El frontend llama a  /api/claude  (sin la key) y esta función
//  le añade la key y reenvía la petición a Anthropic.
// ───────────────────────────────────────────────────────────────

export default async function handler(req, res) {
  // Solo aceptamos POST
  if (req.method !== 'POST') {
    return res.status(405).json({ error: 'Método no permitido. Usa POST.' });
  }

  const apiKey = process.env.ANTHROPIC_API_KEY;
  if (!apiKey) {
    return res.status(500).json({
      error: 'Falta la variable ANTHROPIC_API_KEY en el servidor. ' +
             'Configúrala en Vercel → Settings → Environment Variables.'
    });
  }

  try {
    const upstream = await fetch('https://api.anthropic.com/v1/messages', {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        'x-api-key': apiKey,
        'anthropic-version': '2023-06-01',
      },
      // Reenviamos tal cual el cuerpo que mandó el frontend
      body: JSON.stringify(req.body),
    });

    const data = await upstream.json();
    return res.status(upstream.status).json(data);
  } catch (err) {
    return res.status(500).json({
      error: 'No se pudo contactar a Anthropic.',
      detail: String(err),
    });
  }
}
