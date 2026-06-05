import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { RefreshCw, Plus, ArrowRight } from 'lucide-react';
import { api } from '../lib/api.js';

// Proyectos — agrupación cross-domain (tareas + commitments + tickets LUNA + emails).
// Estilo magazine consistente con Hoy y Decisiones.

const COLOR_TINT = {
  lino:    { fg: 'text-lino-800',   bg: 'bg-lino-200/40' },
  amber:   { fg: 'text-amber',      bg: 'bg-amber/10' },
  sage:    { fg: 'text-green-800',  bg: 'bg-green-100/40' },
  plum:    { fg: 'text-purple-800', bg: 'bg-purple-100/40' },
  sienna:  { fg: 'text-red',        bg: 'bg-red/10' },
  slate:   { fg: 'text-slate-700',  bg: 'bg-slate-100/40' },
};

function daysUntil(iso) {
  if (!iso) return null;
  const days = Math.ceil((new Date(iso).getTime() - Date.now()) / 86_400_000);
  if (days < 0) return { d: Math.abs(days), label: 'vencido', cls: 'text-red' };
  if (days === 0) return { d: 0, label: 'hoy', cls: 'text-amber' };
  if (days <= 7) return { d: days, label: `${days}d`, cls: 'text-amber' };
  return { d: days, label: `${days}d`, cls: 'text-ink-3' };
}

function NewProjectForm({ onCreate, onCancel }) {
  const [nombre, setNombre] = useState('');
  const [descripcion, setDescripcion] = useState('');
  const [fechaMeta, setFechaMeta] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function submit(e) {
    e.preventDefault();
    if (!nombre.trim()) return;
    setSubmitting(true);
    try {
      await onCreate({ nombre: nombre.trim(), descripcion: descripcion.trim() || undefined, fecha_meta: fechaMeta || undefined });
    } finally { setSubmitting(false); }
  }

  return (
    <form onSubmit={submit} className="border-t border-b border-ink-1 py-6 mb-10 space-y-3">
      <div>
        <label className="font-mono text-[9px] tracking-[0.22em] uppercase text-ink-3 block mb-1">Nombre</label>
        <input
          autoFocus
          value={nombre}
          onChange={(e) => setNombre(e.target.value)}
          placeholder="AEP 2026"
          className="w-full bg-transparent border-b border-ink-1 font-serif text-2xl py-1 outline-none placeholder:italic placeholder:text-ink-3"
        />
      </div>
      <div>
        <label className="font-mono text-[9px] tracking-[0.22em] uppercase text-ink-3 block mb-1">Descripción</label>
        <input
          value={descripcion}
          onChange={(e) => setDescripcion(e.target.value)}
          placeholder="Cerrar AEP de los 60 clientes Medicare"
          className="w-full bg-transparent border-b border-lino-400 font-sans text-sm py-1 outline-none placeholder:italic placeholder:text-ink-3"
        />
      </div>
      <div>
        <label className="font-mono text-[9px] tracking-[0.22em] uppercase text-ink-3 block mb-1">Fecha meta (opcional)</label>
        <input
          type="date"
          value={fechaMeta}
          onChange={(e) => setFechaMeta(e.target.value)}
          className="bg-transparent border-b border-lino-400 font-mono text-sm py-1 outline-none"
        />
      </div>
      <div className="flex gap-3 pt-2">
        <button
          type="submit"
          disabled={submitting || !nombre.trim()}
          className="font-mono text-[10px] uppercase tracking-[0.18em] px-4 py-2 bg-ink-1 text-lino-100 hover:bg-lino-800 disabled:opacity-40"
        >
          {submitting ? 'Creando…' : 'Crear proyecto'}
        </button>
        <button
          type="button"
          onClick={onCancel}
          className="font-mono text-[10px] uppercase tracking-[0.18em] px-4 py-2 text-ink-3 hover:text-ink-1"
        >
          Cancelar
        </button>
      </div>
    </form>
  );
}

export default function Proyectos() {
  const [projects, setProjects] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [filter, setFilter] = useState('activo');
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    try {
      const all = await api.projects();
      setProjects(all || []);
    } catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function create(data) {
    try {
      await api.projectCreate(data);
      setShowForm(false);
      await reload();
    } catch (e) { setErr(e.message); }
  }

  const filtered = filter ? projects.filter((p) => p.status === filter) : projects;

  return (
    <div className="pb-12">
      {/* Masthead */}
      <header className="flex items-end justify-between border-b border-ink-1 pt-2 pb-3 mb-8">
        <div className="font-serif text-sm tracking-wide text-ink-1">
          ATHENA <span className="font-mono text-xs text-ink-3 ml-2">Proyectos</span>
        </div>
        <div className="flex items-center gap-3">
          <select
            className="bg-transparent font-mono text-[10px] uppercase tracking-[0.18em] text-ink-1 border-b border-ink-1 outline-none"
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
          >
            <option value="activo">Activos</option>
            <option value="pausado">Pausados</option>
            <option value="cerrado">Cerrados</option>
            <option value="">Todos</option>
          </select>
          <button
            onClick={reload}
            disabled={loading}
            className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1.5"
          >
            <RefreshCw size={11} strokeWidth={1.5} className={loading ? 'animate-spin' : ''} />
            {loading ? 'Cargando' : 'Refrescar'}
          </button>
        </div>
      </header>

      {/* LEAD */}
      <section className="mb-10">
        <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-2">
          Tus esfuerzos grandes
        </p>
        <h1 className="font-serif text-[2rem] leading-[1.1] tracking-tight text-ink-1">
          {filtered.length === 0
            ? <span><em className="italic font-light">Sin proyectos.</em><br/>Crea uno para agrupar lo que haces.</span>
            : <span><span className="font-light">{filtered.length}</span> {filtered.length === 1 ? 'proyecto activo' : 'proyectos activos'}.</span>}
        </h1>
      </section>

      {err && <p className="text-red font-mono text-xs uppercase mb-4">{err}</p>}

      {showForm && <NewProjectForm onCreate={create} onCancel={() => setShowForm(false)} />}

      {!showForm && (
        <button
          onClick={() => setShowForm(true)}
          className="font-mono text-[10px] uppercase tracking-[0.18em] px-4 py-2 border border-ink-1 text-ink-1 hover:bg-ink-1 hover:text-lino-100 inline-flex items-center gap-2 mb-8"
        >
          <Plus size={12} strokeWidth={2} />
          Nuevo proyecto
        </button>
      )}

      {/* PROJECTS LIST */}
      <section>
        {filtered.map((p) => {
          const meta = daysUntil(p.fecha_meta);
          const color = COLOR_TINT[p.color] || COLOR_TINT.lino;
          return (
            <Link
              key={p.id}
              to={`/proyectos/${p.slug}`}
              className="grid grid-cols-[60px_1fr_auto] gap-4 mb-6 group"
            >
              <div className={`font-mono text-[9px] tracking-[0.25em] uppercase pt-1.5 ${color.fg}`}>
                Proyecto
              </div>
              <div className="border-b border-lino-400 pb-5 group-hover:border-ink-1 transition-colors">
                <h3 className="font-serif text-xl leading-tight text-ink-1 mb-1">
                  {p.nombre}
                </h3>
                {p.descripcion && (
                  <p className="font-serif italic text-sm text-ink-3 leading-relaxed mb-2 line-clamp-2">
                    {p.descripcion}
                  </p>
                )}
                <p className="font-mono text-[10px] tracking-wider text-ink-3 uppercase">
                  {p.counts.tasks > 0 && <>{p.counts.tasks} tareas · </>}
                  {p.counts.commitments > 0 && <>{p.counts.commitments} promesas · </>}
                  {p.counts.tickets_luna > 0 && <>{p.counts.tickets_luna} tickets · </>}
                  {p.counts.emails > 0 && <>{p.counts.emails} emails · </>}
                  {p.counts.total === 0 && <span className="italic normal-case">sin items vinculados todavía</span>}
                  {meta && <span className={`ml-2 ${meta.cls}`}>· {meta.label}</span>}
                </p>
              </div>
              <div className="self-center text-ink-3 group-hover:text-ink-1 transition-colors">
                <ArrowRight size={14} strokeWidth={1.5} />
              </div>
            </Link>
          );
        })}

        {!loading && filtered.length === 0 && (
          <p className="font-serif italic text-ink-3 text-center py-12">
            Crea tu primer proyecto para empezar.<br/>
            Buenos candidatos: <em>AEP 2026</em>, <em>Renew license</em>, <em>Vacation prep</em>.
          </p>
        )}
      </section>
    </div>
  );
}
