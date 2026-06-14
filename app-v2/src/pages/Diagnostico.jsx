import { useEffect, useState } from 'react';
import { Check, X, AlertTriangle, RefreshCw } from 'lucide-react';
import { api } from '../lib/api.js';

// Diagnóstico — vista de health de todas las integraciones.
// Estilo magazine. Cada servicio es una línea con su status real.
// LUNA tiene sub-tabla con cada acción individual.

function StatusDot({ ok, configured }) {
  if (!configured) return <span className="inline-block w-2 h-2 rounded-full bg-lino-400 shrink-0" title="no configurado" />;
  if (ok) return <span className="inline-block w-2 h-2 rounded-full bg-green-500 shrink-0" title="OK" />;
  return <span className="inline-block w-2 h-2 rounded-full bg-red shrink-0" title="error" />;
}

function StatusLabel({ ok, configured }) {
  if (!configured) return <span className="text-ink-3">no configurado</span>;
  if (ok) return <span className="text-green-700">activo</span>;
  return <span className="text-red">error</span>;
}

const KIND_LABEL = {
  ok: 'OK',
  action_not_supported: 'Falta agregar en PHP',
  server_error: 'Server error LUNA',
  auth: 'Auth rechazada',
  timeout: 'Timeout',
  network_error: 'Red',
  parse_error: 'PHP devolvió HTML',
  not_configured: 'no configurado',
  unknown: 'desconocido',
  exception: 'excepción',
};

export default function Diagnostico() {
  const [diag, setDiag] = useState(null);
  const [lunaHealth, setLunaHealth] = useState(null);
  const [fallas, setFallas] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    setErr('');
    try {
      const [d, lh, fx] = await Promise.all([
        api.diagnostico().catch(() => ({ services: [] })),
        api.lunaHealth().catch(() => ({ ok: false, actions: [] })),
        api.errors().catch(() => ({ total: 0, today: 0, last24h: 0, recent: [] })),
      ]);
      setDiag(d);
      setLunaHealth(lh);
      setFallas(fx);
    } catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  const services = diag?.services || [];
  const okCount = services.filter((s) => s.ok).length;
  const totalCount = services.length;

  return (
    <div className="pb-12">
      {/* Masthead */}
      <header className="flex items-end justify-between border-b border-ink-1 pt-2 pb-3 mb-8">
        <div className="font-serif text-sm tracking-wide text-ink-1">
          ATHENA <span className="font-mono text-xs text-ink-3 ml-2">Diagnóstico</span>
        </div>
        <button
          onClick={reload}
          disabled={loading}
          className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1.5"
        >
          <RefreshCw size={11} strokeWidth={1.5} className={loading ? 'animate-spin' : ''} />
          {loading ? 'Pinguenado' : 'Refrescar'}
        </button>
      </header>

      {/* LEAD */}
      <section className="mb-10">
        <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-2">
          Estado de cada conexión
        </p>
        <h1 className="font-serif text-[2rem] leading-[1.1] tracking-tight text-ink-1">
          {totalCount > 0 ? (
            <span><span className="font-light">{okCount}</span> de {totalCount} <em className="italic font-light">activas</em>.</span>
          ) : (
            <span><em className="italic font-light">Cargando…</em></span>
          )}
        </h1>
      </section>

      {err && <p className="text-red font-mono text-xs uppercase mb-4">{err}</p>}

      {/* SERVICES */}
      <section className="mb-12 space-y-2">
        {services.map((s) => (
          <article key={s.name} className="grid grid-cols-[12px_1fr_auto] gap-3 items-baseline border-b border-lino-300 pb-2">
            <div className="self-center pt-0.5"><StatusDot ok={s.ok} configured={s.configured} /></div>
            <div>
              <p className="font-serif text-base text-ink-1 leading-tight">{s.name}</p>
              {s.detail && (
                <p className="font-mono text-[10px] tracking-wide text-ink-3 mt-0.5">{s.detail}</p>
              )}
            </div>
            <div className="font-mono text-[10px] uppercase tracking-wider">
              <StatusLabel ok={s.ok} configured={s.configured} />
            </div>
          </article>
        ))}
      </section>

      {/* LUNA actions individual */}
      {lunaHealth?.configured && (
        <section className="border-t border-ink-1 pt-6 mb-12">
          <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-2">
            LUNA · acciones individuales
          </p>
          <h2 className="font-serif text-[1.4rem] leading-tight text-ink-1 mb-4">
            <span className="font-light">{lunaHealth.ok_count}</span> de {lunaHealth.total} <em className="italic">funcionando</em>.
          </h2>
          <div className="space-y-1.5">
            {(lunaHealth.actions || []).map((a) => (
              <article key={a.name} className="grid grid-cols-[16px_1fr_120px_60px] gap-3 items-baseline border-b border-lino-300 pb-1.5">
                <div className="self-center pt-0.5">
                  {a.ok
                    ? <Check size={12} strokeWidth={2} className="text-green-700" />
                    : a.kind === 'action_not_supported'
                      ? <AlertTriangle size={12} strokeWidth={1.5} className="text-amber" />
                      : <X size={12} strokeWidth={2} className="text-red" />}
                </div>
                <div>
                  <p className="font-serif text-sm text-ink-1 leading-tight">{a.label}</p>
                  <p className="font-mono text-[9px] tracking-wide text-ink-3">{a.name}</p>
                </div>
                <div className="font-mono text-[9px] uppercase tracking-wider text-ink-3 text-right">
                  {KIND_LABEL[a.kind] || a.kind}
                </div>
                <div className="font-mono text-[9px] text-ink-3 text-right">
                  {a.elapsed_ms ? `${a.elapsed_ms}ms` : '—'}
                </div>
              </article>
            ))}
          </div>
          {lunaHealth.actions?.some((a) => a.kind === 'action_not_supported') && (
            <p className="font-serif italic text-sm text-amber mt-4 leading-relaxed">
              Las acciones marcadas con triángulo no están implementadas en <em>luna_api.php</em>. Pásale a Sami el archivo <em>PARA_SAMI_LUNA_REPORTS.md</em> para agregarlas.
            </p>
          )}
        </section>
      )}

      {/* FALLAS RECIENTES */}
      <section className="border-t border-ink-1 pt-6 mb-12">
        <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-2">
          Fallas recientes
        </p>
        <h2 className="font-serif text-[1.4rem] leading-tight text-ink-1 mb-4">
          {fallas?.today > 0
            ? <span><span className="font-light text-red">{fallas.today}</span> hoy<span className="text-ink-3"> · {fallas.last24h} en 24h</span></span>
            : <span><em className="italic font-light">Sin fallas hoy.</em> Todo en orden.</span>}
        </h2>
        {fallas?.recent?.length > 0 && (
          <div className="space-y-1.5">
            {fallas.recent.map((f, i) => (
              <article key={i} className="grid grid-cols-[1fr_auto] gap-3 items-baseline border-b border-lino-300 pb-1.5">
                <div>
                  <p className="font-serif text-sm text-ink-1 leading-tight">{f.message}</p>
                  <p className="font-mono text-[9px] tracking-wide text-ink-3">{f.source}</p>
                </div>
                <div className="font-mono text-[9px] text-ink-3 text-right whitespace-nowrap">
                  {f.ts ? new Date(f.ts).toLocaleString('es-MX', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'}
                </div>
              </article>
            ))}
          </div>
        )}
      </section>

      {!loading && services.length === 0 && (
        <p className="font-serif italic text-ink-3 text-center py-12">
          No se pudo cargar el diagnóstico.
        </p>
      )}
    </div>
  );
}
