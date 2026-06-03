import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';

export default function Trends() {
  const [items, setItems] = useState([]);
  const [topics, setTopics] = useState([]);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState('pending');
  const [topicId, setTopicId] = useState('');
  const [scanning, setScanning] = useState(false);
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    try {
      const r = await api.trends(status, topicId || null);
      setItems(r.items || []);
      setTopics(r.topics || []);
    } catch (e) {
      setErr(e.message);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => { reload(); }, [status, topicId]);

  async function scanNow() {
    setScanning(true);
    setErr('');
    try {
      const r = await api.trendsScanNow();
      setErr(`✓ Scan: ${r.fresh.length} nuevo(s), ${r.highScore.length} score≥8.`);
      await reload();
    } catch (e) {
      setErr(e.message);
    } finally {
      setScanning(false);
    }
  }

  async function mark(id, newStatus) {
    try {
      await api.trendsUpdate(id, newStatus);
      reload();
    } catch (e) {
      setErr(e.message);
    }
  }

  function scoreColor(s) {
    if (s >= 9) return 'bg-red/20 text-red';
    if (s >= 7) return 'bg-orange/20 text-orange-700';
    if (s >= 5) return 'bg-yellow/30 text-ink-1';
    return 'bg-lino-200 text-ink-3';
  }

  return (
    <div className="space-y-5">
      <header className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h2 className="font-serif text-3xl text-lino-800">Trends</h2>
          <p className="text-ink-3 text-sm">Lo que está volviéndose viral / trending / breaking en tus dominios. Scan automático 11am.</p>
        </div>
        <button onClick={scanNow} disabled={scanning} className="btn-primary text-sm">
          {scanning ? 'Buscando…' : '🔍 Scan ahora'}
        </button>
      </header>

      {err && <p className="text-xs text-ink-2">{err}</p>}

      <div className="flex flex-wrap gap-2">
        {['pending', 'aplicado', 'archivado'].map((s) => (
          <button
            key={s}
            onClick={() => setStatus(s)}
            className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
              status === s ? 'bg-lino-700 text-white' : 'bg-lino-100 text-ink-2 hover:bg-lino-200'
            }`}
          >
            {s}
          </button>
        ))}
        <select value={topicId} onChange={(e) => setTopicId(e.target.value)} className="input text-xs">
          <option value="">Todos los dominios</option>
          {topics.map((t) => (
            <option key={t.id} value={t.id}>{t.nombre}</option>
          ))}
        </select>
      </div>

      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !items.length && (
        <p className="text-ink-3 text-sm italic">
          {status === 'pending'
            ? 'Sin trends nuevos. El scout corre todos los días a las 11am, o dale "Scan ahora" para forzarlo.'
            : `Sin items ${status}.`}
        </p>
      )}

      <div className="space-y-3">
        {items.map((t) => (
          <div key={t.id} className="card">
            <div className="flex items-start justify-between gap-2 mb-2">
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-1">
                  <span className={`px-2 py-0.5 rounded text-xs font-mono ${scoreColor(t.score)}`}>
                    {t.score}/10
                  </span>
                  <span className="text-xs text-ink-3">{t.topic_nombre}</span>
                  <span className="text-xs text-ink-3">·</span>
                  <span className="text-xs text-ink-3">{new Date(t.ts).toLocaleDateString('es-MX', { month: 'short', day: 'numeric' })}</span>
                </div>
                {t.url ? (
                  <a href={t.url} target="_blank" rel="noopener noreferrer" className="text-sm font-medium text-lino-800 hover:underline">
                    {t.titulo}
                  </a>
                ) : (
                  <div className="text-sm font-medium text-ink-1">{t.titulo}</div>
                )}
              </div>
              <div className="flex gap-1 shrink-0">
                {status !== 'aplicado' && <button onClick={() => mark(t.id, 'aplicado')} className="text-xs text-ink-3 hover:text-green-700" title="Aplicado">✓</button>}
                {status !== 'archivado' && <button onClick={() => mark(t.id, 'archivado')} className="text-xs text-ink-3 hover:text-ink-1" title="Archivar">📁</button>}
                {status !== 'pending' && <button onClick={() => mark(t.id, 'pending')} className="text-xs text-ink-3 hover:text-yellow" title="Volver a pending">↻</button>}
              </div>
            </div>
            <p className="text-sm text-ink-1">{t.summary}</p>
            {t.razon_isabel && (
              <p className="text-xs text-lino-700 mt-2 italic">→ {t.razon_isabel}</p>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
