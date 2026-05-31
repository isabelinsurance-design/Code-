import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';
import Section from '../components/Section.jsx';
import ItemRow from '../components/ItemRow.jsx';

const OWNER_COLORS = {
  isabel: 'bg-lino-200 text-lino-800',
  athena: 'bg-amber/10 text-amber',
  sami: 'bg-lino-300 text-lino-800',
};

export default function Tareas() {
  const [tasks, setTasks] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('pendiente');

  async function reload() {
    setLoading(true);
    try { setTasks(await api.tasks(filter)); } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, [filter]);

  async function complete(id) { await api.taskComplete(id); await reload(); }
  async function cancel(id) {
    if (!confirm('¿Cancelar esta tarea?')) return;
    await api.taskCancel(id); await reload();
  }

  const grupos = {
    isabel: tasks.filter((t) => (t.responsable || t.owner) === 'isabel'),
    athena: tasks.filter((t) => (t.responsable || t.owner) === 'athena'),
    sami: tasks.filter((t) => (t.responsable || t.owner) === 'sami'),
  };

  return (
    <div className="space-y-5">
      <header className="flex items-end justify-between gap-3 flex-wrap">
        <div>
          <h2 className="font-serif text-3xl text-lino-800">Tareas</h2>
          <p className="text-ink-3 text-sm">Tu cola compartida con Athena y Sami.</p>
        </div>
        <select className="input text-sm" value={filter} onChange={(e) => setFilter(e.target.value)}>
          <option value="pendiente">Pendientes</option>
          <option value="completada">Completadas</option>
          <option value="cancelada">Canceladas</option>
          <option value="">Todas</option>
        </select>
      </header>

      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}

      {!loading && Object.entries(grupos).map(([owner, items]) => items.length > 0 && (
        <Section key={owner} title={`Owner: ${owner}`} subtitle={`${items.length} ${filter || 'tarea(s)'}`}>
          {items.map((t) => (
            <ItemRow
              key={t.id}
              title={t.descripcion || t.titulo}
              badge={t.responsable || t.owner}
              meta={
                <>
                  {t.vence && <span>Vence: {new Date(t.vence).toLocaleDateString()}</span>}
                  {t.contexto && <div className="mt-1 italic">{t.contexto}</div>}
                </>
              }
              actions={
                filter === 'pendiente' && (
                  <>
                    <button onClick={() => complete(t.id)} className="text-xs text-lino-700 hover:underline px-2">Completar</button>
                    <button onClick={() => cancel(t.id)} className="text-xs text-red hover:underline px-2">Cancelar</button>
                  </>
                )
              }
            />
          ))}
        </Section>
      ))}

      {!loading && !tasks.length && (
        <p className="text-ink-3 text-sm text-center py-8">Nada en esta vista.</p>
      )}
    </div>
  );
}
