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

  // STT: el PWA manda audio (webm/ogg) y le devolvemos texto transcrito.
  // Usa Whisper — auto-detecta idioma, perfecto para spanglish.
  // El body llega como raw bytes con Content-Type del MediaRecorder.
  app.post('/api/transcribe', requireAuth, async (req, res) => {
    try {
      const chunks = [];
      let total = 0;
      const MAX_BYTES = 10 * 1024 * 1024; // 10 MB ~ varios minutos
      let aborted = false;
      req.on('data', (c) => {
        total += c.length;
        if (total > MAX_BYTES) {
          aborted = true;
          res.status(413).json({ error: 'audio muy largo' });
          req.destroy();
          return;
        }
        chunks.push(c);
      });
      req.on('end', async () => {
        if (aborted) return;
        try {
          const buf = Buffer.concat(chunks);
          if (!buf.length) return res.status(400).json({ error: 'sin audio' });
          const mime = req.headers['content-type'] || 'audio/webm';
          const { transcribeAudioBuffer } = await import('./transcribe.js');
          const r = await transcribeAudioBuffer(buf, mime);
          if (!r.ok) return res.status(502).json({ error: r.error || 'whisper falló' });
          res.json({ text: r.transcript });
        } catch (err) {
          console.error('[api/transcribe]', err.message);
          res.status(500).json({ error: err.message });
        }
      });
      req.on('error', (err) => {
        console.error('[api/transcribe] stream error', err.message);
        if (!res.headersSent) res.status(500).json({ error: err.message });
      });
    } catch (err) {
      console.error('[api/transcribe]', err.message);
      res.status(500).json({ error: err.message });
    }
  });

  // TTS: el PWA manda texto y le devolvemos URL de MP3 público.
  // Usa el mismo synthToPublicUrl que WhatsApp (OpenAI nova/shimmer o ElevenLabs).
  // Así garantizamos voz femenina sin depender del navegador.
  app.post('/api/tts', requireAuth, async (req, res) => {
    try {
      const text = String((req.body && req.body.text) || '').trim();
      if (!text) return res.status(400).json({ error: 'text requerido' });
      if (text.length > 4000) return res.status(400).json({ error: 'texto muy largo' });
      const { synthToPublicUrl, ttsConfigured } = await import('./tts.js');
      if (!ttsConfigured()) {
        return res.status(503).json({ error: 'tts no configurado en servidor' });
      }
      const url = await synthToPublicUrl(text);
      if (!url) return res.status(500).json({ error: 'synth devolvió null' });
      res.json({ url });
    } catch (err) {
      console.error('[api/tts]', err.message);
      res.status(500).json({ error: err.message });
    }
  });

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

  // Nudge — manda un recordatorio a la persona del compromiso vía
  // WhatsApp o SMS y registra el ping. Si no hay contacto guardado,
  // falla con mensaje claro. Sube el contador recordatorios_enviados.
  app.post('/api/commitments/:id/nudge', requireAuth, async (req, res) => {
    try {
      const { getCommitment, noteCommitment, bumpReminder } = await import('./commitments.js');
      const c = getCommitment(req.params.id);
      if (!c) return res.status(404).json({ ok: false, error: 'no existe' });
      const contacto = c.persona_contacto || c.contacto || '';
      if (!contacto) return res.status(400).json({ ok: false, error: 'sin contacto para esta persona' });
      const canal = (c.canal || 'whatsapp').toLowerCase();
      const msg = (req.body?.mensaje || '').trim()
        || `Hola ${c.persona}, te escribe Isabel (vía su asistente Athena). Quería seguir con esto: ${c.descripcion}. ¿Cómo vamos? Gracias.`;
      const { sendMessage } = await import('./whatsapp.js');
      const to = canal === 'sms'
        ? (contacto.startsWith('+') ? contacto : `+${contacto.replace(/\D/g, '')}`)
        : (contacto.startsWith('whatsapp:') ? contacto : `whatsapp:${contacto.startsWith('+') ? contacto : '+' + contacto.replace(/\D/g, '')}`);
      await sendMessage(to, msg);
      if (typeof bumpReminder === 'function') bumpReminder(c.id);
      noteCommitment(c.id, `Nudge enviado vía ${canal} (${new Date().toISOString().slice(0, 10)})`);
      const { logActivity } = await import('./memory.js');
      logActivity({ tool: 'commitment_nudge', input_summary: c.persona, result_summary: `via ${canal}` });
      res.json({ ok: true, canal, to });
    } catch (e) {
      console.error('[api/nudge]', e.message);
      res.status(500).json({ ok: false, error: e.message });
    }
  });

  // LUNA — tickets abiertos del equipo (para que Isabel los vea sin chatear Pilar)
  app.get('/api/luna/tickets', requireAuth, async (req, res) => {
    try {
      const { lunaConfigured, openTickets } = await import('./luna_client.js');
      if (!lunaConfigured()) return res.json({ ok: false, reason: 'LUNA no configurado', tickets: [] });
      const priority = req.query.prioridad || req.query.priority || '';
      const r = await openTickets({ priority });
      if (!r.ok) return res.json({ ok: false, reason: r.error, tickets: [] });
      res.json({ ok: true, tickets: r.data || [] });
    } catch (e) {
      res.status(500).json({ ok: false, error: e.message, tickets: [] });
    }
  });

  // Captura rápida — Isabel dicta una tarea/compromiso y Athena la rutea.
  // Reusa runDirectora con un prompt envuelto que fuerza acción inmediata.
  app.post('/api/quick-capture', requireAuth, async (req, res) => {
    try {
      const text = String(req.body?.text || '').trim();
      if (!text) return res.status(400).json({ error: 'text vacío' });
      const { runDirectora } = await import('./directora.js');
      const { getHistory, saveHistory, logActivity } = await import('./memory.js');
      const history = getHistory();
      const wrapped = `[CAPTURA RÁPIDA desde pantalla Tareas] ${text}\n\n(Esto es delegación pura. Identifica las acciones, créalas todas en una sola vuelta — ticket LUNA si es para el equipo, crear_tarea si es para ti/athena/sami, comprometer_entrega si alguien te prometió algo. Responde corto: solo confirma qué hiciste.)`;
      history.push({ role: 'user', content: wrapped });
      try { logActivity({ tool: 'isabel_pregunta', input_summary: text.slice(0, 200), result_summary: 'quick-capture' }); } catch { /* ignore */ }
      const { reply, messages: updated } = await runDirectora(history);
      saveHistory(updated);
      try { logActivity({ tool: 'athena_responde', input_summary: text.slice(0, 100), result_summary: (reply || '').slice(0, 200) }); } catch { /* ignore */ }
      res.json({ ok: true, reply });
    } catch (e) {
      console.error('[api/quick-capture]', e.message);
      res.status(500).json({ ok: false, error: e.message });
    }
  });

  // Tasks
  app.get('/api/tasks', requireAuth, async (req, res) => {
    const { listTasks } = await import('./tasks.js');
    res.json(listTasks({ status: req.query.status || null }));
  });
  app.post('/api/tasks', requireAuth, async (req, res) => {
    try {
      const { createTask } = await import('./tasks.js');
      const t = createTask({
        descripcion: req.body?.descripcion,
        responsable: req.body?.responsable || 'isabel',
        prioridad: req.body?.prioridad || 'media',
        vence: req.body?.vence || null,
      });
      res.json(t);
    } catch (e) { res.status(400).json({ error: e.message }); }
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
        const { getHistory, saveHistory, logActivity } = await import('./memory.js');
        const history = getHistory();
        try {
          logActivity({
            tool: 'isabel_pregunta',
            input_summary: message.slice(0, 200),
            result_summary: 'PWA chat → Athena',
          });
        } catch { /* ignore */ }
        history.push({ role: 'user', content: `[via web app] ${message}` });
        const { reply, messages: updated } = await runDirectora(history);
        saveHistory(updated);
        try {
          logActivity({
            tool: 'athena_responde',
            input_summary: message.slice(0, 100),
            result_summary: (reply || '').slice(0, 200),
          });
        } catch { /* ignore */ }
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
      const { planAsContext } = await import('./coach_plans.js');
      const { notesAsContext } = await import('./coach_notes.js');
      const { coachPlanTools, makeCoachPlanDispatcher } = await import('./coach_plan_tools.js');
      const spec = SPECIALISTS[coach];
      if (!spec) return res.status(404).json({ error: 'coach desconocido' });
      const wiki = buildWikiContext();
      const planCtx = planAsContext(coach, spec.name);
      const notesCtx = notesAsContext(coach, spec.name);
      // Wiki + plan vigente como contexto; tools para que la coach pueda
      // actualizar su propio plan durante la conversación. Persistimos
      // solo el mensaje del usuario y la respuesta final (no los rounds
      // intermedios de tool_use/tool_result — quedan ephemeral).
      const thread = loadCoachThread(coach);
      const apiMessages = [...toApiMessages(thread), { role: 'user', content: message }];
      // Coach plan tools + web_search server-side (smart coaches A).
      // Web_search es server-side: Anthropic lo resuelve, no necesita
      // pasar por el dispatcher local.
      const WEB_SEARCH = { type: 'web_search_20250305', name: 'web_search', max_uses: 2 };

      // PILAR EXCEPTION: cuando es Pilar Medicare en chat directo PWA,
      // necesita acceso a sus 14 tools luna_* para buscar miembros, crear
      // tickets, citas, etc. Sin esto solo puede "hablar" de Medicare pero
      // no actuar sobre el CRM real. Bug descubierto jun 2026: Pilar
      // misma reportó en chat 'las herramientas luna_* no están disponibles
      // en esta sesión' — confirmando que la inyección que sí ocurre en
      // consultar_especialistas (WhatsApp delegation) NO se replicaba en
      // el flujo PWA directo. Este fix unifica.
      let tools = [...coachPlanTools, WEB_SEARCH];
      let toolDispatcher = makeCoachPlanDispatcher(coach);
      if (coach === 'pilar') {
        const { LUNA_TOOL_DEFINITIONS, runLunaTool } = await import('./luna_tools.js');
        tools = [...tools, ...LUNA_TOOL_DEFINITIONS];
        // Dispatcher combinado: coach_plan_* van al plan dispatcher,
        // luna_* van al luna runner. Otros tools (web_search) son
        // server-side y no necesitan dispatcher local.
        const planDispatcher = makeCoachPlanDispatcher(coach);
        toolDispatcher = async (name, input) => {
          if (name.startsWith('luna_')) return runLunaTool(name, input);
          return planDispatcher(name, input);
        };
      }
      const reply = await askSpecialistThreaded(
        spec,
        apiMessages,
        wiki +
          (notesCtx ? '\n\n' + notesCtx : '') +
          (planCtx ? '\n\n' + planCtx : ''),
        { tools, toolDispatcher },
      );
      appendCoachTurn(coach, 'user', message);
      appendCoachTurn(coach, 'assistant', reply);
      return res.json({ coach, reply });
    } catch (e) {
      console.error('[api/chat]', e);
      res.status(500).json({ error: e.message });
    }
  });

  // ---- Coach threads: cargar / limpiar el historial de una coach ----
  // Briefing del día — generado por el cron 6:30am y guardado a disco.
  // El PWA lo lee al abrir Hoy. Si no hay (deploy fresco, cron no ha
  // corrido), permite forzarlo bajo demanda con POST.
  app.get('/api/briefing/today', requireAuth, async (_req, res) => {
    try {
      const { loadTodayBriefing } = await import('./briefing.js');
      const data = loadTodayBriefing();
      res.json(data || { cards: [], date: null });
    } catch (e) {
      res.status(500).json({ error: e.message });
    }
  });

  // Genera un briefing fresco bajo demanda (cuesta tokens — no es para
  // refrescar a cada rato). Pensado para "no tengo briefing hoy todavía".
  app.post('/api/briefing/refresh', requireAuth, async (_req, res) => {
    try {
      const { sendMorningBriefing, loadTodayBriefing } = await import('./briefing.js');
      await sendMorningBriefing();
      const data = loadTodayBriefing();
      res.json(data || { cards: [] });
    } catch (e) {
      res.status(500).json({ error: e.message });
    }
  });

  // Historial de Athena (compartido con WhatsApp). El PWA lo hidrata al abrir
  // el chat para que Isabel vea sus conversaciones previas en lugar de
  // arrancar en blanco cada vez que cambia de pantalla.
  app.get('/api/chat/history', requireAuth, async (req, res) => {
    try {
      const { getHistory } = await import('./memory.js');
      const limit = Math.min(80, parseInt(req.query.limit || '40', 10));
      const all = getHistory();
      // Solo nos quedamos con turnos de texto plano (no tool_use / tool_result
      // / imágenes embebidas) y solo {role: user|assistant} con content string.
      const out = [];
      for (const m of all.slice(-limit * 2)) {
        if (m.role !== 'user' && m.role !== 'assistant') continue;
        let content = '';
        if (typeof m.content === 'string') {
          content = m.content;
        } else if (Array.isArray(m.content)) {
          // Pluck text blocks; skip image/document/tool_use.
          content = m.content
            .filter((b) => b && b.type === 'text' && typeof b.text === 'string')
            .map((b) => b.text)
            .join('\n')
            .trim();
        }
        if (!content) continue;
        // Quita la marca [via web app] / [contexto: ...] que metimos al
        // mensaje del user para el modelo — Isabel no necesita verlo.
        content = content
          .replace(/^\[via web app\]\s*/, '')
          .replace(/\s*\[contexto:[^\]]*\]\s*$/g, '')
          .trim();
        if (!content) continue;
        out.push({ role: m.role, content });
      }
      res.json({ messages: out.slice(-limit) });
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });

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

  // ---- Manuales: MANUAL_ATHENA.md + RUNBOOK_SAMI.md desde la raíz del repo ----
  app.get('/api/docs/:name', requireAuth, async (req, res) => {
    try {
      const { readFileSync, existsSync } = await import('node:fs');
      const { join, dirname } = await import('node:path');
      const { fileURLToPath } = await import('node:url');
      const ALLOWED = {
        manual: 'MANUAL_ATHENA.md',
        sami: 'RUNBOOK_SAMI.md',
        pendientes: 'PENDIENTES.md',
      };
      const file = ALLOWED[req.params.name];
      if (!file) return res.status(404).json({ error: 'doc no existe' });
      const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..', '..');
      const path = join(repoRoot, file);
      if (!existsSync(path)) return res.status(404).json({ error: 'archivo no encontrado' });
      const content = readFileSync(path, 'utf8');
      res.json({ name: req.params.name, file, content });
    } catch (e) {
      res.status(500).json({ error: e.message });
    }
  });

  // ---- Self-grades: Athena se califica semanalmente ----
  app.get('/api/self_grades', requireAuth, async (req, res) => {
    try {
      const { listSelfGrades } = await import('./self_grade.js');
      res.json(listSelfGrades({ limit: parseInt(req.query.limit, 10) || 12 }));
    } catch (e) { res.status(500).json({ error: e.message }); }
  });

  app.post('/api/self_grades/run', requireAuth, async (_req, res) => {
    try {
      const { gradeWeek } = await import('./self_grade.js');
      res.json(await gradeWeek());
    } catch (e) { res.status(500).json({ error: e.message }); }
  });

  app.post('/api/self_grades/:semana/implemented', requireAuth, async (req, res) => {
    try {
      const { markGradeImplemented } = await import('./self_grade.js');
      res.json(markGradeImplemented(req.params.semana));
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  // ---- Streaks: días/semanas consecutivos por actividad ----
  app.get('/api/streaks', requireAuth, async (_req, res) => {
    try {
      const { allStreaks } = await import('./streaks.js');
      res.json(allStreaks());
    } catch (e) { res.status(500).json({ error: e.message }); }
  });

  // ---- Entities: personas que Athena conoce ----
  app.get('/api/entities', requireAuth, async (req, res) => {
    try {
      const { listEntities } = await import('./entities.js');
      res.json(listEntities({
        type: req.query.type || null,
        limit: parseInt(req.query.limit, 10) || 200,
      }));
    } catch (e) { res.status(500).json({ error: e.message }); }
  });

  app.get('/api/entities/:id', requireAuth, async (req, res) => {
    try {
      const { getEntity } = await import('./entities.js');
      const e = getEntity(req.params.id);
      if (!e) return res.status(404).json({ error: 'entity no existe' });
      res.json(e);
    } catch (e) { res.status(500).json({ error: e.message }); }
  });

  // ---- Insights: signals (nightly), patterns, AAR learnings ----
  app.get('/api/insights', requireAuth, async (_req, res) => {
    try {
      const { loadSignals } = await import('./signals.js');
      const { emocionesPattern } = await import('./journal.js');
      const { recentLearnings, listOpen: listOpenAar } = await import('./aar.js');
      const sigs = loadSignals();
      const pattern = emocionesPattern({ dias: 14 });
      const learnings = recentLearnings({ limit: 8 });
      const openAar = listOpenAar();
      res.json({
        signals: sigs.signals || [],
        signals_ts: sigs.ts,
        emotional_pattern: pattern,
        learnings,
        open_decisions: openAar,
      });
    } catch (e) { res.status(500).json({ error: e.message }); }
  });

  // ---- Goals / OKRs ----
  app.get('/api/goals', requireAuth, async (req, res) => {
    try {
      const { listMetas, proyeccion } = await import('./goals.js');
      const status = req.query.status || 'activa';
      const area = req.query.area || null;
      const items = listMetas({ status, area });
      // Enrich con proyección calculada
      const enriched = items.map((m) => ({ ...m, proyeccion: proyeccion(m) }));
      res.json(enriched);
    } catch (e) { res.status(500).json({ error: e.message }); }
  });

  app.post('/api/goals', requireAuth, async (req, res) => {
    try {
      const { registrarMeta } = await import('./goals.js');
      const r = registrarMeta(req.body || {});
      if (!r.ok) return res.status(400).json({ error: r.error });
      res.json(r.entry);
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  app.patch('/api/goals/:id', requireAuth, async (req, res) => {
    try {
      const { actualizarProgreso, cambiarStatus } = await import('./goals.js');
      let result;
      if (req.body?.progreso !== undefined) {
        result = actualizarProgreso({ id: req.params.id, progreso: req.body.progreso, nota: req.body.nota || '' });
      }
      if (req.body?.status) {
        result = cambiarStatus(req.params.id, req.body.status);
      }
      if (!result) return res.status(404).json({ error: 'goal no encontrado' });
      res.json(result);
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  // ---- Trends scout: lista hits trending/viral encontrados ----
  app.get('/api/trends', requireAuth, async (req, res) => {
    try {
      const { listTrends, getTrendTopics } = await import('./trends.js');
      res.json({
        items: listTrends({
          status: req.query.status || 'pending',
          limit: parseInt(req.query.limit, 10) || 100,
          topic_id: req.query.topic_id || null,
        }),
        topics: getTrendTopics(),
      });
    } catch (e) { res.status(500).json({ error: e.message }); }
  });

  app.patch('/api/trends/:id', requireAuth, async (req, res) => {
    try {
      const { updateTrendStatus } = await import('./trends.js');
      res.json(updateTrendStatus(req.params.id, req.body?.status));
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  app.post('/api/trends/scan', requireAuth, async (_req, res) => {
    try {
      const { runTrendScan } = await import('./trends.js');
      const r = await runTrendScan();
      res.json(r);
    } catch (e) { res.status(500).json({ error: e.message }); }
  });

  // ---- Coaches overview: directorio de los 17 con stats por cada uno ----
  // (plan items, notes length, thread count, last interaction).
  app.get('/api/coaches/overview', requireAuth, async (_req, res) => {
    try {
      const { SPECIALISTS, DIRECTORA } = await import('./agents.js');
      const { loadCoachPlan } = await import('./coach_plans.js');
      const { loadCoachNotes } = await import('./coach_notes.js');
      const { loadCoachThread } = await import('./coach_threads.js');
      const all = [
        { id: 'directora', name: DIRECTORA.name, role: DIRECTORA.role || 'Chief of Staff' },
        ...Object.values(SPECIALISTS).map((s) => ({ id: s.id, name: s.name, role: s.role || '' })),
      ];
      const out = all.map((c) => {
        // directora no usa coach_plans/notes/threads (su memoria es global)
        if (c.id === 'directora') {
          return { ...c, has_data: false, plan_active: 0, plan_total: 0, notes_length: 0, notes_updated: null, thread_length: 0, thread_last_ts: null };
        }
        const plan = loadCoachPlan(c.id);
        const notes = loadCoachNotes(c.id);
        const thread = loadCoachThread(c.id);
        const lastTurn = thread.length ? thread[thread.length - 1] : null;
        return {
          ...c,
          has_data: plan.items.length > 0 || notes.notes.length > 0 || thread.length > 0,
          plan_active: plan.items.filter((i) => i.status === 'active').length,
          plan_total: plan.items.length,
          notes_length: notes.notes.length,
          notes_updated: notes.actualizado,
          thread_length: thread.length,
          thread_last_ts: lastTurn?.ts || null,
        };
      });
      res.json(out);
    } catch (e) {
      res.status(500).json({ error: e.message });
    }
  });

  // ---- Búsqueda global: busca un keyword en todas las fuentes de memoria ----
  app.get('/api/search', requireAuth, async (req, res) => {
    try {
      const { globalSearch } = await import('./search.js');
      const r = await globalSearch(req.query.q || '', { limit: parseInt(req.query.limit, 10) || 20 });
      res.json(r);
    } catch (e) {
      res.status(500).json({ error: e.message });
    }
  });

  // ---- Coach plans agregados: TODOS los planes vigentes en una vista ----
  // Útil para que Isabel vea su "stack" completo cross-coach de un vistazo.
  app.get('/api/coach_plans', requireAuth, async (_req, res) => {
    try {
      const { loadCoachPlan } = await import('./coach_plans.js');
      const { SPECIALISTS } = await import('./agents.js');
      const { readdirSync, existsSync } = await import('node:fs');
      const { join, dirname } = await import('node:path');
      const { fileURLToPath } = await import('node:url');
      const baseDir = join(dirname(fileURLToPath(import.meta.url)), '..', 'data', 'coach_plans');
      const ids = existsSync(baseDir)
        ? readdirSync(baseDir).filter((f) => f.endsWith('.json')).map((f) => f.slice(0, -5))
        : [];
      const out = ids.map((id) => {
        const plan = loadCoachPlan(id);
        return {
          coach_id: id,
          coach_name: SPECIALISTS[id]?.name || id,
          coach_role: SPECIALISTS[id]?.role || '',
          ...plan,
        };
      }).filter((p) => p.items.length > 0);
      res.json(out);
    } catch (e) {
      res.status(500).json({ error: e.message });
    }
  });

  // ---- Coach plans: plan vigente que cada coach le recomendó a Isabel ----
  // GET   /api/coach_plan/:coach              → plan completo
  // POST  /api/coach_plan/:coach              { text } → agrega item (manual)
  // PATCH /api/coach_plan/:coach/:item_id     { text?, status? } → actualiza
  // DELETE /api/coach_plan/:coach/:item_id    → borra item
  // DELETE /api/coach_plan/:coach             → borra plan completo
  app.get('/api/coach_plan/:coach', requireAuth, async (req, res) => {
    try {
      const { loadCoachPlan } = await import('./coach_plans.js');
      res.json(loadCoachPlan(req.params.coach));
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });

  app.post('/api/coach_plan/:coach', requireAuth, async (req, res) => {
    try {
      const { addPlanItem } = await import('./coach_plans.js');
      const plan = addPlanItem(req.params.coach, req.body?.text);
      res.json(plan);
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });

  app.patch('/api/coach_plan/:coach/:item_id', requireAuth, async (req, res) => {
    try {
      const { updatePlanItem } = await import('./coach_plans.js');
      const patch = {};
      if (req.body?.text !== undefined) patch.text = req.body.text;
      if (req.body?.status !== undefined) patch.status = req.body.status;
      const plan = updatePlanItem(req.params.coach, req.params.item_id, patch);
      res.json(plan);
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });

  app.delete('/api/coach_plan/:coach/:item_id', requireAuth, async (req, res) => {
    try {
      const { removePlanItem } = await import('./coach_plans.js');
      const plan = removePlanItem(req.params.coach, req.params.item_id);
      res.json(plan);
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });

  app.delete('/api/coach_plan/:coach', requireAuth, async (req, res) => {
    try {
      const { clearCoachPlan } = await import('./coach_plans.js');
      const plan = clearCoachPlan(req.params.coach);
      res.json(plan);
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });

  // ---- Coach notes: expediente de cada coach sobre Isabel ----
  app.get('/api/coach_notes/:coach', requireAuth, async (req, res) => {
    try {
      const { loadCoachNotes } = await import('./coach_notes.js');
      res.json(loadCoachNotes(req.params.coach));
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });

  app.delete('/api/coach_notes/:coach', requireAuth, async (req, res) => {
    try {
      const { clearCoachNotes } = await import('./coach_notes.js');
      res.json(clearCoachNotes(req.params.coach));
    } catch (e) {
      res.status(400).json({ error: e.message });
    }
  });

  // ---- Journal: lectura, escritura, search, day, patterns ----
  app.get('/api/journal', requireAuth, async (req, res) => {
    try {
      const { listRecent } = await import('./journal.js');
      const dias = parseInt(req.query.dias, 10) || 30;
      const tipo = req.query.tipo || null;
      res.json(listRecent({ dias, tipo }));
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  app.get('/api/journal/search', requireAuth, async (req, res) => {
    try {
      const { searchEntries } = await import('./journal.js');
      const q = req.query.q || '';
      const dias = parseInt(req.query.dias, 10) || 90;
      res.json(searchEntries({ query: q, dias }));
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  app.get('/api/journal/day', requireAuth, async (req, res) => {
    try {
      const { entriesForDay } = await import('./journal.js');
      res.json(entriesForDay(req.query.dia));
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  app.get('/api/journal/pattern', requireAuth, async (req, res) => {
    try {
      const { emocionesPattern } = await import('./journal.js');
      res.json(emocionesPattern({ dias: parseInt(req.query.dias, 10) || 14 }));
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  app.post('/api/journal', requireAuth, async (req, res) => {
    try {
      const { registrarEntrada } = await import('./journal.js');
      const r = registrarEntrada({
        texto: req.body?.texto,
        tipo: req.body?.tipo || 'journal',
        gratitud: req.body?.gratitud || null,
        frustracion: req.body?.frustracion || null,
      });
      if (!r.ok) return res.status(400).json({ error: r.error });
      res.json(r.entry);
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  // ---- Reading list ----
  app.get('/api/reading', requireAuth, async (req, res) => {
    try {
      const { listItems } = await import('./reading_list.js');
      res.json(listItems({
        status: req.query.status || 'pending',
        tag: req.query.tag || null,
        limit: parseInt(req.query.limit, 10) || 50,
      }));
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  app.post('/api/reading', requireAuth, async (req, res) => {
    try {
      const { addItem } = await import('./reading_list.js');
      const it = addItem({
        url: req.body?.url,
        titulo: req.body?.titulo,
        notas: req.body?.notas,
        tags: req.body?.tags,
      });
      res.json(it);
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  app.patch('/api/reading/:id', requireAuth, async (req, res) => {
    try {
      const { updateItem } = await import('./reading_list.js');
      const patch = {};
      ['status', 'titulo', 'notas', 'tags'].forEach((k) => {
        if (req.body?.[k] !== undefined) patch[k] = req.body[k];
      });
      res.json(updateItem(req.params.id, patch));
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  app.delete('/api/reading/:id', requireAuth, async (req, res) => {
    try {
      const { removeItem } = await import('./reading_list.js');
      res.json(removeItem(req.params.id));
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  // ---- Rapport semanal ----
  app.get('/api/rapport', requireAuth, async (req, res) => {
    try {
      const { listRapports, rapportTrend } = await import('./rapport.js');
      res.json({
        items: listRapports({ limit: parseInt(req.query.limit, 10) || 26 }),
        trend: rapportTrend(),
      });
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  app.post('/api/rapport', requireAuth, async (req, res) => {
    try {
      const { registrarRapport } = await import('./rapport.js');
      const entry = registrarRapport({
        peso_lbs: req.body?.peso_lbs,
        medidas: req.body?.medidas,
        foto_url: req.body?.foto_url,
        sentires: req.body?.sentires,
        periodo: req.body?.periodo,
      });
      res.json(entry);
    } catch (e) { res.status(400).json({ error: e.message }); }
  });

  console.log('[api] endpoints REST montados en /api/*');
}
