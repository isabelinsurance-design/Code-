import { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api.js';

const TYPE_LABELS = {
  client: '🩺 Cliente',
  lead: '🌱 Lead',
  family: '🏠 Familia',
  team: '👥 Equipo',
  vendor: '🤝 Vendor',
  broker: '💼 Broker',
  doctor: '⚕️ Doctor',
  friend: '✨ Amigo',
  other: '· Otro',
};

const TYPES = Object.keys(TYPE_LABELS);

export default function Entities() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('');
  const [type, setType] = useState('');
  const [expanded, setExpanded] = useState(null);
  const [detail, setDetail] = useState(null);
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    try { setItems(await api.entitiesList(type || null)); }
    catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, [type]);

  async function showDetail(id) {
    if (expanded === id) {
      setExpanded(null);
      setDetail(null);
      return;
    }
    try {
      const d = await api.entityGet(id);
      setExpanded(id);
      setDetail(d);
    } catch (e) { setErr(e.message); }
  }

  const filtered = useMemo(() => {
    const q = filter.toLowerCase().trim();
    if (!q) return items;
    return items.filter((e) => {
      const aliases = (e.aliases || []).join(' ');
      const notes = (e.notas || []).map((n) => n.texto || '').join(' ');
      const blob = `${e.canonical_name} ${aliases} ${notes}`.toLowerCase();
      return blob.includes(q);
    });
  }, [items, filter]);

  // Group by type para UI más legible
  const grouped = useMemo(() => {
    const g = {};
    for (const e of filtered) {
      const t = e.type || 'other';
      if (!g[t]) g[t] = [];
      g[t].push(e);
    }
    return TYPES.filter((t) => g[t]?.length).map((t) => [t, g[t]]);
  }, [filtered]);

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Personas</h2>
        <p className="text-ink-3 text-sm">El directorio que Athena mantiene — clientes, equipo, familia, todos.</p>
      </header>

      {err && <p className="text-red text-xs">{err}</p>}

      <input
        type="text"
        value={filter}
        onChange={(e) => setFilter(e.target.value)}
        placeholder="Buscar por nombre, alias o nota…"
        className="input w-full text-sm"
      />

      <div className="flex flex-wrap gap-2">
        <button
          onClick={() => setType('')}
          className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
            type === '' ? 'bg-lino-700 text-white' : 'bg-lino-100 text-ink-2 hover:bg-lino-200'
          }`}
        >
          Todos
        </button>
        {TYPES.map((t) => (
          <button
            key={t}
            onClick={() => setType(t)}
            className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
              type === t ? 'bg-lino-700 text-white' : 'bg-lino-100 text-ink-2 hover:bg-lino-200'
            }`}
          >
            {TYPE_LABELS[t] || t}
          </button>
        ))}
      </div>

      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !filtered.length && (
        <p className="text-ink-3 text-sm italic">
          {items.length === 0
            ? 'Athena no conoce a nadie todavía. Cuando le hables de personas en WhatsApp, las va agregando.'
            : `Sin matches.`}
        </p>
      )}

      <div className="space-y-4">
        {grouped.map(([typeGroup, entities]) => (
          <div key={typeGroup}>
            <h3 className="text-xs font-medium text-ink-3 uppercase tracking-wide mb-2">
              {TYPE_LABELS[typeGroup] || typeGroup} ({entities.length})
            </h3>
            <div className="space-y-2">
              {entities.map((e) => {
                const topNote = (e.notas || []).slice().sort((a, b) => (b.salience || 5) - (a.salience || 5))[0];
                const lastMention = e.ultima_mencion || e.actualizado;
                return (
                  <div key={e.id} className="card">
                    <button
                      onClick={() => showDetail(e.id)}
                      className="w-full text-left"
                    >
                      <div className="flex items-start justify-between gap-2">
                        <div className="flex-1">
                          <div className="font-medium text-ink-1">{e.canonical_name}</div>
                          {e.aliases?.length > 0 && (
                            <div className="text-xs text-ink-3 mt-0.5">aka: {e.aliases.join(', ')}</div>
                          )}
                          {topNote && (
                            <p className="text-sm text-ink-2 mt-1 italic">{topNote.texto?.slice(0, 200)}</p>
                          )}
                        </div>
                        <div className="text-xs text-ink-3 shrink-0">
                          {(e.notas || []).length} nota{(e.notas || []).length !== 1 ? 's' : ''}
                          {lastMention && (
                            <div className="mt-1">
                              {new Date(lastMention).toLocaleDateString('es-MX', { month: 'short', day: 'numeric' })}
                            </div>
                          )}
                        </div>
                      </div>
                    </button>
                    {expanded === e.id && detail && (
                      <div className="mt-3 pt-3 border-t border-lino-200 space-y-2">
                        {(detail.notas || []).map((n, i) => (
                          <div key={i} className="text-sm">
                            <p className="text-ink-1">{n.texto}</p>
                            <p className="text-xs text-ink-3 mt-1">
                              {n.fecha && new Date(n.fecha).toLocaleDateString('es-MX', { month: 'short', day: 'numeric' })}
                              {n.salience && ` · salience ${n.salience}`}
                            </p>
                          </div>
                        ))}
                        {detail.cliente_id && (
                          <p className="text-xs text-lino-700">Vinculado a cliente CRM: {detail.cliente_id}</p>
                        )}
                      </div>
                    )}
                  </div>
                );
              })}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
