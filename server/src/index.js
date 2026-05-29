import 'dotenv/config';
import express from 'express';
import cron from 'node-cron';
import twilio from 'twilio';
import { runDirectora } from './directora.js';
import { sendMessage } from './whatsapp.js';
import { getHistory, saveHistory } from './memory.js';
import { sendMorningBriefing } from './briefing.js';
import { sendEveningCheckin, sendWeeklyReview, nightlyReflection } from './proactive.js';
import { taskTick } from './tasks.js';
import { nightlyEmailTriage } from './triage.js';
import { transcribeWhatsAppAudio } from './transcribe.js';
import { checkUpcomingMeetingsTick, calendarConfigured } from './calendar.js';
import { commitmentChaseTick } from './commitments.js';

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

// Convierte un mensaje entrante de WhatsApp (texto + 0..N adjuntos)
// en el formato de content que Anthropic espera.
//  - Imágenes → image blocks base64
//  - Audio (voice notes) → transcripción Whisper, se mete como texto
//  - Otros → nota textual
async function buildUserContent(text, numMedia, body) {
  if (!numMedia) return text;
  const parts = [];
  const transcripts = [];
  for (let i = 0; i < numMedia; i++) {
    const url = body[`MediaUrl${i}`];
    const ctype = body[`MediaContentType${i}`] || '';
    if (!url) continue;
    if (ctype.startsWith('image/')) {
      const img = await fetchTwilioMedia(url);
      parts.push({
        type: 'image',
        source: { type: 'base64', media_type: ctype, data: img },
      });
    } else if (ctype.startsWith('audio/')) {
      const t = await transcribeWhatsAppAudio(url, ctype);
      if (t.ok && t.transcript) {
        transcripts.push(`[Nota de voz transcrita] ${t.transcript}`);
      } else {
        transcripts.push(t.note || '[Audio recibido, no se pudo transcribir.]');
      }
    } else {
      transcripts.push(`[Isabel adjuntó un archivo ${ctype} que todavía no puedo procesar.]`);
    }
  }
  const merged = [text, ...transcripts].filter(Boolean).join('\n\n');
  parts.push({ type: 'text', text: merged || '(Isabel mandó adjunto sin texto — describe lo que ves/oíste y reacciona.)' });
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
// Triage corre antes del briefing para que Athena tenga lista la
// clasificación + borradores en cola cuando salude a Isabel.
scheduleCron('triage',  process.env.EMAIL_TRIAGE_CRON    || '0 5 * * *',  nightlyEmailTriage);
// Task tick: cada hora entre 7am y 9pm (TZ local). taskTick adentro
// también respeta quiet hours para los recordatorios. El trabajo
// silencioso de Athena puede correr a cualquier hora pero lo
// limitamos a horas despiertas para acotar costo.
scheduleCron('tasks',   process.env.TASK_TICK_CRON       || '0 7-21 * * *', taskTick);
// Persecución de compromisos: cada 2h en horas despiertas reviso
// promesas vencidas. Si tengo cómo, le doy un nudge cordial a la
// persona; en cualquier caso le aviso a Isabel (una vez por compromiso).
scheduleCron('chase',   process.env.COMMITMENT_CHASE_CRON || '0 8-20/2 * * *', commitmentChaseTick);
// Pre-meeting brief: cada 5 min revisa si hay una junta en 10-20 min
// y manda el brief. Solo se activa si Google Calendar está configurado.
if (calendarConfigured()) {
  scheduleCron('cal',    process.env.CAL_TICK_CRON        || '*/5 7-21 * * *', checkUpcomingMeetingsTick);
} else {
  console.log('[cron] cal: Google Calendar no configurado — pre-meeting briefs desactivados.');
}

const port = process.env.PORT || 3000;
app.listen(port, () => {
  console.log(`👑 Athena escuchando en el puerto ${port}`);
});
