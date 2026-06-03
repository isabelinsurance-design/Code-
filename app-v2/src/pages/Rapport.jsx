import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';

export default function Rapport() {
  const [items, setItems] = useState([]);
  const [trend, setTrend] = useState(null);
  const [loading, setLoading] = useState(true);
  const [composing, setComposing] = useState(false);
  const [peso, setPeso] = useState('');
  const [cintura, setCintura] = useState('');
  const [cadera, setCadera] = useState('');
  const [brazo, setBrazo] = useState('');
  const [muslo, setMuslo] = useState('');
  const [sentires, setSentires] = useState('');
  const [periodo, setPeriodo] = useState('');
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    try {
      const r = await api.rapport(26);
      setItems(r.items || []);
      setTrend(r.trend);
    } catch (e) {
      setErr(e.message);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => { reload(); }, []);

  async function submit() {
    setErr('');
    const medidas = {};
    if (cintura) medidas.cintura = Number(cintura);
    if (cadera) medidas.cadera = Number(cadera);
    if (brazo) medidas.brazo = Number(brazo);
    if (muslo) medidas.muslo = Number(muslo);
    try {
      await api.rapportAdd({
        peso_lbs: peso ? Number(peso) : null,
        medidas: Object.keys(medidas).length ? medidas : null,
        sentires: sentires.trim() || null,
        periodo: periodo || null,
      });
      setPeso(''); setCintura(''); setCadera(''); setBrazo(''); setMuslo(''); setSentires(''); setPeriodo('');
      setComposing(false);
      reload();
    } catch (e) {
      setErr(e.message);
    }
  }

  // Sparkline simple del peso — sin libraries, SVG inline
  const pesoSeries = items
    .slice()
    .reverse() // cronológico
    .map((i) => i.peso_lbs)
    .filter((v) => typeof v === 'number');

  const sparkline = () => {
    if (pesoSeries.length < 2) return null;
    const min = Math.min(...pesoSeries);
    const max = Math.max(...pesoSeries);
    const range = max - min || 1;
    const w = 300;
    const h = 60;
    const step = w / (pesoSeries.length - 1);
    const points = pesoSeries.map((v, i) => `${i * step},${h - ((v - min) / range) * h}`).join(' ');
    return (
      <svg width={w} height={h} className="text-lino-700">
        <polyline points={points} fill="none" stroke="currentColor" strokeWidth="2" />
        {pesoSeries.map((v, i) => (
          <circle key={i} cx={i * step} cy={h - ((v - min) / range) * h} r="2.5" fill="currentColor" />
        ))}
      </svg>
    );
  };

  return (
    <div className="space-y-5">
      <header className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h2 className="font-serif text-3xl text-lino-800">Rapport</h2>
          <p className="text-ink-3 text-sm">Snapshots semanales del cuerpo + cómo te sientes.</p>
        </div>
        <button onClick={() => setComposing((c) => !c)} className="btn-primary text-sm">
          {composing ? 'Cancelar' : '+ Nuevo rapport'}
        </button>
      </header>

      {trend && trend.latest && (
        <div className="card bg-lino-50">
          <div className="flex items-end justify-between flex-wrap gap-2">
            <div>
              <div className="text-xs text-ink-3">Último rapport (semana {trend.latest.semana})</div>
              <div className="text-3xl font-serif text-lino-800 mt-1">
                {trend.latest.peso_lbs ? `${trend.latest.peso_lbs} lbs` : 'sin peso'}
              </div>
              <div className="flex gap-3 text-xs text-ink-2 mt-1">
                {trend.delta_4w !== null && (
                  <span>4 sem: <span className={trend.delta_4w < 0 ? 'text-green-700' : 'text-ink-1'}>{trend.delta_4w > 0 ? '+' : ''}{trend.delta_4w} lbs</span></span>
                )}
                {trend.delta_12w !== null && (
                  <span>12 sem: <span className={trend.delta_12w < 0 ? 'text-green-700' : 'text-ink-1'}>{trend.delta_12w > 0 ? '+' : ''}{trend.delta_12w} lbs</span></span>
                )}
              </div>
            </div>
            <div>{sparkline()}</div>
          </div>
        </div>
      )}

      {composing && (
        <div className="card space-y-2">
          <div className="grid grid-cols-2 gap-2">
            <input type="number" value={peso} onChange={(e) => setPeso(e.target.value)} placeholder="Peso (lbs)" className="input text-sm" step="0.1" />
            <select value={periodo} onChange={(e) => setPeriodo(e.target.value)} className="input text-sm">
              <option value="">Periodo…</option>
              <option value="regular">Regular</option>
              <option value="irregular">Irregular</option>
              <option value="no aplica">No aplica</option>
            </select>
          </div>
          <div className="text-xs text-ink-3 mt-2">Medidas (pulgadas, opcional):</div>
          <div className="grid grid-cols-4 gap-2">
            <input type="number" value={cintura} onChange={(e) => setCintura(e.target.value)} placeholder="cintura" className="input text-sm" step="0.5" />
            <input type="number" value={cadera} onChange={(e) => setCadera(e.target.value)} placeholder="cadera" className="input text-sm" step="0.5" />
            <input type="number" value={brazo} onChange={(e) => setBrazo(e.target.value)} placeholder="brazo" className="input text-sm" step="0.5" />
            <input type="number" value={muslo} onChange={(e) => setMuslo(e.target.value)} placeholder="muslo" className="input text-sm" step="0.5" />
          </div>
          <textarea
            rows={3}
            value={sentires}
            onChange={(e) => setSentires(e.target.value)}
            placeholder="Cómo te sientes esta semana — energía, sueño, ánimo, lo que sea…"
            className="input w-full text-sm resize-none"
          />
          {err && <p className="text-red text-xs">{err}</p>}
          <div className="flex justify-end">
            <button onClick={submit} className="btn-primary text-sm">Guardar</button>
          </div>
        </div>
      )}

      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !items.length && <p className="text-ink-3 text-sm italic">Sin rapports todavía. Tu primero va a marcar el baseline.</p>}

      <div className="space-y-2">
        {items.map((i) => (
          <div key={i.id} className="card">
            <div className="flex items-baseline justify-between gap-2">
              <div className="text-sm font-medium text-lino-800">
                Semana {i.semana}
                {i.peso_lbs && <span className="ml-2 text-ink-1 font-serif">{i.peso_lbs} lbs</span>}
              </div>
              <span className="text-xs text-ink-3 shrink-0">{new Date(i.ts).toLocaleDateString('es-MX', { month: 'short', day: 'numeric' })}</span>
            </div>
            {i.medidas && Object.keys(i.medidas).length > 0 && (
              <div className="text-xs text-ink-2 mt-1">
                {Object.entries(i.medidas).map(([k, v]) => `${k} ${v}"`).join(' · ')}
              </div>
            )}
            {i.periodo && <div className="text-xs text-ink-3 mt-1">periodo: {i.periodo}</div>}
            {i.sentires && <p className="text-sm text-ink-1 mt-2 whitespace-pre-wrap">{i.sentires}</p>}
          </div>
        ))}
      </div>
    </div>
  );
}
