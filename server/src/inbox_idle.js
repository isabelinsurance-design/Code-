// ============================================================
//  Inbox IDLE — Gmail event-driven listener
//  ─────────────────────────────────────────
//  Mantiene una conexión IMAP persistente con Gmail y reacciona
//  a correos nuevos EN EL MOMENTO en vez de esperar al cron de
//  las 5am. Para cada correo nuevo:
//   1. Lo clasifica con Haiku (rápido + barato)
//   2. Si es VIP o urgente → notifica a Isabel por WhatsApp
//      (sujeto a quiet hours + tope diario)
//   3. Si es de cliente Medicare → genera un borrador de respuesta
//      que entra a la cola pendiente de "envía"
//   4. Si es spam/newsletter → ignora silenciosamente
//
//  Resiliente: si la conexión se cae, reconecta con backoff.
//  Si email no está configurado, no arranca nada.
// ============================================================
import { ImapFlow } from 'imapflow';
import { simpleParser } from 'mailparser';
import { anthropic } from './claude.js';
import { sendMessage } from './whatsapp.js';
import { canSendProactive } from './proactive.js';
import { bumpProactiveCount, logActivity, remember } from './memory.js';

const GMAIL_USER = process.env.GMAIL_USER;
const GMAIL_APP_PASSWORD = process.env.GMAIL_APP_PASSWORD;
const enabled = Boolean(GMAIL_USER && GMAIL_APP_PASSWORD && process.env.INBOX_IDLE !== 'false');

// Backoff: 5s → 15s → 60s → 5min → 15min, luego se queda en 15min.
const BACKOFF_LADDER = [5_000, 15_000, 60_000, 300_000, 900_000];
let attempt = 0;
let stopRequested = false;
let currentClient = null;

// Cache simple de UIDs ya procesados para evitar dobles disparos en
// reconexiones (IMAP IDLE puede repetir notificaciones).
const seenUids = new Set();
const SEEN_UIDS_MAX = 1000;

function rememberSeen(uid) {
  if (seenUids.has(uid)) return false;
  seenUids.add(uid);
  if (seenUids.size > SEEN_UIDS_MAX) {
    const toDrop = [...seenUids].slice(0, seenUids.size - SEEN_UIDS_MAX);
    for (const u of toDrop) seenUids.delete(u);
  }
  return true;
}

export async function startInboxIdle() {
  if (!enabled) {
    console.log('[idle] inbox IDLE deshabilitado (faltan creds o INBOX_IDLE=false).');
    return;
  }
  stopRequested = false;
  loop().catch((err) => console.error('[idle] loop fatal:', err.message));
}

export async function stopInboxIdle() {
  stopRequested = true;
  if (currentClient) {
    try { await currentClient.logout(); } catch { /* ignore */ }
    currentClient = null;
  }
}

async function loop() {
  while (!stopRequested) {
    try {
      await runOnce();
      attempt = 0; // éxito: reset backoff
    } catch (err) {
      console.warn(`[idle] conexión cayó: ${err.message}`);
    }
    if (stopRequested) break;
    const wait = BACKOFF_LADDER[Math.min(attempt, BACKOFF_LADDER.length - 1)];
    attempt += 1;
    console.log(`[idle] reintentando en ${Math.round(wait / 1000)}s…`);
    await new Promise((r) => setTimeout(r, wait));
  }
}

async function runOnce() {
  const client = new ImapFlow({
    host: 'imap.gmail.com',
    port: 993,
    secure: true,
    auth: { user: GMAIL_USER, pass: GMAIL_APP_PASSWORD },
    logger: false,
  });
  currentClient = client;

  await client.connect();
  await client.mailboxOpen('INBOX');
  console.log(`[idle] conectado, escuchando INBOX (${client.mailbox.exists} mensajes).`);

  let lastSeq = client.mailbox.exists;

  client.on('exists', async (data) => {
    try {
      // data.count = nuevo total. Trae los mensajes nuevos.
      const newCount = data.count;
      if (newCount <= lastSeq) return;
      const range = `${lastSeq + 1}:${newCount}`;
      lastSeq = newCount;
      for await (const msg of client.fetch(range, { envelope: true, flags: true, source: true, uid: true })) {
        if (!rememberSeen(msg.uid)) continue;
        await processMessage(msg).catch((err) => console.warn('[idle] processMessage:', err.message));
      }
    } catch (err) {
      console.warn('[idle] handler exists:', err.message);
    }
  });

  // Reconectar agresivamente si el servidor nos tira.
  client.on('error', (err) => {
    console.warn('[idle] client error:', err.message);
  });
  client.on('close', () => {
    console.log('[idle] conexión cerrada por el servidor.');
  });

  // Iniciar IDLE (loop infinito hasta que se cierre la conexión).
  // ImapFlow re-emite IDLE periódicamente para que Gmail no nos tire
  // por timeout.
  await client.idle();

  // Si idle() retorna, la conexión murió.
  throw new Error('IDLE terminó');
}

// ---- Clasificación + reacción ----
async function processMessage(msg) {
  const env = msg.envelope || {};
  let body = '';
  let html = '';
  try {
    const parsed = await simpleParser(msg.source);
    body = (parsed.text || '').replace(/\s+/g, ' ').trim().slice(0, 2000);
    html = (parsed.html || '').slice(0, 200);
  } catch {
    body = '(no se pudo parsear)';
  }

  const fromAddr = env.from?.[0]?.address || '';
  const fromName = env.from?.[0]?.name || fromAddr;
  const subject = env.subject || '(sin asunto)';

  const verdict = await classify({ fromAddr, fromName, subject, body });
  logActivity({
    tool: 'inbox_classify',
    input_summary: `${fromName}: ${subject}`,
    result_summary: `${verdict.categoria} (${verdict.urgencia})`,
  });

  if (verdict.categoria === 'spam' || verdict.categoria === 'newsletter') {
    // No molestamos.
    return;
  }

  const isUrgent = verdict.urgencia === 'alta' || verdict.categoria === 'urgente';
  const isVip = verdict.categoria === 'vip';

  if (isUrgent || isVip) {
    const to = process.env.ISABEL_WHATSAPP;
    const gate = canSendProactive();
    if (to && gate.ok) {
      const preview = body.slice(0, 100).replace(/\s+/g, ' ');
      const tag = isVip ? '⭐ VIP' : '🔥 URGENTE';
      await sendMessage(
        to,
        `${tag} email — ${fromName}: "${subject}"\n${preview}${body.length > 100 ? '…' : ''}\n\n¿Quieres que redacte respuesta?`,
      );
      bumpProactiveCount(gate.dayKey);
    } else if (to && !gate.ok) {
      // Quiet hours / cap: lo guardamos para el briefing
      remember(`Email ${isVip ? 'VIP' : 'urgente'} pendiente: ${fromName} – "${subject}" (llegó ${new Date().toISOString()})`);
    }
  } else if (verdict.categoria === 'cliente_medicare') {
    // No interrumpimos a Isabel — el cron de triage de las 5am lo va a
    // recoger junto con el resto. Marcamos en memoria para que el
    // briefing sepa.
    remember(`Email de cliente Medicare en cola para triage: ${fromName} – "${subject}".`);
  }
}

// Clasificación rápida con Haiku 4.5 (barato, ~50ms).
async function classify({ fromAddr, fromName, subject, body }) {
  const prompt = `Clasifica este correo entrante a Isabel Fuentes (agente Medicare en SoCal).
Devuelve SOLO un JSON con dos campos: "categoria" y "urgencia".

categoria: una de [vip | cliente_medicare | urgente | personal | newsletter | spam | otro]
urgencia: una de [alta | media | baja]

VIP = familia cercana, Sami (asistente), abogado, contador, o un cliente premium nombrado.
cliente_medicare = un cliente actual o lead, asuntos de plan, MBI, SOA, AEP, renovación.
urgente = vence algo, pierde dinero, requiere respuesta hoy.
spam = marketing masivo, phishing.
newsletter = boletines, suscripciones.

Correo:
De: ${fromName} <${fromAddr}>
Asunto: ${subject}
Cuerpo: ${body.slice(0, 800)}

JSON:`;

  try {
    const r = await anthropic.messages.create({
      model: 'claude-haiku-4-5-20251001',
      max_tokens: 100,
      messages: [{ role: 'user', content: prompt }],
    });
    const text = r.content?.[0]?.text || '';
    const m = text.match(/\{[\s\S]*\}/);
    if (!m) return { categoria: 'otro', urgencia: 'baja' };
    const parsed = JSON.parse(m[0]);
    return {
      categoria: parsed.categoria || 'otro',
      urgencia: parsed.urgencia || 'baja',
    };
  } catch (err) {
    console.warn('[idle] classify falló:', err.message);
    return { categoria: 'otro', urgencia: 'baja' };
  }
}

export { enabled as inboxIdleEnabled };
