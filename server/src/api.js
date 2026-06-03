// ============================================================
//  API REST para la web app / PWA (app-v2/)
//  ───────────────────────────────────────────
//  Auth: sesión por cookie firmada (HMAC-SHA256). Sin DB.
//  Password único en env APP_PASSWORD.
//  Cookie dura 30 días. Renueva en cada request.
//
//  Endpoints:
//    POST /api/login    { password }
//    POST /api/logout
//    GET  /api/me
//    GET  /api/hoy      → snapshot del día
//    + CRUDs de cada módulo (rutinas, focus, research, legal, …)
// ============================================================
import crypto from 'node:crypto';

const COOKIE_NAME = 'athena_session';
const SESSION_TTL_MS = 30 * 24 * 60 * 60 * 1000; // 30 días

function getSecret() {
  return process.env.APP_SECRET || process.env.SESSION_SECRET || 'dev-only-secret-NOT-for-prod';
}

function signSession(payload) {
  const body = Buffer.from(JSON.stringify(payload), 'utf8').toString('base64url');
  const sig = crypto.createHmac('sha256', getSecret()).update(body).digest('base64url');
  return `${body}.${sig}`;
}

function verifySession(cookie) {
  if (!cookie || typeof cookie !== 'string') return null;
  const [body, sig] = cookie.split('.');
  if (!body || !sig) return null;
  const expected = crypto.createHmac('sha256', getSecret()).update(body).digest('base64url');
  const sigBuf = Buffer.from(sig);
  const expBuf = Buffer.from(expected);
  // timingSafeEqual lanza si los buffers difieren en longitud — un cookie
  // manipulado o truncado tronaría requireAuth con 500 en vez de 401.
  if (sigBuf.length !== expBuf.length) return null;
  if (!crypto.timingSafeEqual(sigBuf, expBuf)) return null;
  try {
    const payload = JSON.parse(Buffer.from(body, 'base64url').toString('utf8'));
    if (payload.exp && Date.now() > payload.exp) return null;
    return payload;
  } catch { return null; }
}

function parseCookies(header) {
  if (!header) return {};
  return Object.fromEntries(
    header.split(';').map((c) => {
      const idx = c.indexOf('=');
      if (idx < 0) return [c.trim(), ''];
      return [c.slice(0, idx).trim(), decodeURIComponent(c.slice(idx + 1).trim())];
    })
  );
}

function setSessionCookie(res, payload) {
  const token = signSession(payload);
  const secure = process.env.NODE_ENV === 'production' ? '; Secure' : '';
  res.setHeader('Set-Cookie',
    `${COOKIE_NAME}=${encodeURIComponent(token)}; HttpOnly; SameSite=Lax; Path=/; Max-Age=${Math.floor(SESSION_TTL_MS / 1000)}${secure}`
  );
}

function clearSessionCookie(res) {
  res.setHeader('Set-Cookie', `${COOKIE_NAME}=; HttpOnly; SameSite=Lax; Path=/; Max-Age=0`);
}

function requireAuth(req, res, next) {
  const cookies = parseCookies(req.headers.cookie);
  const sess = verifySession(cookies[COOKIE_NAME]);
  if (!sess) {
    res.status(401).json({ error: 'unauthorized' });
    return;
  }
  req.session = sess;
  // Renueva el cookie en cada request
  setSessionCookie(res, { ...sess, exp: Date.now() + SESSION_TTL_MS });
  next();
}

// ---- Construye el snapshot de la pantalla "Hoy" ----
async function buildHoyState() {
  const fecha = new Date().toLocaleDateString('es-MX', {
    weekday: 'long', day: 'numeric', month: 'long',
    timeZone: process.env.TIMEZONE || 'America/Los_Angeles',
  });

  const state = { fecha };

  try {
    const { computeTrustScore } = await import('./trust_score.js');
    state.trust = computeTrustScore();
  } catch { /* ignore */ }

  try {
    const { bloquesDeHoy } = await import('./focus_blocks.js');
    state.focus_blocks = bloquesDeHoy();
  } catch { state.focus_blocks = []; }

  try {
    const { rutinasDeHoy, progresoHoy } = await import('./routines.js');
    state.routines = rutinasDeHoy().map((r) => ({
      ...r,
      done: progresoHoy(r.id).filter((c) => c.accion === 'completado').length,
    }));
  } catch { state.routines = []; }

  try {
    const { alertasActivas } = await import('./legal.js');
    state.legal_alerts = alertasActivas();
  } catch { state.legal_alerts = null; }

  return state;
}

// ---- Registra todos los endpoints en una app Express ----
export function registerApi(app) {
  // Login
  app.post('/api/login', (req, res) => {
    const expected = process.env.APP_PASSWORD;
    if (!expected) {
      res.status(503).json({ error: 'APP_PASSWORD no configurado' });
      return;
    }
    const provided = (req.body && req.body.password) || '';
    if (!provided || provided !== expected) {
      res.status(401).json({ error: 'password incorrecto' });
      return;
    }
    setSessionCookie(res, { user: 'isabel', exp: Date.now() + SESSION_TTL_MS });
    res.json({ user: 'isabel' });
  });

  app.post('/api/logout', (_req, res) => {
    clearSessionCookie(res);
    res.json({ ok: true });
  });

  app.get('/api/me', (req, res) => {
    const cookies = parseCookies(req.headers.cookie);
    const sess = verifySession(cookies[COOKIE_NAME]);
    if (!sess) return res.status(401).json({ error: 'unauthorized' });
    res.json({ user: sess.user });
  });

  // ---- Todo lo demás requiere auth ----

  app.get('/api/hoy', requireAuth, async (_req, res) => {
    try {
      const state = await buildHoyState();
      res.json(state);
    } catch (e) {
      res.status(500).json({ error: e.message });
    }
  });

  // Rutinas
  app.get('/api/routines', requireAuth, async (_req, res) => {
    const { listarRutinas } = await import('./routines.js');
    res.json(listarRutinas({ activas_solo: false }));
  });
  app.post('/api/routines', requireAuth, async (req, res) => {
    const { crearRutina } = await import('./routines.js');
    res.json(crearRutina(req.body || {}));
  });
  app.post('/api/routines/:id/deactivate', requireAuth, async (req, res) => {
    const { desactivarRutina } = await import('./routines.js');
    res.json(desactivarRutina(req.params.id));
  });
  app.post('/api/routines/:id/step', requireAuth, async (req, res) => {
    const { registrarPaso } = await import('./routines.js');
    res.json(registrarPaso({ rutina_id: req.params.id, ...(req.body || {}) }));
  });

  // Focus blocks
  app.get('/api/focus', requireAuth, async (_req, res) => {
    const { listarBloques } = await import('./focus_blocks.js');
    res.json(listarBloques({ activos_solo: false }));
  });
  app.post('/api/focus', requireAuth, async (req, res) => {
    const { crearBloque } = await import('./focus_blocks.js');
    res.json(crearBloque(req.body || {}));
  });
  app.post('/api/focus/:id/deactivate', requireAuth, async (req, res) => {
    const { desactivarBloque } = await import('./focus_blocks.js');
    res.json(desactivarBloque(req.params.id));
  });

  // Research
  app.get('/api/research', requireAuth, async (_req, res) => {
    const { listarTemas } = await import('./research.js');
    res.json(listarTemas({ activos_solo: false }));
  });
  app.post('/api/research', requireAuth, async (req, res) => {
    const { crearTema } = await import('./research.js');
    res.json(crearTema(req.body || {}));
  });
  app.post('/api/research/:id/pause', requireAuth, async (req, res) => {
    const { pausarTema } = await import('./research.js');
    res.json(pausarTema(req.params.id));
  });
  app.delete('/api/research/:id', requireAuth, async (req, res) => {
    const { eliminarTema } = await import('./research.js');
    res.json(eliminarTema(req.params.id));
  });
  app.post('/api/research/seed', requireAuth, async (_req, res) => {
    const { seedDefaultTopics } = await import('./research.js');
    res.json(seedDefaultTopics());
  });

  // Improvements
  app.get('/api/improvements', requireAuth, async (req, res) => {
    const { listImprovements } = await import('./improvements.js');
    res.json(listImprovements({ status: req.query.status || null }));
  });
  app.post('/api/improvements/:id/status', requireAuth, async (req, res) => {
    const { updateImprovementStatus } = await import('./improvements.js');
    res.json(updateImprovementStatus(req.params.id, (req.body || {}).status));
  });

  // Legal
  app.get('/api/legal', requireAuth, async (_req, res) => {
    const { listarObligaciones, alertasActivas } = await import('./legal.js');
    res.json({ obligaciones: listarObligaciones(), alertas: alertasActivas() });
  });
  app.post('/api/legal', requireAuth, async (req, res) => {
    const { registrarObligacion } = await import('./legal.js');
    res.json(registrarObligacion(req.body || {}));
  });
  app.post('/api/legal/:id/complete', requireAuth, async (req, res) => {
    const { marcarCumplida } = await import('./legal.js');
    res.json(marcarCumplida(req.params.id, (req.body || {}).evidencia || ''));
  });

  // Coach cadence — citas programadas con coaches
  app.get('/api/coach-cadence', requireAuth, async (_req, res) => {
    const { listCadences } = await import('./coach_cadence.js');
    res.json(listCadences({ activas_solo: false }));
  });
  app.get('/api/coach-cadence/today', requireAuth, async (_req, res) => {
    const { cadenciasDeHoy } = await import('./coach_cadence.js');
    res.json(cadenciasDeHoy());
  });
  app.post('/api/coach-cadence', requireAuth, async (req, res) => {
    const { setCadence } = await import('./coach_cadence.js');
    res.json(setCadence(req.body || {}));
  });
  app.post('/api/coach-cadence/:coach/pause', requireAuth, async (req, res) => {
    const { pauseCadence } = await import('./coach_cadence.js');
    res.json(pauseCadence(req.params.coach));
  });
  app.delete('/api/coach-cadence/:coach', requireAuth, async (req, res) => {
    const { removeCadence } = await import('./coach_cadence.js');
    res.json({ ok: removeCadence(req.params.coach) });
  });
  app.post('/api/coach-cadence/seed', requireAuth, async (_req, res) => {
    const { seedDefaultCadences } = await import('./coach_cadence.js');
    res.json(seedDefaultCadences());
  });
  app.post('/api/coach-cadence/:coach/check-in', requireAuth, async (req, res) => {
    const { registrarCheckIn } = await import('./coach_cadence.js');
    res.json(registrarCheckIn({ coach: req.params.coach, ...(req.body || {}) }));
  });
  app.get('/api/coach-cadence/:coach/prompt', requireAuth, async (req, res) => {
    const { promptInicialPara } = await import('./coach_cadence.js');
    res.json({ prompt: promptInicialPara(req.params.coach) });
  });

  // Perfect Week template — pattern Elite EA SOP
  app.get('/api/perfect-week', requireAuth, async (_req, res) => {
    const { getPerfectWeek } = await import('./perfect_week.js');
    res.json(getPerfectWeek());
  });
  app.put('/api/perfect-week', requireAuth, async (req, res) => {
    const { updatePerfectWeek } = await import('./perfect_week.js');
    res.json(updatePerfectWeek(req.body || {}));
  });
  app.post('/api/perfect-week/reset', requireAuth, async (_req, res) => {
    const { resetToDefault } = await import('./perfect_week.js');
    res.json(resetToDefault());
  });

  // Closing the loop — EOD report
  app.get('/api/closing-loop', requireAuth, async (_req, res) => {
    const { computeClosingLoop } = await import('./closing_loop.js');
    res.json(computeClosingLoop());
  });

  // Calendar (Google Calendar - WRITE habilitado)
  app.get('/api/calendar/status', requireAuth, async (_req, res) => {
    const { calendarConfigured } = await import('./calendar.js');
    res.json({ configured: calendarConfigured() });
  });
  app.get('/api/calendar/upcoming', requireAuth, async (req, res) => {
    try {
      const { listUpcomingEvents } = await import('./calendar.js');
      const events = await listUpcomingEvents({
        withinHours: parseInt(req.query.hours || '168', 10), // default 7 días
        limit: parseInt(req.query.limit || '25', 10),
      });
      res.json(events);
    } catch (e) {
      res.status(500).json({ error: e.message });
    }
  });
  app.post('/api/calendar/event', requireAuth, async (req, res) => {
    try {
      const { createEvent } = await import('./calendar.js');
      const e = await createEvent(req.body || {});
      res.json(e);
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });
  app.patch('/api/calendar/event/:id', requireAuth, async (req, res) => {
    try {
      const { updateEvent } = await import('./calendar.js');
      const e = await updateEvent(req.params.id, req.body || {});
      res.json(e);
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });
  app.delete('/api/calendar/event/:id', requireAuth, async (req, res) => {
    try {
      const { deleteEvent } = await import('./calendar.js');
      await deleteEvent(req.params.id);
      res.json({ ok: true });
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });
  app.post('/api/calendar/freeslots', requireAuth, async (req, res) => {
    try {
      const { findFreeSlots } = await import('./calendar.js');
      const slots = await findFreeSlots(req.body || {});
      res.json(slots);
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });

  // Commitments — promesas que otros te deben
  app.get('/api/commitments', requireAuth, async (req, res) => {
    const { listCommitments } = await import('./commitments.js');
    res.json(listCommitments({ status: req.query.status || null, persona: req.query.persona || null }));
  });
  app.post('/api/commitments', requireAuth, async (req, res) => {
    try {
      const { createCommitment } = await import('./commitments.js');
      res.json({ ok: true, commitment: createCommitment(req.body || {}) });
    } catch (e) {
      res.status(400).json({ ok: false, error: e.message });
    }
  });
  app.post('/api/commitments/:id/complete', requireAuth, async (req, res) => {
    const { completeCommitment } = await import('./commitments.js');
    res.json(completeCommitment(req.params.id, (req.body || {}).evidencia || ''));
  });
  app.post('/api/commitments/:id/fail', requireAuth, async (req, res) => {
    const { failCommitment } = await import('./commitments.js');
    res.json(failCommitment(req.params.id, (req.body || {}).razon || ''));
  });
  app.post('/api/commitments/:id/cancel', requireAuth, async (req, res) => {
    const { cancelCommitment } = await import('./commitments.js');
    res.json(cancelCommitment(req.params.id));
  });
  app.post('/api/commitments/:id/note', requireAuth, async (req, res) => {
    const { noteCommitment } = await import('./commitments.js');
    res.json(noteCommitment(req.params.id, (req.body || {}).texto || ''));
  });

  // Tasks
  app.get('/api/tasks', requireAuth, async (req, res) => {
    const { listTasks } = await import('./tasks.js');
    res.json(listTasks({ status: req.query.status || null }));
  });
  app.post('/api/tasks/:id/complete', requireAuth, async (req, res) => {
    const { completeTask } = await import('./tasks.js');
    res.json(completeTask(req.params.id));
  });
  app.post('/api/tasks/:id/cancel', requireAuth, async (req, res) => {
    const { cancelTask } = await import('./tasks.js');
    res.json(cancelTask(req.params.id));
  });

  // Wiki & temporada
  app.get('/api/wiki', requireAuth, async (_req, res) => {
    const { getWiki } = await import('./memory.js');
    res.json(getWiki());
  });
  app.post('/api/wiki', requireAuth, async (req, res) => {
    const { remember } = await import('./memory.js');
    res.json(remember((req.body || {}).texto || ''));
  });
  app.get('/api/season', requireAuth, async (_req, res) => {
    const { getSeason } = await import('./memory.js');
    res.json(getSeason()); // ya viene como { texto, actualizado }
  });
  app.put('/api/season', requireAuth, async (req, res) => {
    const { setSeason } = await import('./memory.js');
    res.json(setSeason((req.body || {}).texto || ''));
  });

  // Activity (audit) — logActivity hace unshift, así que index 0 es lo más
  // reciente. Toma los primeros N, no los últimos.
  app.get('/api/activity', requireAuth, async (req, res) => {
    const { getActivity } = await import('./memory.js');
    const all = getActivity();
    const limit = Math.min(200, parseInt(req.query.limit || '50', 10));
    res.json(all.slice(0, limit));
  });

  // Brand pipeline
  app.get('/api/brand/ideas', requireAuth, async (req, res) => {
    const { ideasList } = await import('./brand.js');
    res.json(ideasList({ estado: req.query.estado || 'idea', tema: req.query.tema || null, plataforma: req.query.plataforma || null }));
  });
  app.post('/api/brand/ideas', requireAuth, async (req, res) => {
    const { ideaAdd } = await import('./brand.js');
    res.json(ideaAdd(req.body || {}));
  });
  app.post('/api/brand/ideas/:id/bump', requireAuth, async (req, res) => {
    const { ideaBump } = await import('./brand.js');
    res.json(ideaBump(req.params.id));
  });
  app.post('/api/brand/ideas/:id/archivar', requireAuth, async (req, res) => {
    const { ideaArchivar } = await import('./brand.js');
    res.json(ideaArchivar(req.params.id));
  });
  app.get('/api/brand/calendar', requireAuth, async (req, res) => {
    const { calendarProximas } = await import('./brand.js');
    res.json(calendarProximas({ dias: parseInt(req.query.dias || '14', 10) }));
  });
  app.post('/api/brand/calendar', requireAuth, async (req, res) => {
    const { calendarAdd } = await import('./brand.js');
    res.json(calendarAdd(req.body || {}));
  });
  app.post('/api/brand/calendar/:id/estado', requireAuth, async (req, res) => {
    const { calendarUpdateEstado } = await import('./brand.js');
    res.json(calendarUpdateEstado(req.params.id, (req.body || {}).estado));
  });
  app.get('/api/brand/posts', requireAuth, async (req, res) => {
    const { postsList } = await import('./brand.js');
    res.json(postsList({ desde: req.query.desde || null, plataforma: req.query.plataforma || null }));
  });
  app.post('/api/brand/posts', requireAuth, async (req, res) => {
    const { postRegistrar } = await import('./brand.js');
    res.json(postRegistrar(req.body || {}));
  });
  app.post('/api/brand/posts/:id/metricas', requireAuth, async (req, res) => {
    const { postUpdateMetricas } = await import('./brand.js');
    res.json(postUpdateMetricas(req.params.id, req.body || {}));
  });
  app.get('/api/brand/stats', requireAuth, async (_req, res) => {
    const { statsLast30Days } = await import('./brand.js');
    res.json(statsLast30Days() || { total_posts: 0 });
  });

  // Skills
  app.get('/api/skills', requireAuth, async (_req, res) => {
    const { listSkills } = await import('./skills.js');
    res.json(listSkills());
  });
  app.post('/api/skills/:slug/approve', requireAuth, async (req, res) => {
    const { approveSkill } = await import('./skills.js');
    res.json(approveSkill(req.params.slug));
  });
  app.post('/api/skills/:slug/retire', requireAuth, async (req, res) => {
    const { retireSkill } = await import('./skills.js');
    res.json(retireSkill(req.params.slug));
  });

  // ---- Web Push ----
  app.get('/api/push/key', requireAuth, async (_req, res) => {
    const { getPublicKey, pushEnabled } = await import('./push.js');
    res.json({ enabled: pushEnabled(), publicKey: getPublicKey() });
  });

  app.post('/api/push/subscribe', requireAuth, async (req, res) => {
    const { subscribe } = await import('./push.js');
    const { subscription, ua } = req.body || {};
    const r = subscribe(subscription, { ua: String(ua || '').slice(0, 200) });
    res.json(r);
  });

  app.post('/api/push/unsubscribe', requireAuth, async (req, res) => {
    const { unsubscribe } = await import('./push.js');
    const { endpoint } = req.body || {};
    res.json(unsubscribe(endpoint));
  });

  app.post('/api/push/test', requireAuth, async (_req, res) => {
    const { sendToAll } = await import('./push.js');
    const r = await sendToAll({
      title: 'Athena',
      body: 'Test desde la app — push notifications funcionan ✓',
      url: '/app/hoy',
      tag: 'test',
    });
    res.json(r);
  });

  // ---- Chat con coaches (one-shot, sin tools) ----
  // Endpoint single-turn: manda mensaje, recibe respuesta del coach pedido.
  // Para Athena (directora) usa runDirectora con history acumulado.
  app.get('/api/chat/coaches', requireAuth, async (_req, res) => {
    const { SPECIALISTS, DIRECTORA } = await import('./agents.js');
    const coaches = [
      { id: 'directora', name: DIRECTORA.name, role: DIRECTORA.role || 'Chief of Staff' },
      ...Object.values(SPECIALISTS).map((s) => ({ id: s.id, name: s.name, role: s.role || '' })),
    ];
    res.json(coaches);
  });

  app.post('/api/chat', requireAuth, async (req, res) => {
    const { coach = 'directora', message = '' } = req.body || {};
    if (!message || !message.trim()) {
      return res.status(400).json({ error: 'message vacío' });
    }
    try {
      if (coach === 'directora') {
        // DECISIÓN DE DISEÑO: web y WhatsApp comparten el MISMO history.
        // Esto es intencional — "una Athena, dos puertas". Si Isabel
        // mencionó algo por WA en la mañana, la versión web también lo
        // sabe. La marca [via web app] le dice a Athena de qué canal
        // viene esta vuelta para que pueda calibrar tono / formato.
        const { runDirectora } = await import('./directora.js');
        const { getHistory, saveHistory } = await import('./memory.js');
        const history = getHistory();
        history.push({ role: 'user', content: `[via web app] ${message}` });
        const { reply, messages: updated } = await runDirectora(history);
        saveHistory(updated);
        return res.json({ coach, reply });
      }
      // Specialists: hilo persistente por coach. Cada especialista
      // recuerda lo que ha hablado con Isabel entre sesiones (cargado
      // de data/coach_threads/<coach>.json). Athena en WhatsApp NO usa
      // este hilo — sigue con consultas single-turn via consultar_
      // especialistas.
      const { askSpecialistThreaded } = await import('./claude.js');
      const { SPECIALISTS } = await import('./agents.js');
      const { buildWikiContext } = await import('./memory.js');
      const { loadCoachThread, appendCoachTurn, toApiMessages } = await import('./coach_threads.js');
      const spec = SPECIALISTS[coach];
      if (!spec) return res.status(404).json({ error: 'coach desconocido' });
      const wiki = buildWikiContext();
      // Cargamos el hilo, appendeamos el mensaje del usuario, y mandamos
      // todo a Claude. Si la respuesta viene OK, persistimos ambos turnos.
      const thread = loadCoachThread(coach);
      const apiMessages = [...toApiMessages(thread), { role: 'user', content: message }];
      const reply = await askSpecialistThreaded(spec, apiMessages, wiki);
      appendCoachTurn(coach, 'user', message);
      appendCoachTurn(coach, 'assistant', reply);
      return res.json({ coach, reply });
    } catch (e) {
      console.error('[api/chat]', e);
      res.status(500).json({ error: e.message });
    }
  });

  // ---- Coach threads: cargar / limpiar el historial de una coach ----
  // GET devuelve el hilo persistido (para hidratación al abrir la pantalla).
  // DELETE lo borra (para que Isabel pueda "reset" si quiere empezar de cero).
  app.get('/api/coach_thread/:coach', requireAuth, async (req, res) => {
    try {
      const { loadCoachThread } = await import('./coach_threads.js');
      const thread = loadCoachThread(req.params.coach);
      res.json({ coach: req.params.coach, messages: thread });
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });

  app.delete('/api/coach_thread/:coach', requireAuth, async (req, res) => {
    try {
      const { clearCoachThread } = await import('./coach_threads.js');
      clearCoachThread(req.params.coach);
      res.json({ ok: true, coach: req.params.coach });
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });

  console.log('[api] endpoints REST montados en /api/*');
}
