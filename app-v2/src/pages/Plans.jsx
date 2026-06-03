import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api.js';

const STATUS_LABELS = { active: 'Activos', paused: 'Pausados', done: 'Hechos', all: 'Todos' };

export default function Plans() {
  const [plans, setPlans] = useState([]);
  const [loading, setLoading] = useState(true);
  const [statusFilter, setStatusFilter] = useState('active');
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    try { setPlans(await api.coachPlansAll()); }
    catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function updateStatus(coach, itemId, status) {
    try {
      await api.coachPlanUpdate(coach, itemId, { status });
      reload();
    } catch (e) { setErr(e.message); }
  }

  async function removeItem(coach, itemId) {
    if (!confirm('¿Borrar este item del plan?')) return;
    try {
      await api.coachPlanRemove(coach, itemId);
      reload();
    } catch (e) { setErr(e.message); }
  }

  const filteredPlans = useMemo(() => {
    if (statusFilter === 'all') return plans;
    return plans.map((p) => ({
      ...p,
      items: p.items.filter((i) => i.status === statusFilter),
    })).filter((p) => p.items.length > 0);
  }, [plans, statusFilter]);

  const totalActive = useMemo(() => {
    return plans.reduce((acc, p) => acc + p.items.filter((i) => i.status === 'active').length, 0);
  }, [plans]);

  return (
    <div className="space-y-5">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Planes vigentes</h2>
        <p className="text-ink-3 text-sm">Lo que cada coach te ha recomendado — vista completa cross-coach.</p>
      </header>

      <div className="flex items-center justify-between flex-wrap gap-2">
        <div className="flex gap-2">
          {Object.entries(STATUS_LABELS).map(([key, label]) => (
            <button
              key={key}
              onClick={() => setStatusFilter(key)}
              className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
                statusFilter === key ? 'bg-lino-700 text-white' : 'bg-lino-100 text-ink-2 hover:bg-lino-200'
              }`}
            >
              {label}
            </button>
          ))}
        </div>
        <div className="text-xs text-ink-3">
          {totalActive} ítems activos en {plans.length} coach{plans.length !== 1 ? 'es' : ''}
        </div>
      </div>

      {err && <p className="text-red text-xs">{err}</p>}

      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !filteredPlans.length && (
        <p className="text-ink-3 text-sm italic">
          {plans.length === 0
            ? 'Ninguna coach tiene plan vigente todavía. Cuando chatees con alguna y te recomiende algo concreto, va a aparecer aquí.'
            : `Sin items ${STATUS_LABELS[statusFilter].toLowerCase()}.`}
        </p>
      )}

      <div className="space-y-4">
        {filteredPlans.map((plan) => (
          <div key={plan.coach_id} className="card">
            <div className="flex items-center justify-between mb-3">
              <Link to={`/chat/${plan.coach_id}`} className="text-lg font-serif text-lino-800 hover:underline">
                {plan.coach_name}
              </Link>
              <span className="text-xs text-ink-3">{plan.coach_role}</span>
            </div>
            <div className="space-y-1.5">
              {plan.items.map((item) => (
                <div key={item.id} className="flex items-start gap-2 text-sm">
                  <span className={`shrink-0 px-2 py-0.5 rounded text-xs font-mono ${
                    item.status === 'active' ? 'bg-green/20 text-green-700' :
                    item.status === 'paused' ? 'bg-yellow/30 text-ink-2' :
                    'bg-lino-200 text-ink-3 line-through'
                  }`}>
                    {item.status}
                  </span>
                  <span className={`flex-1 ${item.status === 'done' ? 'line-through text-ink-3' : 'text-ink-1'}`}>
                    {item.text}
                    <span className="ml-2 text-xs text-ink-3">desde {item.ts_created?.slice(0, 10)}</span>
                  </span>
                  <div className="flex gap-1 shrink-0">
                    {item.status !== 'active' && (
                      <button onClick={() => updateStatus(plan.coach_id, item.id, 'active')} className="text-xs text-ink-3 hover:text-lino-700" title="Reactivar">▶</button>
                    )}
                    {item.status === 'active' && (
                      <button onClick={() => updateStatus(plan.coach_id, item.id, 'paused')} className="text-xs text-ink-3 hover:text-yellow" title="Pausar">⏸</button>
                    )}
                    {item.status !== 'done' && (
                      <button onClick={() => updateStatus(plan.coach_id, item.id, 'done')} className="text-xs text-ink-3 hover:text-green-700" title="Marcar hecho">✓</button>
                    )}
                    <button onClick={() => removeItem(plan.coach_id, item.id)} className="text-xs text-ink-3 hover:text-red" title="Borrar">✕</button>
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
