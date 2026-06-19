// ============================================================
//  travel.js — búsqueda de vuelos con precios reales (Amadeus)
//  ────────────────────────────────────────────────────────────
//  Amadeus for Developers (amadeus.com/en/developers). Self-Service.
//  Config:
//    AMADEUS_API_KEY, AMADEUS_API_SECRET   (de tu app en Amadeus)
//    AMADEUS_HOST   (opcional) — 'test.api.amadeus.com' por default
//                   (datos de PRUEBA, no 100% reales). Para precios
//                   reales: 'api.amadeus.com' (requiere app aprobada).
//
//  Si las llaves no están, travelConfigured() = false y la tool
//  devuelve un mensaje claro de "no configurado" sin romper.
//
//  SOLO BÚSQUEDA (read-only). La reserva (que cobra una tarjeta) NO se
//  implementa aquí a propósito — ver nota en el spec / handoff.
// ============================================================

const HOST = process.env.AMADEUS_HOST || 'test.api.amadeus.com';
const BASE = `https://${HOST}`;
const TIMEOUT_MS = 15_000;

export function travelConfigured() {
  return Boolean(process.env.AMADEUS_API_KEY && process.env.AMADEUS_API_SECRET);
}

let _token = null; // { value, expiresAt }
async function getToken() {
  if (_token && Date.now() < _token.expiresAt - 30_000) return _token.value;
  const body = new URLSearchParams({
    grant_type: 'client_credentials',
    client_id: process.env.AMADEUS_API_KEY,
    client_secret: process.env.AMADEUS_API_SECRET,
  });
  const res = await fetch(`${BASE}/v1/security/oauth2/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body,
    signal: AbortSignal.timeout(TIMEOUT_MS),
  });
  if (!res.ok) throw new Error(`Amadeus auth HTTP ${res.status}`);
  const j = await res.json();
  _token = { value: j.access_token, expiresAt: Date.now() + (j.expires_in || 1799) * 1000 };
  return _token.value;
}

// "PT12H30M" → "12h 30m"
export function prettyDuration(iso) {
  if (!iso) return '';
  const m = String(iso).match(/PT(?:(\d+)H)?(?:(\d+)M)?/);
  if (!m) return String(iso);
  return [m[1] ? `${m[1]}h` : '', m[2] ? `${m[2]}m` : ''].filter(Boolean).join(' ');
}

// PURA (testeable): respuesta cruda de Amadeus → lista limpia, ordenada por precio.
export function parseFlightOffers(json, { limit = 8 } = {}) {
  const offers = (json && json.data) || [];
  const carriers = (json && json.dictionaries && json.dictionaries.carriers) || {};
  return offers
    .map((o) => {
      const legs = (o.itineraries || []).map((it) => {
        const segs = it.segments || [];
        const first = segs[0]?.departure || {};
        const last = segs[segs.length - 1]?.arrival || {};
        return {
          from: first.iataCode || '',
          to: last.iataCode || '',
          depart: first.at || '',
          arrive: last.at || '',
          stops: Math.max(0, segs.length - 1),
          duration: prettyDuration(it.duration),
          airlines: [...new Set(segs.map((s) => carriers[s.carrierCode] || s.carrierCode).filter(Boolean))],
        };
      });
      return {
        price: Number(o.price?.grandTotal || o.price?.total || 0),
        currency: o.price?.currency || 'USD',
        legs,
      };
    })
    .sort((a, b) => a.price - b.price)
    .slice(0, limit);
}

export async function searchFlights({ origin, destination, date, adults = 1, cabin = 'ECONOMY', currency = 'USD', max = 10 }) {
  if (!travelConfigured()) {
    return { ok: false, error: 'Amadeus no está configurado (faltan AMADEUS_API_KEY / AMADEUS_API_SECRET en Railway).', kind: 'not_configured' };
  }
  if (!origin || !destination || !date) return { ok: false, error: 'Faltan origen, destino o fecha.' };
  try {
    const token = await getToken();
    const params = new URLSearchParams({
      originLocationCode: String(origin).toUpperCase().trim(),
      destinationLocationCode: String(destination).toUpperCase().trim(),
      departureDate: date,
      adults: String(adults),
      travelClass: String(cabin || 'ECONOMY').toUpperCase(),
      currencyCode: currency,
      max: String(max),
    });
    const res = await fetch(`${BASE}/v2/shopping/flight-offers?${params}`, {
      headers: { Authorization: `Bearer ${token}` },
      signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    const j = await res.json().catch(() => ({}));
    if (!res.ok) {
      const detail = j?.errors?.[0]?.detail || j?.error_description || `HTTP ${res.status}`;
      return { ok: false, error: `Amadeus: ${detail}`, status: res.status };
    }
    return { ok: true, flights: parseFlightOffers(j, { limit: 8 }), host: HOST };
  } catch (e) {
    const msg = e.name === 'TimeoutError' || e.name === 'AbortError' ? 'Amadeus tardó demasiado (timeout).' : e.message;
    return { ok: false, error: msg };
  }
}

// Formato humano para el tool output.
export function formatFlights(flights, { origin, destination, date, host } = {}) {
  if (!flights || !flights.length) return `No encontré vuelos ${origin || ''}→${destination || ''} para ${date || 'esa fecha'}.`;
  const lines = flights.map((f, i) => {
    const leg = f.legs[0] || {};
    const stops = leg.stops === 0 ? 'directo' : `${leg.stops} escala${leg.stops > 1 ? 's' : ''}`;
    const air = (leg.airlines || []).join('/') || '?';
    const dep = (leg.depart || '').slice(11, 16);
    return `${i + 1}. $${f.price} ${f.currency} — ${air} · ${stops} · ${leg.duration}${dep ? ` · sale ${dep}` : ''}`;
  });
  const nota = host && host.includes('test')
    ? '\n\n⚠️ Estos son datos de PRUEBA de Amadeus. Para precios 100% reales hace falta activar el entorno de producción de Amadeus.'
    : '';
  return `Vuelos ${origin}→${destination} · ${date} (del más barato):\n${lines.join('\n')}${nota}`;
}
