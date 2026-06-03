import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';

const SEV_STYLE = {
  alto: 'bg-red/10 border-red/30 text-red',
  aviso: 'bg-orange/10 border-orange/30 text-orange-700',
  info: 'bg-lino-100 border-lino-200 text-ink-2',
};

const SEV_ICON = { alto: '🛑', aviso: '⚠️', info: 'ℹ️' };

const EMOCION_LABELS = {
  estres: '😣 Estrés',
  alegria: '😊 Alegría',
  frustracion: '😤 Frustración',
  tristeza: '😢 Tristeza',
  miedo: '😨 Miedo',
  paz: '☮️ Paz',
};

export default function Insights() {
  const [data, setData] = useState(null);
  const [err, setErr] = useState('');

  useEffect(() => {
    api.insights().then(setData).catch((e) => setErr(e.message));
  }, []);

  if (err) return <p className="text-red">{err}</p>;
  if (!data) return <p className="text-ink-3">Cargando insights…</p>;

  const signalsByPrio = (data.signals || []).slice().sort((a, b) => {
    const order = { alto: 0, aviso: 1, info: 2 };
    return (order[a.severidad] || 99) - (order[b.severidad] || 99);
  });

  const topEmociones = Object.entries(data.emotional_pattern?.counts || {})
    .sort((a, b) => b[1] - a[1])
    .slice(0, 6);

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Insights</h2>
        <p className="text-ink-3 text-sm">
          Lo que Athena ha detectado.
          {data.signals_ts && (
            <span className="ml-1">Última reflexión: {new Date(data.signals_ts).toLocaleString('es-MX', { dateStyle: 'short', timeStyle: 'short' })}.</span>
          )}
        </p>
      </header>

      {/* Signals — alertas detectadas por reflexión nocturna */}
      <section>
        <h3 className="text-sm font-medium text-lino-800 mb-2">Señales activas</h3>
        {!signalsByPrio.length && (
          <p className="text-ink-3 text-sm italic">Sin señales activas. (La reflexión corre a las 2am — si es muy temprano puede estar vacío.)</p>
        )}
        <div className="space-y-2">
          {signalsByPrio.map((s, i) => (
            <div key={i} className={`card border ${SEV_STYLE[s.severidad] || SEV_STYLE.info}`}>
              <div className="flex items-start gap-2">
                <span className="shrink-0">{SEV_ICON[s.severidad] || '·'}</span>
                <div className="flex-1">
                  <div className="text-sm font-medium">{s.mensaje}</div>
                  <div className="text-xs opacity-70 mt-1">{s.tipo}</div>
                </div>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* Patrones emocionales — últimos 14 días */}
      {data.emotional_pattern?.n_entradas > 0 && (
        <section>
          <h3 className="text-sm font-medium text-lino-800 mb-2">
            Tu estado emocional (últimos {data.emotional_pattern.dias_analizados}d)
          </h3>
          <div className="card">
            <p className="text-xs text-ink-3 mb-3">
              Basado en {data.emotional_pattern.n_entradas} entrada{data.emotional_pattern.n_entradas !== 1 ? 's' : ''} de journal.
            </p>
            <div className="space-y-2">
              {topEmociones.map(([emo, n]) => {
                const max = topEmociones[0][1];
                const pct = Math.round((n / max) * 100);
                return (
                  <div key={emo} className="text-sm">
                    <div className="flex justify-between mb-1">
                      <span className="text-ink-1">{EMOCION_LABELS[emo] || emo}</span>
                      <span className="text-ink-3 text-xs">{n} menci{n !== 1 ? 'ones' : 'ón'}</span>
                    </div>
                    <div className="w-full bg-lino-200 rounded-full h-1.5 overflow-hidden">
                      <div className="bg-lino-700 h-full" style={{ width: `${pct}%` }} />
                    </div>
                  </div>
                );
              })}
            </div>
          </div>
        </section>
      )}

      {/* Decisiones abiertas (AAR pendientes) */}
      {data.open_decisions?.length > 0 && (
        <section>
          <h3 className="text-sm font-medium text-lino-800 mb-2">Decisiones abiertas — pendientes de cerrar</h3>
          <div className="space-y-2">
            {data.open_decisions.map((d) => (
              <div key={d.id} className="card">
                <div className="flex items-start justify-between gap-2">
                  <div className="flex-1">
                    <p className="text-sm text-ink-1">{d.intended}</p>
                    {d.target && <p className="text-xs text-ink-3 mt-1">Target: {d.target}</p>}
                    {d.context && <p className="text-xs text-ink-3 mt-1 italic">{d.context}</p>}
                  </div>
                  <span className="text-xs text-ink-3 shrink-0">{d.type}</span>
                </div>
              </div>
            ))}
          </div>
        </section>
      )}

      {/* Learnings — qué se cerró recientemente */}
      {data.learnings?.length > 0 && (
        <section>
          <h3 className="text-sm font-medium text-lino-800 mb-2">Aprendizajes recientes</h3>
          <div className="space-y-2">
            {data.learnings.map((l, i) => (
              <div key={i} className="card">
                <p className="text-sm text-ink-1">{l.learning || l.actual}</p>
                {l.gap && <p className="text-xs text-ink-3 mt-1">Gap: {l.gap}</p>}
              </div>
            ))}
          </div>
        </section>
      )}
    </div>
  );
}
