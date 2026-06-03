import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';

const STATUS_LABELS = { pending: 'Pendientes', leido: 'Leídos', archivado: 'Archivados' };
const STATUS_COLORS = {
  pending: 'bg-yellow/20 text-ink-2',
  leido: 'bg-green/20 text-green-700',
  archivado: 'bg-lino-200 text-ink-3',
};

export default function Reading() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState('pending');
  const [composing, setComposing] = useState(false);
  const [url, setUrl] = useState('');
  const [titulo, setTitulo] = useState('');
  const [notas, setNotas] = useState('');
  const [tags, setTags] = useState('');
  const [err, setErr] = useState('');
  const [expandedId, setExpandedId] = useState(null);

  async function reload() {
    setLoading(true);
    try { setItems(await api.readingList(status)); }
    catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, [status]);

  async function submit() {
    setErr('');
    const u = url.trim();
    if (!u) { setErr('URL requerida.'); return; }
    try {
      await api.readingAdd({
        url: u,
        titulo: titulo.trim() || null,
        notas: notas.trim() || null,
        tags: tags.split(',').map((t) => t.trim()).filter(Boolean),
      });
      setUrl(''); setTitulo(''); setNotas(''); setTags('');
      setComposing(false);
      reload();
    } catch (e) {
      setErr(e.message);
    }
  }

  async function changeStatus(id, newStatus) {
    try {
      await api.readingUpdate(id, { status: newStatus });
      reload();
    } catch (e) {
      setErr(e.message);
    }
  }

  async function remove(id) {
    if (!confirm('¿Borrar este item?')) return;
    try {
      await api.readingRemove(id);
      reload();
    } catch (e) {
      setErr(e.message);
    }
  }

  return (
    <div className="space-y-5">
      <header className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h2 className="font-serif text-3xl text-lino-800">Reading List</h2>
          <p className="text-ink-3 text-sm">Tu pocket interno — links que quieres procesar después.</p>
        </div>
        <button onClick={() => setComposing((c) => !c)} className="btn-primary text-sm">
          {composing ? 'Cancelar' : '+ Agregar URL'}
        </button>
      </header>

      {composing && (
        <div className="card space-y-2">
          <input
            type="url"
            value={url}
            onChange={(e) => setUrl(e.target.value)}
            placeholder="https://…"
            className="input w-full text-sm"
            autoFocus
          />
          <input
            type="text"
            value={titulo}
            onChange={(e) => setTitulo(e.target.value)}
            placeholder="Título (opcional)"
            className="input w-full text-sm"
          />
          <input
            type="text"
            value={notas}
            onChange={(e) => setNotas(e.target.value)}
            placeholder="Por qué te interesa (opcional)"
            className="input w-full text-sm"
          />
          <input
            type="text"
            value={tags}
            onChange={(e) => setTags(e.target.value)}
            placeholder="tags separados por coma (ej. medicare, AEP)"
            className="input w-full text-sm"
          />
          {err && <p className="text-red text-xs">{err}</p>}
          <div className="flex justify-end">
            <button onClick={submit} disabled={!url.trim()} className="btn-primary text-sm">Guardar</button>
          </div>
        </div>
      )}

      <div className="flex gap-2">
        {Object.entries(STATUS_LABELS).map(([key, label]) => (
          <button
            key={key}
            onClick={() => setStatus(key)}
            className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
              status === key ? 'bg-lino-700 text-white' : 'bg-lino-100 text-ink-2 hover:bg-lino-200'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !items.length && <p className="text-ink-3 text-sm italic">Sin items en {STATUS_LABELS[status].toLowerCase()}.</p>}

      <div className="space-y-2">
        {items.map((i) => (
          <div key={i.id} className="card">
            <div className="flex items-start justify-between gap-2">
              <div className="flex-1 min-w-0">
                <a href={i.url} target="_blank" rel="noopener noreferrer" className="text-sm font-medium text-lino-800 hover:underline break-words block">
                  {i.titulo || i.url}
                </a>
                <div className="text-xs text-ink-3 mt-0.5">
                  {i.fuente || 'web'}
                  {i.tags?.length > 0 && <> · {i.tags.map((t) => `#${t}`).join(' ')}</>}
                  {' · '}
                  {new Date(i.agregado_ts).toLocaleDateString('es-MX', { month: 'short', day: 'numeric' })}
                </div>
                {i.notas && <p className="text-xs text-ink-2 mt-1 italic">{i.notas}</p>}
                {i.resumen && (
                  <div className="mt-2">
                    <button onClick={() => setExpandedId(expandedId === i.id ? null : i.id)} className="text-xs text-lino-700 hover:underline">
                      {expandedId === i.id ? '▾ Ocultar resumen' : '▸ Ver resumen'}
                    </button>
                    {expandedId === i.id && (
                      <pre className="text-xs text-ink-1 whitespace-pre-wrap font-sans mt-2 bg-lino-50 p-3 rounded">{i.resumen}</pre>
                    )}
                  </div>
                )}
              </div>
              <div className="flex gap-1 shrink-0">
                {status !== 'leido' && <button onClick={() => changeStatus(i.id, 'leido')} className="text-xs text-ink-3 hover:text-green-700" title="Marcar leído">✓</button>}
                {status !== 'archivado' && <button onClick={() => changeStatus(i.id, 'archivado')} className="text-xs text-ink-3 hover:text-ink-1" title="Archivar">📁</button>}
                {status !== 'pending' && <button onClick={() => changeStatus(i.id, 'pending')} className="text-xs text-ink-3 hover:text-yellow" title="Reactivar">↻</button>}
                <button onClick={() => remove(i.id)} className="text-xs text-ink-3 hover:text-red" title="Borrar">✕</button>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
