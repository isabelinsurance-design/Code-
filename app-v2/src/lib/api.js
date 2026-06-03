// Cliente delgado para hablar con el backend de Athena.
// Todas las llamadas usan credentials:'include' para mandar el cookie de sesión.

const base = '/api';

async function request(path, opts = {}) {
  const res = await fetch(base + path, {
    credentials: 'include',
    headers: { 'Content-Type': 'application/json', ...(opts.headers || {}) },
    ...opts,
  });
  if (res.status === 401) {
    // Sesión expiró — la app maneja el redirect
    throw new Error('UNAUTHORIZED');
  }
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`API ${res.status}: ${text.slice(0, 200)}`);
  }
  const ct = res.headers.get('content-type') || '';
  return ct.includes('application/json') ? res.json() : res.text();
}

export const api = {
  // Auth
  login: (password) => request('/login', { method: 'POST', body: JSON.stringify({ password }) }),
  logout: () => request('/logout', { method: 'POST' }),
  me: () => request('/me'),

  // Estado general — pantalla Hoy
  hoyState: () => request('/hoy'),

  // Tareas y compromisos
  tasks: (status) => request(`/tasks${status ? `?status=${status}` : ''}`),
  taskComplete: (id) => request(`/tasks/${id}/complete`, { method: 'POST' }),
  taskCancel: (id) => request(`/tasks/${id}/cancel`, { method: 'POST' }),

  // Calendar (Google)
  calendarStatus: () => request('/calendar/status'),
  calendarUpcoming: (hours = 168, limit = 25) => request(`/calendar/upcoming?hours=${hours}&limit=${limit}`),
  calendarCreate: (data) => request('/calendar/event', { method: 'POST', body: JSON.stringify(data) }),
  calendarUpdate: (id, patch) => request(`/calendar/event/${id}`, { method: 'PATCH', body: JSON.stringify(patch) }),
  calendarDelete: (id) => request(`/calendar/event/${id}`, { method: 'DELETE' }),
  calendarFreeSlots: (params) => request('/calendar/freeslots', { method: 'POST', body: JSON.stringify(params) }),

  // Commitments — promesas que otros te deben
  commitments: (status, persona) => {
    const qs = new URLSearchParams();
    if (status) qs.set('status', status);
    if (persona) qs.set('persona', persona);
    const s = qs.toString();
    return request(`/commitments${s ? `?${s}` : ''}`);
  },
  commitmentCreate: (data) => request('/commitments', { method: 'POST', body: JSON.stringify(data) }),
  commitmentComplete: (id, evidencia) => request(`/commitments/${id}/complete`, { method: 'POST', body: JSON.stringify({ evidencia }) }),
  commitmentFail: (id, razon) => request(`/commitments/${id}/fail`, { method: 'POST', body: JSON.stringify({ razon }) }),
  commitmentCancel: (id) => request(`/commitments/${id}/cancel`, { method: 'POST' }),
  commitmentNote: (id, texto) => request(`/commitments/${id}/note`, { method: 'POST', body: JSON.stringify({ texto }) }),

  // Rutinas
  routines: () => request('/routines'),
  routineCreate: (data) => request('/routines', { method: 'POST', body: JSON.stringify(data) }),
  routineDeactivate: (id) => request(`/routines/${id}/deactivate`, { method: 'POST' }),
  routineStep: (id, paso_idx, accion) => request(`/routines/${id}/step`, { method: 'POST', body: JSON.stringify({ paso_idx, accion }) }),

  // Focus blocks
  focusBlocks: () => request('/focus'),
  focusCreate: (data) => request('/focus', { method: 'POST', body: JSON.stringify(data) }),
  focusDeactivate: (id) => request(`/focus/${id}/deactivate`, { method: 'POST' }),

  // Research topics
  researchTopics: () => request('/research'),
  researchCreate: (data) => request('/research', { method: 'POST', body: JSON.stringify(data) }),
  researchPause: (id) => request(`/research/${id}/pause`, { method: 'POST' }),
  researchDelete: (id) => request(`/research/${id}`, { method: 'DELETE' }),
  researchSeed: () => request('/research/seed', { method: 'POST' }),

  // Skills (aprobar/retirar/listar)
  skills: () => request('/skills'),
  skillApprove: (slug) => request(`/skills/${slug}/approve`, { method: 'POST' }),
  skillRetire: (slug) => request(`/skills/${slug}/retire`, { method: 'POST' }),

  // Mejoras propuestas por Athena
  improvements: (status) => request(`/improvements${status ? `?status=${status}` : ''}`),
  improvementStatus: (id, status) => request(`/improvements/${id}/status`, { method: 'POST', body: JSON.stringify({ status }) }),

  // Legal calendar
  legal: () => request('/legal'),
  legalCreate: (data) => request('/legal', { method: 'POST', body: JSON.stringify(data) }),
  legalComplete: (id, evidencia) => request(`/legal/${id}/complete`, { method: 'POST', body: JSON.stringify({ evidencia }) }),

  // Wiki + temporada
  wiki: () => request('/wiki'),
  wikiAdd: (texto) => request('/wiki', { method: 'POST', body: JSON.stringify({ texto }) }),
  season: () => request('/season'),
  seasonUpdate: (texto) => request('/season', { method: 'PUT', body: JSON.stringify({ texto }) }),

  // Actividad (audit log)
  activity: (limit = 50) => request(`/activity?limit=${limit}`),

  // Coach cadence
  coachCadences: () => request('/coach-cadence'),
  coachCadencesToday: () => request('/coach-cadence/today'),
  coachCadenceSet: (data) => request('/coach-cadence', { method: 'POST', body: JSON.stringify(data) }),
  coachCadencePause: (coach) => request(`/coach-cadence/${coach}/pause`, { method: 'POST' }),
  coachCadenceRemove: (coach) => request(`/coach-cadence/${coach}`, { method: 'DELETE' }),
  coachCadenceSeed: () => request('/coach-cadence/seed', { method: 'POST' }),
  coachCadenceCheckIn: (coach, accion, nota) => request(`/coach-cadence/${coach}/check-in`, { method: 'POST', body: JSON.stringify({ accion, nota }) }),
  coachCadencePrompt: (coach) => request(`/coach-cadence/${coach}/prompt`),

  // Brand pipeline (YouTube/IG/TikTok)
  brandIdeas: (filters = {}) => {
    const qs = new URLSearchParams(filters).toString();
    return request(`/brand/ideas${qs ? `?${qs}` : ''}`);
  },
  brandIdeaCreate: (data) => request('/brand/ideas', { method: 'POST', body: JSON.stringify(data) }),
  brandIdeaBump: (id) => request(`/brand/ideas/${id}/bump`, { method: 'POST' }),
  brandIdeaArchive: (id) => request(`/brand/ideas/${id}/archivar`, { method: 'POST' }),
  brandCalendar: (dias = 14) => request(`/brand/calendar?dias=${dias}`),
  brandCalendarCreate: (data) => request('/brand/calendar', { method: 'POST', body: JSON.stringify(data) }),
  brandCalendarEstado: (id, estado) => request(`/brand/calendar/${id}/estado`, { method: 'POST', body: JSON.stringify({ estado }) }),
  brandPosts: (filters = {}) => {
    const qs = new URLSearchParams(filters).toString();
    return request(`/brand/posts${qs ? `?${qs}` : ''}`);
  },
  brandPostCreate: (data) => request('/brand/posts', { method: 'POST', body: JSON.stringify(data) }),
  brandPostMetricas: (id, metricas) => request(`/brand/posts/${id}/metricas`, { method: 'POST', body: JSON.stringify(metricas) }),
  brandStats: () => request('/brand/stats'),

  // Web Push (notifications nativas en iPhone/Android)
  pushKey: () => request('/push/key'),
  pushSubscribe: (subscription, ua) => request('/push/subscribe', { method: 'POST', body: JSON.stringify({ subscription, ua }) }),
  pushUnsubscribe: (endpoint) => request('/push/unsubscribe', { method: 'POST', body: JSON.stringify({ endpoint }) }),
  pushTest: () => request('/push/test', { method: 'POST' }),

  // Chat con coaches (envía mensaje, recibe reply)
  chatCoaches: () => request('/chat/coaches'),
  chat: (coach, message) => request('/chat', { method: 'POST', body: JSON.stringify({ coach, message }) }),
  // Hilo persistente de una coach (no aplica a 'directora' — ella usa
  // el history de WhatsApp). Devuelve { coach, messages: [{ role, content, ts }] }.
  coachThread: (coach) => request(`/coach_thread/${encodeURIComponent(coach)}`),
  coachThreadClear: (coach) => request(`/coach_thread/${encodeURIComponent(coach)}`, { method: 'DELETE' }),
  // Plan vigente de una coach — lo que ella le ha recomendado a Isabel.
  // Devuelve { coach_id, items: [{ id, text, status, ts_created, ts_updated }], actualizado }.
  coachPlan: (coach) => request(`/coach_plan/${encodeURIComponent(coach)}`),
  coachPlanAdd: (coach, text) =>
    request(`/coach_plan/${encodeURIComponent(coach)}`, { method: 'POST', body: JSON.stringify({ text }) }),
  coachPlanUpdate: (coach, itemId, patch) =>
    request(`/coach_plan/${encodeURIComponent(coach)}/${encodeURIComponent(itemId)}`, { method: 'PATCH', body: JSON.stringify(patch) }),
  coachPlanRemove: (coach, itemId) =>
    request(`/coach_plan/${encodeURIComponent(coach)}/${encodeURIComponent(itemId)}`, { method: 'DELETE' }),
  coachPlanClear: (coach) => request(`/coach_plan/${encodeURIComponent(coach)}`, { method: 'DELETE' }),
};
