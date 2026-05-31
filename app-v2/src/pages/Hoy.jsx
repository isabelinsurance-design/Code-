import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';
import PushSettings from '../components/PushSettings.jsx';

export default function Hoy() {
  const [state, setState] = useState(null);
  const [err, setErr] = useState('');

  useEffect(() => {
    api.hoyState().then(setState).catch((e) => setErr(e.message));
  }, []);

  if (err) return <p className="text-red">{err}</p>;
  if (!state) return <p className="text-ink-3">Cargando tu día…</p>;

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Hoy</h2>
        <p className="text-ink-3 text-sm">{state.fecha}</p>
      </header>

      <PushSettings />

      {state.trust && (
        <section className="card">
          <div className="flex items-baseline justify-between mb-2">
            <h3 className="font-medium text-ink-1">Trust score</h3>
            <span className="font-serif text-3xl text-lino-700">{state.trust.total}<span className="text-ink-3 text-base">/100</span></span>
          </div>
          <p className="text-ink-2 text-sm uppercase tracking-wide mb-1">{state.trust.veredicto}</p>
          <p className="text-ink-2">{state.trust.recomendacion}</p>
        </section>
      )}

      {state.focus_blocks?.length > 0 && (
        <section className="card">
          <h3 className="font-medium text-ink-1 mb-2">Tiempo protegido hoy</h3>
          <ul className="space-y-1">
            {state.focus_blocks.map((b) => (
              <li key={b.id} className="text-sm text-ink-2">
                <span className="font-medium">{b.titulo}</span> — {b.modo}, {b.inicio_hhmm}–{b.fin_hhmm}
              </li>
            ))}
          </ul>
        </section>
      )}

      {state.routines?.length > 0 && (
        <section className="card">
          <h3 className="font-medium text-ink-1 mb-2">Rutinas</h3>
          <ul className="space-y-1">
            {state.routines.map((r) => (
              <li key={r.id} className="text-sm text-ink-2">
                <span className="font-medium">{r.nombre}</span>
                {r.hora_inicio && <span className="text-ink-3"> · {r.hora_inicio}</span>}
                <span className="text-ink-3"> · {r.done}/{r.pasos.length} pasos</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {state.legal_alerts && (state.legal_alerts.vencidas?.length || state.legal_alerts['7']?.length) && (
        <section className="card border-amber/40 bg-amber/5">
          <h3 className="font-medium text-ink-1 mb-2">Legal — atención</h3>
          {state.legal_alerts.vencidas?.map((o) => (
            <p key={o.id} className="text-sm text-red"><strong>VENCIDA:</strong> {o.descripcion} ({o.dias_vencida}d)</p>
          ))}
          {state.legal_alerts['7']?.map((o) => (
            <p key={o.id} className="text-sm text-amber">{o.descripcion} en {o.dias_falt} días</p>
          ))}
        </section>
      )}
    </div>
  );
}
