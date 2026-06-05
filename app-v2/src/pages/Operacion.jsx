import { useState } from 'react';
import { RefreshCw, FileText } from 'lucide-react';
import { api } from '../lib/api.js';

// Reporte operacional de Medicare — Pilar deep dive.
// Análisis estratégico de TODO el CRM. NO tabla. Ensayo de COO.

export default function Operacion() {
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(false);
  const [err, setErr] = useState('');

  async function generate() {
    if (loading) return;
    if (data && !confirm('¿Generar reporte nuevo? Cuesta ~$0.15 de tokens (deep tier).')) return;
    setLoading(true);
    setErr('');
    try {
      const r = await api.medicareReport();
      if (!r.ok) {
        setErr(r.error || 'no se pudo generar');
      } else {
        setData(r);
      }
    } catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }

  return (
    <div className="pb-12">
      {/* Masthead */}
      <header className="flex items-end justify-between border-b border-ink-1 pt-2 pb-3 mb-8">
        <div className="font-serif text-sm tracking-wide text-ink-1">
          ATHENA <span className="font-mono text-xs text-ink-3 ml-2">CRM · LUNA</span>
        </div>
        <button
          onClick={generate}
          disabled={loading}
          className="font-mono text-[10px] uppercase tracking-[0.18em] px-4 py-2 border border-ink-1 text-ink-1 hover:bg-ink-1 hover:text-lino-100 inline-flex items-center gap-2 disabled:opacity-40"
        >
          {loading ? <RefreshCw size={12} strokeWidth={1.5} className="animate-spin" /> : <FileText size={12} strokeWidth={1.5} />}
          {loading ? 'LUNA analizando…' : data ? 'Pedir uno nuevo' : 'Generar reporte'}
        </button>
      </header>

      {/* LEAD */}
      <section className="mb-10">
        <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-2">
          Análisis deep de LUNA sobre tu CRM
        </p>
        <h1 className="font-serif text-[2rem] leading-[1.1] tracking-tight text-ink-1">
          {data
            ? <span><em className="italic font-light">Lo que LUNA ve</em><br/>cuando mira todo junto.</span>
            : <span><em className="italic font-light">Sin reporte todavía.</em><br/>Tap arriba para que LUNA haga un análisis completo.</span>}
        </h1>
      </section>

      {err && <p className="text-red font-mono text-xs uppercase mb-4">{err}</p>}

      {/* REPORT BODY */}
      {data?.report && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-10">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
            Reporte
            <div className="text-[8px] tracking-[0.2em] text-ink-3 mt-1">
              {data.date}
            </div>
          </div>
          <div className="border-b border-lino-400 pb-8">
            <div className="font-serif text-lg leading-relaxed text-ink-1 whitespace-pre-wrap">
              {data.report}
            </div>
          </div>
        </article>
      )}

      {/* Raw data dump (collapsible) */}
      {data?.data_dump && (
        <details className="mt-8">
          <summary className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 cursor-pointer">
            Ver datos crudos que vio LUNA
          </summary>
          <pre className="font-mono text-[11px] text-ink-3 leading-relaxed mt-4 whitespace-pre-wrap border-t border-lino-300 pt-4">
            {data.data_dump}
          </pre>
        </details>
      )}

      {data?.generated_at && (
        <p className="font-mono text-[9px] tracking-wider uppercase text-ink-3 mt-8">
          Generado: {new Date(data.generated_at).toLocaleString('es-MX')}
        </p>
      )}

      {!data && !loading && (
        <p className="font-serif italic text-ink-3 mt-12 leading-relaxed">
          El reporte tira en paralelo todos los queries de LUNA (tickets, hot leads, SOAs,
          T65, carriers, citas hoy, retención, actividad reciente) y le pide a Pilar que
          escriba un ensayo de 4-6 párrafos cubriendo: estado general, riesgo más grande,
          oportunidad que nadie está viendo, 3 acciones para las próximas 48h, y patrones
          sistémicos. Cuesta ~$0.15 cada vez. Genéralo cuando necesites perspectiva
          estratégica, no a cada rato.
        </p>
      )}
    </div>
  );
}
