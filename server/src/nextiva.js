// ============================================================
//  Nextiva — visibilidad de SMS de negocio
//  ─────────────────────────────────────────
//  Objetivo principal: contestar "qué clientes están esperando
//  respuesta mía". Athena lee los hilos, ordena por última
//  actividad, marca los que tienen un mensaje entrante más
//  reciente que la última respuesta saliente, y los reporta.
//
//  La API exacta de Nextiva varía por plan (Communications API).
//  Para que esto sea fácil de conectar:
//    - Configuras NEXTIVA_BASE_URL (default api.nextiva.com)
//    - Configuras NEXTIVA_API_KEY (Bearer token / API key del
//      portal Nextiva).
//    - Opcional: NEXTIVA_ACCOUNT_ID si tu cuenta requiere header
//      X-Account-Id.
//  Si tu endpoint exacto difiere, ajusta listThreads() abajo —
//  todo el resto de la lógica vive arriba de esa abstracción.
// ============================================================

const BASE = process.env.NEXTIVA_BASE_URL || 'https://api.nextiva.com';
const KEY = process.env.NEXTIVA_API_KEY;
const ACCOUNT = process.env.NEXTIVA_ACCOUNT_ID;

export function nextivaConfigured() {
  return Boolean(KEY);
}

function headers() {
  const h = {
    Authorization: `Bearer ${KEY}`,
    Accept: 'application/json',
  };
  if (ACCOUNT) h['X-Account-Id'] = ACCOUNT;
  return h;
}

// Trae los hilos SMS recientes con los últimos N mensajes de cada uno.
// Devuelve estructura normalizada que NO depende de la shape de Nextiva.
//
// Ajusta el path "messaging/v1/threads" si tu cuenta usa otro
// — la documentación oficial de Nextiva Communications API la
// indica en el portal de developers.
async function listThreads({ limit = 50, sinceHours = 168 } = {}) {
  const since = new Date(Date.now() - sinceHours * 3600_000).toISOString();
  const url = `${BASE}/messaging/v1/threads?limit=${limit}&since=${encodeURIComponent(since)}`;
  const r = await fetch(url, { headers: headers() });
  if (!r.ok) {
    const txt = await r.text().catch(() => '');
    throw new Error(`Nextiva ${r.status}: ${txt.slice(0, 200)}`);
  }
  const data = await r.json();
  // Normalización tolerante a varias formas de respuesta
  const raw = data.threads || data.data || data.items || data;
  if (!Array.isArray(raw)) return [];
  return raw.map(normalizeThread).filter(Boolean);
}

function normalizeThread(t) {
  if (!t || typeof t !== 'object') return null;
  const messages = (t.messages || t.last_messages || []).map((m) => ({
    direction: (m.direction || m.dir || '').toLowerCase(), // 'inbound'|'outbound'
    body: m.body || m.text || m.message || '',
    at: m.created_at || m.timestamp || m.date || null,
    from: m.from || m.from_number || '',
    to: m.to || m.to_number || '',
  })).filter((m) => m.at);
  const contact = t.contact || t.participant || {};
  const lastAt = messages.length
    ? new Date(messages.map((m) => new Date(m.at).getTime()).sort((a, b) => b - a)[0]).toISOString()
    : t.updated_at || t.last_message_at || null;
  return {
    id: t.id || t.thread_id || t.uuid,
    contact_name: contact.name || t.contact_name || '',
    contact_phone: contact.phone || t.phone || t.number || '',
    messages,
    last_at: lastAt,
    last_direction: messages.length ? messages.sort((a, b) => new Date(b.at).getTime() - new Date(a.at).getTime())[0].direction : null,
  };
}

// "Quién está esperando respuesta": hilo donde el último mensaje es
// entrante (inbound). Por antigüedad descendente.
export async function pendingResponses({ sinceHours = 168 } = {}) {
  if (!nextivaConfigured()) {
    return { ok: false, reason: 'Nextiva no está configurado (falta NEXTIVA_API_KEY).', items: [] };
  }
  try {
    const threads = await listThreads({ limit: 100, sinceHours });
    const items = threads
      .filter((t) => t.last_direction === 'inbound')
      .sort((a, b) => new Date(a.last_at).getTime() - new Date(b.last_at).getTime());
    return { ok: true, items };
  } catch (err) {
    return { ok: false, reason: err.message, items: [] };
  }
}

export async function recentActivity({ sinceHours = 24, limit = 30 } = {}) {
  if (!nextivaConfigured()) {
    return { ok: false, reason: 'Nextiva no está configurado.', items: [] };
  }
  try {
    const threads = await listThreads({ limit, sinceHours });
    return { ok: true, items: threads };
  } catch (err) {
    return { ok: false, reason: err.message, items: [] };
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  console.log('Nextiva configured:', nextivaConfigured());
  if (nextivaConfigured()) {
    console.log(JSON.stringify(await pendingResponses(), null, 2));
  }
  process.exit(0);
}
