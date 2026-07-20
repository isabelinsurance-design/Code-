/**
 * ═══════════════════════════════════════════════════════════════════
 *  CRM WS-RELAY — avisos en vivo para Medicare with Isabel CRM
 * ─────────────────────────────────────────────────────────────────
 *  Qué hace: mantiene abierta una conexión con cada navegador que tiene
 *  el CRM abierto. Cuando alguien guarda algo (PHP hace un POST aquí a
 *  /broadcast), este servidor le avisa AL INSTANTE a todos los demás
 *  navegadores conectados, y ellos disparan su propio softReload() para
 *  traer los datos reales desde el CRM (PHP + MySQL, en Bluehost).
 *
 *  Importante: este servidor NUNCA ve datos reales del CRM (nombres,
 *  SSN, MBI, nada). Solo reenvía una señal minúscula tipo "cambió algo
 *  en TICKETS" — el propio navegador va a buscar los datos reales,
 *  autenticado, directo al CRM de siempre. Si este servidor se cae,
 *  el CRM sigue funcionando normal (con el refresco automático cada
 *  cierto tiempo como respaldo).
 * ═══════════════════════════════════════════════════════════════════
 */
const http = require('http');
const { WebSocketServer } = require('ws');

const PORT   = process.env.PORT || 3000;
const SECRET = process.env.RELAY_SECRET || '';

if (!SECRET) {
  console.warn('⚠️  RELAY_SECRET no está configurado — el servidor rechazará TODAS las conexiones.');
  console.warn('    Configúralo en Railway → Variables → RELAY_SECRET.');
}

function readBody(req, maxBytes = 1024 * 50) {
  return new Promise((resolve, reject) => {
    let body = '';
    let size = 0;
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > maxBytes) { reject(new Error('body demasiado grande')); req.destroy(); return; }
      body += chunk;
    });
    req.on('end', () => resolve(body));
    req.on('error', reject);
  });
}

const server = http.createServer(async (req, res) => {
  // CORS: el CRM llama desde withisabelfuentes.com (navegador→relay, WS aparte)
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Relay-Secret');
  res.setHeader('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
  if (req.method === 'OPTIONS') { res.writeHead(204); res.end(); return; }

  if (req.method === 'GET' && req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: true, clients: wss.clients.size }));
    return;
  }

  if (req.method === 'POST' && req.url === '/broadcast') {
    try {
      const auth = req.headers['x-relay-secret'];
      if (!SECRET || auth !== SECRET) {
        res.writeHead(401, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: 'unauthorized' }));
        return;
      }
      const raw = await readBody(req);
      let payload = {};
      try { payload = raw ? JSON.parse(raw) : {}; } catch (e) { /* body vacío/no-JSON: seguir con {} */ }

      const msg = JSON.stringify({
        type: 'changed',
        tab: typeof payload.tab === 'string' ? payload.tab.slice(0, 40) : null,
        at: Date.now(),
      });

      let sent = 0;
      wss.clients.forEach((client) => {
        if (client.readyState === 1 /* OPEN */) { client.send(msg); sent++; }
      });

      res.writeHead(200, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: true, sent }));
    } catch (e) {
      res.writeHead(400, { 'Content-Type': 'application/json' });
      res.end(JSON.stringify({ ok: false, error: 'bad request' }));
    }
    return;
  }

  res.writeHead(404, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify({ ok: false, error: 'not found' }));
});

const wss = new WebSocketServer({ server, path: '/ws' });

wss.on('connection', (ws, req) => {
  try {
    const url = new URL(req.url, 'http://relay.local');
    const token = url.searchParams.get('token');
    if (!SECRET || token !== SECRET) {
      ws.close(4001, 'unauthorized');
      return;
    }
  } catch (e) {
    ws.close(4000, 'bad request');
    return;
  }
  ws.isAlive = true;
  ws.on('pong', () => { ws.isAlive = true; });
  ws.on('error', () => {}); // evitar que un socket roto tumbe el proceso
});

// Keepalive: cada 30s hace ping a cada cliente; si uno no responde en la
// siguiente ronda, se considera muerto y se cierra (evita que proxies
// intermedios corten conexiones "inactivas" y que se acumulen sockets zombis).
const KEEPALIVE_MS = 30000;
setInterval(() => {
  wss.clients.forEach((ws) => {
    if (ws.isAlive === false) { ws.terminate(); return; }
    ws.isAlive = false;
    try { ws.ping(); } catch (e) {}
  });
}, KEEPALIVE_MS);

server.listen(PORT, () => {
  console.log(`✓ CRM WS-Relay escuchando en el puerto ${PORT} (WS en /ws, avisos en POST /broadcast)`);
});
