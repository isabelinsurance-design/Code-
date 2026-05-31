// ============================================================
//  Inbox cleanup — limpia el ruido de Isabel
//  ────────────────────────────────────────
//  Tres operaciones:
//
//   1. SCAN — recorre INBOX, agrupa por remitente, te muestra
//      los más ruidosos de los últimos N días.
//   2. UNSUBSCRIBE — para un remitente, intenta el unsubscribe
//      "real" vía List-Unsubscribe header (RFC 2369). Si el
//      header trae mailto:, manda un email vacío de baja. Si
//      solo trae URL https, devuelve la URL para que Isabel
//      decida (sin Computer Use no podemos clickear).
//   3. SUPPRESS LIST + SWEEP — agrega el remitente a una
//      blacklist persistente. Cada hora un cron mueve emails
//      nuevos de remitentes suprimidos al Trash. Funciona
//      independientemente de si el unsubscribe se procesó.
//
//  Architecture choice: usamos solo IMAP + SMTP (mismo
//  setup que email.js), sin requerir Gmail API OAuth. Más
//  simple para Isabel — solo app-password. El trade-off:
//  los filtros viven en NUESTRO server, no en Gmail, así
//  que solo aplican mientras Athena esté arriba.
// ============================================================
import { ImapFlow } from 'imapflow';
import { simpleParser } from 'mailparser';
import nodemailer from 'nodemailer';
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const SUPPRESS_FILE = join(DATA_DIR, 'inbox_suppress.json');

const GMAIL_USER = process.env.GMAIL_USER;
const GMAIL_APP_PASSWORD = process.env.GMAIL_APP_PASSWORD;

export function inboxCleanupEnabled() {
  return Boolean(GMAIL_USER && GMAIL_APP_PASSWORD);
}

function ensureDir() {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
}

function loadSuppress() {
  try {
    if (existsSync(SUPPRESS_FILE)) return JSON.parse(readFileSync(SUPPRESS_FILE, 'utf8'));
  } catch { /* ignore */ }
  return { senders: [] };
}

function saveSuppress(data) {
  ensureDir();
  writeFileSync(SUPPRESS_FILE, JSON.stringify(data, null, 2));
}

function normalizeEmail(e) {
  return String(e || '').trim().toLowerCase();
}

// ─────────── Suppress list management ───────────

export function getSuppressList() {
  return loadSuppress().senders;
}

export function addToSuppress(senderEmail, meta = {}) {
  const e = normalizeEmail(senderEmail);
  if (!e) return null;
  const data = loadSuppress();
  if (!data.senders) data.senders = [];
  if (data.senders.find((s) => s.email === e)) return data.senders.find((s) => s.email === e);
  const entry = {
    email: e,
    added_at: new Date().toISOString(),
    via_unsubscribe: Boolean(meta.via_unsubscribe),
    unsubscribe_note: meta.note || '',
  };
  data.senders.push(entry);
  saveSuppress(data);
  return entry;
}

export function removeFromSuppress(senderEmail) {
  const e = normalizeEmail(senderEmail);
  const data = loadSuppress();
  const before = (data.senders || []).length;
  data.senders = (data.senders || []).filter((s) => s.email !== e);
  saveSuppress(data);
  return before - data.senders.length;
}

export function isSuppressed(senderEmail) {
  const e = normalizeEmail(senderEmail);
  return getSuppressList().some((s) => s.email === e);
}

// ─────────── IMAP helpers ───────────

function client() {
  return new ImapFlow({
    host: 'imap.gmail.com',
    port: 993,
    secure: true,
    auth: { user: GMAIL_USER, pass: GMAIL_APP_PASSWORD },
    logger: false,
  });
}

// Agrupa remitentes en últimos N días. Devuelve [{ email, name, count, sample_uid }]
export async function scanNoisySenders({ days = 30, limit = 25 } = {}) {
  if (!inboxCleanupEnabled()) return { ok: false, error: 'Gmail no configurado.', senders: [] };
  const c = client();
  await c.connect();
  const out = new Map(); // email → { email, name, count, sample_uid, last_subject }
  try {
    const lock = await c.getMailboxLock('INBOX');
    try {
      const since = new Date(Date.now() - days * 86_400_000);
      const uids = await c.search({ since });
      if (!uids?.length) return { ok: true, senders: [] };
      for await (const msg of c.fetch(uids.slice(-2000), { envelope: true, uid: true })) {
        const from = msg.envelope?.from?.[0];
        if (!from?.address) continue;
        const email = normalizeEmail(from.address);
        // skip Isabel's own outgoing & known important channels
        if (email === normalizeEmail(GMAIL_USER)) continue;
        const existing = out.get(email);
        if (existing) {
          existing.count++;
          // keep most recent sample (later UIDs come later)
          existing.sample_uid = msg.uid;
          existing.last_subject = msg.envelope?.subject || existing.last_subject;
        } else {
          out.set(email, {
            email,
            name: from.name || '',
            count: 1,
            sample_uid: msg.uid,
            last_subject: msg.envelope?.subject || '',
          });
        }
      }
    } finally {
      lock.release();
    }
  } finally {
    await c.logout();
  }
  const senders = [...out.values()]
    .sort((a, b) => b.count - a.count)
    .slice(0, limit);
  // anotar si ya están suprimidos
  for (const s of senders) s.already_suppressed = isSuppressed(s.email);
  return { ok: true, senders };
}

// ─────────── Unsubscribe attempt ───────────

// Lee un email específico, extrae List-Unsubscribe header.
// Devuelve { mailto, urls, raw } o null.
async function readUnsubscribeHeaders(messageUid) {
  if (!inboxCleanupEnabled()) return null;
  const c = client();
  await c.connect();
  try {
    const lock = await c.getMailboxLock('INBOX');
    try {
      const msg = await c.fetchOne(String(messageUid), { source: true, uid: true });
      if (!msg) return null;
      const parsed = await simpleParser(msg.source);
      const raw = parsed.headers?.get('list-unsubscribe') || '';
      if (!raw) return null;
      const mailtoMatch = String(raw).match(/<mailto:([^>]+)>/i);
      const urlMatches = [...String(raw).matchAll(/<(https?:\/\/[^>]+)>/gi)].map((m) => m[1]);
      return {
        raw: String(raw),
        mailto: mailtoMatch ? mailtoMatch[1] : null,
        urls: urlMatches,
      };
    } finally {
      lock.release();
    }
  } finally {
    await c.logout();
  }
}

// Si el header tiene mailto: con sintaxis válida, manda un email vacío
// de baja. Si solo tiene URL https, devuelve la URL como pendiente.
export async function attemptUnsubscribe(senderEmail) {
  const e = normalizeEmail(senderEmail);
  if (!e) return { ok: false, error: 'Falta sender.' };
  if (!inboxCleanupEnabled()) return { ok: false, error: 'Gmail no configurado.' };

  // Buscar el email más reciente de este sender para extraer el header.
  const c = client();
  await c.connect();
  let sampleUid = null;
  try {
    const lock = await c.getMailboxLock('INBOX');
    try {
      const uids = await c.search({ from: e });
      if (uids?.length) sampleUid = uids[uids.length - 1];
    } finally {
      lock.release();
    }
  } finally {
    await c.logout();
  }
  if (!sampleUid) return { ok: false, error: `Sin emails recientes de ${e}.` };

  const hdr = await readUnsubscribeHeaders(sampleUid);
  if (!hdr) return { ok: false, status: 'no_unsubscribe_header', error: 'Este remitente no incluye List-Unsubscribe header. Filtro auto-delete sigue siendo opción.' };

  if (hdr.mailto) {
    // Mandar email vacío de baja
    const m = hdr.mailto.split('?')[0]; // mailto:abc@x.com?subject=unsubscribe
    const subject = (hdr.mailto.match(/subject=([^&]+)/i) || [])[1] || 'unsubscribe';
    try {
      const t = nodemailer.createTransport({
        host: 'smtp.gmail.com',
        port: 465,
        secure: true,
        auth: { user: GMAIL_USER, pass: GMAIL_APP_PASSWORD },
      });
      await t.sendMail({
        from: GMAIL_USER,
        to: m,
        subject: decodeURIComponent(subject),
        text: 'unsubscribe',
      });
      return { ok: true, status: 'mailto_sent', mailto: m };
    } catch (err) {
      return { ok: false, status: 'mailto_failed', error: err.message, mailto: m };
    }
  }

  if (hdr.urls?.length) {
    return {
      ok: false,
      status: 'url_only',
      urls: hdr.urls,
      error: 'Solo hay URL https para baja — sin browser no puedo clickear. Filtro auto-delete cubre el efecto.',
    };
  }

  return { ok: false, status: 'unparseable', raw: hdr.raw };
}

// ─────────── Sweep: move suppressed-sender emails to Trash ───────────

export async function sweepSuppressed({ limitPerSender = 50 } = {}) {
  if (!inboxCleanupEnabled()) return { ok: false, error: 'Gmail no configurado.', moved: 0 };
  const suppress = getSuppressList();
  if (!suppress.length) return { ok: true, moved: 0, scanned: 0 };
  const c = client();
  await c.connect();
  let totalMoved = 0;
  const perSender = {};
  try {
    const lock = await c.getMailboxLock('INBOX');
    try {
      for (const s of suppress) {
        try {
          const uids = await c.search({ from: s.email });
          if (!uids?.length) continue;
          const slice = uids.slice(-limitPerSender);
          await c.messageMove(slice, '[Gmail]/Trash');
          perSender[s.email] = slice.length;
          totalMoved += slice.length;
        } catch (err) {
          perSender[s.email] = `error: ${err.message}`;
        }
      }
    } finally {
      lock.release();
    }
  } finally {
    await c.logout();
  }
  return { ok: true, moved: totalMoved, per_sender: perSender, suppressed_count: suppress.length };
}

// CLI
if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  const cmd = process.argv[2];
  if (cmd === 'scan') {
    console.log(JSON.stringify(await scanNoisySenders({ days: 30, limit: 20 }), null, 2));
  } else if (cmd === 'sweep') {
    console.log(JSON.stringify(await sweepSuppressed(), null, 2));
  } else if (cmd === 'list') {
    console.log(JSON.stringify(getSuppressList(), null, 2));
  } else {
    console.error('Uso: node src/inbox_cleanup.js [scan|sweep|list]');
    process.exit(1);
  }
}
