import { useEffect, useState } from 'react';
import { api } from '../../lib/api.js';
import Section from '../Section.jsx';
import ItemRow from '../ItemRow.jsx';

export default function TasksPanel() {
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
    <div className="space-y-4">
      <div className="flex justify-end">
        <select className="input text-sm" value={filter} onChange={(e) => setFilter(e.target.value)}>
          <option value="pendiente">Pendientes</option>
          <option value="completada">Completadas</option>
          <option value="cancelada">Canceladas</option>
          <option value="">Todas</option>
        </select>
      </div>

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
