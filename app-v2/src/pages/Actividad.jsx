import { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api.js';

export default function Actividad() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('');
  const [autoRefresh, setAutoRefresh] = useState(false);

  async function reload() {
    setLoading(true);
    try { setItems(await api.activity(200)); } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  // Auto-refresh cada 10s si el usuario lo activa — pantalla "live ops"
  useEffect(() => {
    if (!autoRefresh) return;
    const id = setInterval(reload, 10000);
    return () => clearInterval(id);
  }, [autoRefresh]);

  // Stats: top tools + errores en últimas 24h. Útil para detectar
  // patrones (¿está fallando algo? ¿qué herramientas usa Athena más?).
  const stats = useMemo(() => {
    const since = Date.now() - 24 * 3600 * 1000;
    const recent = items.filter((a) => new Date(a.ts || a.timestamp || 0).getTime() >= since);
    const byTool = {};
    let errors = 0;
    for (const a of recent) {
      byTool[a.tool] = (byTool[a.tool] || 0) + 1;
      const blob = `${a.result_summary || ''} ${a.input_summary || ''}`.toLowerCase();
      if (/error|falló|fail|timeout|no pude/.test(blob)) errors += 1;
    }
    const topTools = Object.entries(byTool).sort((a, b) => b[1] - a[1]).slice(0, 5);
    return { total24h: recent.length, errors, topTools };
  }, [items]);

  const filtered = useMemo(() => {
    const q = filter.toLowerCase().trim();
    if (!q) return items;
    return items.filter((a) => {
      const blob = `${a.tool} ${a.input_summary || ''} ${a.result_summary || ''}`.toLowerCase();
      return blob.includes(q);
    });
  }, [items, filter]);

  function isError(a) {
    const blob = `${a.result_summary || ''} ${a.input_summary || ''}`.toLowerCase();
    return /error|falló|fail|timeout|no pude/.test(blob);
  }

  return (
    <div className="space-y-5">
      <header className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h2 className="font-serif text-3xl text-lino-800">Actividad</h2>
          <p className="text-ink-3 text-sm">Cada tool que Athena ha ejecutado (PII redacted).</p>
        </div>
        <div className="flex items-center gap-2">
          <label className="flex items-center gap-1 text-xs text-ink-3">
            <input type="checkbox" checked={autoRefresh} onChange={(e) => setAutoRefresh(e.target.checked)} />
            Auto-refresh 10s
          </label>
          <button onClick={reload} className="btn-ghost text-sm">Refrescar</button>
        </div>
      </header>

      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div className="card text-center">
          <div className="text-2xl font-serif text-lino-800">{stats.total24h}</div>
          <div className="text-xs text-ink-3">tool calls (24h)</div>
        </div>
        <div className={`card text-center ${stats.errors > 0 ? 'bg-red/5 border-red/20' : ''}`}>
          <div className={`text-2xl font-serif ${stats.errors > 0 ? 'text-red' : 'text-lino-800'}`}>{stats.errors}</div>
          <div className="text-xs text-ink-3">errores (24h)</div>
        </div>
        <div className="card col-span-2 md:col-span-2">
          <div className="text-xs text-ink-3 mb-1">top tools (24h)</div>
          <div className="space-y-1">
            {stats.topTools.length === 0 && <div className="text-xs text-ink-3 italic">sin datos</div>}
            {stats.topTools.map(([tool, n]) => (
              <div key={tool} className="flex justify-between text-xs">
                <code className="text-lino-700">{tool}</code>
                <span className="text-ink-2">×{n}</span>
              </div>
            ))}
          </div>
        </div>
      </div>

      <input
        type="text"
        value={filter}
        onChange={(e) => setFilter(e.target.value)}
        placeholder="Filtrar por tool, input, output…"
        className="input w-full text-sm"
      />

      <div className="card">
        {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
        {!loading && !filtered.length && <p className="text-ink-3 text-sm">{filter ? `Sin matches para "${filter}".` : 'Sin actividad registrada.'}</p>}
        <ul className="divide-y divide-lino-200">
          {filtered.map((a, i) => (
            <li key={i} className={`py-2 text-sm ${isError(a) ? 'bg-red/5 -mx-2 px-2' : ''}`}>
              <div className="flex items-baseline justify-between gap-3">
                <code className={`font-mono text-xs font-medium ${isError(a) ? 'text-red' : 'text-lino-700'}`}>
                  {isError(a) && '⚠ '}{a.tool}
                </code>
                <span className="text-xs text-ink-3 shrink-0">{new Date(a.ts || a.timestamp || a.creado).toLocaleString()}</span>
              </div>
              {a.input_summary && <div className="text-xs text-ink-2 mt-0.5"><span className="text-ink-3">in:</span> {a.input_summary}</div>}
              {a.result_summary && <div className={`text-xs mt-0.5 ${isError(a) ? 'text-red' : 'text-ink-2'}`}><span className="text-ink-3">out:</span> {a.result_summary}</div>}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
