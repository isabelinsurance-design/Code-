import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api.js';
import PushSettings from '../components/PushSettings.jsx';

export default function Hoy() {
  const [state, setState] = useState(null);
  const [stats, setStats] = useState({
    trends_pending: 0,
    trends_top_score: 0,
    reading_pending: 0,
    goals_active: 0,
    goals_off_track: 0,
    rapport_latest: null,
    journal_week: 0,
    plans_total_active: 0,
  });
  const [err, setErr] = useState('');

  useEffect(() => {
    api.hoyState().then(setState).catch((e) => setErr(e.message));

    // Carga stats en paralelo, ninguna bloquea — cada una tolera falla.
    (async () => {
      const next = {};
      try {
        const t = await api.trends('pending');
        next.trends_pending = t.items?.length || 0;
        next.trends_top_score = t.items?.[0]?.score || 0;
      } catch {}
      try {
        const r = await api.readingList('pending');
        next.reading_pending = r?.length || 0;
      } catch {}
      try {
        const g = await api.goalsList('activa');
        next.goals_active = g?.length || 0;
        next.goals_off_track = (g || []).filter((m) => m.proyeccion && !m.proyeccion.en_track).length;
      } catch {}
      try {
        const rap = await api.rapport(1);
        next.rapport_latest = rap.trend?.latest || null;
      } catch {}
      try {
        const j = await api.journalList(7);
        next.journal_week = j?.length || 0;
      } catch {}
      try {
        const p = await api.coachPlansAll();
        next.plans_total_active = (p || []).reduce((acc, c) => acc + c.items.filter((i) => i.status === 'active').length, 0);
      } catch {}
      setStats((s) => ({ ...s, ...next }));
    })();
  }, []);

  if (err) return <p className="text-red">{err}</p>;
  if (!state) return <p className="text-ink-3">Cargando tu día…</p>;

  function daysAgo(iso) {
    if (!iso) return null;
    const d = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
    if (d < 1) return 'hoy';
    if (d === 1) return 'ayer';
    return `hace ${d}d`;
  }

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Hoy</h2>
        <p className="text-ink-3 text-sm">{state.fecha}</p>
      </header>

      {/* Dashboard summary — stats clicables a cada sección */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Link to="/trends" className="card hover:bg-lino-50 transition-colors">
          <div className="text-xs text-ink-3">Trends</div>
          <div className="font-serif text-2xl text-lino-800 mt-1">{stats.trends_pending}</div>
          <div className="text-xs text-ink-3">{stats.trends_top_score > 0 ? `top ${stats.trends_top_score}/10` : 'pendientes'}</div>
        </Link>
        <Link to="/plans" className="card hover:bg-lino-50 transition-colors">
          <div className="text-xs text-ink-3">Plan activo</div>
          <div className="font-serif text-2xl text-lino-800 mt-1">{stats.plans_total_active}</div>
          <div className="text-xs text-ink-3">items de coaches</div>
        </Link>
        <Link to="/goals" className="card hover:bg-lino-50 transition-colors">
          <div className="text-xs text-ink-3">Metas</div>
          <div className="font-serif text-2xl text-lino-800 mt-1">{stats.goals_active}</div>
          <div className={`text-xs ${stats.goals_off_track > 0 ? 'text-orange-700' : 'text-ink-3'}`}>
            {stats.goals_off_track > 0 ? `⚠ ${stats.goals_off_track} off-track` : 'activas'}
          </div>
        </Link>
        <Link to="/reading" className="card hover:bg-lino-50 transition-colors">
          <div className="text-xs text-ink-3">Reading</div>
          <div className="font-serif text-2xl text-lino-800 mt-1">{stats.reading_pending}</div>
          <div className="text-xs text-ink-3">pendientes</div>
        </Link>
        <Link to="/journal" className="card hover:bg-lino-50 transition-colors">
          <div className="text-xs text-ink-3">Journal</div>
          <div className="font-serif text-2xl text-lino-800 mt-1">{stats.journal_week}</div>
          <div className="text-xs text-ink-3">esta semana</div>
        </Link>
        <Link to="/rapport" className="card hover:bg-lino-50 transition-colors">
          <div className="text-xs text-ink-3">Rapport</div>
          <div className="font-serif text-2xl text-lino-800 mt-1">
            {stats.rapport_latest?.peso_lbs ? stats.rapport_latest.peso_lbs : '—'}
            {stats.rapport_latest?.peso_lbs && <span className="text-base text-ink-3"> lbs</span>}
          </div>
          <div className="text-xs text-ink-3">{daysAgo(stats.rapport_latest?.ts) || 'sin data'}</div>
        </Link>
        <Link to="/coaches" className="card hover:bg-lino-50 transition-colors">
          <div className="text-xs text-ink-3">Coaches</div>
          <div className="font-serif text-2xl text-lino-800 mt-1">17</div>
          <div className="text-xs text-ink-3">disponibles</div>
        </Link>
        <Link to="/search" className="card hover:bg-lino-50 transition-colors">
          <div className="text-xs text-ink-3">Buscar</div>
          <div className="font-serif text-2xl text-lino-800 mt-1">🔍</div>
          <div className="text-xs text-ink-3">global</div>
        </Link>
      </div>

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
