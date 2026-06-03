import nodemailer from 'nodemailer';
import { ImapFlow } from 'imapflow';
import { simpleParser } from 'mailparser';

const GMAIL_USER = process.env.GMAIL_USER;
const GMAIL_APP_PASSWORD = process.env.GMAIL_APP_PASSWORD;
const RESEND_API_KEY = process.env.RESEND_API_KEY;
const RESEND_FROM = process.env.RESEND_FROM || GMAIL_USER || 'connect@withisabelfuentes.com';
const emailEnabled = Boolean((GMAIL_USER && GMAIL_APP_PASSWORD) || RESEND_API_KEY);

// ---- Mandar correo ----
// Estrategia de fallback:
//   1) Si RESEND_API_KEY está set → HTTP API de Resend (no usa SMTP).
//      Esta es la ruta CONFIABLE. Railway egress a smtp.gmail.com:587/465
//      da ETIMEDOUT consistente (Google bloquea conexión o Railway no
//      sale a esos puertos). HTTP siempre funciona.
//   2) Fallback SMTP de Gmail (legacy) si Resend no está configurado.
//      Probablemente fallará en producción pero útil para dev local.
//
// Setup de Resend (Sami, 5 min):
//   - resend.com → crear cuenta gratis (free tier: 100/día, suficiente)
//   - Add Domain → "withisabelfuentes.com" → seguir las instrucciones
//     para agregar 3 registros DNS (DKIM/SPF/DMARC). Toma 5-30 min en
//     propagar.
//   - API Keys → Create API Key con permisos "Sending access" → copiar
//   - Railway: agregar RESEND_API_KEY=re_xxx
//   - Opcional: RESEND_FROM=connect@withisabelfuentes.com (default usa
//     GMAIL_USER si está set, si no fallback a connect@...)
export async function sendEmail(to, subject, body) {
  if (!emailEnabled) {
    return 'El email todavía no está configurado. Pon RESEND_API_KEY (recomendado) o GMAIL_USER+GMAIL_APP_PASSWORD para fallback SMTP.';
  }
  const firma = `\n\n—\n${process.env.ISABEL_NAME || 'Isabel Fuentes'}\nLicensed Medicare Agent · California`;
  const fullBody = body + firma;

  // Ruta 1: Resend HTTP API (preferida)
  if (RESEND_API_KEY) {
    try {
      const res = await fetch('https://api.resend.com/emails', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${RESEND_API_KEY}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          from: RESEND_FROM,
          to: [to],
          subject,
          text: fullBody,
        }),
      });
      if (!res.ok) {
        const errText = await res.text().catch(() => '');
        const err = new Error(`Resend ${res.status}: ${errText.slice(0, 300)}`);
        err.code = `RESEND_${res.status}`;
        console.error(`[email] Resend fail to=${to} status=${res.status} body=${errText.slice(0, 300)}`);
        throw err;
      }
      const json = await res.json().catch(() => ({}));
      console.log(`[email] enviado vía Resend a ${to}: id=${json.id || '?'}`);
      return `Correo enviado a ${to} con el asunto "${subject}".`;
    } catch (err) {
      console.error(`[email] Resend exception to=${to} message="${err.message}"`);
      throw err;
    }
  }

  // Ruta 2: SMTP Gmail (fallback / legacy)
  const transporter = nodemailer.createTransport({
    host: 'smtp.gmail.com',
    port: 587,
    secure: false,
    auth: { user: GMAIL_USER, pass: GMAIL_APP_PASSWORD },
    connectionTimeout: 15_000,
    socketTimeout: 15_000,
  });
  try {
    const info = await transporter.sendMail({
      from: GMAIL_USER,
      to,
      subject,
      text: fullBody,
    });
    console.log(`[email] enviado vía SMTP a ${to}: messageId=${info.messageId}`);
    return `Correo enviado a ${to} con el asunto "${subject}".`;
  } catch (err) {
    console.error(`[email] SMTP fail to=${to} code=${err.code || '?'} command=${err.command || '?'} response="${err.response || ''}" message="${err.message}"`);
    throw err;
  }
}

// ---- Revisar correos recientes (IMAP de Gmail) ----
export async function checkEmails(limit = 5) {
  if (!emailEnabled) {
    return 'El email todavía no está configurado. Pon GMAIL_USER y GMAIL_APP_PASSWORD en el .env para activarlo.';
  }
  const client = new ImapFlow({
    host: 'imap.gmail.com',
    port: 993,
    secure: true,
    auth: { user: GMAIL_USER, pass: GMAIL_APP_PASSWORD },
    logger: false,
  });

  await client.connect();
  const summaries = [];
  try {
    const lock = await client.getMailboxLock('INBOX');
    try {
      const total = client.mailbox.exists;
      if (!total) return 'No hay correos en la bandeja.';
      const start = Math.max(1, total - limit + 1);
      for await (const msg of client.fetch(`${start}:*`, { envelope: true, flags: true })) {
        const env = msg.envelope || {};
        const from = env.from?.[0]?.name || env.from?.[0]?.address || 'desconocido';
        const unread = !msg.flags?.has('\\Seen') ? '🔵 ' : '';
        summaries.push(`${unread}De: ${from} — ${env.subject || '(sin asunto)'}`);
      }
    } finally {
      lock.release();
    }
  } finally {
    await client.logout();
  }
  return summaries.reverse().join('\n') || 'No hay correos recientes.';
}

// Devuelve los últimos N correos con CUERPO + asunto + remitente + flags.
// Lo usa la triage nocturna para clasificar y redactar respuestas.
export async function fetchRecentEmails(limit = 25) {
  if (!emailEnabled) return [];
  const client = new ImapFlow({
    host: 'imap.gmail.com',
    port: 993,
    secure: true,
    auth: { user: GMAIL_USER, pass: GMAIL_APP_PASSWORD },
    logger: false,
  });
  await client.connect();
  const out = [];
  try {
    const lock = await client.getMailboxLock('INBOX');
    try {
      const total = client.mailbox.exists;
      if (!total) return [];
      const start = Math.max(1, total - limit + 1);
      for await (const msg of client.fetch(`${start}:*`, { envelope: true, flags: true, source: true, uid: true })) {
        const env = msg.envelope || {};
        let body = '';
        try {
          const parsed = await simpleParser(msg.source);
          body = (parsed.text || parsed.html || '').replace(/\s+/g, ' ').trim().slice(0, 2000);
        } catch {
          body = '(no se pudo parsear el cuerpo)';
        }
        out.push({
          uid: msg.uid,
          fecha: env.date,
          de: env.from?.[0]?.address || '',
          de_nombre: env.from?.[0]?.name || '',
          asunto: env.subject || '(sin asunto)',
          no_leido: !msg.flags?.has('\\Seen'),
          body_preview: body,
        });
      }
    } finally {
      lock.release();
    }
  } finally {
    await client.logout();
  }
  return out.reverse();
}

export { emailEnabled };
