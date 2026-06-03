import { useEffect, useMemo, useState } from 'react';
import { api } from '../lib/api.js';

const EMOCION_COLORS = {
  estres: 'bg-red/10 text-red',
  frustracion: 'bg-orange/10 text-orange-700',
  tristeza: 'bg-blue/10 text-blue-700',
  miedo: 'bg-purple/10 text-purple-700',
  alegria: 'bg-green/10 text-green-700',
  paz: 'bg-teal/10 text-teal-700',
};

export default function Journal() {
  const [items, setItems] = useState([]);
  const [pattern, setPattern] = useState(null);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState('');
  const [composing, setComposing] = useState(false);
  const [texto, setTexto] = useState('');
  const [tipo, setTipo] = useState('journal');
  const [gratitud, setGratitud] = useState('');
  const [frustracion, setFrustracion] = useState('');
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    try {
      const [list, pat] = await Promise.all([
        api.journalList(60),
        api.journalPattern(14),
      ]);
      setItems(list);
      setPattern(pat);
    } catch (e) {
      setErr(e.message);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => { reload(); }, []);

  const filtered = useMemo(() => {
    const q = filter.toLowerCase().trim();
    if (!q) return items;
    return items.filter((e) => {
      const blob = `${e.texto || ''} ${e.gratitud || ''} ${e.frustracion || ''}`.toLowerCase();
      return blob.includes(q);
    });
  }, [items, filter]);

  // Agrupa por día para UI más legible
  const grouped = useMemo(() => {
    const g = {};
    for (const e of filtered) {
      const dia = e.dia || (e.ts || '').slice(0, 10);
      if (!g[dia]) g[dia] = [];
      g[dia].push(e);
    }
    return Object.entries(g).sort((a, b) => b[0].localeCompare(a[0]));
  }, [filtered]);

  async function submit() {
    setErr('');
    const t = texto.trim();
    if (!t) { setErr('Escribe algo.'); return; }
    try {
      await api.journalAdd({
        texto: t,
        tipo,
        gratitud: gratitud.trim() || null,
        frustracion: frustracion.trim() || null,
      });
      setTexto(''); setGratitud(''); setFrustracion('');
      setComposing(false);
      reload();
    } catch (e) {
      setErr(e.message);
    }
  }

  return (
    <div className="space-y-5">
      <header className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h2 className="font-serif text-3xl text-lino-800">Journal</h2>
          <p className="text-ink-3 text-sm">Lo que has escrito últimamente — buscable, agrupado por día.</p>
        </div>
        <button onClick={() => setComposing((c) => !c)} className="btn-primary text-sm">
          {composing ? 'Cancelar' : '+ Nueva entrada'}
        </button>
      </header>

      {pattern && pattern.n_entradas > 0 && (
        <div className="card bg-lino-50">
          <div className="text-xs text-ink-3 mb-1">Patrones emocionales (últimos {pattern.dias_analizados}d, {pattern.n_entradas} entradas):</div>
          <div className="flex flex-wrap gap-2">
            {Object.entries(pattern.counts || {}).sort((a, b) => b[1] - a[1]).map(([emo, n]) => (
              <span key={emo} className={`px-2 py-0.5 rounded text-xs font-medium ${EMOCION_COLORS[emo] || 'bg-lino-200 text-ink-2'}`}>
                {emo} ×{n}
              </span>
            ))}
          </div>
        </div>
      )}

      {composing && (
        <div className="card space-y-2">
          <div className="flex gap-2">
            <select value={tipo} onChange={(e) => setTipo(e.target.value)} className="input text-sm">
              <option value="journal">journal</option>
              <option value="gratitud">gratitud</option>
              <option value="win">win</option>
              <option value="frustracion">frustración</option>
            </select>
          </div>
          <textarea
            rows={4}
            value={texto}
            onChange={(e) => setTexto(e.target.value)}
            placeholder="Lo que estás pensando, sintiendo, procesando…"
            className="input w-full text-sm resize-none"
            autoFocus
          />
          <input
            type="text"
            value={gratitud}
            onChange={(e) => setGratitud(e.target.value)}
            placeholder="Gratitud (opcional)"
            className="input w-full text-sm"
          />
          <input
            type="text"
            value={frustracion}
            onChange={(e) => setFrustracion(e.target.value)}
            placeholder="Frustración (opcional)"
            className="input w-full text-sm"
          />
          {err && <p className="text-red text-xs">{err}</p>}
          <div className="flex justify-end gap-2">
            <button onClick={submit} disabled={!texto.trim()} className="btn-primary text-sm">Guardar</button>
          </div>
        </div>
      )}

      <input
        type="text"
        value={filter}
        onChange={(e) => setFilter(e.target.value)}
        placeholder="Buscar en tu journal…"
        className="input w-full text-sm"
      />

      <div className="space-y-4">
        {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
        {!loading && !grouped.length && (
          <p className="text-ink-3 text-sm italic">
            {filter ? `Sin matches para "${filter}".` : 'Tu journal está vacío. Empieza con + Nueva entrada.'}
          </p>
        )}
        {grouped.map(([dia, entries]) => (
          <div key={dia}>
            <h3 className="text-xs font-medium text-ink-3 uppercase tracking-wide mb-2">
              {new Date(dia + 'T12:00:00').toLocaleDateString('es-MX', { weekday: 'long', day: 'numeric', month: 'long' })}
            </h3>
            <div className="space-y-2">
              {entries.map((e) => (
                <div key={e.id} className="card">
                  <div className="flex items-center justify-between mb-1">
                    <span className="text-xs text-ink-3 font-mono">{e.tipo}</span>
                    <span className="text-xs text-ink-3">{new Date(e.ts).toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' })}</span>
                  </div>
                  <p className="text-sm text-ink-1 whitespace-pre-wrap">{e.texto}</p>
                  {e.gratitud && <p className="text-xs text-green-700 mt-2">🙏 {e.gratitud}</p>}
                  {e.frustracion && <p className="text-xs text-orange-700 mt-1">😤 {e.frustracion}</p>}
                  {e.emociones?.length > 0 && (
                    <div className="flex flex-wrap gap-1 mt-2">
                      {e.emociones.map((emo) => (
                        <span key={emo} className={`px-1.5 py-0.5 rounded text-xs ${EMOCION_COLORS[emo] || 'bg-lino-200 text-ink-2'}`}>
                          {emo}
                        </span>
                      ))}
                    </div>
                  )}
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
