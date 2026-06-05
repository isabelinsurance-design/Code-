import { useEffect, useState } from 'react';
import { Plus, Pause, Play, Trash2, RefreshCw } from 'lucide-react';
import { api } from '../lib/api.js';

// Reglas permanentes — standing orders que Athena obedece SIEMPRE.
// Estilo magazine consistente. Cada regla es un "edict" en formato serif.

const CATEGORIAS = [
  { id: 'comunicacion', label: 'Comunicación', desc: 'Cómo responde a comunicaciones entrantes' },
  { id: 'escalacion', label: 'Escalación', desc: 'Qué te despierta' },
  { id: 'tiempo', label: 'Tiempo', desc: 'Quiet hours y ventanas' },
  { id: 'equipo', label: 'Equipo', desc: 'Auto-followup, asignación default' },
  { id: 'delegacion', label: 'Delegación', desc: 'Qué hace sin preguntar' },
  { id: 'compliance', label: 'Compliance', desc: 'CMS, SOA, MBI, TCPA' },
  { id: 'otro', label: 'Otro', desc: 'Sin categoría específica' },
];

const SUGERIDAS = [
  { categoria: 'equipo', regla: 'Si nombro a alguien para una tarea sin nombrar a quién, asigna a Sami (id 10) por default.' },
  { categoria: 'equipo', regla: 'Si Sami no contesta un ticket en 24h, mándale SMS recordatorio auto.' },
  { categoria: 'comunicacion', regla: 'Lead nuevo Medicare → template de bienvenida + crear miembro en LUNA en estado PROSPECTO.' },
  { categoria: 'escalacion', regla: 'Carrier rep con deadline <24h → escalar inmediato aunque sea quiet hours.' },
  { categoria: 'tiempo', regla: 'Nunca me interrumpas entre 9pm y 7am salvo emergencia familiar o cliente en crisis.' },
  { categoria: 'compliance', regla: 'Nunca mandes detalles de plan a un cliente Medicare sin SOA firmada primero.' },
  { categoria: 'delegacion', regla: 'Templates pre-aprobados se pueden mandar sin pedir mi "envía". El resto sí espera mi OK.' },
];

function NewOrderForm({ onCreate, onCancel, prefill }) {
  const [regla, setRegla] = useState(prefill?.regla || '');
  const [categoria, setCategoria] = useState(prefill?.categoria || 'equipo');
  const [submitting, setSubmitting] = useState(false);

  async function submit(e) {
    e.preventDefault();
    if (!regla.trim()) return;
    setSubmitting(true);
    try { await onCreate({ regla: regla.trim(), categoria }); }
    finally { setSubmitting(false); }
  }

  return (
    <form onSubmit={submit} className="border-t border-b border-ink-1 py-6 mb-10 space-y-3">
      <div>
        <label className="font-mono text-[9px] tracking-[0.22em] uppercase text-ink-3 block mb-1">Regla</label>
        <textarea
          autoFocus
          value={regla}
          onChange={(e) => setRegla(e.target.value)}
          placeholder='Ej. "Si Sami no contesta un ticket en 24h, mándale SMS auto."'
          rows={3}
          className="w-full bg-transparent border-b border-ink-1 font-serif text-lg italic py-1 outline-none placeholder:text-ink-3/60 resize-none"
        />
      </div>
      <div>
        <label className="font-mono text-[9px] tracking-[0.22em] uppercase text-ink-3 block mb-1">Categoría</label>
        <select
          value={categoria}
          onChange={(e) => setCategoria(e.target.value)}
          className="bg-transparent border-b border-lino-400 font-mono text-xs uppercase tracking-wider text-ink-1 py-1 outline-none"
        >
          {CATEGORIAS.map((c) => (
            <option key={c.id} value={c.id}>{c.label}</option>
          ))}
        </select>
      </div>
      <div className="flex gap-3 pt-2">
        <button
          type="submit"
          disabled={submitting || !regla.trim()}
          className="font-mono text-[10px] uppercase tracking-[0.18em] px-4 py-2 bg-ink-1 text-lino-100 hover:bg-lino-800 disabled:opacity-40"
        >
          {submitting ? 'Creando…' : 'Crear regla'}
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

export default function Reglas() {
  const [orders, setOrders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [prefill, setPrefill] = useState(null);
  const [filter, setFilter] = useState('activa');
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    try { setOrders(await api.orders(filter)); }
    catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, [filter]);

  async function create(data) {
    try {
      await api.orderCreate(data);
      setShowForm(false);
      setPrefill(null);
      await reload();
    } catch (e) { setErr(e.message); }
  }

  async function pause(id) {
    try { await api.orderPause(id); await reload(); }
    catch (e) { setErr(e.message); }
  }
  async function activate(id) {
    try { await api.orderActivate(id); await reload(); }
    catch (e) { setErr(e.message); }
  }
  async function del(id) {
    if (!confirm('¿Borrar esta regla permanentemente?')) return;
    try { await api.orderDelete(id); await reload(); }
    catch (e) { setErr(e.message); }
  }

  // Agrupa por categoría
  const byCategory = {};
  for (const o of orders) {
    if (!byCategory[o.categoria]) byCategory[o.categoria] = [];
    byCategory[o.categoria].push(o);
  }

  return (
    <div className="pb-12">
      {/* Masthead */}
      <header className="flex items-end justify-between border-b border-ink-1 pt-2 pb-3 mb-8">
        <div className="font-serif text-sm tracking-wide text-ink-1">
          ATHENA <span className="font-mono text-xs text-ink-3 ml-2">Órdenes permanentes</span>
        </div>
        <div className="flex items-center gap-3">
          <select
            className="bg-transparent font-mono text-[10px] uppercase tracking-[0.18em] text-ink-1 border-b border-ink-1 outline-none"
            value={filter}
            onChange={(e) => setFilter(e.target.value)}
          >
            <option value="activa">Activas</option>
            <option value="pausada">Pausadas</option>
            <option value="retirada">Retiradas</option>
            <option value="">Todas</option>
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
          Reglas que Athena sigue siempre
        </p>
        <h1 className="font-serif text-[2rem] leading-[1.1] tracking-tight text-ink-1">
          {orders.length === 0
            ? <span><em className="italic font-light">Sin reglas.</em><br/>Dale órdenes — ella las aplica para siempre.</span>
            : <span><span className="font-light">{orders.length}</span> {orders.length === 1 ? 'regla' : 'reglas'} en vigor.</span>}
        </h1>
      </section>

      {err && <p className="text-red font-mono text-xs uppercase mb-4">{err}</p>}

      {showForm && <NewOrderForm onCreate={create} onCancel={() => { setShowForm(false); setPrefill(null); }} prefill={prefill} />}

      {!showForm && (
        <button
          onClick={() => { setPrefill(null); setShowForm(true); }}
          className="font-mono text-[10px] uppercase tracking-[0.18em] px-4 py-2 border border-ink-1 text-ink-1 hover:bg-ink-1 hover:text-lino-100 inline-flex items-center gap-2 mb-8"
        >
          <Plus size={12} strokeWidth={2} />
          Nueva regla
        </button>
      )}

      {/* RULES BY CATEGORY */}
      {CATEGORIAS.map((cat) => {
        const items = byCategory[cat.id];
        if (!items?.length) return null;
        return (
          <section key={cat.id} className="mb-10">
            <div className="border-t border-ink-1 pt-4 mb-4">
              <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3">
                {cat.label}
              </p>
              <p className="font-serif italic text-sm text-ink-3 mt-0.5">{cat.desc}</p>
            </div>
            <div className="space-y-4">
              {items.map((o) => (
                <article key={o.id} className="grid grid-cols-[1fr_auto] gap-4 items-start">
                  <div className="border-b border-lino-400 pb-4">
                    <p className="font-serif text-lg leading-snug text-ink-1">
                      {o.regla}
                    </p>
                    <p className="font-mono text-[10px] tracking-wider uppercase text-ink-3 mt-2">
                      {o.veces_aplicada
                        ? <>aplicada {o.veces_aplicada}× · </>
                        : <>nunca aplicada todavía · </>}
                      {o.status}
                    </p>
                  </div>
                  <div className="flex flex-col gap-1 pt-1">
                    {o.status === 'activa' && (
                      <button onClick={() => pause(o.id)} title="Pausar" className="text-ink-3 hover:text-amber p-1">
                        <Pause size={14} strokeWidth={1.5} />
                      </button>
                    )}
                    {o.status === 'pausada' && (
                      <button onClick={() => activate(o.id)} title="Activar" className="text-ink-3 hover:text-green-700 p-1">
                        <Play size={14} strokeWidth={1.5} />
                      </button>
                    )}
                    <button onClick={() => del(o.id)} title="Borrar" className="text-ink-3 hover:text-red p-1">
                      <Trash2 size={14} strokeWidth={1.5} />
                    </button>
                  </div>
                </article>
              ))}
            </div>
          </section>
        );
      })}

      {/* SUGERIDAS */}
      {orders.length === 0 && !showForm && (
        <section className="border-t border-ink-1 pt-6 mt-10">
          <p className="font-mono text-[10px] tracking-[0.22em] uppercase text-ink-3 mb-3">
            Sugeridas para empezar
          </p>
          <div className="space-y-2">
            {SUGERIDAS.map((s, i) => (
              <button
                key={i}
                onClick={() => { setPrefill(s); setShowForm(true); }}
                className="block text-left w-full border-b border-lino-300 pb-3 hover:border-ink-1 transition-colors group"
              >
                <span className="font-mono text-[9px] uppercase tracking-[0.22em] text-ink-3 group-hover:text-ink-1">
                  {CATEGORIAS.find((c) => c.id === s.categoria)?.label}
                </span>
                <p className="font-serif text-base italic text-ink-2 group-hover:text-ink-1 mt-1">
                  "{s.regla}"
                </p>
              </button>
            ))}
          </div>
        </section>
      )}
    </div>
  );
}
