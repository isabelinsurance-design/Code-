import { useEffect, useState } from 'react';
import { api } from '../../lib/api.js';
import Section from '../Section.jsx';

const CANALES = ['email', 'sms', 'whatsapp', 'llamada', 'whatsapp_voice', 'otro'];

export default function CommitmentsPanel() {
  const [items, setItems] = useState([]);
  const [filter, setFilter] = useState('pendiente');
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);

  async function reload() {
    setLoading(true);
    try { setItems(await api.commitments(filter)); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, [filter]);

  async function onCreate(data) {
    const r = await api.commitmentCreate(data);
    if (r.ok) { setShowForm(false); await reload(); }
    else alert(r.error || 'No se pudo crear.');
  }

  async function complete(id) {
    const evidencia = prompt('Evidencia / cómo lo cumplieron (opcional):') || '';
    await api.commitmentComplete(id, evidencia); await reload();
  }
  async function fail(id) {
    const razon = prompt('Razón (opcional):') || '';
    await api.commitmentFail(id, razon); await reload();
  }
  async function cancel(id) {
    if (!confirm('¿Cancelar este compromiso?')) return;
    await api.commitmentCancel(id); await reload();
  }
  async function addNote(id) {
    const texto = prompt('Nueva nota:');
    if (!texto?.trim()) return;
    await api.commitmentNote(id, texto); await reload();
  }

  function venceLabel(c) {
    if (!c.vence) return null;
    const ms = new Date(c.vence).getTime() - Date.now();
    const dias = Math.ceil(ms / 86400000);
    if (dias < 0) return { txt: `vencido ${Math.abs(dias)}d`, cls: 'text-red' };
    if (dias === 0) return { txt: 'vence hoy', cls: 'text-amber' };
    if (dias <= 3) return { txt: `${dias}d`, cls: 'text-amber' };
    return { txt: `${dias}d`, cls: 'text-ink-3' };
  }

  return (
    <div className="space-y-4">
      <Section
        title="Promesas que otros te hicieron"
        subtitle="Athena las persigue sola — te avisa si alguien se atrasa."
        action={
          <div className="flex gap-2">
            <select className="input text-xs" value={filter} onChange={(e) => setFilter(e.target.value)}>
              <option value="pendiente">Pendientes</option>
              <option value="cumplida">Cumplidas</option>
              <option value="fallida">Fallidas</option>
              <option value="cancelada">Canceladas</option>
              <option value="">Todas</option>
            </select>
            <button onClick={() => setShowForm((s) => !s)} className="btn-primary text-sm">
              {showForm ? 'Cancelar' : '+ Nuevo'}
            </button>
          </div>
        }
      >
        {showForm && <CommitmentForm onSubmit={onCreate} onCancel={() => setShowForm(false)} />}
        {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
        {!loading && !items.length && <p className="text-ink-3 text-sm">Nada con ese filtro.</p>}
        {items.map((c) => {
          const v = venceLabel(c);
          return (
            <div key={c.id} className="border-b border-lino-200 last:border-0 py-3">
              <div className="flex items-start justify-between gap-3">
                <div className="flex-1">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-medium text-ink-1">{c.persona}</span>
                    <span className="text-xs text-ink-3">· {c.canal}</span>
                    {v && <span className={`text-xs font-medium ${v.cls}`}>{v.txt}</span>}
                    {c.recordatorios_enviados > 0 && (
                      <span className="text-[10px] uppercase tracking-wider font-semibold px-1.5 py-0.5 rounded bg-amber/10 text-amber">
                        {c.recordatorios_enviados} nudge{c.recordatorios_enviados !== 1 ? 's' : ''}
                      </span>
                    )}
                  </div>
                  <div className="text-sm text-ink-2 mt-1">{c.descripcion}</div>
                  {c.notas?.length > 0 && (
                    <div className="mt-1 text-xs text-ink-3 italic">
                      última nota: "{c.notas[c.notas.length - 1].texto}"
                    </div>
                  )}
                </div>
              </div>
              {filter === 'pendiente' && (
                <div className="flex flex-wrap gap-1 mt-2">
                  <button onClick={() => complete(c.id)} className="text-xs text-lino-700 hover:underline px-2">Cumplió</button>
                  <button onClick={() => fail(c.id)} className="text-xs text-red hover:underline px-2">Falló</button>
                  <button onClick={() => cancel(c.id)} className="text-xs text-ink-3 hover:underline px-2">Cancelar</button>
                  <button onClick={() => addNote(c.id)} className="text-xs text-ink-3 hover:underline px-2">+ nota</button>
                </div>
              )}
            </div>
          );
        })}
      </Section>
    </div>
  );
}

function CommitmentForm({ onSubmit, onCancel }) {
  const [persona, setPersona] = useState('');
  const [descripcion, setDescripcion] = useState('');
  const [canal, setCanal] = useState('whatsapp');
  const [personaContacto, setPersonaContacto] = useState('');
  const [vence, setVence] = useState('');
  const [notas, setNotas] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function submit(e) {
    e.preventDefault();
    if (!persona.trim() || !descripcion.trim()) { alert('Pon persona y descripción.'); return; }
    setSubmitting(true);
    try {
      const data = { persona, descripcion, canal, persona_contacto: personaContacto, notas };
      if (vence) data.vence = vence;
      await onSubmit(data);
    } finally { setSubmitting(false); }
  }

  return (
    <form onSubmit={submit} className="bg-lino-50 border border-lino-300 rounded-xl p-4 space-y-3 mb-2">
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label">Persona</label>
          <input className="input w-full" value={persona} onChange={(e) => setPersona(e.target.value)} autoFocus placeholder="Carlos del banco" />
        </div>
        <div>
          <label className="label">Contacto (opcional)</label>
          <input className="input w-full" value={personaContacto} onChange={(e) => setPersonaContacto(e.target.value)} placeholder="+1..., email" />
        </div>
      </div>
      <div>
        <label className="label">Qué te prometió</label>
        <textarea rows={2} className="input w-full" value={descripcion} onChange={(e) => setDescripcion(e.target.value)} placeholder="Me iba a mandar el statement del 2025 antes del viernes." />
      </div>
      <div className="grid grid-cols-2 gap-3">
        <div>
          <label className="label">Canal donde lo prometió</label>
          <select className="input w-full" value={canal} onChange={(e) => setCanal(e.target.value)}>
            {CANALES.map((c) => <option key={c} value={c}>{c}</option>)}
          </select>
        </div>
        <div>
          <label className="label">Para cuándo</label>
          <input type="date" className="input w-full" value={vence} onChange={(e) => setVence(e.target.value)} />
        </div>
      </div>
      <div>
        <label className="label">Nota inicial (opcional)</label>
        <input className="input w-full" value={notas} onChange={(e) => setNotas(e.target.value)} />
      </div>
      <div className="flex justify-end gap-2 pt-1">
        <button type="button" onClick={onCancel} className="btn-ghost text-sm">Cancelar</button>
        <button type="submit" disabled={submitting} className="btn-primary text-sm">{submitting ? '…' : 'Guardar'}</button>
      </div>
    </form>
  );
}
