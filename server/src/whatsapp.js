import twilio from 'twilio';

const sid = process.env.TWILIO_ACCOUNT_SID;
const token = process.env.TWILIO_AUTH_TOKEN;

export const twilioClient = sid && token ? twilio(sid, token) : null;

// Manda un mensaje por WhatsApp (o SMS). Si "to" no trae prefijo
// whatsapp:, se manda como SMS normal.
//
// opts.mediaUrl: si está presente (string o array), se manda como
// MMS / media de WhatsApp. SMS solo soporta media en US/Canadá.
export async function sendMessage(to, body, opts = {}) {
  if (!twilioClient) {
    console.warn('[whatsapp] Twilio no configurado — mensaje no enviado:', body);
    return null;
  }
  const from = to.startsWith('whatsapp:')
    ? process.env.TWILIO_WHATSAPP_FROM
    : (process.env.TWILIO_SMS_FROM || process.env.TWILIO_WHATSAPP_FROM?.replace('whatsapp:', ''));

  // Si hay media (audio/imagen), lo mandamos en UN solo mensaje con el cuerpo
  // como caption opcional. WhatsApp/Twilio admite hasta 10 mediaUrl.
  if (opts.mediaUrl) {
    const mediaUrl = Array.isArray(opts.mediaUrl) ? opts.mediaUrl : [opts.mediaUrl];
    return twilioClient.messages.create({
      from,
      to,
      body: body || undefined,
      mediaUrl,
    });
  }

  // WhatsApp corta mensajes largos; los partimos en ~1500 chars.
  const chunks = splitMessage(body, 1500);
  let last = null;
  for (const chunk of chunks) {
    last = await twilioClient.messages.create({ from, to, body: chunk });
  }
  return last;
}

function splitMessage(text, max) {
  if (text.length <= max) return [text];
  const out = [];
  let rest = text;
  while (rest.length > max) {
    let cut = rest.lastIndexOf('\n', max);
    if (cut < max * 0.5) cut = rest.lastIndexOf(' ', max);
    if (cut < max * 0.5) cut = max;
    out.push(rest.slice(0, cut));
    rest = rest.slice(cut).trimStart();
  }
  if (rest) out.push(rest);
  return out;
}
