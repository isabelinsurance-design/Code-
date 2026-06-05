import { useEffect, useState } from 'react';
import { Link, useParams, useNavigate } from 'react-router-dom';
import { RefreshCw, ChevronLeft, Pause, Play, Archive } from 'lucide-react';
import { api } from '../lib/api.js';

// Detalle de un proyecto — todas las tareas + commitments + tickets + emails
// en una sola vista.

function daysUntil(iso) {
  if (!iso) return null;
  const days = Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000);
  if (days < 0) return { d: Math.abs(days), label: `vencido ${Math.abs(days)}d`, cls: 'text-red' };
  if (days === 0) return { d: 0, label: 'vence hoy', cls: 'text-amber' };
  if (days <= 7) return { d: days, label: `${days}d`, cls: 'text-amber' };
  return { d: days, label: `${days}d`, cls: 'text-ink-3' };
}

export default function ProyectoDetalle() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [proj, setProj] = useState(null);
  const [loading, setLoading] = useState(true);
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    try { setProj(await api.project(id)); }
    catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, [id]);

  async function setStatus(status) {
    try {
      await api.projectUpdate(id, { status });
      await reload();
    } catch (e) { setErr(e.message); }
  }

  async function unlinkItem(kind, itemId) {
    if (!confirm('¿Desvincular este item del proyecto?')) return;
    try {
      await api.projectUnlink(id, kind, itemId);
      await reload();
    } catch (e) { setErr(e.message); }
  }

  if (loading && !proj) return <p className="font-mono text-xs uppercase tracking-wider text-ink-3 py-12">Cargando proyecto…</p>;
  if (err && !proj) return <p className="text-red font-mono text-xs uppercase">{err}</p>;
  if (!proj) return null;

  const meta = daysUntil(proj.fecha_meta);

  return (
    <div className="pb-12">
      {/* Masthead */}
      <header className="flex items-end justify-between border-b border-ink-1 pt-2 pb-3 mb-8">
        <Link to="/proyectos" className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1">
          <ChevronLeft size={12} strokeWidth={1.5} />
          Todos los proyectos
        </Link>
        <div className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3">
          {proj.status}
        </div>
      </header>

      {/* LEAD */}
      <section className="mb-10">
        <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-2">
          Proyecto
        </p>
        <h1 className="font-serif text-[2.4rem] leading-[1.05] tracking-tight text-ink-1 mb-3">
          {proj.nombre}
        </h1>
        {proj.descripcion && (
          <p className="font-serif italic text-base text-ink-2 leading-relaxed">
            {proj.descripcion}
          </p>
        )}
        <p className="font-mono text-[10px] tracking-wider uppercase text-ink-3 mt-3">
          {proj.counts.total} items
          {meta && <span className={`ml-3 ${meta.cls}`}>· {meta.label}</span>}
        </p>

        {/* Acciones de status */}
        <div className="flex gap-3 mt-5">
          {proj.status !== 'activo' && (
            <button onClick={() => setStatus('activo')} className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1.5">
              <Play size={11} strokeWidth={1.5} /> Activar
            </button>
          )}
          {proj.status === 'activo' && (
            <button onClick={() => setStatus('pausado')} className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1.5">
              <Pause size={11} strokeWidth={1.5} /> Pausar
            </button>
          )}
          {proj.status !== 'cerrado' && (
            <button onClick={() => setStatus('cerrado')} className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-red inline-flex items-center gap-1.5">
              <Archive size={11} strokeWidth={1.5} /> Cerrar
            </button>
          )}
          <button onClick={reload} className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1.5">
            <RefreshCw size={11} strokeWidth={1.5} className={loading ? 'animate-spin' : ''} /> Refrescar
          </button>
        </div>
      </section>

      {/* TAREAS */}
      {proj.items.tasks.length > 0 && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
            Tareas <span className="block mt-0.5 text-[8px]">({proj.items.tasks.length})</span>
          </div>
          <div className="border-b border-lino-400 pb-5 space-y-2">
            {proj.items.tasks.map((t) => {
              const v = daysUntil(t.vence);
              return (
                <div key={t.id} className="font-serif text-base text-ink-1 leading-snug flex items-start gap-2">
                  <span className="font-mono text-[10px] text-ink-3 uppercase tracking-wide mt-1">{t.responsable}</span>
                  <span className="flex-1">{t.descripcion || t.titulo}</span>
                  {v && <span className={`font-mono text-[10px] uppercase tracking-wide mt-1 ${v.cls}`}>{v.label}</span>}
                  <button onClick={() => unlinkItem('tasks', t.id)} className="font-mono text-[9px] text-ink-3 hover:text-red uppercase tracking-wider mt-1">×</button>
                </div>
              );
            })}
          </div>
        </article>
      )}

      {/* COMMITMENTS */}
      {proj.items.commitments.length > 0 && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
            Promesas <span className="block mt-0.5 text-[8px]">({proj.items.commitments.length})</span>
          </div>
          <div className="border-b border-lino-400 pb-5 space-y-2">
            {proj.items.commitments.map((c) => {
              const v = daysUntil(c.vence);
              return (
                <div key={c.id} className="font-serif text-base text-ink-1 leading-snug flex items-start gap-2">
                  <span className="font-medium">{c.persona}</span>
                  <span className="flex-1 italic text-ink-2">{c.descripcion}</span>
                  {v && <span className={`font-mono text-[10px] uppercase tracking-wide mt-1 ${v.cls}`}>{v.label}</span>}
                  <button onClick={() => unlinkItem('commitments', c.id)} className="font-mono text-[9px] text-ink-3 hover:text-red uppercase tracking-wider mt-1">×</button>
                </div>
              );
            })}
          </div>
        </article>
      )}

      {/* TICKETS LUNA */}
      {proj.items.tickets_luna.length > 0 && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
            Equipo <span className="block mt-0.5 text-[8px]">LUNA ({proj.items.tickets_luna.length})</span>
          </div>
          <div className="border-b border-lino-400 pb-5 space-y-2">
            {proj.items.tickets_luna.map((t) => (
              <div key={t.id} className="font-serif text-base text-ink-1 leading-snug flex items-start gap-2">
                <span className="font-mono text-[10px] text-ink-3 uppercase tracking-wide mt-1">
                  #{t.id} {t.prioridad ? `[${t.prioridad}]` : ''}
                </span>
                <span className="flex-1">{t.descripcion || t.titulo}</span>
                {t.miembro_nombre && <span className="font-mono text-[10px] text-ink-3 mt-1">{t.miembro_nombre}</span>}
                <button onClick={() => unlinkItem('tickets_luna', String(t.id))} className="font-mono text-[9px] text-ink-3 hover:text-red uppercase tracking-wider mt-1">×</button>
              </div>
            ))}
          </div>
        </article>
      )}

      {/* EMPTY */}
      {proj.counts.total === 0 && (
        <p className="font-serif italic text-ink-3 text-center py-12">
          Sin items vinculados todavía.<br/>
          Dile a Athena: <em>"vincula esta tarea al proyecto {proj.nombre}"</em><br/>
          o ella lo hará sola la próxima vez que crees algo relacionado.
        </p>
      )}

      {err && <p className="text-red font-mono text-xs uppercase mt-4">{err}</p>}
    </div>
  );
}
