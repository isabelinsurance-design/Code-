// ============================================================
//  Instagram Graph API (Business / Creator)
//  ─────────────────────────────────────────
//  Visibilidad — NO acción autónoma. Athena puede leer:
//    - DMs (conversaciones) y identificar cuáles esperan respuesta
//    - Comentarios en posts recientes
//    - Stats básicos (followers, posts)
//
//  Requisitos:
//    IG_ACCESS_TOKEN     — long-lived token de tu app de Meta
//    IG_USER_ID          — el numeric ID de tu cuenta Business/Creator
//                          (NO el username)
//
//  Tu cuenta @withisabelfuentes debe ser Business o Creator y estar
//  vinculada a una Facebook Page. Convertir es gratis y toma 60s.
//  Para responder DMs (no implementado todavía aquí — Phase 6+),
//  necesitas App Review para instagram_manage_messages.
// ============================================================

const FB_BASE = 'https://graph.facebook.com/v21.0';
const TOKEN = process.env.IG_ACCESS_TOKEN;
const IG_USER_ID = process.env.IG_USER_ID;

export function instagramConfigured() {
  return Boolean(TOKEN && IG_USER_ID);
}

async function fb(path, params = {}) {
  const url = new URL(`${FB_BASE}/${path}`);
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null) url.searchParams.set(k, String(v));
  }
  url.searchParams.set('access_token', TOKEN);
  const r = await fetch(url);
  if (!r.ok) {
    const txt = await r.text().catch(() => '');
    throw new Error(`IG ${r.status}: ${txt.slice(0, 200)}`);
  }
  return r.json();
}

// ---- DMs / Conversations ----
export async function listConversations({ limit = 25 } = {}) {
  if (!instagramConfigured()) return { ok: false, reason: 'Instagram no configurado (faltan IG_ACCESS_TOKEN, IG_USER_ID).', items: [] };
  try {
    const data = await fb(`${IG_USER_ID}/conversations`, {
      platform: 'instagram',
      limit,
      fields: 'participants,updated_time,messages.limit(1){message,from,created_time}',
    });
    const items = (data.data || []).map(normalizeConversation).filter(Boolean);
    return { ok: true, items };
  } catch (err) {
    return { ok: false, reason: err.message, items: [] };
  }
}

function normalizeConversation(c) {
  const lastMsg = c.messages?.data?.[0];
  if (!lastMsg) return null;
  const other = (c.participants?.data || []).find((p) => String(p.id) !== String(IG_USER_ID));
  return {
    id: c.id,
    interlocutor: other?.username || other?.name || 'desconocido',
    interlocutor_id: other?.id,
    ultimo_mensaje: lastMsg.message,
    ultimo_at: lastMsg.created_time,
    ultimo_de_isabel: String(lastMsg.from?.id) === String(IG_USER_ID),
    actualizado: c.updated_time,
  };
}

// Devuelve DMs donde el ÚLTIMO mensaje NO es de Isabel
// (i.e., personas esperando que ella responda).
export async function pendingDms({ limit = 50 } = {}) {
  const r = await listConversations({ limit });
  if (!r.ok) return r;
  const items = r.items
    .filter((c) => !c.ultimo_de_isabel)
    .sort((a, b) => new Date(a.ultimo_at).getTime() - new Date(b.ultimo_at).getTime());
  return { ok: true, items };
}

// ---- Comentarios recientes en posts ----
export async function recentComments({ postsToScan = 10, limit = 25 } = {}) {
  if (!instagramConfigured()) return { ok: false, reason: 'Instagram no configurado.', items: [] };
  try {
    const media = await fb(`${IG_USER_ID}/media`, {
      fields: 'id,caption,timestamp,permalink',
      limit: postsToScan,
    });
    const items = [];
    for (const m of media.data || []) {
      const c = await fb(`${m.id}/comments`, {
        fields: 'id,text,from,timestamp,replies{id,from}',
        limit: 10,
      });
      for (const com of c.data || []) {
        items.push({
          id: com.id,
          texto: com.text,
          de: com.from?.username || com.from?.id || 'desconocido',
          cuando: com.timestamp,
          post_caption: (m.caption || '').slice(0, 60),
          post_link: m.permalink,
          tiene_respuestas: Boolean(com.replies?.data?.length),
        });
      }
    }
    items.sort((a, b) => new Date(b.cuando).getTime() - new Date(a.cuando).getTime());
    return { ok: true, items: items.slice(0, limit) };
  } catch (err) {
    return { ok: false, reason: err.message, items: [] };
  }
}

// Comentarios sin respuesta de Isabel — heurística: no_responses + de ≠ ella.
export async function pendingComments({ postsToScan = 10 } = {}) {
  const r = await recentComments({ postsToScan, limit: 50 });
  if (!r.ok) return r;
  const items = r.items.filter((c) => !c.tiene_respuestas);
  return { ok: true, items };
}

// ---- Insights / snapshot ----
export async function snapshot() {
  if (!instagramConfigured()) return { ok: false, reason: 'Instagram no configurado.' };
  try {
    const me = await fb(IG_USER_ID, {
      fields: 'username,followers_count,follows_count,media_count',
    });
    return { ok: true, snapshot: me };
  } catch (err) {
    return { ok: false, reason: err.message };
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  console.log('IG configured:', instagramConfigured());
  if (instagramConfigured()) {
    console.log('Pendientes DMs:');
    console.log(JSON.stringify(await pendingDms(), null, 2));
  }
  process.exit(0);
}
