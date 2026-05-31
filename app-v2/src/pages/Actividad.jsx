import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';

export default function Actividad() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);

  async function reload() {
    setLoading(true);
    try { setItems(await api.activity(100)); } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  return (
    <div className="space-y-5">
      <header className="flex items-end justify-between">
        <div>
          <h2 className="font-serif text-3xl text-lino-800">Actividad</h2>
          <p className="text-ink-3 text-sm">Cada tool que Athena ha ejecutado (PII redacted).</p>
        </div>
        <button onClick={reload} className="btn-ghost text-sm">Refrescar</button>
      </header>

      <div className="card">
        {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
        {!loading && !items.length && <p className="text-ink-3 text-sm">Sin actividad registrada.</p>}
        <ul className="divide-y divide-lino-200">
          {items.map((a, i) => (
            <li key={i} className="py-2 text-sm">
              <div className="flex items-baseline justify-between gap-3">
                <code className="font-mono text-xs text-lino-700 font-medium">{a.tool}</code>
                <span className="text-xs text-ink-3 shrink-0">{new Date(a.ts || a.timestamp || a.creado).toLocaleString()}</span>
              </div>
              {a.input_summary && <div className="text-xs text-ink-2 mt-0.5"><span className="text-ink-3">in:</span> {a.input_summary}</div>}
              {a.result_summary && <div className="text-xs text-ink-2 mt-0.5"><span className="text-ink-3">out:</span> {a.result_summary}</div>}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
