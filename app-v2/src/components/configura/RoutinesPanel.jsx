import { useEffect, useState } from 'react';
import { api } from '../../lib/api.js';
import Section from '../Section.jsx';
import ItemRow from '../ItemRow.jsx';

const RECURRENCIAS = [
  { value: 'diaria', label: 'Diaria' },
  { value: 'L-V', label: 'Lunes a viernes' },
  { value: 'lunes', label: 'Lunes' },
  { value: 'martes', label: 'Martes' },
  { value: 'miércoles', label: 'Miércoles' },
  { value: 'jueves', label: 'Jueves' },
  { value: 'viernes', label: 'Viernes' },
  { value: 'sabado', label: 'Sábado' },
  { value: 'domingo', label: 'Domingo' },
];

export default function RoutinesPanel() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);

  async function reload() {
    setLoading(true);
    try { setItems(await api.routines()); } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function onCreate(data) {
    const r = await api.routineCreate(data);
    if (r.ok) {
      setShowForm(false);
      await reload();
    } else {
      alert(r.error || 'No se pudo crear.');
    }
  }

  async function onDeactivate(id) {
    if (!confirm('¿Desactivar esta rutina?')) return;
    await api.routineDeactivate(id);
    await reload();
  }

  return (
    <Section
      title="Rutinas"
      subtitle="Multi-paso recurrentes: morning ritual, meal prep, recording day, etc."
      action={
        <button onClick={() => setShowForm((s) => !s)} className="btn-primary text-sm">
          {showForm ? 'Cancelar' : '+ Nueva rutina'}
        </button>
      }
    >
      {showForm && <RoutineForm onSubmit={onCreate} onCancel={() => setShowForm(false)} />}
      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !items.length && <p className="text-ink-3 text-sm">No tienes rutinas configuradas todavía.</p>}
      {items.map((r) => (
        <ItemRow
          key={r.id}
          muted={!r.activa}
          title={r.nombre}
          badge={r.activa ? null : 'inactiva'}
          meta={
            <>
              <div>{r.pasos.length} pasos · {r.recurrencia}{r.hora_inicio ? ` · ${r.hora_inicio}` : ''}</div>
              <div className="mt-1 text-ink-2">{r.pasos.join(' → ')}</div>
            </>
          }
          actions={
            r.activa && (
              <button onClick={() => onDeactivate(r.id)} className="text-xs text-red hover:underline px-2">
                Desactivar
              </button>
            )
          }
        />
      ))}
    </Section>
  );
}

function RoutineForm({ onSubmit, onCancel }) {
  const [nombre, setNombre] = useState('');
  const [pasosText, setPasosText] = useState('');
  const [recurrencia, setRecurrencia] = useState('diaria');
  const [horaInicio, setHoraInicio] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function submit(e) {
    e.preventDefault();
    const pasos = pasosText.split('\n').map((s) => s.trim()).filter(Boolean);
    if (!nombre.trim() || !pasos.length) {
      alert('Pon nombre y al menos un paso.');
      return;
    }
    setSubmitting(true);
    try {
      await onSubmit({ nombre, pasos, recurrencia, hora_inicio: horaInicio || null });
    } finally { setSubmitting(false); }
  }

  return (
    <form onSubmit={submit} className="bg-lino-50 border border-lino-300 rounded-xl p-4 space-y-3 mb-2">
      <div>
        <label className="label">Nombre</label>
        <input className="input w-full" value={nombre} onChange={(e) => setNombre(e.target.value)} placeholder="Morning ritual" autoFocus />
      </div>
      <div>
        <label className="label">Pasos (uno por línea)</label>
        <textarea
          className="input w-full font-mono text-sm"
          rows={4}
          value={pasosText}
          onChange={(e) => setPasosText(e.target.value)}
          placeholder="agua 16oz&#10;meditar 5 min&#10;pesarse"
        />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label">Recurrencia</label>
          <select className="input w-full" value={recurrencia} onChange={(e) => setRecurrencia(e.target.value)}>
            {RECURRENCIAS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
          </select>
        </div>
        <div>
          <label className="label">Hora inicio (opcional)</label>
          <input type="time" className="input w-full" value={horaInicio} onChange={(e) => setHoraInicio(e.target.value)} />
        </div>
      </div>
      <div className="flex justify-end gap-2 pt-1">
        <button type="button" onClick={onCancel} className="btn-ghost text-sm">Cancelar</button>
        <button type="submit" disabled={submitting} className="btn-primary text-sm">
          {submitting ? 'Guardando…' : 'Crear rutina'}
        </button>
      </div>
    </form>
  );
}
