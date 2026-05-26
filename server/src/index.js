import 'dotenv/config';
import express from 'express';
import cron from 'node-cron';
import twilio from 'twilio';
import { runDirectora } from './directora.js';
import { sendMessage } from './whatsapp.js';
import { getHistory, saveHistory } from './memory.js';
import { sendMorningBriefing } from './briefing.js';

const app = express();
app.use(express.urlencoded({ extended: false }));
app.use(express.json());

app.get('/', (_req, res) => res.send('Todo Isabel — La Directora está despierta. 👑'));
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

  // Respondemos 200 de inmediato para que Twilio no reintente,
  // y procesamos la respuesta en segundo plano.
  res.set('Content-Type', 'text/xml');
  res.send('<Response></Response>');

  if (!text || !from) return;

  try {
    const messages = getHistory();
    messages.push({ role: 'user', content: text });
    const { reply, messages: updated } = await runDirectora(messages);
    saveHistory(updated);
    await sendMessage(from, reply);
  } catch (err) {
    console.error('[whatsapp] Error procesando mensaje:', err);
    await sendMessage(from, 'Tuve un problema técnico, Isabel. Intenta de nuevo en un momento. 🙏').catch(() => {});
  }
});

// ---- Briefing de la mañana (cron) ----
// node-cron corre dentro del servidor mientras esté vivo. Como el
// servidor en la nube NO se duerme, esto sí dispara aunque Isabel
// tenga todo cerrado.
const cronExpr = process.env.MORNING_BRIEFING_CRON || '30 6 * * *';
const tz = process.env.TIMEZONE || 'America/Los_Angeles';
if (cron.validate(cronExpr)) {
  cron.schedule(
    cronExpr,
    () => {
      sendMorningBriefing().catch((e) => console.error('[briefing] error:', e));
    },
    { timezone: tz }
  );
  console.log(`[cron] Briefing de la mañana programado: "${cronExpr}" (${tz})`);
} else {
  console.warn(`[cron] Expresión inválida: ${cronExpr} — briefing desactivado.`);
}

const port = process.env.PORT || 3000;
app.listen(port, () => {
  console.log(`👑 La Directora escuchando en el puerto ${port}`);
});
