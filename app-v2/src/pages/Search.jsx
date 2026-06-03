import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api.js';

const SECTION_LABELS = {
  wiki: '📖 Wiki',
  entities: '👥 Personas',
  journal: '📓 Journal',
  reading: '📚 Reading list',
  tasks: '✎ Tareas',
  commitments: '🤝 Compromisos',
  coach_plans: '◎ Coach plans',
  coach_notes: '🗂 Expedientes de coaches',
  coach_threads: '💬 Conversaciones con coaches',
};

export default function Search() {
  const [q, setQ] = useState('');
  const [results, setResults] = useState(null);
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState('');
  const debounceRef = useRef(null);

  useEffect(() => {
    if (q.trim().length < 2) {
      setResults(null);
      return;
    }
    setErr('');
    if (debounceRef.current) clearTimeout(debounceRef.current);
    debounceRef.current = setTimeout(async () => {
      setLoading(true);
      try {
        const r = await api.searchGlobal(q.trim());
        setResults(r);
      } catch (e) {
        setErr(e.message);
      } finally {
        setLoading(false);
      }
    }, 300);
    return () => debounceRef.current && clearTimeout(debounceRef.current);
  }, [q]);

  const sections = results ? Object.entries(results.results || {}) : [];

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Buscar</h2>
        <p className="text-ink-3 text-sm">Una sola caja. Busca en wiki, journal, personas, reading list, tareas, compromisos, planes y conversaciones de coaches.</p>
      </header>

      <input
        type="text"
        value={q}
        onChange={(e) => setQ(e.target.value)}
        placeholder="Escribe lo que buscas — mínimo 2 letras…"
        className="input w-full text-base"
        autoFocus
      />

      {err && <p className="text-red text-xs">{err}</p>}
      {loading && <p className="text-ink-3 text-sm">Buscando…</p>}

      {results && results.total === 0 && !loading && (
        <p className="text-ink-3 text-sm italic">Sin resultados para "{results.query}".</p>
      )}

      {results && results.total > 0 && (
        <p className="text-xs text-ink-3">{results.total} resultado{results.total !== 1 ? 's' : ''} en {sections.length} categoría{sections.length !== 1 ? 's' : ''}.</p>
      )}

      <div className="space-y-5">
        {sections.map(([section, items]) => (
          <div key={section}>
            <h3 className="text-sm font-medium text-lino-800 mb-2">
              {SECTION_LABELS[section] || section} <span className="text-xs text-ink-3 font-normal">({Array.isArray(items) ? items.length : 0})</span>
            </h3>
            <div className="space-y-2">
              {section === 'wiki' && items.map((n, i) => (
                <div key={i} className="card text-sm">
                  <p className="text-ink-1">{n.texto}</p>
                  <p className="text-xs text-ink-3 mt-1">{n.fecha && new Date(n.fecha).toLocaleDateString('es-MX')}</p>
                </div>
              ))}
              {section === 'entities' && items.map((e) => (
                <div key={e.id} className="card text-sm">
                  <div className="flex items-center justify-between">
                    <span className="font-medium text-ink-1">{e.canonical_name}</span>
                    <span className="text-xs text-ink-3">{e.type} · {e.notas_count} nota(s)</span>
                  </div>
                  {e.top_note && <p className="text-xs text-ink-2 mt-1 italic">{e.top_note}</p>}
                </div>
              ))}
              {section === 'journal' && items.map((j) => (
                <div key={j.id} className="card text-sm">
                  <p className="text-xs text-ink-3 mb-1">{j.dia} · {j.tipo}{j.emociones?.length > 0 && ` · ${j.emociones.join(', ')}`}</p>
                  <p className="text-ink-1">{j.texto}</p>
                </div>
              ))}
              {section === 'reading' && items.map((r) => (
                <div key={r.id} className="card text-sm">
                  <a href={r.url} target="_blank" rel="noopener noreferrer" className="text-lino-800 font-medium hover:underline">
                    {r.titulo || r.url}
                  </a>
                  <p className="text-xs text-ink-3 mt-1">{r.fuente} · {r.status}</p>
                  {r.notas && <p className="text-xs text-ink-2 mt-1 italic">{r.notas}</p>}
                </div>
              ))}
              {section === 'tasks' && items.map((t) => (
                <div key={t.id} className="card text-sm">
                  <p className="text-ink-1">{t.descripcion}</p>
                  <p className="text-xs text-ink-3 mt-1">{t.responsable} · {t.status}{t.vence ? ` · vence ${t.vence.slice(0, 10)}` : ''}</p>
                </div>
              ))}
              {section === 'commitments' && items.map((c) => (
                <div key={c.id} className="card text-sm">
                  <p className="text-ink-1"><strong>{c.persona}</strong> → {c.descripcion}</p>
                  <p className="text-xs text-ink-3 mt-1">{c.status}{c.vence ? ` · vence ${c.vence.slice(0, 10)}` : ''}</p>
                </div>
              ))}
              {section === 'coach_plans' && items.map((p, i) => (
                <Link key={i} to={`/chat/${p.coach_id}`} className="card text-sm block hover:bg-lino-50">
                  <p className="text-ink-1">{p.text}</p>
                  <p className="text-xs text-ink-3 mt-1">{p.coach_id} · {p.status}</p>
                </Link>
              ))}
              {section === 'coach_notes' && items.map((n, i) => (
                <Link key={i} to={`/chat/${n.coach_id}`} className="card text-sm block hover:bg-lino-50">
                  <p className="text-xs text-ink-3 mb-1">expediente de <strong>{n.coach_id}</strong>:</p>
                  <p className="text-ink-1">{n.snippet}</p>
                </Link>
              ))}
              {section === 'coach_threads' && items.map((t, i) => (
                <div key={i} className="card text-sm">
                  <Link to={`/chat/${t.coach_id}`} className="text-xs text-lino-800 font-medium hover:underline">
                    Chat con {t.coach_id} ({t.hits.length} hit{t.hits.length !== 1 ? 's' : ''})
                  </Link>
                  <div className="space-y-1 mt-2">
                    {t.hits.map((h, j) => (
                      <div key={j} className="text-xs">
                        <span className="text-ink-3 font-mono">{h.role}:</span>{' '}
                        <span className="text-ink-2">{h.snippet}</span>
                      </div>
                    ))}
                  </div>
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
