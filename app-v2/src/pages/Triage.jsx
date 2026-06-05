import { useEffect, useState } from 'react';
import { RefreshCw, Mail, ExternalLink } from 'lucide-react';
import { api } from '../lib/api.js';

// Triage email — snapshot del batch que el cron de 5am procesó.
// Muestra cada email con quién, asunto, preview, y la clasificación
// que Athena le dio (si la dio).

function fmtTime(iso) {
  if (!iso) return '';
  try {
    return new Date(iso).toLocaleString('es-MX', {
      timeZone: 'America/Los_Angeles',
      hour: '2-digit', minute: '2-digit',
      day: '2-digit', month: 'short',
    });
  } catch { return iso; }
}

const CLASE_COLOR = {
  cliente_medicare: 'text-lino-800',
  urgente: 'text-red',
  personal: 'text-ink-2',
  newsletter: 'text-ink-3',
  spam: 'text-ink-3',
  otro: 'text-ink-3',
  pendiente: 'text-ink-3',
};

const CLASE_LABEL = {
  cliente_medicare: 'Cliente Medicare',
  urgente: 'Urgente',
  personal: 'Personal',
  newsletter: 'Newsletter',
  spam: 'Spam',
  otro: 'Otro',
  pendiente: 'Sin clasificar',
};

export default function Triage() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    setErr('');
    try { setData(await api.triageToday()); }
    catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function runNow() {
    if (!confirm('¿Correr el triage ahora? Cuesta ~$0.04 de tokens.')) return;
    setRefreshing(true);
    setErr('');
    try {
      const r = await api.triageRefresh();
      setData(r);
    } catch (e) { setErr(e.message); }
    finally { setRefreshing(false); }
  }

  const emails = data?.emails || [];
  const totalRevisados = data?.total_revisados ?? emails.length;
  const hasData = !!data && (data.summary || emails.length > 0);

  return (
    <div className="pb-12">
      {/* Masthead */}
      <header className="flex items-end justify-between border-b border-ink-1 pt-2 pb-3 mb-8">
        <div className="font-serif text-sm tracking-wide text-ink-1">
          ATHENA <span className="font-mono text-xs text-ink-3 ml-2">Triage de inbox</span>
        </div>
        <div className="flex gap-3">
          <button
            onClick={runNow}
            disabled={refreshing}
            className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1.5"
            title="Correr triage ahora (cuesta ~$0.04 de tokens)"
          >
            <Mail size={11} strokeWidth={1.5} className={refreshing ? 'animate-pulse' : ''} />
            {refreshing ? 'Triageando…' : 'Correr ahora'}
          </button>
          <button
            onClick={reload}
            disabled={loading}
            className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1.5"
          >
            <RefreshCw size={11} strokeWidth={1.5} className={loading ? 'animate-spin' : ''} />
            Refrescar
          </button>
        </div>
      </header>

      {/* LEAD */}
      <section className="mb-10">
        <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-2">
          Inbox procesado por Athena
        </p>
        <h1 className="font-serif text-[2rem] leading-[1.1] tracking-tight text-ink-1">
          {totalRevisados > 0 ? (
            <span><span className="font-light">{totalRevisados}</span> {totalRevisados === 1 ? 'email' : 'emails'} revisados <em className="italic font-light">hoy</em>.</span>
          ) : (
            <span><em className="italic font-light">Inbox limpio.</em><br/>Nada nuevo desde anoche.</span>
          )}
        </h1>
        {data?.stale && (
          <p className="font-mono text-[10px] uppercase tracking-wider text-amber mt-3">
            Triage de un día anterior · pide uno nuevo si quieres el de hoy
          </p>
        )}
      </section>

      {err && <p className="text-red font-mono text-xs uppercase mb-4">{err}</p>}

      {/* SUMMARY */}
      {data?.summary && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
            Resumen
          </div>
          <div className="border-b border-lino-400 pb-6">
            <p className="font-serif italic text-base text-ink-1 leading-relaxed">
              "{data.summary}"
            </p>
          </div>
        </article>
      )}

      {/* EMAILS */}
      {emails.length > 0 && (
        <section className="mb-8">
          {emails.map((e) => (
            <article key={e.id} className="grid grid-cols-[80px_1fr] gap-4 mb-5">
              <div className={`font-mono text-[9px] tracking-[0.22em] uppercase pt-1.5 ${CLASE_COLOR[e.clasificacion] || CLASE_COLOR.pendiente}`}>
                {CLASE_LABEL[e.clasificacion] || e.clasificacion}
                {e.no_leido && <div className="text-[8px] tracking-[0.18em] text-amber mt-0.5">No leído</div>}
              </div>
              <div className="border-b border-lino-400 pb-5">
                <div className="flex items-baseline justify-between mb-1">
                  <span className="font-mono text-[10px] uppercase tracking-wider text-ink-3">
                    {e.de_nombre || e.de}
                  </span>
                  <span className="font-mono text-[10px] text-ink-3">{fmtTime(e.fecha)}</span>
                </div>
                <p className="font-serif text-lg leading-tight text-ink-1 mb-1.5">
                  {e.asunto || '(sin asunto)'}
                </p>
                <p className="font-serif italic text-sm text-ink-3 leading-relaxed line-clamp-3">
                  {e.body_preview}
                </p>
                {e.accion && (
                  <p className="font-mono text-[10px] uppercase tracking-wider text-lino-700 mt-2">
                    Athena: {e.accion}
                  </p>
                )}
              </div>
            </article>
          ))}
        </section>
      )}

      {/* EMPTY */}
      {!loading && !hasData && (
        <section className="text-center py-12">
          <p className="font-serif italic text-ink-3 mb-4">
            Sin triage todavía. El cron corre a las 5am.
          </p>
          <button
            onClick={runNow}
            disabled={refreshing}
            className="font-mono text-[10px] uppercase tracking-[0.18em] px-4 py-2 border border-ink-1 text-ink-1 hover:bg-ink-1 hover:text-lino-100 inline-flex items-center gap-2 disabled:opacity-40"
          >
            <Mail size={12} strokeWidth={1.5} />
            {refreshing ? 'Triageando…' : 'Triagea mi inbox ahora'}
          </button>
        </section>
      )}

      {/* OPEN GMAIL */}
      {emails.length > 0 && (
        <p className="font-mono text-[10px] uppercase tracking-wider text-ink-3 mt-8">
          <a
            href="https://mail.google.com"
            target="_blank"
            rel="noopener noreferrer"
            className="hover:text-ink-1 inline-flex items-center gap-1.5"
          >
            <ExternalLink size={11} strokeWidth={1.5} />
            Abrir Gmail
          </a>
        </p>
      )}

      {data?.generated_at && (
        <p className="font-mono text-[9px] tracking-wider uppercase text-ink-3 mt-8">
          Última corrida: {fmtTime(data.generated_at)}
        </p>
      )}
    </div>
  );
}
