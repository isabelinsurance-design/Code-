import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Check, X, AlertTriangle, RefreshCw, Bell } from 'lucide-react';
import { api } from '../../lib/api.js';

// Bandeja operacional — alertas + drafts + triage + team en una vista.
// Estilo magazine consistente con Hoy / Decisiones.

function ago(iso) {
  if (!iso) return '';
  const sec = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (sec < 60) return `${sec}s`;
  const m = Math.floor(sec / 60);
  if (m < 60) return `${m}m`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h`;
  return `${Math.floor(h / 24)}d`;
}

export default function BandejaPanel() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState({});
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    setErr('');
    try { setData(await api.bandeja()); }
    catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function approveDraft(d) {
    const key = `draft:${d.id}`;
    setBusy((b) => ({ ...b, [key]: 'aprobando' }));
    try {
      await api.decisionApprove('outbound', d.id);
      await reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy((b) => { const x = { ...b }; delete x[key]; return x; }); }
  }
  async function discardDraft(d) {
    if (!confirm(`¿Descartar el mensaje a ${d.to}?`)) return;
    const key = `draft:${d.id}`;
    setBusy((b) => ({ ...b, [key]: 'descartando' }));
    try {
      await api.decisionDecline('outbound', d.id);
      await reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy((b) => { const x = { ...b }; delete x[key]; return x; }); }
  }
  async function nudgeCommit(c) {
    const key = `nudge:${c.id}`;
    setBusy((b) => ({ ...b, [key]: 'pinging' }));
    try {
      const r = await api.commitmentNudge(c.id);
      if (r.ok) await reload();
      else setErr(r.error || 'no se pudo nudgear');
    } catch (e) { setErr(e.message); }
    finally { setBusy((b) => { const x = { ...b }; delete x[key]; return x; }); }
  }

  if (loading && !data) {
    return <p className="font-mono text-xs uppercase text-ink-3 py-12">Cargando bandeja…</p>;
  }
  if (!data) return null;

  const alertCount =
    (data.alerts?.tareas_vencidas?.length || 0) +
    (data.alerts?.commits_vencidos?.length || 0) +
    (data.alerts?.tickets_stale?.length || 0);
  const draftCount = data.drafts?.length || 0;

  return (
    <div className="space-y-10">
      {/* Refresh tab — magazine style */}
      <div className="flex items-baseline justify-between border-b border-lino-300 pb-3">
        <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3">
          Tu bandeja operacional
        </p>
        <button
          onClick={reload}
          disabled={loading}
          className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1.5"
        >
          <RefreshCw size={11} strokeWidth={1.5} className={loading ? 'animate-spin' : ''} />
          {loading ? 'Cargando' : 'Refrescar'}
        </button>
      </div>

      {err && <p className="text-red font-mono text-xs uppercase">{err}</p>}

      {/* ALERTAS */}
      {alertCount > 0 && (
        <article className="grid grid-cols-[60px_1fr] gap-4">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-red pt-1.5">
            Alertas <span className="block mt-0.5 text-[8px]">({alertCount})</span>
          </div>
          <div className="border-b border-lino-400 pb-6 space-y-3">
            {data.alerts.tareas_vencidas?.length > 0 && (
              <div>
                <p className="font-mono text-[10px] uppercase tracking-wider text-ink-3 mb-1">
                  Tareas vencidas
                </p>
                {data.alerts.tareas_vencidas.map((t) => (
                  <div key={t.id} className="font-serif text-base text-ink-1 leading-snug">
                    · {t.descripcion}
                    <span className="font-mono text-[10px] uppercase tracking-wide text-red ml-2">
                      vencida {t.dias_vencida}d
                    </span>
                    <span className="font-mono text-[10px] text-ink-3 ml-2">→ {t.responsable}</span>
                  </div>
                ))}
              </div>
            )}
            {data.alerts.commits_vencidos?.length > 0 && (
              <div>
                <p className="font-mono text-[10px] uppercase tracking-wider text-ink-3 mb-1">
                  Promesas vencidas
                </p>
                {data.alerts.commits_vencidos.map((c) => {
                  const k = `nudge:${c.id}`;
                  return (
                    <div key={c.id} className="font-serif text-base text-ink-1 leading-snug flex items-center justify-between gap-2">
                      <span>
                        · <strong>{c.persona}</strong>
                        <span className="italic text-ink-2 ml-1">— {c.descripcion}</span>
                        <span className="font-mono text-[10px] uppercase tracking-wide text-red ml-2">
                          vencido {c.dias_vencido}d
                        </span>
                        {c.nudges > 0 && (
                          <span className="font-mono text-[10px] uppercase tracking-wide text-amber ml-2">
                            {c.nudges} nudge{c.nudges !== 1 ? 's' : ''}
                          </span>
                        )}
                      </span>
                      {c.contacto && (
                        <button
                          onClick={() => nudgeCommit(c)}
                          disabled={!!busy[k]}
                          className="font-mono text-[9px] uppercase tracking-wider px-2 py-1 border border-ink-1 text-ink-1 hover:bg-ink-1 hover:text-lino-100 inline-flex items-center gap-1 disabled:opacity-40 shrink-0"
                        >
                          <Bell size={10} strokeWidth={1.5} />
                          {busy[k] === 'pinging' ? '…' : 'Ping'}
                        </button>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
            {data.alerts.tickets_stale?.length > 0 && (
              <div>
                <p className="font-mono text-[10px] uppercase tracking-wider text-ink-3 mb-1">
                  Tickets LUNA estancados (ALTA)
                </p>
                {data.alerts.tickets_stale.map((t) => (
                  <div key={t.id} className="font-serif text-base text-ink-1 leading-snug">
                    · #{t.id} {t.descripcion}
                    {t.miembro_nombre && <span className="text-ink-3 ml-2">({t.miembro_nombre})</span>}
                    <span className="font-mono text-[10px] uppercase tracking-wide text-amber ml-2">
                      {t.dias}d sin movimiento
                    </span>
                    {t.asignado_nombre && <span className="font-mono text-[10px] text-ink-3 ml-2">→ {t.asignado_nombre}</span>}
                  </div>
                ))}
              </div>
            )}
          </div>
        </article>
      )}

      {/* DRAFTS ESPERANDO */}
      <article className="grid grid-cols-[60px_1fr] gap-4">
        <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
          Drafts <span className="block mt-0.5 text-[8px]">({draftCount})</span>
        </div>
        <div className="border-b border-lino-400 pb-6">
          {draftCount === 0 ? (
            <p className="font-serif italic text-ink-3 text-sm">
              Sin borradores esperando tu OK. <em>Pulcro.</em>
            </p>
          ) : (
            <div className="space-y-4">
              {data.drafts.map((d) => {
                const k = `draft:${d.id}`;
                return (
                  <div key={d.id}>
                    <div className="flex items-baseline justify-between mb-1">
                      <span className="font-mono text-[10px] uppercase tracking-wider text-ink-3">
                        {d.kind} → {d.to}
                      </span>
                      {d.ts && <span className="font-mono text-[10px] text-ink-3">{ago(d.ts)}</span>}
                    </div>
                    {d.subject && (
                      <p className="font-serif text-lg leading-tight text-ink-1 mb-1">{d.subject}</p>
                    )}
                    <p className="font-serif italic text-sm text-ink-3 leading-relaxed mb-2 line-clamp-3">
                      {d.body_preview}
                    </p>
                    <div className="flex items-center gap-3">
                      <button
                        onClick={() => approveDraft(d)}
                        disabled={!!busy[k]}
                        className="font-mono text-[10px] uppercase tracking-[0.18em] px-3 py-1.5 bg-ink-1 text-lino-100 hover:bg-lino-800 disabled:opacity-40 inline-flex items-center gap-1.5"
                      >
                        <Check size={11} strokeWidth={2} />
                        {busy[k] === 'aprobando' ? 'Enviando…' : 'Enviar'}
                      </button>
                      <button
                        onClick={() => discardDraft(d)}
                        disabled={!!busy[k]}
                        className="font-mono text-[10px] uppercase tracking-[0.18em] px-3 py-1.5 text-ink-3 hover:text-red inline-flex items-center gap-1.5"
                      >
                        <X size={11} strokeWidth={2} />
                        Descartar
                      </button>
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </div>
      </article>

      {/* TRIAGE DE LA MAÑANA */}
      {data.triage?.summary && (
        <article className="grid grid-cols-[60px_1fr] gap-4">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
            Triage <span className="block mt-0.5 text-[8px]">({ago(data.triage.last_run)})</span>
          </div>
          <div className="border-b border-lino-400 pb-6">
            <p className="font-serif italic text-base text-ink-1 leading-relaxed">
              "{data.triage.summary}"
            </p>
          </div>
        </article>
      )}

      {/* EQUIPO MEDICARE (LUNA) */}
      {data.team && (
        <article className="grid grid-cols-[60px_1fr] gap-4">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
            Equipo
          </div>
          <div className="border-b border-lino-400 pb-6">
            <p className="font-mono text-[11px] uppercase tracking-wider text-ink-3 mb-2">
              {data.team.total} tickets abiertos{data.team.alta > 0 && <> · <span className="text-amber">{data.team.alta} ALTA</span></>}
            </p>
            <div className="space-y-1">
              {Object.entries(data.team.by_owner || {})
                .sort((a, b) => b[1] - a[1])
                .map(([owner, n]) => (
                  <p key={owner} className="font-serif text-base text-ink-1">
                    · <strong>{owner}</strong>
                    <span className="font-mono text-[10px] text-ink-3 ml-2">{n} ticket{n !== 1 ? 's' : ''}</span>
                  </p>
                ))}
            </div>
            <p className="font-mono text-[10px] uppercase tracking-wider text-ink-3 mt-3">
              <Link to="/clientes" className="hover:text-ink-1">Ver clientes →</Link>
            </p>
          </div>
        </article>
      )}

      {/* Empty state */}
      {alertCount === 0 && draftCount === 0 && !data.triage?.summary && !data.team && (
        <p className="font-serif italic text-ink-3 text-center py-12">
          Bandeja limpia. <em>Día tranquilo.</em>
        </p>
      )}
    </div>
  );
}
