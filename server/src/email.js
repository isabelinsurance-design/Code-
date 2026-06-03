import nodemailer from 'nodemailer';
import { ImapFlow } from 'imapflow';
import { simpleParser } from 'mailparser';

const GMAIL_USER = process.env.GMAIL_USER;
const GMAIL_APP_PASSWORD = process.env.GMAIL_APP_PASSWORD;
const emailEnabled = Boolean(GMAIL_USER && GMAIL_APP_PASSWORD);

// ---- Mandar correo (SMTP explícito — más confiable que service:'gmail') ----
// Cambio del shortcut "service: gmail" (puerto 465/secure) al config explícito
// (puerto 587 / STARTTLS) porque 465 a veces queda colgado desde Railway egress.
// Mismo Gmail/Workspace, mismo App Password — solo cambia el handshake.
export async function sendEmail(to, subject, body) {
  if (!emailEnabled) {
    return 'El email todavía no está configurado. Pon GMAIL_USER y GMAIL_APP_PASSWORD en el .env para activarlo.';
  }
  const transporter = nodemailer.createTransport({
    host: 'smtp.gmail.com',
    port: 587,
    secure: false, // STARTTLS — más confiable que 465 desde Railway
    auth: { user: GMAIL_USER, pass: GMAIL_APP_PASSWORD },
    connectionTimeout: 15_000, // 15s evita cuelgues largos sin error claro
    socketTimeout: 15_000,
  });
  const firma = `\n\n—\n${process.env.ISABEL_NAME || 'Isabel Fuentes'}\nLicensed Medicare Agent · California`;
  try {
    const info = await transporter.sendMail({
      from: GMAIL_USER,
      to,
      subject,
      text: body + firma,
    });
    console.log(`[email] enviado a ${to}: messageId=${info.messageId}`);
    return `Correo enviado a ${to} con el asunto "${subject}".`;
  } catch (err) {
    // Log detallado para diagnóstico — el error real (code/command/response)
    // queda en Railway logs aunque Athena solo le muestre el message a Isabel.
    console.error(`[email] SMTP fail to=${to} code=${err.code || '?'} command=${err.command || '?'} response="${err.response || ''}" message="${err.message}"`);
    throw err; // re-throw para que confirmar_envio re-encole el draft
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
