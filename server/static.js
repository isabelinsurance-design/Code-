// Servidor de archivos estaticos minimo.
//   /            -> app/samia.html     (la escuela: app de entrenamiento)
//   /dashboard   -> app/dashboard.html (panel de operacion)
// Reemplaza el rol de serve.sh (python http.server) y ademas expone /api.

import { readFile, stat } from 'node:fs/promises';
import { resolve, normalize, extname, join } from 'node:path';
import { REPO_ROOT } from './config.js';

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.ico': 'image/x-icon',
};

export async function serveStatic(req, res) {
  let urlPath = decodeURIComponent(new URL(req.url, 'http://x').pathname);
  if (urlPath === '/') urlPath = '/app/samia.html';
  else if (urlPath === '/dashboard' || urlPath === '/dashboard/') urlPath = '/app/dashboard.html';

  // Anti path-traversal: resolver dentro de REPO_ROOT.
  const target = normalize(join(REPO_ROOT, urlPath));
  if (!target.startsWith(REPO_ROOT)) {
    res.writeHead(403).end('Forbidden');
    return true;
  }
  // No servir el backend ni secretos por estatico.
  if (target.startsWith(resolve(REPO_ROOT, 'server')) || target.includes('.env') || target.startsWith(resolve(REPO_ROOT, 'data'))) {
    res.writeHead(404).end('Not found');
    return true;
  }

  try {
    const s = await stat(target);
    if (s.isDirectory()) {
      res.writeHead(404).end('Not found');
      return true;
    }
    const body = await readFile(target);
    res.writeHead(200, { 'Content-Type': TYPES[extname(target)] || 'application/octet-stream' });
    res.end(body);
    return true;
  } catch {
    res.writeHead(404, { 'Content-Type': 'text/plain' }).end('Not found');
    return true;
  }
}
