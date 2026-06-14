import { useEffect, useState } from 'react';
import { RefreshCw } from 'lucide-react';
import { api } from '../lib/api.js';

// Uso / Costos — estimación en vivo. NO es facturación real,
// es derivada del activity log + precios promedio.

const TOOL_LABEL = {
  morning_briefing: 'Briefing matutino',
  day_plan_scheduled: 'Day plan (manager)',
  evening_checkin: 'Check-in noche',
  weekly_review: 'Weekly review',
  saturday_brief: 'Saturday brief',
  nightly_reflection: 'Dreaming nocturno',
  closing_loop: 'Cierre del día',
  triage_inbox: 'Triage inbox',
  inbox_idle_react: 'Email idle react',
  ticket_monitor_alert: 'Ticket monitor',
  vacation_morning_report: 'Vacación morning',
  vacation_evening_report: 'Vacación evening',
  commitment_chase: 'Chase compromisos',
  overload_check: 'Overload check',
  eod_team_nudge: 'EOD team nudge',
  hourly_nudge: 'Hourly nudge',
  daily_audit: 'Daily audit',
  pre_meeting_deep: 'Pre-meeting brief',
  coach_cadence_auto: 'Coach cadence',
  focus_block_auto: 'Focus block',
  self_grade: 'Self-grade',
  trends_scan: 'Trends scan',
  research_digest: 'Research digest',
  isabel_pregunta: 'Chats con Isabel',
  consultar_especialistas: 'Consultar coaches',
  llamar_cliente: 'Llamadas',
  web_search: 'Web search',
};

function money(n) {
  return `$${(n || 0).toFixed(2)}`;
}

function Period({ title, period, total }) {
  if (!period) return null;
  return (
    <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
      <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
        {title}
      </div>
      <div className="border-b border-lino-400 pb-6">
        <div className="flex items-baseline justify-between mb-4">
          <span className="font-serif text-[2.4rem] leading-none text-ink-1 font-light">
            {money(period.cost)}
          </span>
          <span className="font-mono text-[10px] uppercase tracking-wider text-ink-3">
            {period.count} acciones
          </span>
        </div>
        {period.top?.length > 0 && (
          <div className="space-y-1">
            {period.top.slice(0, 8).map((t) => (
              <div key={t.tool} className="grid grid-cols-[1fr_50px_40px] gap-3 items-baseline text-sm">
                <span className="font-serif text-ink-1 truncate">
                  {TOOL_LABEL[t.tool] || t.tool.replace(/_/g, ' ')}
                </span>
                <span className="font-mono text-xs text-ink-3 text-right">{money(t.cost)}</span>
                <span className="font-mono text-[10px] text-ink-3 text-right">{t.pct || 0}%</span>
              </div>
            ))}
          </div>
        )}
        {period.cost === 0 && (
          <p className="font-serif italic text-ink-3 text-sm">Sin actividad en este periodo.</p>
        )}
      </div>
    </article>
  );
}

export default function Uso() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    setErr('');
    try { setData(await api.usage()); }
    catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  if (loading && !data) {
    return <p className="font-mono text-xs uppercase text-ink-3 py-12">Calculando…</p>;
  }

  return (
    <div className="pb-12">
      {/* Masthead */}
      <header className="flex items-end justify-between border-b border-ink-1 pt-2 pb-3 mb-8">
        <div className="font-serif text-sm tracking-wide text-ink-1">
          ATHENA <span className="font-mono text-xs text-ink-3 ml-2">Uso · costos</span>
        </div>
        <button
          onClick={reload}
          disabled={loading}
          className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1.5"
        >
          <RefreshCw size={11} strokeWidth={1.5} className={loading ? 'animate-spin' : ''} />
          {loading ? 'Cargando' : 'Refrescar'}
        </button>
      </header>

      {/* LEAD */}
      <section className="mb-10">
        <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-2">
          Lo que Athena gasta
        </p>
        <h1 className="font-serif text-[2rem] leading-[1.1] tracking-tight text-ink-1">
          {data?.month?.cost > 0
            ? <span><span className="font-light">{money(data.month.cost)}</span> este mes.</span>
            : <span><em className="italic font-light">Mes nuevo.</em><br/>Aún sin actividad.</span>}
        </h1>
      </section>

      {err && <p className="text-red font-mono text-xs uppercase mb-4">{err}</p>}

      {/* COSTO REAL — tokens exactos (cuando hay datos) */}
      {data?.real?.has_data && (
        <section className="border border-lino-400 p-4 mb-10">
          <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-3">
            Costo real · tokens exactos
          </p>
          <div className="grid grid-cols-3 gap-3">
            {[['Hoy', 'today'], ['Semana', 'week'], ['Mes', 'month']].map(([lbl, k]) => (
              <div key={k}>
                <div className="font-serif text-[1.5rem] leading-none text-ink-1 font-light">
                  {money(data.real[k]?.cost)}
                </div>
                <div className="font-mono text-[9px] uppercase tracking-wider text-ink-3 mt-1">
                  {lbl} · {data.real[k]?.count || 0} llamadas
                </div>
              </div>
            ))}
          </div>
          <p className="font-serif italic text-[11px] text-ink-3 mt-3 leading-relaxed">
            Números exactos de los tokens cobrados. Lo de abajo es la estimación por tipo de tarea.
          </p>
        </section>
      )}

      <Period title="Hoy" period={data?.today} />
      <Period title="Esta semana" period={data?.week} />
      <Period title="Este mes" period={data?.month} />

      {data?.disclaimer && (
        <p className="font-serif italic text-xs text-ink-3 mt-8 leading-relaxed">
          {data.disclaimer}
        </p>
      )}
    </div>
  );
}
