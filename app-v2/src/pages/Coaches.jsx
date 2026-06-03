import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api.js';

export default function Coaches() {
  const [coaches, setCoaches] = useState([]);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    try { setCoaches(await api.coachesOverview()); }
    catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  function timeAgo(iso) {
    if (!iso) return null;
    const ms = Date.now() - new Date(iso).getTime();
    const d = Math.floor(ms / 86400000);
    if (d < 1) return 'hoy';
    if (d === 1) return 'ayer';
    if (d < 7) return `hace ${d}d`;
    if (d < 30) return `hace ${Math.floor(d / 7)}sem`;
    return `hace ${Math.floor(d / 30)}m`;
  }

  // Ordena: coaches con data primero, luego alfabético
  const sorted = [...coaches].sort((a, b) => {
    if (a.id === 'directora') return -1;
    if (b.id === 'directora') return 1;
    if (a.has_data !== b.has_data) return b.has_data - a.has_data;
    return a.name.localeCompare(b.name);
  });

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Coaches</h2>
        <p className="text-ink-3 text-sm">Tu equipo completo. Toca cualquiera para abrir chat.</p>
      </header>

      {err && <p className="text-red text-xs">{err}</p>}
      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}

      <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
        {sorted.map((c) => (
          <Link
            key={c.id}
            to={c.id === 'directora' ? '/chat' : `/chat/${c.id}`}
            className={`card hover:shadow-md transition-shadow ${c.has_data ? '' : 'opacity-70'}`}
          >
            <div className="flex items-start justify-between mb-2">
              <div>
                <div className="font-serif text-lg text-lino-800">{c.name}</div>
                <div className="text-xs text-ink-3">{c.role}</div>
              </div>
              {c.thread_last_ts && (
                <span className="text-xs text-ink-3 shrink-0">{timeAgo(c.thread_last_ts)}</span>
              )}
            </div>
            <div className="flex gap-3 text-xs text-ink-2 mt-2">
              {c.id === 'directora' ? (
                <span className="italic text-ink-3">Memoria global vía WhatsApp</span>
              ) : (
                <>
                  {c.plan_total > 0 && (
                    <span>
                      <strong className="text-lino-700">◎</strong> {c.plan_active} activo{c.plan_active !== 1 ? 's' : ''}
                      {c.plan_total > c.plan_active && <span className="text-ink-3"> /{c.plan_total}</span>}
                    </span>
                  )}
                  {c.notes_length > 0 && (
                    <span><strong className="text-lino-700">🗂</strong> expediente {Math.round(c.notes_length / 100) / 10}k</span>
                  )}
                  {c.thread_length > 0 && (
                    <span><strong className="text-lino-700">💬</strong> {c.thread_length} turn{c.thread_length !== 1 ? 's' : ''}</span>
                  )}
                  {!c.has_data && <span className="italic text-ink-3">Sin conversaciones todavía</span>}
                </>
              )}
            </div>
          </Link>
        ))}
      </div>
    </div>
  );
}
