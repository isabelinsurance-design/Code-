import 'dotenv/config';
import express from 'express';
import cron from 'node-cron';
import twilio from 'twilio';
import { runDirectora } from './directora.js';
import { sendMessage } from './whatsapp.js';
import { getHistory, saveHistory } from './memory.js';
import { sendMorningBriefing } from './briefing.js';
import { sendEveningCheckin, sendWeeklyReview, nightlyReflection } from './proactive.js';

const app = express();
app.use(express.urlencoded({ extended: false }));
app.use(express.json());

app.get('/', (_req, res) => res.send('Todo Isabel — Athena está despierta. 👑'));
app.get('/health', (_req, res) => res.json({ ok: true, time: new Date().toISOString() }));

// ---- Webhook de WhatsApp entrante (Twilio le pega aquí) ----
app.post('/whatsapp', async (req, res) => {
  // Verificación opcional de que el mensaje viene de Twilio de verdad.
  if (process.env.VERIFY_TWILIO_SIGNATURE === 'true') {
    const signature = req.headers['x-twilio-signature'];
    const url = `${process.env.PUBLIC_URL}/whatsapp`;
    const valid = twilio.validateRequest(
      process.env.TWILIO_AUTH_TOKEN,
      signature,
      url,
      req.body
    );
    if (!valid) {
      console.warn('[whatsapp] Firma de Twilio inválida — rechazado.');
      return res.status(403).send('Forbidden');
    }
  }

  const from = req.body.From; // ej. whatsapp:+1...
  const text = (req.body.Body || '').trim();
  const numMedia = parseInt(req.body.NumMedia || '0', 10);

  // Respondemos 200 de inmediato para que Twilio no reintente,
  // y procesamos la respuesta en segundo plano.
  res.set('Content-Type', 'text/xml');
  res.send('<Response></Response>');

  if (!from) return;
  if (!text && !numMedia) return;

  try {
    const messages = getHistory();
    const userContent = await buildUserContent(text, numMedia, req.body);
    messages.push({ role: 'user', content: userContent });
    const { reply, messages: updated } = await runDirectora(messages);
    saveHistory(updated);
    await sendMessage(from, reply);
  } catch (err) {
    console.error('[whatsapp] Error procesando mensaje:', err);
    await sendMessage(from, 'Tuve un problema técnico, Isabel. Intenta de nuevo en un momento.').catch(() => {});
  }
});

// Convierte un mensaje entrante de WhatsApp (texto + 0..N imágenes adjuntas)
// en el formato de content que Anthropic espera. Si solo hay texto, devuelve
// un string para no cambiar la forma de los mensajes viejos en el historial.
async function buildUserContent(text, numMedia, body) {
  if (!numMedia) return text;
  const parts = [];
  for (let i = 0; i < numMedia; i++) {
    const url = body[`MediaUrl${i}`];
    const ctype = body[`MediaContentType${i}`] || '';
    if (!url) continue;
    if (!ctype.startsWith('image/')) {
      // Por ahora solo imágenes — audio/video los manejamos en una iteración futura.
      parts.push({ type: 'text', text: `[Isabel adjuntó un archivo ${ctype} que todavía no puedo procesar.]` });
      continue;
    }
    const img = await fetchTwilioMedia(url);
    parts.push({
      type: 'image',
      source: { type: 'base64', media_type: ctype, data: img },
    });
  }
  parts.push({ type: 'text', text: text || '(Isabel mandó imagen sin texto — describe lo que ves y reacciona.)' });
  return parts;
}

// Baja el archivo desde Twilio (requiere autenticación básica con las creds).
async function fetchTwilioMedia(url) {
  const sid = process.env.TWILIO_ACCOUNT_SID;
  const token = process.env.TWILIO_AUTH_TOKEN;
  const auth = sid && token ? 'Basic ' + Buffer.from(`${sid}:${token}`).toString('base64') : undefined;
  const r = await fetch(url, { headers: auth ? { Authorization: auth } : {}, redirect: 'follow' });
  if (!r.ok) throw new Error(`Twilio media fetch falló: ${r.status}`);
  const buf = Buffer.from(await r.arrayBuffer());
  return buf.toString('base64');
}

// ---- Cron jobs proactivos ----
// node-cron corre dentro del servidor mientras esté vivo. Como el
// servidor en la nube NO se duerme, esto sí dispara aunque Isabel
// tenga todo cerrado.
const tz = process.env.TIMEZONE || 'America/Los_Angeles';

function scheduleCron(label, expr, fn) {
  if (!cron.validate(expr)) {
    console.warn(`[cron] ${label}: expresión inválida "${expr}" — desactivado.`);
    return;
  }
  cron.schedule(expr, () => fn().catch((e) => console.error(`[${label}] error:`, e)), { timezone: tz });
  console.log(`[cron] ${label} programado: "${expr}" (${tz})`);
}

scheduleCron('briefing', process.env.MORNING_BRIEFING_CRON || '30 6 * * *', sendMorningBriefing);
scheduleCron('evening', process.env.EVENING_CHECKIN_CRON || '0 21 * * *', sendEveningCheckin);
scheduleCron('weekly',  process.env.WEEKLY_REVIEW_CRON   || '0 18 * * 0', sendWeeklyReview);
scheduleCron('reflect', process.env.NIGHTLY_REFLECT_CRON || '0 2 * * *',  nightlyReflection);

const port = process.env.PORT || 3000;
app.listen(port, () => {
  console.log(`👑 Athena escuchando en el puerto ${port}`);
});
