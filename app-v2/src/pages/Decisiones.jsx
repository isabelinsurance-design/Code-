import { useEffect, useState } from 'react';
import { Check, X, RefreshCw } from 'lucide-react';
import { api } from '../lib/api.js';

// Decisiones — pantalla dedicada a TODO lo que Athena necesita tu OK.
// Estilo magazine consistente con Hoy: running heads, hairlines, serif.
// Una decisión, una línea, dos botones (APROBAR / DECLINAR).
// Bandera de urgencia.

const KIND_LABEL = {
  outbound: 'Mensaje saliente',
  improvement: 'Mejora propuesta',
  skill: 'Skill nueva',
};

const SUB_LABEL = {
  email: 'Email',
  sms: 'SMS',
  high: 'Alta prioridad',
  medium: 'Media',
  alta: 'Alta',
  media: 'Media',
  baja: 'Baja',
  draft: 'Borrador',
};

const URGENCY_COLOR = {
  high: 'text-red',
  normal: 'text-ink-2',
  low: 'text-ink-3',
};

function timeAgo(iso) {
  if (!iso) return '—';
  const sec = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (sec < 60) return `${sec}s`;
  const m = Math.floor(sec / 60);
  if (m < 60) return `${m}m`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h`;
  return `${Math.floor(h / 24)}d`;
}

export default function Decisiones() {
  const [decisions, setDecisions] = useState([]);
  const [autonomy, setAutonomy] = useState(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState({});
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    try {
      const [d, a] = await Promise.all([
        api.commandDecisions().catch(() => ({ decisions: [] })),
        api.commandAutonomy().catch(() => ({ total: 0, grouped: [], recent: [] })),
      ]);
      setDecisions(d.decisions || []);
      setAutonomy(a);
    } catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function approve(d) {
    const key = `${d.kind}:${d.id}`;
    setBusy((b) => ({ ...b, [key]: 'aprobando' }));
    try {
      await api.decisionApprove(d.kind, d.id);
      await reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy((b) => { const x = { ...b }; delete x[key]; return x; }); }
  }
  async function decline(d) {
    const key = `${d.kind}:${d.id}`;
    if (!confirm(`¿Descartar "${d.title}"?`)) return;
    setBusy((b) => ({ ...b, [key]: 'descartando' }));
    try {
      await api.decisionDecline(d.kind, d.id);
      await reload();
    } catch (e) { setErr(e.message); }
    finally { setBusy((b) => { const x = { ...b }; delete x[key]; return x; }); }
  }

  return (
    <div className="pb-12">
      {/* Masthead consistente con Hoy */}
      <header className="flex items-end justify-between border-b border-ink-1 pt-2 pb-3 mb-8">
        <div className="font-serif text-sm tracking-wide text-ink-1">
          ATHENA <span className="font-mono text-xs text-ink-3 ml-2">Decisiones</span>
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
          Lo que Athena necesita tu OK
        </p>
        <h1 className="font-serif text-[2rem] leading-[1.1] tracking-tight text-ink-1">
          {decisions.length === 0 ? (
            <span><em className="italic font-light">Cola limpia.</em><br/>Nada espera tu decisión.</span>
          ) : (
            <span>
              <span className="font-light">{decisions.length}</span>{' '}
              {decisions.length === 1 ? 'cosa espera' : 'cosas esperan'}
              <br/>tu <em className="italic">decisión</em>.
            </span>
          )}
        </h1>
      </section>

      {err && <p className="text-red font-mono text-xs uppercase mb-4">{err}</p>}

      {/* DECISIONES */}
      {decisions.length > 0 && (
        <section className="mb-12">
          {decisions.map((d) => {
            const key = `${d.kind}:${d.id}`;
            const isBusy = busy[key];
            return (
              <article key={key} className="grid grid-cols-[60px_1fr] gap-4 mb-6">
                <div className={`font-mono text-[9px] tracking-[0.25em] uppercase pt-1.5 ${URGENCY_COLOR[d.urgency] || URGENCY_COLOR.normal}`}>
                  {KIND_LABEL[d.kind] || d.kind}
                  {d.sub && (
                    <div className="text-ink-3 mt-0.5 text-[8px] tracking-[0.2em]">
                      {SUB_LABEL[d.sub] || d.sub}
                    </div>
                  )}
                </div>
                <div className="border-b border-lino-400 pb-5">
                  <h3 className="font-serif text-xl leading-tight text-ink-1 mb-1.5">
                    {d.title}
                  </h3>
                  {d.preview && (
                    <p className="font-serif italic text-sm text-ink-3 leading-relaxed mb-2 line-clamp-3">
                      {d.preview}
                    </p>
                  )}
                  {d.to && (
                    <p className="font-mono text-[10px] tracking-wider text-ink-3 uppercase mb-3">
                      → {d.to} · {timeAgo(d.ts)}
                    </p>
                  )}
                  <div className="flex items-center gap-3 mt-3">
                    <button
                      onClick={() => approve(d)}
                      disabled={!!isBusy}
                      className="font-mono text-[10px] uppercase tracking-[0.18em] px-4 py-2 bg-ink-1 text-lino-100 hover:bg-lino-800 disabled:opacity-40 inline-flex items-center gap-1.5"
                    >
                      <Check size={12} strokeWidth={2} />
                      {isBusy === 'aprobando' ? 'Aprobando…' : 'Aprobar'}
                    </button>
                    <button
                      onClick={() => decline(d)}
                      disabled={!!isBusy}
                      className="font-mono text-[10px] uppercase tracking-[0.18em] px-4 py-2 text-ink-3 hover:text-red disabled:opacity-40 inline-flex items-center gap-1.5"
                    >
                      <X size={12} strokeWidth={2} />
                      {isBusy === 'descartando' ? 'Descartando…' : 'Descartar'}
                    </button>
                  </div>
                </div>
              </article>
            );
          })}
        </section>
      )}

      {/* AUTONOMÍA HOY */}
      {autonomy && autonomy.total > 0 && (
        <section className="mb-8">
          <div className="border-t border-ink-1 pt-6 mb-6">
            <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-2">
              Autonomía hoy
            </p>
            <h2 className="font-serif text-[1.6rem] leading-tight text-ink-1">
              <span className="font-light">{autonomy.total}</span>{' '}
              {autonomy.total === 1 ? 'acción' : 'acciones'} sin molestarte.
            </h2>
          </div>

          <div className="space-y-3">
            {autonomy.grouped.slice(0, 12).map((g) => (
              <article key={g.tool} className="grid grid-cols-[60px_1fr_50px] gap-4 items-baseline">
                <div className={`font-mono text-[9px] tracking-[0.25em] uppercase pt-1 ${g.category === 'delegation' ? 'text-lino-700' : 'text-ink-3'}`}>
                  {g.category === 'delegation' ? 'Delega' : 'Pro­activa'}
                </div>
                <div className="border-b border-lino-300 pb-2">
                  <p className="font-serif text-base text-ink-1">
                    {g.tool.replace(/_/g, ' ')}
                  </p>
                  {g.samples?.length > 0 && (
                    <p className="font-mono text-[10px] text-ink-3 mt-0.5 truncate">
                      última: {g.samples[g.samples.length - 1]}
                    </p>
                  )}
                </div>
                <div className="font-mono text-sm text-ink-1 text-right">
                  ×{String(g.count).padStart(2, '0')}
                </div>
              </article>
            ))}
          </div>
        </section>
      )}

      {!loading && decisions.length === 0 && (!autonomy || autonomy.total === 0) && (
        <p className="font-serif italic text-ink-3 text-center py-12">
          Día tranquilo. Athena está en standby — todo bajo control.
        </p>
      )}
    </div>
  );
}
