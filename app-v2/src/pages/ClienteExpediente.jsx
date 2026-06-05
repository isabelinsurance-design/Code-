import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { ChevronLeft, RefreshCw, AlertTriangle, Check } from 'lucide-react';
import { api } from '../lib/api.js';

// Expediente completo del cliente — magazine style, todo de LUNA en una vista.
// Si SOA missing / MBI pending / 12-month touchpoint vencido → flag visual.

function fmtDate(s) {
  if (!s) return '—';
  try { return new Date(s).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' }); }
  catch { return s; }
}

function ageFromDob(dob) {
  if (!dob) return null;
  const d = new Date(dob);
  if (isNaN(d.getTime())) return null;
  const diff = Date.now() - d.getTime();
  return Math.floor(diff / (365.25 * 86_400_000));
}

function daysSince(iso) {
  if (!iso) return null;
  const ms = Date.now() - new Date(iso).getTime();
  return Math.floor(ms / 86_400_000);
}

function RunningHead({ children, alert = false }) {
  return (
    <div className={`font-mono text-[9px] tracking-[0.25em] uppercase pt-1.5 ${alert ? 'text-red' : 'text-ink-3'}`}>
      {children}
    </div>
  );
}

export default function ClienteExpediente() {
  const { id } = useParams();
  const [m, setM] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    setErr('');
    try {
      const r = await api.lunaMember(id);
      if (r.ok) setM(r.data || r);
      else setErr(r.reason || 'no se pudo cargar');
    } catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, [id]);

  if (loading && !m) return <p className="font-mono text-xs uppercase text-ink-3 py-12">Cargando expediente…</p>;
  if (err && !m) return (
    <div className="py-12">
      <Link to="/clientes" className="font-mono text-[10px] uppercase tracking-wider text-ink-3">‹ Volver</Link>
      <p className="text-red font-mono text-xs uppercase mt-4">{err}</p>
    </div>
  );
  if (!m) return null;

  // Compliance checks
  const soaOk = m.soa?.status === 'firmada' || m.soa_status === 'firmada' || m.soa_signed;
  const mbiOk = m.mbi_verificada || m.mbi_verified || m.mbi?.verificada;
  const lastTouch = m.last_touchpoint || m.ultimo_touchpoint || m.touchpoints?.[0]?.fecha;
  const touchDays = daysSince(lastTouch);
  const touch12 = touchDays != null ? touchDays < 365 : false;

  return (
    <div className="pb-12">
      {/* Masthead */}
      <header className="flex items-end justify-between border-b border-ink-1 pt-2 pb-3 mb-8">
        <Link to="/clientes" className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1">
          <ChevronLeft size={12} strokeWidth={1.5} />
          Buscar otro
        </Link>
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
          Cliente · id {m.id}
        </p>
        <h1 className="font-serif text-[2.4rem] leading-[1.05] tracking-tight text-ink-1 mb-3">
          {m.nombre || m.name || 'Sin nombre'}
        </h1>
        <p className="font-mono text-[10px] tracking-wider text-ink-3 uppercase">
          {[
            m.carrier,
            m.plan,
            m.estado,
            ageFromDob(m.dob || m.fecha_nacimiento) && `${ageFromDob(m.dob || m.fecha_nacimiento)} años`,
          ].filter(Boolean).join(' · ')}
        </p>
      </section>

      {/* COMPLIANCE GATE */}
      <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
        <RunningHead alert={!soaOk || !mbiOk || !touch12}>Compliance</RunningHead>
        <div className="border-b border-lino-400 pb-5 space-y-2 font-mono text-[11px] uppercase tracking-wider">
          <div className={`flex items-center gap-2 ${soaOk ? 'text-ink-1' : 'text-red'}`}>
            {soaOk ? <Check size={12} strokeWidth={2} /> : <AlertTriangle size={12} strokeWidth={2} />}
            SOA {soaOk ? `firmada ${m.soa?.fecha ? `· ${fmtDate(m.soa.fecha)}` : ''}` : 'pendiente'}
          </div>
          <div className={`flex items-center gap-2 ${mbiOk ? 'text-ink-1' : 'text-red'}`}>
            {mbiOk ? <Check size={12} strokeWidth={2} /> : <AlertTriangle size={12} strokeWidth={2} />}
            MBI {mbiOk ? 'verificada' : 'sin verificar'}
          </div>
          <div className={`flex items-center gap-2 ${touch12 ? 'text-ink-1' : 'text-amber'}`}>
            {touch12 ? <Check size={12} strokeWidth={2} /> : <AlertTriangle size={12} strokeWidth={2} />}
            12-MONTH {touchDays != null ? `· último touchpoint hace ${touchDays}d` : '· sin touchpoint'}
          </div>
          {m.tcpa && (
            <div className="flex items-center gap-2 text-ink-1">
              <Check size={12} strokeWidth={2} /> TCPA otorgado
            </div>
          )}
        </div>
      </article>

      {/* DATOS */}
      <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
        <RunningHead>Datos</RunningHead>
        <div className="border-b border-lino-400 pb-5 grid grid-cols-2 gap-3 text-sm">
          {m.mbi && <div><span className="font-mono text-[10px] uppercase text-ink-3 block">MBI</span><span className="font-mono text-ink-1">{m.mbi.numero || m.mbi}</span></div>}
          {(m.dob || m.fecha_nacimiento) && <div><span className="font-mono text-[10px] uppercase text-ink-3 block">DOB</span><span className="font-serif text-ink-1">{fmtDate(m.dob || m.fecha_nacimiento)}</span></div>}
          {m.telefono && <div><span className="font-mono text-[10px] uppercase text-ink-3 block">Teléfono</span><a href={`tel:${m.telefono}`} className="font-mono text-ink-1 hover:underline">{m.telefono}</a></div>}
          {m.email && <div><span className="font-mono text-[10px] uppercase text-ink-3 block">Email</span><a href={`mailto:${m.email}`} className="font-mono text-ink-1 hover:underline break-all">{m.email}</a></div>}
          {m.direccion && <div className="col-span-2"><span className="font-mono text-[10px] uppercase text-ink-3 block">Dirección</span><span className="font-serif text-ink-1">{m.direccion}</span></div>}
        </div>
      </article>

      {/* DRUG LIST */}
      {(m.drugs?.length > 0 || m.drug_list?.length > 0) && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <RunningHead>Medicamentos</RunningHead>
          <div className="border-b border-lino-400 pb-5 space-y-1">
            {(m.drugs || m.drug_list).map((d, i) => (
              <p key={i} className="font-serif text-base text-ink-1">
                · {d.nombre || d.name || d}
                {(d.dosis || d.dose) && <span className="font-mono text-[10px] text-ink-3 ml-2 uppercase tracking-wide">{d.dosis || d.dose}</span>}
                {d.formulary && <span className="font-mono text-[10px] text-green-700 ml-2 uppercase tracking-wide">en formulary</span>}
              </p>
            ))}
          </div>
        </article>
      )}

      {/* DOCTORS */}
      {(m.doctors?.length > 0 || m.providers?.length > 0) && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <RunningHead>Doctors</RunningHead>
          <div className="border-b border-lino-400 pb-5 space-y-1">
            {(m.doctors || m.providers).map((d, i) => (
              <p key={i} className="font-serif text-base text-ink-1">
                · {d.nombre || d.name || d}
                {d.tipo && <span className="font-mono text-[10px] text-ink-3 ml-2 uppercase tracking-wide">{d.tipo}</span>}
                {d.network && <span className="font-mono text-[10px] text-green-700 ml-2 uppercase tracking-wide">network ✓</span>}
              </p>
            ))}
          </div>
        </article>
      )}

      {/* TICKETS */}
      {m.tickets_abiertos?.length > 0 && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <RunningHead alert>Tickets abiertos</RunningHead>
          <div className="border-b border-lino-400 pb-5 space-y-2">
            {m.tickets_abiertos.map((t) => (
              <div key={t.id} className="font-serif text-base text-ink-1 leading-snug">
                <span className="font-mono text-[10px] text-ink-3 uppercase tracking-wide">#{t.id} {t.prioridad ? `[${t.prioridad}]` : ''}</span>
                <span className="ml-2">{t.descripcion || t.titulo}</span>
                {t.asignado_nombre && <span className="font-mono text-[10px] text-ink-3 ml-2 uppercase">→ {t.asignado_nombre}</span>}
              </div>
            ))}
          </div>
        </article>
      )}

      {/* CITAS */}
      {m.citas?.length > 0 && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <RunningHead>Citas próximas</RunningHead>
          <div className="border-b border-lino-400 pb-5 space-y-1">
            {m.citas.map((c) => (
              <p key={c.id} className="font-serif text-base text-ink-1">
                <span className="font-mono text-[10px] text-ink-3 uppercase tracking-wide">{fmtDate(c.fecha_hora || c.fecha)}</span>
                <span className="ml-2">{c.tipo || 'cita'}</span>
                {c.notas && <span className="text-ink-3 italic ml-2">— {c.notas}</span>}
              </p>
            ))}
          </div>
        </article>
      )}

      {/* TOUCHPOINTS */}
      {m.touchpoints?.length > 0 && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <RunningHead>Touchpoints recientes</RunningHead>
          <div className="border-b border-lino-400 pb-5 space-y-1">
            {m.touchpoints.slice(0, 10).map((t, i) => (
              <p key={i} className="font-serif text-sm text-ink-1 leading-snug">
                <span className="font-mono text-[10px] text-ink-3 uppercase tracking-wide">{fmtDate(t.fecha)}</span>
                <span className="ml-2">{t.tipo || 'touchpoint'}</span>
                {t.usuario && <span className="text-ink-3 ml-2">({t.usuario})</span>}
                {(t.descripcion || t.nota) && <span className="text-ink-2 ml-2">— {t.descripcion || t.nota}</span>}
              </p>
            ))}
          </div>
        </article>
      )}

      {/* NO DATA fallback */}
      {!m.tickets_abiertos?.length && !m.citas?.length && !m.touchpoints?.length && !m.drugs?.length && !m.doctors?.length && (
        <p className="font-serif italic text-ink-3 text-center py-12">
          Expediente básico cargado.<br/>
          Para más detalle pregúntale a Pilar en el chat.
        </p>
      )}
    </div>
  );
}
