import { useEffect, useState } from 'react';
import { api } from '../../lib/api.js';
import Section from '../Section.jsx';
import ItemRow from '../ItemRow.jsx';

const MODOS = [
  { value: 'silencio',  label: 'Silencio (cero notificaciones)' },
  { value: 'lectura',   label: 'Lectura' },
  { value: 'recording', label: 'Recording (YouTube/podcast)' },
  { value: 'piano',     label: 'Piano' },
  { value: 'gym',       label: 'Gym / Tonal' },
];

const DIAS = [
  { value: 1, label: 'L' }, { value: 2, label: 'M' }, { value: 3, label: 'X' },
  { value: 4, label: 'J' }, { value: 5, label: 'V' }, { value: 6, label: 'S' },
  { value: 0, label: 'D' },
];

export default function FocusPanel() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);

  async function reload() {
    setLoading(true);
    try { setItems(await api.focusBlocks()); } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function onCreate(data) {
    const r = await api.focusCreate(data);
    if (r.ok) {
      setShowForm(false);
      await reload();
    } else {
      alert(r.error || 'No se pudo crear.');
    }
  }

  async function onDeactivate(id) {
    if (!confirm('¿Desactivar este bloque?')) return;
    await api.focusDeactivate(id);
    await reload();
  }

  return (
    <Section
      title="Focus blocks"
      subtitle="Ventanas protegidas — Athena se calla durante estas horas."
      action={
        <button onClick={() => setShowForm((s) => !s)} className="btn-primary text-sm">
          {showForm ? 'Cancelar' : '+ Nuevo bloque'}
        </button>
      }
    >
      {showForm && <FocusForm onSubmit={onCreate} onCancel={() => setShowForm(false)} />}
      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !items.length && <p className="text-ink-3 text-sm">Sin bloques. Crea uno para proteger tu tiempo (lectura, piano, gym).</p>}
      {items.map((b) => {
        const dias = (b.dias_semana || []).map((d) => DIAS.find((x) => x.value === d)?.label || '?').join(' ');
        return (
          <ItemRow
            key={b.id}
            muted={!b.activo}
            title={b.titulo}
            badge={b.modo}
            meta={`${b.inicio_hhmm}–${b.fin_hhmm} · ${dias}${b.notas ? ` · ${b.notas}` : ''}`}
            actions={
              b.activo && (
                <button onClick={() => onDeactivate(b.id)} className="text-xs text-red hover:underline px-2">
                  Desactivar
                </button>
              )
            }
          />
        );
      })}
    </Section>
  );
}

function FocusForm({ onSubmit, onCancel }) {
  const [titulo, setTitulo] = useState('');
  const [modo, setModo] = useState('lectura');
  const [inicio, setInicio] = useState('20:00');
  const [fin, setFin] = useState('21:00');
  const [dias, setDias] = useState([0, 1, 2, 3, 4, 5, 6]);
  const [notas, setNotas] = useState('');
  const [submitting, setSubmitting] = useState(false);

  function toggleDia(v) {
    setDias((cur) => cur.includes(v) ? cur.filter((d) => d !== v) : [...cur, v].sort());
  }

  async function submit(e) {
    e.preventDefault();
    if (!titulo.trim() || !inicio || !fin || !dias.length) {
      alert('Pon título, inicio, fin y al menos un día.');
      return;
    }
    setSubmitting(true);
    try {
      await onSubmit({ titulo, modo, inicio_hhmm: inicio, fin_hhmm: fin, dias_semana: dias, notas });
    } finally { setSubmitting(false); }
  }

  return (
    <form onSubmit={submit} className="bg-lino-50 border border-lino-300 rounded-xl p-4 space-y-3 mb-2">
      <div>
        <label className="label">Título</label>
        <input className="input w-full" value={titulo} onChange={(e) => setTitulo(e.target.value)} placeholder="Lectura noche" autoFocus />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label">Modo</label>
          <select className="input w-full" value={modo} onChange={(e) => setModo(e.target.value)}>
            {MODOS.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </select>
        </div>
        <div className="grid grid-cols-2 gap-2">
          <div>
            <label className="label">Inicio</label>
            <input type="time" className="input w-full" value={inicio} onChange={(e) => setInicio(e.target.value)} />
          </div>
          <div>
            <label className="label">Fin</label>
            <input type="time" className="input w-full" value={fin} onChange={(e) => setFin(e.target.value)} />
          </div>
        </div>
      </div>
      <div>
        <label className="label">Días</label>
        <div className="flex gap-1">
          {DIAS.map((d) => (
            <button
              key={d.value}
              type="button"
              onClick={() => toggleDia(d.value)}
              className={`w-9 h-9 rounded-lg font-medium text-sm transition-colors ${
                dias.includes(d.value) ? 'bg-lino-600 text-white' : 'bg-white border border-lino-300 text-ink-2'
              }`}
            >
              {d.label}
            </button>
          ))}
        </div>
      </div>
      <div>
        <label className="label">Notas (opcional)</label>
        <input className="input w-full" value={notas} onChange={(e) => setNotas(e.target.value)} placeholder="Sin pantallas, libro de papel" />
      </div>
      <div className="flex justify-end gap-2 pt-1">
        <button type="button" onClick={onCancel} className="btn-ghost text-sm">Cancelar</button>
        <button type="submit" disabled={submitting} className="btn-primary text-sm">
          {submitting ? 'Guardando…' : 'Crear bloque'}
        </button>
      </div>
    </form>
  );
}
