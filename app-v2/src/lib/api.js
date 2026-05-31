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

  // Chat con coaches (envía mensaje, recibe reply)
  chat: (coach, message) => request('/chat', { method: 'POST', body: JSON.stringify({ coach, message }) }),
};
