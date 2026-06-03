import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';

function SubscoreBar({ label, value, max = 20 }) {
  const pct = Math.min(100, Math.round((value / max) * 100));
  const color = pct >= 75 ? 'bg-green-700' : pct >= 50 ? 'bg-lino-700' : 'bg-orange';
  return (
    <div className="text-xs">
      <div className="flex justify-between mb-1">
        <span className="text-ink-2">{label}</span>
        <span className="text-ink-3">{value}/{max}</span>
      </div>
      <div className="w-full bg-lino-200 rounded-full h-1.5 overflow-hidden">
        <div className={`h-full ${color}`} style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

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
  const [grades, setGrades] = useState([]);
  const [grading, setGrading] = useState(false);
  const [err, setErr] = useState('');

  async function reloadGrades() {
    try { setGrades(await api.selfGrades(6)); } catch { /* tolera */ }
  }

  useEffect(() => {
    api.insights().then(setData).catch((e) => setErr(e.message));
    reloadGrades();
  }, []);

  async function gradeNow() {
    setGrading(true); setErr('');
    try {
      await api.selfGradeRun();
      reloadGrades();
    } catch (e) {
      setErr(e.message);
    } finally {
      setGrading(false);
    }
  }

  async function markImplemented(semana) {
    try {
      await api.selfGradeImplemented(semana);
      reloadGrades();
    } catch (e) { setErr(e.message); }
  }

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

      {/* Self-grade de Athena — auto-evaluación semanal */}
      <section>
        <div className="flex items-center justify-between mb-2">
          <h3 className="text-sm font-medium text-lino-800">📊 Self-grade de Athena</h3>
          <button onClick={gradeNow} disabled={grading} className="text-xs text-lino-700 hover:underline">
            {grading ? 'Calculando…' : 'Correr ahora'}
          </button>
        </div>
        {!grades.length && (
          <p className="text-ink-3 text-sm italic">Sin self-grades todavía. El cron corre domingo 8pm — o dale "Correr ahora".</p>
        )}
        {grades.length > 0 && (
          <>
            {/* Trayectoria visual */}
            <div className="card mb-2">
              <div className="flex items-end gap-2 h-16">
                {grades.slice().reverse().map((g) => {
                  const h = Math.max(8, Math.round((g.score / 100) * 64));
                  return (
                    <div key={g.semana} className="flex-1 flex flex-col items-center justify-end" title={`${g.semana}: ${g.score}/100`}>
                      <div className={`w-full rounded-t ${g.score >= 80 ? 'bg-green-700' : g.score >= 60 ? 'bg-lino-700' : 'bg-orange'}`} style={{ height: h }} />
                    </div>
                  );
                })}
              </div>
              <div className="flex justify-between mt-1 text-xs text-ink-3">
                <span>{grades[grades.length - 1]?.semana}</span>
                <span>{grades[0]?.semana} (último)</span>
              </div>
            </div>

            {/* Último grade en detalle */}
            {(() => {
              const g = grades[0];
              const delta = g.deltas?.total || 0;
              return (
                <div className="card">
                  <div className="flex items-baseline justify-between mb-3">
                    <div>
                      <span className="font-serif text-3xl text-lino-800">{g.score}<span className="text-ink-3 text-base">/100</span></span>
                      <span className={`ml-2 text-sm ${delta >= 0 ? 'text-green-700' : 'text-orange-700'}`}>
                        {delta >= 0 ? '+' : ''}{delta} vs sem prev
                      </span>
                    </div>
                    <span className="text-xs text-ink-3">{g.semana}</span>
                  </div>
                  <div className="grid grid-cols-2 gap-3 mb-3">
                    <SubscoreBar label="Response (sin errores)" value={g.subscores?.response || 0} />
                    <SubscoreBar label="Coverage (tools usadas)" value={g.subscores?.coverage || 0} />
                    <SubscoreBar label="Engagement (volumen)" value={g.subscores?.engagement || 0} />
                    <SubscoreBar label="Proactive (Isabel responde)" value={g.subscores?.proactive || 0} />
                    <SubscoreBar label="Team (sin atrasados)" value={g.subscores?.team || 0} />
                  </div>
                  {g.cambio_propuesto && (
                    <div className="mt-3 pt-3 border-t border-lino-200">
                      <div className="text-xs text-ink-3 mb-1">Cambio propuesto para próxima sem:</div>
                      <pre className="text-xs text-ink-1 whitespace-pre-wrap font-sans">{g.cambio_propuesto}</pre>
                      {!g.implementado && (
                        <button onClick={() => markImplemented(g.semana)} className="mt-2 text-xs text-lino-700 hover:underline">
                          ✓ Marcar como implementado
                        </button>
                      )}
                      {g.implementado && (
                        <p className="text-xs text-green-700 mt-2">✓ Implementado el {g.implementado_ts?.slice(0, 10)}</p>
                      )}
                    </div>
                  )}
                </div>
              );
            })()}
          </>
        )}
      </section>

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
