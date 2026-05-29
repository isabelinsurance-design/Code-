// ============================================================
//  Seguridad y endurecimiento del webhook
//  ────────────────────────────────────────
//  - Validación de firma de Twilio (rechaza requests no firmados)
//  - Idempotencia por MessageSid (Twilio reintenta — evitamos doble
//    proceso si la primera respuesta se atrasa)
//  - Rate limit en memoria (protege contra abuso si el URL se filtra)
//  - Redacción de PII para logs y audit trail
// ============================================================
import twilio from 'twilio';

// ---- 1) Validación de firma Twilio ----
// En prod debe estar ENCENDIDO siempre. Solo para desarrollo local
// (donde Twilio no te pega) se permite apagarlo.
export function twilioSignatureMiddleware(req, res, next) {
  const required = process.env.TWILIO_REQUIRE_SIGNATURE !== 'false';
  if (!required) return next();

  const token = process.env.TWILIO_AUTH_TOKEN;
  const publicUrl = process.env.PUBLIC_URL;
  if (!token || !publicUrl) {
    console.warn('[security] TWILIO_AUTH_TOKEN o PUBLIC_URL faltan — no puedo validar firmas. Rechazando por seguridad.');
    return res.status(503).send('Server misconfigured');
  }

  const signature = req.headers['x-twilio-signature'];
  if (!signature) {
    console.warn('[security] Request al webhook sin X-Twilio-Signature.');
    return res.status(403).send('Forbidden');
  }

  // Twilio firma la URL completa + parámetros del body (form-urlencoded).
  const fullUrl = `${publicUrl.replace(/\/+$/, '')}${req.originalUrl}`;
  const valid = twilio.validateRequest(token, signature, fullUrl, req.body);
  if (!valid) {
    console.warn(`[security] Firma Twilio inválida. url=${fullUrl}`);
    return res.status(403).send('Forbidden');
  }
  next();
}

// ---- 2) Idempotencia por MessageSid ----
// Twilio reintenta si el webhook tarda >15s o devuelve 5xx. Sin
// dedupe corremos el riesgo de duplicar emails/SMS de Athena.
// Mantenemos un Set de SIDs vistos con prune cada hora.
const seenSids = new Map(); // sid -> timestamp
const SID_TTL_MS = 24 * 3600_000;

export function checkAndMarkSid(sid) {
  if (!sid) return { duplicate: false };
  const now = Date.now();
  if (seenSids.has(sid)) {
    const seenAt = seenSids.get(sid);
    if (now - seenAt < SID_TTL_MS) return { duplicate: true, seenAt };
  }
  seenSids.set(sid, now);
  return { duplicate: false };
}

export function pruneSeenSids() {
  const now = Date.now();
  let removed = 0;
  for (const [sid, t] of seenSids) {
    if (now - t > SID_TTL_MS) {
      seenSids.delete(sid);
      removed += 1;
    }
  }
  return removed;
}

// ---- 3) Rate limit (sliding window, en memoria) ----
// 30 req/min por IP. Isabel sola jamás llegará a eso; un atacante
// con la URL filtrada lo notará al primer minuto.
const RATE_LIMIT_WINDOW_MS = 60_000;
const RATE_LIMIT_MAX = parseInt(process.env.RATE_LIMIT_PER_MIN || '30', 10);
const hits = new Map(); // ip -> timestamps[]

export function rateLimitMiddleware(req, res, next) {
  const ip = (req.headers['x-forwarded-for']?.split(',')[0].trim()) || req.ip || 'unknown';
  const now = Date.now();
  const arr = (hits.get(ip) || []).filter((t) => now - t < RATE_LIMIT_WINDOW_MS);
  arr.push(now);
  hits.set(ip, arr);
  if (arr.length > RATE_LIMIT_MAX) {
    console.warn(`[security] Rate limit excedido: ${ip} (${arr.length}/${RATE_LIMIT_MAX} en 1min).`);
    return res.status(429).send('Too Many Requests');
  }
  next();
}

// Prune del rate limit map (correr cada 5min para no inflar memoria).
export function pruneRateLimit() {
  const now = Date.now();
  for (const [ip, arr] of hits) {
    const fresh = arr.filter((t) => now - t < RATE_LIMIT_WINDOW_MS);
    if (!fresh.length) hits.delete(ip);
    else hits.set(ip, fresh);
  }
}

// ---- 4) Redacción de PII ----
// Para logs, console output y audit trail. NO modifica el contenido
// que va al LLM — solo lo que se persiste o imprime.
//
// ORDEN IMPORTA: corremos teléfono primero (consume strings de dígitos
// puros) y MBI al final con un guard que exige al menos UNA letra,
// porque la spec CMS pone letras en posiciones fijas (2, 5, 8, 9) y
// así no atrapamos números de teléfono o cuentas bancarias.
export function redactPII(input) {
  if (input == null) return input;
  let s = typeof input === 'string' ? input : JSON.stringify(input);

  // 1) Email
  s = s.replace(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/gi, '[email-redactado]');
  // 2) SSN US (formato exacto XXX-XX-XXXX)
  s = s.replace(/\b\d{3}-\d{2}-\d{4}\b/g, '[ssn-redactado]');
  // 3) Teléfono (US/MX, con o sin formato). Se hace ANTES que MBI.
  s = s.replace(/\+?\d{0,2}[\s.-]?\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}\b/g, '[tel-redactado]');
  // 4) MBI Medicare (11 alfanuméricos, requiere ≥1 letra para no
  //    confundirse con números restantes). Aceptamos opcionalmente
  //    guiones tipo XXXX-XXX-XXXX o XXXX-XXXX-XXX.
  s = s.replace(/\b[A-Z0-9]{4}-?[A-Z0-9]{3,4}-?[A-Z0-9]{3,4}\b/g, (m) => {
    const compact = m.replace(/-/g, '');
    if (compact.length !== 11) return m;
    if (!/[A-Z]/.test(compact)) return m; // solo dígitos → no es MBI
    return '[mbi-redactado]';
  });

  return s;
}

// Wrapper para console.log/warn/error que redacta antes de imprimir.
// Úsalo en lugar de console.* en módulos que manejan datos de clientes.
export const safeLog = {
  log: (...args) => console.log(...args.map((a) => (typeof a === 'string' ? redactPII(a) : a))),
  warn: (...args) => console.warn(...args.map((a) => (typeof a === 'string' ? redactPII(a) : a))),
  error: (...args) => console.error(...args.map((a) => (typeof a === 'string' ? redactPII(a) : a))),
};
