import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Search } from 'lucide-react';
import { api } from '../lib/api.js';

// Clientes — buscar y abrir expediente.
// Search-as-you-type con debounce de 300ms. Persistimos últimos 8 buscados
// en localStorage para acceso rápido.

const RECENT_KEY = 'athena_recent_clients';

function loadRecent() {
  try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); } catch { return []; }
}
function pushRecent(m) {
  const list = loadRecent().filter((x) => x.id !== m.id);
  list.unshift({ id: m.id, nombre: m.nombre, carrier: m.carrier, ts: Date.now() });
  try { localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 8))); } catch { /* ignore */ }
}

export default function Clientes() {
  const [q, setQ] = useState('');
  const [results, setResults] = useState([]);
  const [searching, setSearching] = useState(false);
  const [err, setErr] = useState('');
  const [recent, setRecent] = useState(loadRecent());
  const debRef = useRef(null);

  useEffect(() => {
    setErr('');
    if (debRef.current) clearTimeout(debRef.current);
    if (q.trim().length < 2) { setResults([]); return; }
    debRef.current = setTimeout(async () => {
      setSearching(true);
      try {
        const r = await api.lunaSearch(q.trim());
        if (r.ok) setResults(r.results || []);
        else setErr(r.reason || 'búsqueda falló');
      } catch (e) { setErr(e.message); }
      finally { setSearching(false); }
    }, 300);
    return () => debRef.current && clearTimeout(debRef.current);
  }, [q]);

  function onPick(m) {
    pushRecent(m);
    setRecent(loadRecent());
  }

  return (
    <div className="pb-12">
      {/* Masthead */}
      <header className="flex items-end justify-between border-b border-ink-1 pt-2 pb-3 mb-8">
        <div className="font-serif text-sm tracking-wide text-ink-1">
          ATHENA <span className="font-mono text-xs text-ink-3 ml-2">Clientes</span>
        </div>
      </header>

      {/* LEAD */}
      <section className="mb-8">
        <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-2">
          Tu CRM en una pantalla
        </p>
        <h1 className="font-serif text-[2rem] leading-[1.1] tracking-tight text-ink-1">
          Busca a quien sea.<br/>
          <span className="italic font-light text-ink-3">Por nombre, teléfono o MBI.</span>
        </h1>
      </section>

      {/* SEARCH */}
      <div className="border-b border-ink-1 pb-4 mb-8 flex items-center gap-3">
        <Search size={20} strokeWidth={1.5} className="text-ink-3" />
        <input
          autoFocus
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Maritza · 310… · 8XJ4…"
          className="flex-1 bg-transparent font-serif italic text-2xl py-1 outline-none placeholder:text-ink-3/50"
        />
        {searching && <span className="font-mono text-[10px] uppercase tracking-wider text-ink-3">buscando…</span>}
      </div>

      {err && <p className="text-red font-mono text-xs uppercase mb-4">{err}</p>}

      {/* RESULTS */}
      {results.length > 0 && (
        <section className="mb-10">
          <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-3">
            Resultados ({results.length})
          </p>
          <div className="space-y-1">
            {results.map((m) => (
              <Link
                key={m.id}
                to={`/clientes/${encodeURIComponent(m.id)}`}
                onClick={() => onPick(m)}
                className="grid grid-cols-[1fr_auto] gap-3 items-baseline border-b border-lino-300 pb-2 hover:border-ink-1 transition-colors group"
              >
                <div>
                  <p className="font-serif text-lg text-ink-1 leading-tight">{m.nombre}</p>
                  <p className="font-mono text-[10px] tracking-wide text-ink-3 uppercase mt-0.5">
                    {[m.carrier, m.plan, m.estado].filter(Boolean).join(' · ')}
                    {m.telefono && <> · {m.telefono}</>}
                  </p>
                </div>
                <span className="font-mono text-[10px] text-ink-3 group-hover:text-ink-1">→</span>
              </Link>
            ))}
          </div>
        </section>
      )}

      {/* RECENT */}
      {q.length < 2 && recent.length > 0 && (
        <section>
          <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-3">
            Recientes
          </p>
          <div className="space-y-1">
            {recent.map((m) => (
              <Link
                key={m.id}
                to={`/clientes/${encodeURIComponent(m.id)}`}
                className="grid grid-cols-[1fr_auto] gap-3 items-baseline border-b border-lino-300 pb-2 hover:border-ink-1 transition-colors group"
              >
                <div>
                  <p className="font-serif text-lg text-ink-1 leading-tight">{m.nombre}</p>
                  {m.carrier && <p className="font-mono text-[10px] tracking-wide text-ink-3 uppercase mt-0.5">{m.carrier}</p>}
                </div>
                <span className="font-mono text-[10px] text-ink-3 group-hover:text-ink-1">→</span>
              </Link>
            ))}
          </div>
        </section>
      )}

      {q.length < 2 && recent.length === 0 && (
        <p className="font-serif italic text-ink-3 text-center py-12">
          Empieza a escribir. Athena busca en LUNA en tiempo real.
        </p>
      )}
    </div>
  );
}
