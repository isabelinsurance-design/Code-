import 'dotenv/config';
import express from 'express';
import cron from 'node-cron';
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
import { synthToPublicUrl, ttsConfigured, cleanupOldAudio, AUDIO_DIR } from './tts.js';
import {
  twilioSignatureMiddleware,
  checkAndMarkSid,
  pruneSeenSids,
  rateLimitMiddleware,
  pruneRateLimit,
} from './security.js';
import { snapshot as backupSnapshot } from './backup.js';
import { startInboxIdle, inboxIdleEnabled } from './inbox_idle.js';
import { buildIncomingTwiml, handleVoiceStatus, attachVoiceRelay } from './voice.js';
import { runSlash } from './slash.js';
import { dashboardEnabled, dashboardAuth, renderDashboardHtml, buildDashboardState } from './dashboard.js';

const app = express();
// Si estamos detrás de un proxy (Railway/Render/Fly), confiamos en
// X-Forwarded-* para que rate limit y firma vean el IP/host reales.
app.set('trust proxy', 1);
app.use(express.urlencoded({ extended: false }));
app.use(express.json());

app.get('/', (_req, res) => res.send('Todo Isabel — Athena está despierta. 👑'));
app.get('/health', (_req, res) => res.json({ ok: true, time: new Date().toISOString() }));

// Sirve los MP3 generados por TTS para que Twilio los pueda jalar.
// Carpeta efímera — los archivos se borran después de 24h.
app.use('/audio', express.static(AUDIO_DIR, { maxAge: '6h', extensions: ['mp3'] }));

// ---- Webhooks de voz (Twilio Programmable Voice + ConversationRelay) ----
// /voice/incoming devuelve TwiML que conecta la llamada a nuestro WS.
// /voice/status recibe lifecycle updates (start, ring, answer, complete,
// recording-available). El WebSocket en wss:///voice/relay se atacha
// abajo, después de crear el http.Server.
app.post('/voice/incoming', twilioSignatureMiddleware, (req, res) => {
  const twiml = buildIncomingTwiml(req);
  res.set('Content-Type', 'text/xml');
  res.send(twiml);
});
app.post('/voice/status', twilioSignatureMiddleware, handleVoiceStatus);

// ---- Dashboard (Basic Auth con DASHBOARD_PASSWORD) ----
// Si la env no está, el dashboard devuelve 404 — no se expone nada.
if (dashboardEnabled()) {
  app.get('/dashboard', dashboardAuth, (_req, res) => {
    res.set('Content-Type', 'text/html; charset=utf-8');
    res.send(renderDashboardHtml());
  });
  app.get('/dashboard/state', dashboardAuth, async (_req, res) => {
    try {
      const state = await buildDashboardState();
      res.json(state);
    } catch (err) {
      res.status(500).json({ error: err.message });
    }
  });
  console.log('[dashboard] activado en /dashboard (Basic Auth).');
} else {
  console.log('[dashboard] desactivado (DASHBOARD_PASSWORD no está).');
}

// ---- Webhook de WhatsApp entrante (Twilio le pega aquí) ----
// Middleware en orden: rate limit (barato, primero) → firma Twilio
// (rechaza requests que no vienen de Twilio) → handler.
app.post('/whatsapp', rateLimitMiddleware, twilioSignatureMiddleware, async (req, res) => {
  const from = req.body.From; // ej. whatsapp:+1...
  const text = (req.body.Body || '').trim();
  const numMedia = parseInt(req.body.NumMedia || '0', 10);
  const sid = req.body.MessageSid;

  // Respondemos 200 de inmediato para que Twilio no reintente,
  // y procesamos la respuesta en segundo plano.
  res.set('Content-Type', 'text/xml');
  res.send('<Response></Response>');

  if (!from) return;
  if (!text && !numMedia) return;

  // Idempotencia: si Twilio ya nos mandó este SID (reintento por timeout
  // o ack perdido), saltamos para no duplicar la respuesta de Athena.
  const dup = checkAndMarkSid(sid);
  if (dup.duplicate) {
    console.log(`[whatsapp] SID duplicado ${sid} — ignorado (visto hace ${Math.round((Date.now()-dup.seenAt)/1000)}s).`);
    return;
  }

  // Detecta si Isabel mandó audio — Athena le responde con voz también.
  const userSentVoice = audioMediaPresent(numMedia, req.body);

  // Slash commands se ejecutan SIN llamar a Athena (más rápido,
  // determinista, y Sami tiene su propio allowlist). Si el mensaje
  // empieza con "/" y es un comando válido, contestamos directo.
  if (text.startsWith('/')) {
    try {
      const slashResult = await runSlash(text, from);
      if (slashResult) {
        await replyTo(from, slashResult.reply, { voice: false });
        return;
      }
    } catch (err) {
      console.error('[slash] error:', err.message);
      await sendMessage(from, `Error en slash command: ${err.message}`).catch(() => {});
      return;
    }
  }

  try {
    const messages = getHistory();
    const userContent = await buildUserContent(text, numMedia, req.body);
    messages.push({ role: 'user', content: userContent });
    const { reply, messages: updated } = await runDirectora(messages);
    saveHistory(updated);
    await replyTo(from, reply, { voice: userSentVoice });
  } catch (err) {
    console.error('[whatsapp] Error procesando mensaje:', err);
    await sendMessage(from, 'Tuve un problema técnico, Isabel. Intenta de nuevo en un momento.').catch(() => {});
  }
});

function audioMediaPresent(numMedia, body) {
  for (let i = 0; i < numMedia; i++) {
    if ((body[`MediaContentType${i}`] || '').startsWith('audio/')) return true;
  }
  return false;
}

// Si Isabel mandó voz, intentamos responder con voz (sintetizamos +
// mandamos como mediaUrl). Si TTS no está configurado o falla,
// hacemos fallback a texto sin drama.
async function replyTo(to, text, { voice = false } = {}) {
  if (voice && ttsConfigured()) {
    try {
      const audioUrl = await synthToPublicUrl(text);
      if (audioUrl) {
        await sendMessage(to, '', { mediaUrl: audioUrl });
        return;
      }
    } catch (err) {
      console.warn('[whatsapp] TTS falló, fallback a texto:', err.message);
    }
  }
  await sendMessage(to, text);
}

// Convierte un mensaje entrante de WhatsApp (texto + 0..N adjuntos)
// en el formato de content que Anthropic espera.
//  - Imágenes (jpg/png/webp/gif) → image blocks base64
//  - PDFs → document blocks base64 (Claude los lee nativo desde 3.5)
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
    } else if (ctype === 'application/pdf') {
      // Claude lee PDFs nativo. Ideal para SOA firmadas, EOB, plan
      // summaries, screenshots de Plan Finder, etc.
      try {
        const pdf = await fetchTwilioMedia(url);
        parts.push({
          type: 'document',
          source: { type: 'base64', media_type: 'application/pdf', data: pdf },
        });
      } catch (err) {
        transcripts.push(`[Isabel mandó un PDF que no pude descargar: ${err.message}]`);
      }
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
  parts.push({ type: 'text', text: merged || '(Isabel mandó adjunto sin texto — describe lo que ves/oíste/leíste y reacciona.)' });
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
// Limpieza horaria de los MP3 viejos generados por TTS (>24h).
scheduleCron('audio_gc', '0 * * * *', async () => {
  const n = cleanupOldAudio(24);
  if (n) console.log(`[audio_gc] borrados ${n} MP3 viejos.`);
});
// Backup horario: snapshot completo de data/ con rotación local de
// 24 + sync opcional (configurar BACKUP_SYNC_CMD para offsite).
scheduleCron('backup', process.env.BACKUP_CRON || '15 * * * *', async () => {
  const r = await backupSnapshot();
  if (r.ok) console.log(`[backup] snapshot OK ${r.file}${r.synced ? ' (sync ✓)' : ''}`);
  else console.warn('[backup]', r.reason);
});
// Prune horario de los Maps de seguridad (idempotencia + rate limit).
scheduleCron('security_gc', '5 * * * *', async () => {
  const sids = pruneSeenSids();
  pruneRateLimit();
  if (sids) console.log(`[security_gc] borrados ${sids} SIDs vencidos.`);
});
// Pre-meeting brief: cada 5 min revisa si hay una junta en 10-20 min
// y manda el brief. Solo se activa si Google Calendar está configurado.
if (calendarConfigured()) {
  scheduleCron('cal',    process.env.CAL_TICK_CRON        || '*/5 7-21 * * *', checkUpcomingMeetingsTick);
} else {
  console.log('[cron] cal: Google Calendar no configurado — pre-meeting briefs desactivados.');
}
// Inbox cleanup sweep: cada hora mueve al Trash emails de remitentes
// que Isabel suprimió. Solo se activa si Gmail está configurado.
const { inboxCleanupEnabled, sweepSuppressed } = await import('./inbox_cleanup.js');
if (inboxCleanupEnabled()) {
  scheduleCron('inbox_sweep', '7 * * * *', async () => {
    const r = await sweepSuppressed();
    if (r.moved) console.log(`[inbox_sweep] movió ${r.moved} emails al Trash (${r.suppressed_count} senders activos).`);
  });
} else {
  console.log('[cron] inbox_sweep: Gmail no configurado — desactivado.');
}
// Saturday brief: viernes 9pm Athena compila la semana del equipo y
// se lo manda a Isabel para que sábado morning abra LUNA sabiendo
// dónde meter foco.
const { sendSaturdayBrief } = await import('./saturday_brief.js');
scheduleCron('saturday_brief', process.env.SATURDAY_BRIEF_CRON || '0 21 * * 5', sendSaturdayBrief);
// EOD nudge: 6pm chequea quién del equipo NO ha reportado y le pide
// a Sami que les recuerde. Solo días entre semana.
scheduleCron('eod_nudge', process.env.EOD_NUDGE_CRON || '0 18 * * 1-5', async () => {
  const { checkMissingReports } = await import('./team_eod.js');
  const { sendMessage } = await import('./whatsapp.js');
  const r = checkMissingReports();
  if (!r.shouldNudgeSami) return;
  const samiPhone = process.env.SAMI_WHATSAPP;
  if (!samiPhone) {
    console.log(`[eod_nudge] sin SAMI_WHATSAPP. Faltan: ${r.missing.join(', ')}`);
    return;
  }
  await sendMessage(samiPhone, `🕐 Recordatorio EOD: faltan reportes de ${r.missing.join(', ')}. Por favor recuérdales mandar /eod antes de irse.`);
  console.log(`[eod_nudge] nudge mandado a Sami sobre: ${r.missing.join(', ')}`);
});

const port = process.env.PORT || 3000;
const httpServer = app.listen(port, () => {
  console.log(`👑 Athena escuchando en el puerto ${port}`);
  // Arranca el listener de Gmail IDLE (event-driven inbox). Si las
  // credenciales no están, no hace nada. Si la conexión cae, se
  // reconecta con backoff. NO bloquea el arranque del server.
  if (inboxIdleEnabled) {
    startInboxIdle().catch((err) => console.error('[idle] arranque falló:', err.message));
  }
});
// Atacha el endpoint WebSocket /voice/relay al mismo HTTP server.
// Twilio ConversationRelay nos pega aquí con el streaming texto-a-texto
// (ya hace STT/TTS por su lado).
attachVoiceRelay(httpServer);
