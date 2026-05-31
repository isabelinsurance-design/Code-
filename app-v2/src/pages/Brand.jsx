import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';
import Section from '../components/Section.jsx';
import ItemRow from '../components/ItemRow.jsx';

const PLATAFORMAS = [
  { value: 'youtube', label: 'YouTube' },
  { value: 'instagram_reel', label: 'IG Reel' },
  { value: 'instagram_carrusel', label: 'IG Carrusel' },
  { value: 'instagram_post', label: 'IG Post' },
  { value: 'tiktok', label: 'TikTok' },
  { value: 'short', label: 'Short' },
  { value: 'blog', label: 'Blog' },
];

const ESTADOS = ['idea', 'aprobada', 'grabando', 'editando', 'lista_publicar', 'publicada', 'archivada'];

const TABS = [
  { id: 'calendar', label: 'Calendario' },
  { id: 'ideas', label: 'Ideas' },
  { id: 'posts', label: 'Posts & métricas' },
];

export default function Brand() {
  const [tab, setTab] = useState('calendar');
  const [stats, setStats] = useState(null);

  useEffect(() => {
    api.brandStats().then(setStats).catch(() => setStats(null));
  }, []);

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Brand</h2>
        <p className="text-ink-3 text-sm">YouTube + Instagram. Marisol te ayuda; tú decides.</p>
      </header>

      {stats && stats.total_posts > 0 && (
        <div className="card grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
          <div><div className="text-2xl font-serif text-lino-700">{stats.total_posts}</div><div className="text-xs text-ink-3 uppercase tracking-wider">Posts 30d</div></div>
          <div><div className="text-2xl font-serif text-lino-700">{stats.vistas_total?.toLocaleString() || 0}</div><div className="text-xs text-ink-3 uppercase tracking-wider">Vistas</div></div>
          <div><div className="text-2xl font-serif text-lino-700">+{stats.seguidores_nuevos || 0}</div><div className="text-xs text-ink-3 uppercase tracking-wider">Seguidores</div></div>
          <div><div className="text-2xl font-serif text-lino-700">{stats.engagement_promedio || 0}</div><div className="text-xs text-ink-3 uppercase tracking-wider">Engagement prom</div></div>
        </div>
      )}

      <div className="flex gap-1 border-b border-lino-200 -mx-2 px-2 overflow-x-auto">
        {TABS.map((t) => (
          <button
            key={t.id}
            onClick={() => setTab(t.id)}
            className={`px-4 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap transition-colors ${
              tab === t.id ? 'border-lino-600 text-lino-800' : 'border-transparent text-ink-3 hover:text-ink-2'
            }`}
          >
            {t.label}
          </button>
        ))}
      </div>

      {tab === 'calendar' && <CalendarPanel />}
      {tab === 'ideas' && <IdeasPanel />}
      {tab === 'posts' && <PostsPanel />}
    </div>
  );
}

function CalendarPanel() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);

  async function reload() {
    setLoading(true);
    try { setItems(await api.brandCalendar(30)); } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function setEstado(id, estado) {
    await api.brandCalendarEstado(id, estado);
    await reload();
  }

  async function onCreate(data) {
    const r = await api.brandCalendarCreate(data);
    if (r.ok) { setShowForm(false); await reload(); }
    else alert(r.error || 'No se pudo crear.');
  }

  return (
    <Section
      title="Calendario de publicación"
      subtitle="Próximos 30 días. Cambia el estado conforme avances."
      action={
        <button onClick={() => setShowForm((s) => !s)} className="btn-primary text-sm">
          {showForm ? 'Cancelar' : '+ Agendar'}
        </button>
      }
    >
      {showForm && <CalendarForm onSubmit={onCreate} onCancel={() => setShowForm(false)} />}
      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !items.length && <p className="text-ink-3 text-sm">Calendario vacío. Agenda algo o aprueba una idea del backlog.</p>}
      {items.map((c) => {
        const fecha = new Date(c.fecha).toLocaleDateString('es-MX', { weekday: 'short', day: 'numeric', month: 'short' });
        return (
          <div key={c.id} className="border-b border-lino-200 last:border-0 py-2">
            <div className="flex items-start justify-between gap-3">
              <div className="flex-1">
                <div className="flex items-center gap-2 flex-wrap">
                  <span className="text-xs font-medium text-lino-700">{fecha}</span>
                  <span className="text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded bg-lino-200 text-lino-800">{c.plataforma}</span>
                  <span className="text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded bg-amber/10 text-amber">{c.estado}</span>
                </div>
                <div className="text-sm font-medium text-ink-1 mt-1">{c.titulo}</div>
                {c.hook && <div className="text-xs text-ink-2 italic mt-0.5">"{c.hook}"</div>}
              </div>
              <select
                value={c.estado}
                onChange={(e) => setEstado(c.id, e.target.value)}
                className="input text-xs shrink-0"
              >
                {ESTADOS.map((e) => <option key={e} value={e}>{e}</option>)}
              </select>
            </div>
          </div>
        );
      })}
    </Section>
  );
}

function CalendarForm({ onSubmit, onCancel }) {
  const [titulo, setTitulo] = useState('');
  const [plataforma, setPlataforma] = useState('youtube');
  const [fecha, setFecha] = useState('');
  const [hook, setHook] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function submit(e) {
    e.preventDefault();
    if (!titulo.trim() || !fecha) { alert('Pon título y fecha.'); return; }
    setSubmitting(true);
    try { await onSubmit({ titulo, plataforma, fecha, hook }); } finally { setSubmitting(false); }
  }

  return (
    <form onSubmit={submit} className="bg-lino-50 border border-lino-300 rounded-xl p-4 space-y-3 mb-2">
      <div>
        <label className="label">Título</label>
        <input className="input w-full" value={titulo} onChange={(e) => setTitulo(e.target.value)} autoFocus />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label">Plataforma</label>
          <select className="input w-full" value={plataforma} onChange={(e) => setPlataforma(e.target.value)}>
            {PLATAFORMAS.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
          </select>
        </div>
        <div>
          <label className="label">Fecha</label>
          <input type="date" className="input w-full" value={fecha} onChange={(e) => setFecha(e.target.value)} />
        </div>
      </div>
      <div>
        <label className="label">Hook (la primera línea)</label>
        <input className="input w-full" value={hook} onChange={(e) => setHook(e.target.value)} />
      </div>
      <div className="flex justify-end gap-2 pt-1">
        <button type="button" onClick={onCancel} className="btn-ghost text-sm">Cancelar</button>
        <button type="submit" disabled={submitting} className="btn-primary text-sm">{submitting ? '…' : 'Agendar'}</button>
      </div>
    </form>
  );
}

function IdeasPanel() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [filter, setFilter] = useState('idea');

  async function reload() {
    setLoading(true);
    try { setItems(await api.brandIdeas({ estado: filter })); } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, [filter]);

  async function onCreate(data) {
    const r = await api.brandIdeaCreate(data);
    if (r.ok) { setShowForm(false); await reload(); }
    else alert(r.error || 'No se pudo crear.');
  }

  async function bump(id) { await api.brandIdeaBump(id); await reload(); }
  async function archive(id) {
    if (!confirm('¿Archivar esta idea?')) return;
    await api.brandIdeaArchive(id); await reload();
  }

  async function promote(idea) {
    const fecha = prompt(`Agendar "${idea.titulo}" para qué fecha? (YYYY-MM-DD)`);
    if (!fecha) return;
    const r = await api.brandCalendarCreate({
      titulo: idea.titulo,
      plataforma: idea.plataforma || 'youtube',
      fecha,
      hook: idea.hook || '',
      idea_id: idea.id,
    });
    if (r.ok) {
      alert(`Agendada para ${fecha}. Ve al tab Calendario.`);
      await reload();
    } else {
      alert(r.error || 'No se pudo agendar.');
    }
  }

  return (
    <Section
      title="Ideas — backlog"
      subtitle="Descarta sin culpa. Mejor 5 ideas afiladas que 50 vagas."
      action={
        <div className="flex gap-2">
          <select className="input text-xs" value={filter} onChange={(e) => setFilter(e.target.value)}>
            <option value="idea">Sin usar</option>
            <option value="aprobada">Aprobadas</option>
            <option value="archivada">Archivadas</option>
          </select>
          <button onClick={() => setShowForm((s) => !s)} className="btn-primary text-sm">
            {showForm ? 'Cancelar' : '+ Idea'}
          </button>
        </div>
      }
    >
      {showForm && <IdeaForm onSubmit={onCreate} onCancel={() => setShowForm(false)} />}
      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !items.length && <p className="text-ink-3 text-sm">Sin ideas. Habla con Marisol en Chat — te tira 5.</p>}
      {items.map((idea) => (
        <ItemRow
          key={idea.id}
          title={idea.titulo}
          badge={`★${idea.salience}`}
          meta={
            <>
              <div>
                {idea.tema && <span className="mr-2">{idea.tema}</span>}
                {idea.plataforma && <span className="text-ink-3">· {idea.plataforma}</span>}
                {idea.formato && <span className="text-ink-3"> · {idea.formato}</span>}
              </div>
              {idea.hook && <div className="mt-1 italic">"{idea.hook}"</div>}
              {idea.notas && <div className="mt-1 text-ink-3">{idea.notas}</div>}
            </>
          }
          actions={
            filter === 'idea' && (
              <>
                <button onClick={() => bump(idea.id)} className="text-xs text-lino-700 hover:underline px-2">★</button>
                <button onClick={() => promote(idea)} className="text-xs text-lino-700 hover:underline px-2">Agendar</button>
                <button onClick={() => archive(idea.id)} className="text-xs text-red hover:underline px-2">Archivar</button>
              </>
            )
          }
        />
      ))}
    </Section>
  );
}

function IdeaForm({ onSubmit, onCancel }) {
  const [titulo, setTitulo] = useState('');
  const [hook, setHook] = useState('');
  const [notas, setNotas] = useState('');
  const [plataforma, setPlataforma] = useState('');
  const [tema, setTema] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function submit(e) {
    e.preventDefault();
    if (!titulo.trim()) { alert('Pon título.'); return; }
    setSubmitting(true);
    try { await onSubmit({ titulo, hook, notas, plataforma, tema }); }
    finally { setSubmitting(false); }
  }

  return (
    <form onSubmit={submit} className="bg-lino-50 border border-lino-300 rounded-xl p-4 space-y-3 mb-2">
      <div>
        <label className="label">Título / tema</label>
        <input className="input w-full" value={titulo} onChange={(e) => setTitulo(e.target.value)} autoFocus />
      </div>
      <div>
        <label className="label">Hook (la primera línea — opcional)</label>
        <input className="input w-full" value={hook} onChange={(e) => setHook(e.target.value)} />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label">Tema</label>
          <input className="input w-full" value={tema} onChange={(e) => setTema(e.target.value)} placeholder="Medicare / Latina founder / etc" />
        </div>
        <div>
          <label className="label">Plataforma sugerida</label>
          <select className="input w-full" value={plataforma} onChange={(e) => setPlataforma(e.target.value)}>
            <option value="">— sin elegir —</option>
            {PLATAFORMAS.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
          </select>
        </div>
      </div>
      <div>
        <label className="label">Notas (opcional)</label>
        <textarea rows={2} className="input w-full" value={notas} onChange={(e) => setNotas(e.target.value)} />
      </div>
      <div className="flex justify-end gap-2 pt-1">
        <button type="button" onClick={onCancel} className="btn-ghost text-sm">Cancelar</button>
        <button type="submit" disabled={submitting} className="btn-primary text-sm">{submitting ? '…' : 'Guardar idea'}</button>
      </div>
    </form>
  );
}

function PostsPanel() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);

  async function reload() {
    setLoading(true);
    try { setItems(await api.brandPosts()); } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function onCreate(data) {
    const r = await api.brandPostCreate(data);
    if (r.ok) { setShowForm(false); await reload(); }
    else alert(r.error || 'No se pudo registrar.');
  }

  async function updateMetricas(id) {
    const v = prompt('Vistas:'); if (v === null) return;
    const l = prompt('Likes:'); const c = prompt('Comentarios:');
    await api.brandPostMetricas(id, {
      vistas: parseInt(v, 10) || 0,
      likes: parseInt(l, 10) || 0,
      comentarios: parseInt(c, 10) || 0,
    });
    await reload();
  }

  return (
    <Section
      title="Posts publicados"
      subtitle="Lo que ya salió + métricas que vas actualizando."
      action={
        <button onClick={() => setShowForm((s) => !s)} className="btn-primary text-sm">
          {showForm ? 'Cancelar' : '+ Registrar post'}
        </button>
      }
    >
      {showForm && <PostForm onSubmit={onCreate} onCancel={() => setShowForm(false)} />}
      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !items.length && <p className="text-ink-3 text-sm">Sin posts registrados. Cuando publiques algo, regístralo aquí.</p>}
      {items.map((p) => (
        <div key={p.id} className="border-b border-lino-200 last:border-0 py-2">
          <div className="flex items-start justify-between gap-3">
            <div className="flex-1">
              <div className="font-medium text-ink-1">{p.titulo}</div>
              <div className="text-xs text-ink-3 mt-0.5">
                {new Date(p.fecha_publicacion).toLocaleDateString('es-MX')} · {p.plataforma}
                {p.url && <> · <a href={p.url} target="_blank" rel="noreferrer" className="text-lino-700 hover:underline">link ↗</a></>}
              </div>
              <div className="text-xs text-ink-2 mt-1">
                {p.metricas?.vistas != null && <span className="mr-2">👁 {p.metricas.vistas}</span>}
                {p.metricas?.likes != null && <span className="mr-2">♥ {p.metricas.likes}</span>}
                {p.metricas?.comentarios != null && <span className="mr-2">💬 {p.metricas.comentarios}</span>}
                {p.metricas?.seguidores_nuevos != null && <span className="mr-2">+{p.metricas.seguidores_nuevos} seguidores</span>}
              </div>
            </div>
            <button onClick={() => updateMetricas(p.id)} className="text-xs text-lino-700 hover:underline shrink-0">
              Actualizar métricas
            </button>
          </div>
        </div>
      ))}
    </Section>
  );
}

function PostForm({ onSubmit, onCancel }) {
  const [titulo, setTitulo] = useState('');
  const [plataforma, setPlataforma] = useState('youtube');
  const [url, setUrl] = useState('');
  const [fecha, setFecha] = useState(new Date().toISOString().slice(0, 10));
  const [submitting, setSubmitting] = useState(false);

  async function submit(e) {
    e.preventDefault();
    if (!titulo.trim()) { alert('Pon título.'); return; }
    setSubmitting(true);
    try { await onSubmit({ titulo, plataforma, url, fecha_publicacion: fecha }); }
    finally { setSubmitting(false); }
  }

  return (
    <form onSubmit={submit} className="bg-lino-50 border border-lino-300 rounded-xl p-4 space-y-3 mb-2">
      <div>
        <label className="label">Título</label>
        <input className="input w-full" value={titulo} onChange={(e) => setTitulo(e.target.value)} autoFocus />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label">Plataforma</label>
          <select className="input w-full" value={plataforma} onChange={(e) => setPlataforma(e.target.value)}>
            {PLATAFORMAS.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
          </select>
        </div>
        <div>
          <label className="label">Fecha de publicación</label>
          <input type="date" className="input w-full" value={fecha} onChange={(e) => setFecha(e.target.value)} />
        </div>
      </div>
      <div>
        <label className="label">URL (opcional)</label>
        <input className="input w-full" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://youtu.be/..." />
      </div>
      <div className="flex justify-end gap-2 pt-1">
        <button type="button" onClick={onCancel} className="btn-ghost text-sm">Cancelar</button>
        <button type="submit" disabled={submitting} className="btn-primary text-sm">{submitting ? '…' : 'Registrar'}</button>
      </div>
    </form>
  );
}
