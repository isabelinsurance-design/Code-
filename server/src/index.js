import 'dotenv/config';
import express from 'express';
import cron from 'node-cron';
import twilio from 'twilio';
import { runDirectora } from './directora.js';
import { sendMessage } from './whatsapp.js';
import { getHistory, saveHistory, logActivity } from './memory.js';
import { sendMorningBriefing } from './briefing.js';
import { sendEveningCheckin, sendWeeklyReview, nightlyReflection, sendResearchDigest, sendWeeklyRapport, dailyTrendScan, weeklySelfGrade } from './proactive.js';
import { sendClosingLoop } from './closing_loop.js';
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
import { registerApi } from './api.js';
import { existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname_idx = dirname(fileURLToPath(import.meta.url));

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

// ---- API REST + Web app (PWA) ----
// El API maneja auth con cookie firmada (APP_PASSWORD + APP_SECRET).
// El app React se sirve estático en /app/ después del build de Vite.
registerApi(app);
const APP_DIR = join(__dirname_idx, '..', 'public', 'app');
if (existsSync(APP_DIR)) {
  app.use('/app', express.static(APP_DIR, { index: 'index.html', maxAge: '5m' }));
  // SPA fallback: cualquier ruta bajo /app/* sin archivo → index.html
  app.get(/^\/app(\/.*)?$/, (_req, res) => {
    res.sendFile(join(APP_DIR, 'index.html'));
  });
  console.log(`[app] React app servido desde ${APP_DIR} en /app`);
} else {
  console.log('[app] /app aún no construido (corre "npm run build" en app-v2/)');
}

// ---- Webhooks de voz (Twilio Programmable Voice + ConversationRelay) ----
// /voice/incoming devuelve TwiML que conecta la llamada a nuestro WS.
// /voice/status recibe lifecycle updates (start, ring, answer, complete,
// recording-available). El WebSocket en wss:///voice/relay se atacha
// abajo, después de crear el http.Server.
//
// NOTA importante: durante diagnóstico de errores tipo "application
// error" que Twilio dice al caller, dejamos un fallback que renderea
// el TwiML AUNQUE la signature middleware falle. Sin esto, cualquier
// problema con la signature → 403 → Twilio dice "application error"
// y nunca podemos diagnosticar más.
// Voice endpoints: signature validation se evalúa pero NO bloquea —
// los logs muestran si pasó/falló, pero TwiML se renderiza siempre. Esto
// es CRÍTICO para diagnosticar "application error" que Twilio dice al
// caller cuando un endpoint devuelve 4xx/5xx. La llamada es legítima
// (Twilio la inició desde nuestro propio outbound) — el CallSid en el
// body confirma que viene de Twilio.
function voiceWebhookLogger(req, res, next) {
  // Log de la request entera para diagnóstico.
  console.log(`[voice/${req.path.replace(/^\/voice\//, '')}] hit from=${req.body?.From || '?'} to=${req.body?.To || '?'} callSid=${req.body?.CallSid || '?'} callStatus=${req.body?.CallStatus || '?'} query=${JSON.stringify(req.query)}`);
  // Verificación de firma (loggeo, NO bloqueante)
  const token = process.env.TWILIO_AUTH_TOKEN;
  const publicUrl = process.env.PUBLIC_URL;
  if (token && publicUrl) {
    const signature = req.headers['x-twilio-signature'];
    if (signature) {
      const fullUrl = `${publicUrl.replace(/\/+$/, '')}${req.originalUrl}`;
      try {
        const valid = twilio.validateRequest(token, signature, fullUrl, req.body);
        console.log(`[voice] signature ${valid ? 'OK' : 'INVALID'} url=${fullUrl}`);
      } catch (err) {
        console.warn(`[voice] signature check threw: ${err.message}`);
      }
    } else {
      console.warn(`[voice] no X-Twilio-Signature header`);
    }
  }
  next();
}
app.post('/voice/incoming', voiceWebhookLogger, (req, res) => {
  try {
    const twiml = buildIncomingTwiml(req);
    res.set('Content-Type', 'text/xml');
    res.send(twiml);
    console.log(`[voice/incoming] TwiML enviado len=${twiml.length}`);
  } catch (err) {
    console.error(`[voice/incoming] error generando TwiML:`, err.message, err.stack);
    res.set('Content-Type', 'text/xml');
    res.send(`<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say voice="Polly.Lupe-Neural" language="es-MX">Hola, soy Athena. Tuvimos un problema técnico. Llama de nuevo en unos minutos.</Say>
  <Hangup/>
</Response>`);
  }
});
app.post('/voice/status', voiceWebhookLogger, handleVoiceStatus);

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
    // Log Isabel's message to activity feed para que aparezca en /actividad
    // (antes solo se logueaban tool calls — ella no veía su propia pregunta).
    try {
      logActivity({
        tool: 'isabel_pregunta',
        input_summary: text || `(media: ${numMedia} adjunto${numMedia === 1 ? '' : 's'})`,
        result_summary: 'WhatsApp inbound',
      });
    } catch { /* ignore */ }
    messages.push({ role: 'user', content: userContent });
    const { reply, messages: updated } = await runDirectora(messages);
    saveHistory(updated);
    try {
      logActivity({
        tool: 'athena_responde',
        input_summary: text?.slice(0, 100) || '(media)',
        result_summary: (reply || '').slice(0, 200),
      });
    } catch { /* ignore */ }
    await replyTo(from, reply, { voice: userSentVoice });
  } catch (err) {
    console.error('[whatsapp] Error procesando mensaje:', err);
    // Diagnóstico inteligente: en vez de "problema técnico" genérico,
    // intenta clasificar el error y darle a Isabel el siguiente paso
    // concreto. Ahorra debugging manual.
    const msg = String(err?.message || err || '').toLowerCase();
    let userMsg = 'Tuve un problema técnico, Isabel. Intenta de nuevo en un momento.';
    if (msg.includes('credit') || msg.includes('balance') || msg.includes('insufficient_quota') || msg.includes('billing')) {
      userMsg = 'Se acabó el saldo de Anthropic, Isabel. Entra a console.anthropic.com → Billing y recarga. Apenas haya saldo vuelvo a responder.';
    } else if (msg.includes('rate_limit') || msg.includes('429')) {
      userMsg = 'Anthropic está rate-limited en este momento, Isabel. Intenta en 1-2 minutos.';
    } else if (msg.includes('overloaded') || msg.includes('529')) {
      userMsg = 'Anthropic está sobrecargada ahorita, Isabel. Es de ellos, no nuestro. Intenta en 30 segundos.';
    } else if (msg.includes('invalid_api_key') || msg.includes('401') || msg.includes('authentication')) {
      userMsg = 'La ANTHROPIC_API_KEY de Railway está inválida o se rotó. Necesitas revisar Railway → Variables.';
    } else if (msg.includes('etimedout') || msg.includes('network') || msg.includes('econnreset')) {
      userMsg = 'Problema de red entre Railway y Anthropic, Isabel. Intenta en 30 segundos.';
    } else if (err?.message) {
      // Para cualquier otro error: incluye el mensaje técnico real
      // (recortado) para que se pueda diagnosticar sin tener que buscar logs.
      userMsg = `Tuve un error: ${String(err.message).slice(0, 200)}. Si se repite, mándame screenshot y revisamos.`;
    }
    await sendMessage(from, userMsg).catch(() => {});
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
// Reescribe el mensaje de Isabel para inyectar su contacto cuando dice
// "llámame", "mándame email", etc. Athena tiene el dato en su system
// prompt + memoria, pero a veces lo ignora. Si el dato está en el mensaje
// mismo del usuario, ya no puede ignorarlo — lo está leyendo literal.
function injectIsabelContact(text) {
  if (!text) return text;
  const ISABEL_PHONE = '+13102700626';
  const ISABEL_EMAIL = 'connect@withisabelfuentes.com';
  // Llamadas
  if (/\b(ll[aá]mame|c[aá]llame|m[aá]rcame|call me|phone me|ring me)\b/i.test(text)
      && !text.includes(ISABEL_PHONE)) {
    text += ` [contexto: el número de Isabel es ${ISABEL_PHONE} — úsalo con llamar_cliente sin preguntar]`;
  }
  // Email
  if (/\b(m[aá]ndame email|send me email|email me|email to me)\b/i.test(text)
      && !text.includes(ISABEL_EMAIL)) {
    text += ` [contexto: el email de Isabel es ${ISABEL_EMAIL}]`;
  }
  // SMS
  if (/\b(m[aá]ndame sms|m[aá]ndame texto|text me|sms me)\b/i.test(text)
      && !text.includes(ISABEL_PHONE)) {
    text += ` [contexto: el número de Isabel es ${ISABEL_PHONE} — úsalo con enviar_sms]`;
  }
  return text;
}

async function buildUserContent(text, numMedia, body) {
  text = injectIsabelContact(text);
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
  const merged = injectIsabelContact([text, ...transcripts].filter(Boolean).join('\n\n'));
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
// === Manager Mode — 6 rutinas que la convierten en mánager ===
import('./manager_mode.js').then((mm) => {
  // 1. Day plan scheduled — 7am, horario explícito del día
  scheduleCron('mgr_day_plan', process.env.MGR_DAY_PLAN_CRON || '0 7 * * 1-6', mm.dayPlanScheduled);
  // 2. Coach cadence auto — 8am, ping de check-ins de coaches due
  scheduleCron('mgr_coach_cadence', process.env.MGR_COACH_CADENCE_CRON || '0 8 * * *', mm.coachCadenceAuto);
  // 3. Focus blocks auto — 6:45am L-V, crea bloque 9-11am si hay standing order
  scheduleCron('mgr_focus_blocks', process.env.MGR_FOCUS_CRON || '45 6 * * 1-5', mm.focusBlocksAuto);
  // 4. Hourly nudge — cada 30min 7am-9pm, ping si algo viene en <30min
  scheduleCron('mgr_hourly_nudge', process.env.MGR_NUDGE_CRON || '0 7-21 * * *', mm.hourlyNudge);
  // 5. Daily audit — 8pm L-V, "dijiste X, hiciste Y"
  scheduleCron('mgr_daily_audit', process.env.MGR_AUDIT_CRON || '0 20 * * 1-5', mm.dailyAudit);
  // 6. Pre-meeting deep brief — cada 5min, brief serio 15min antes
  scheduleCron('mgr_premeeting', process.env.MGR_PREMEETING_CRON || '*/5 7-21 * * *', mm.preMeetingDeepBrief);
}).catch((e) => console.warn('[cron] manager_mode no se pudo cargar:', e.message));

scheduleCron('weekly',  process.env.WEEKLY_REVIEW_CRON   || '0 18 * * 0', sendWeeklyReview);
// Rapport semanal: viernes 6pm — peso/medidas/foto/sentires. Le da
// continuidad real a Sofía y Rivera (trend del cuerpo en vez de adivinar).
scheduleCron('rapport', process.env.WEEKLY_RAPPORT_CRON  || '0 18 * * 5', sendWeeklyRapport);
// Trend scout: 11am todos los días — busca virales / trending /
// breakthroughs en Medicare, brand, salud 50+, productividad, wealth.
// Si encuentra hit con score ≥ 8, hace proactive ping.
// Trend scout: 3 días por semana (L/M/V) — bajamos de daily porque
// las trends no se mueven THAT rápido y el scan cuesta ($0.30-0.50/día).
// Si Isabel quiere force, '/scan' slash command lo dispara on-demand.
scheduleCron('trends',  process.env.TREND_SCAN_CRON      || '0 11 * * 1,3,5', dailyTrendScan);
// Self-grade: domingo 8pm. Athena se califica vs sem prev + propone
// UN cambio concreto. Si baja >5pts o score≤60, pinguea proactiva.
scheduleCron('self_grade', process.env.SELF_GRADE_CRON   || '0 20 * * 0', weeklySelfGrade);
// Research digest: mediodía — Athena rota tus temas, hace web_search,
// te manda 3 cards con top items. Le ahorra a Isabel ~2h/día de scroll.
// Research digest: 2x semana (martes/jueves) — overlap conceptual con
// trend scan, no necesita ser daily. Saves ~$0.50/día.
scheduleCron('research', process.env.RESEARCH_DIGEST_CRON || '0 12 * * 2,4', sendResearchDigest);
// Team morning email — 6am todos los días. Manda email personalizado a
// cada miembro del equipo (Isabel, Sami, Skarleth, Arlette) con sus
// tickets LUNA del día. Isabel pidió esto el 6 jun 2026.
// Emails se leen de env: ISABEL_EMAIL, SAMI_EMAIL, SKARLETH_EMAIL,
// ARLETTE_EMAIL. Si una falta, esa persona se salta sin romper el cron.
import('./team_morning_email.js').then(({ sendTeamMorningEmails }) => {
  scheduleCron('team_morning_email', process.env.TEAM_MORNING_EMAIL_CRON || '0 6 * * *', sendTeamMorningEmails);
}).catch((e) => console.warn('[cron] team_morning_email no se pudo cargar:', e.message));
// Closing the loop — 6pm-7pm, reporta lo que cerramos hoy (pattern del Elite EA SOP)
scheduleCron('closing_loop', process.env.CLOSING_LOOP_CRON || '0 18 * * 1-5', sendClosingLoop);
// Ticket monitor — 10am y 4pm L-S, avisa tickets LUNA estancados (>2 días, >0.5 si ALTA)
import('./ticket_monitor.js').then(({ sendStaleTicketAlert }) => {
  scheduleCron('ticket_monitor', process.env.TICKET_MONITOR_CRON || '0 10,16 * * 1-6', sendStaleTicketAlert);
}).catch((e) => console.warn('[cron] ticket_monitor no se pudo cargar:', e.message));
// Vacation reports — corre cada hora y solo manda si la hora local de Isabel
// (en SU timezone) es 9am o 7pm. Bypass si no hay vacación activa.
import('./vacation_report.js').then(({ sendVacationReports }) => {
  scheduleCron('vacation_reports', process.env.VACATION_REPORTS_CRON || '0 * * * *', sendVacationReports);
}).catch((e) => console.warn('[cron] vacation_reports no se pudo cargar:', e.message));
scheduleCron('reflect', process.env.NIGHTLY_REFLECT_CRON || '0 2 * * *',  nightlyReflection);
// Triage corre antes del briefing para que Athena tenga lista la
// clasificación + borradores en cola cuando salude a Isabel.
scheduleCron('triage',  process.env.EMAIL_TRIAGE_CRON    || '0 5 * * *',  nightlyEmailTriage);
// Task tick: cada hora entre 7am y 9pm (TZ local). taskTick adentro
// también respeta quiet hours para los recordatorios. El trabajo
// silencioso de Athena puede correr a cualquier hora pero lo
// limitamos a horas despiertas para acotar costo.
// Task tick: cada 3 horas en horas despiertas (vs cada hora antes).
// taskTick adentro respeta quiet hours. Cada tick que tiene work real
// cuesta ~$0.05-0.10. 5 ticks/día → 3 ticks/día (beginning, middle, end).
// Isabel pidió expresamente 3x: 9am mañana, 1pm mediodía, 5pm tarde.
scheduleCron('tasks',   process.env.TASK_TICK_CRON       || '0 9,13,17 * * *', taskTick);
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
// Say-do follow-through: cada 30 min Athena revisa SUS promesas
// que están por vencer o vencidas. Si hay alguna sin cumplir,
// trigger Athena con mensaje sintético "tengo promesas vencidas,
// cierralas o avisa a Isabel". Esto cierra el loop say-do:
// no solo promete — cumple.
scheduleCron('saydo_followup', '0 7-21/2 * * *', async () => {
  const { listOverdue, listActive } = await import('./saydo.js');
  const overdue = listOverdue();
  const cerca = listActive().filter((p) => {
    const v = new Date(p.vence).getTime();
    return v > Date.now() && v < Date.now() + 30 * 60_000;
  });
  if (!overdue.length && !cerca.length) return;
  // Solo log — Athena puede leerlas del contexto base en su próximo turno.
  // El cron NO manda mensaje proactivo porque iría sobre el cap diario.
  // En el próximo turno conversacional Athena las verá en su contexto y
  // las cerrará / actualizará por iniciativa.
  console.log(`[saydo_followup] ${overdue.length} vencidas + ${cerca.length} próximas a vencer`);
});

// Overload detection: cada 3 horas durante horario laboral, Athena
// chequea si Isabel está sobrecargada. Si score ≥ 4, le manda
// PROACTIVAMENTE el triage con propuestas. Respeta quiet hours +
// daily cap. No la satura — solo cuando hay señal real.
scheduleCron('overload_check', process.env.OVERLOAD_CRON || '0 10,13,16 * * 1-5', async () => {
  const { buildTriageProposal } = await import('./overload.js');
  const { canSendProactive } = await import('./proactive.js');
  const { sendMessage } = await import('./whatsapp.js');
  const { bumpProactiveCount, logActivity } = await import('./memory.js');
  const to = process.env.ISABEL_WHATSAPP;
  if (!to) return;
  const t = buildTriageProposal();
  if (!t) return;
  const gate = canSendProactive();
  if (!gate.ok) {
    console.log(`[overload] detected pero saltado: ${gate.reason}`);
    return;
  }
  await sendMessage(to, t.mensaje);
  bumpProactiveCount(gate.dayKey);
  logActivity({ tool: 'overload_proactive_triage', input_summary: `score=${t.overload.score}`, result_summary: `${t.proposals.length} propuestas` });
  console.log(`[overload] triage proactivo mandado (score ${t.overload.score})`);
});
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
const httpServer = app.listen(port, async () => {
  console.log(`👑 Athena escuchando en el puerto ${port}`);
  // Corre migraciones de data al boot (rename pilar→luna, luna→aurora, etc).
  // Idempotente — si ya corrió, no hace nada.
  try {
    const { runAllMigrations } = await import('./migrations.js');
    runAllMigrations();
  } catch (e) {
    console.error('[migrations] error:', e.message);
  }
  // Arranca el listener de Gmail IDLE (event-driven inbox). Si las
  // credenciales no están, no hace nada. Si la conexión cae, se
  // reconecta con backoff. NO bloquea el arranque del server.
  if (inboxIdleEnabled) {
    startInboxIdle().catch((err) => console.error('[idle] arranque falló:', err.message));
  }
  // MCP boot: descubre tools de Zapier / Notion / etc. si están
  // configuradas. Las cachea en globalThis para que directora las
  // vea en cada llamada Anthropic sin tener que re-fetch.
  try {
    const { initToolsFromMcp } = await import('./tools.js');
    const r = await initToolsFromMcp();
    if (r.servers > 0) {
      console.log(`[mcp] ${r.servers} server(s) conectados, ${r.tools} tool(s) descubiertas.`);
    }
  } catch (err) {
    console.warn('[mcp] init falló (no bloquea Athena):', err.message);
  }
});
// MCP refresh: cada hora actualiza el cache de tools descubiertas.
// Si Zapier agrega/quita apps habilitadas, Athena las ve en máx 1h.
scheduleCron('mcp_refresh', '13 * * * *', async () => {
  try {
    const { refreshMcpToolsCache } = await import('./tools.js');
    await refreshMcpToolsCache();
  } catch (err) {
    console.warn('[mcp_refresh] error:', err.message);
  }
});
// Atacha el endpoint WebSocket /voice/relay al mismo HTTP server.
// Twilio ConversationRelay nos pega aquí con el streaming texto-a-texto
// (ya hace STT/TTS por su lado).
attachVoiceRelay(httpServer);
