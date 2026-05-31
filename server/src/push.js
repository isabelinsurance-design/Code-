// ============================================================
//  Web Push — Athena pinga el iPhone como notificación nativa
//  ────────────────────────────────────────────────────────────
//  Cuando el briefing 6:30am sale, además de WhatsApp, manda push.
//  El SW del PWA muestra la notificación incluso si Athena no está
//  abierta. Tap → abre la app en la sección relevante.
//
//  Setup (one-time):
//    1. Sami genera VAPID keys con: node src/push.js --generate-keys
//    2. Pone VAPID_PUBLIC_KEY + VAPID_PRIVATE_KEY + VAPID_SUBJECT
//       en Railway.
//    3. Isabel abre la app → click "Permitir notificaciones".
//
//  Subscriptions guardadas en data/push_subscriptions.json.
//  Auto-purge de las que devuelven 410/404.
// ============================================================
import webpush from 'web-push';
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'push_subscriptions.json');

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function loadSubs() {
  try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {}
  return [];
}
function saveSubs(subs) {
  ensureDir();
  writeFileSync(FILE, JSON.stringify(subs, null, 2));
}

let configured = false;
function configure() {
  if (configured) return true;
  const pub = process.env.VAPID_PUBLIC_KEY;
  const prv = process.env.VAPID_PRIVATE_KEY;
  const subject = process.env.VAPID_SUBJECT || 'mailto:isabel.insurance@gmail.com';
  if (!pub || !prv) return false;
  webpush.setVapidDetails(subject, pub, prv);
  configured = true;
  return true;
}

export function pushEnabled() {
  return !!(process.env.VAPID_PUBLIC_KEY && process.env.VAPID_PRIVATE_KEY);
}

export function getPublicKey() {
  return process.env.VAPID_PUBLIC_KEY || null;
}

export function subscribe(subscription, meta = {}) {
  if (!subscription || !subscription.endpoint) {
    return { ok: false, error: 'subscription inválido' };
  }
  const subs = loadSubs();
  // Si ya existe ese endpoint, actualiza meta y devuelve OK.
  const i = subs.findIndex((s) => s.endpoint === subscription.endpoint);
  if (i >= 0) {
    subs[i] = { ...subs[i], ...subscription, ...meta, updated: new Date().toISOString() };
  } else {
    subs.push({
      ...subscription,
      ...meta,
      created: new Date().toISOString(),
      updated: new Date().toISOString(),
    });
  }
  saveSubs(subs);
  return { ok: true, count: subs.length };
}

export function unsubscribe(endpoint) {
  const subs = loadSubs();
  const next = subs.filter((s) => s.endpoint !== endpoint);
  saveSubs(next);
  return { ok: true, removed: subs.length - next.length };
}

export function listSubs() { return loadSubs(); }

// Envía una notificación a todas las subscriptions activas.
// payload: { title, body, url?, tag?, icon? }
// Auto-purga las subscriptions caducas (410/404).
export async function sendToAll(payload) {
  if (!configure()) {
    return { ok: false, reason: 'VAPID keys no configuradas — push desactivado' };
  }
  const subs = loadSubs();
  if (!subs.length) return { ok: true, sent: 0, removed: 0 };
  const body = JSON.stringify({
    title: payload.title || 'Athena',
    body: payload.body || '',
    url: payload.url || '/app/hoy',
    tag: payload.tag || 'athena',
    icon: payload.icon || '/app/icon-192.png',
    badge: '/app/icon-192.png',
  });

  let sent = 0;
  const stillValid = [];
  for (const s of subs) {
    try {
      await webpush.sendNotification({
        endpoint: s.endpoint,
        keys: s.keys,
      }, body);
      sent++;
      stillValid.push(s);
    } catch (err) {
      const code = err?.statusCode;
      if (code === 404 || code === 410) {
        // Subscription caducó — no la guardamos
        continue;
      }
      // Otro error: la mantenemos para reintentar la próxima vez
      stillValid.push(s);
      console.warn('[push] error enviando:', code, err.body || err.message);
    }
  }
  if (stillValid.length !== subs.length) {
    saveSubs(stillValid);
  }
  return { ok: true, sent, removed: subs.length - stillValid.length };
}

// CLI: node src/push.js --generate-keys
if (import.meta.url === `file://${process.argv[1]}`) {
  if (process.argv.includes('--generate-keys')) {
    const keys = webpush.generateVAPIDKeys();
    console.log('Pega estas en Railway:');
    console.log(`\nVAPID_PUBLIC_KEY=${keys.publicKey}`);
    console.log(`VAPID_PRIVATE_KEY=${keys.privateKey}`);
    console.log(`VAPID_SUBJECT=mailto:isabel.insurance@gmail.com`);
    console.log('\nDespués isabel acepta el permiso en la app y los pings se activan.');
  } else {
    console.log('Uso: node src/push.js --generate-keys');
  }
}
