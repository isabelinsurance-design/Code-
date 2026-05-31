import { useEffect, useState } from 'react';
import { api } from '../../lib/api.js';
import Section from '../Section.jsx';
import ItemRow from '../ItemRow.jsx';

export default function ResearchPanel() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);

  async function reload() {
    setLoading(true);
    try { setItems(await api.researchTopics()); } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  async function onCreate(data) {
    const r = await api.researchCreate(data);
    if (r.ok) { setShowForm(false); await reload(); }
    else alert(r.error || 'No se pudo crear.');
  }

  async function onPause(id) { await api.researchPause(id); await reload(); }

  async function onDelete(id) {
    if (!confirm('¿Eliminar este tema permanentemente?')) return;
    await api.researchDelete(id);
    await reload();
  }

  async function onSeed() {
    if (!confirm('¿Sembrar 3 temas default (Medicare News, Brand & Content Latina, Insurance Industry)?')) return;
    await api.researchSeed();
    await reload();
  }

  return (
    <Section
      title="Research"
      subtitle="Temas que Athena investiga al mediodía. Te ahorra horas de scroll."
      action={
        <div className="flex gap-2">
          {!items.length && (
            <button onClick={onSeed} className="btn-ghost text-sm">Sembrar 3 defaults</button>
          )}
          <button onClick={() => setShowForm((s) => !s)} className="btn-primary text-sm">
            {showForm ? 'Cancelar' : '+ Nuevo tema'}
          </button>
        </div>
      }
    >
      {showForm && <ResearchForm onSubmit={onCreate} onCancel={() => setShowForm(false)} />}
      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !items.length && <p className="text-ink-3 text-sm">Sin temas. Siembra defaults o crea uno desde cero.</p>}
      {items.map((t) => (
        <ItemRow
          key={t.id}
          muted={!t.activo}
          title={t.nombre}
          badge={t.activo ? null : 'pausado'}
          meta={
            <>
              <div>{t.queries.length} queries · max {t.max_items} items/día</div>
              <div className="mt-1 text-ink-2 italic">{t.queries.slice(0, 2).map((q) => `"${q}"`).join(' · ')}{t.queries.length > 2 ? ` · +${t.queries.length - 2}` : ''}</div>
              {t.fuente_hint && <div className="mt-1 text-ink-3 text-[11px]">Hint: {t.fuente_hint}</div>}
            </>
          }
          actions={
            <>
              <button onClick={() => onPause(t.id)} className="text-xs text-ink-2 hover:underline px-2">
                {t.activo ? 'Pausar' : 'Activar'}
              </button>
              <button onClick={() => onDelete(t.id)} className="text-xs text-red hover:underline px-2">
                Borrar
              </button>
            </>
          }
        />
      ))}
    </Section>
  );
}

function ResearchForm({ onSubmit, onCancel }) {
  const [nombre, setNombre] = useState('');
  const [queriesText, setQueriesText] = useState('');
  const [fuenteHint, setFuenteHint] = useState('');
  const [maxItems, setMaxItems] = useState(2);
  const [submitting, setSubmitting] = useState(false);

  async function submit(e) {
    e.preventDefault();
    const queries = queriesText.split('\n').map((s) => s.trim()).filter(Boolean);
    if (!nombre.trim() || !queries.length) {
      alert('Pon nombre y al menos una query.');
      return;
    }
    setSubmitting(true);
    try {
      await onSubmit({ nombre, queries, fuente_hint: fuenteHint, max_items: maxItems });
    } finally { setSubmitting(false); }
  }

  return (
    <form onSubmit={submit} className="bg-lino-50 border border-lino-300 rounded-xl p-4 space-y-3 mb-2">
      <div>
        <label className="label">Nombre del tema</label>
        <input className="input w-full" value={nombre} onChange={(e) => setNombre(e.target.value)} placeholder="Medicare News" autoFocus />
      </div>
      <div>
        <label className="label">Queries (1-5, una por línea — Athena rota entre ellas)</label>
        <textarea
          className="input w-full font-mono text-sm"
          rows={4}
          value={queriesText}
          onChange={(e) => setQueriesText(e.target.value)}
          placeholder="Medicare CMS Final Rule brokers&#10;SCAN Anthem Humana news&#10;AEP 2027 changes"
        />
      </div>
      <div>
        <label className="label">Hint de fuentes (opcional)</label>
        <input
          className="input w-full"
          value={fuenteHint}
          onChange={(e) => setFuenteHint(e.target.value)}
          placeholder="Prefiere fuentes oficiales. Skip listicles."
        />
      </div>
      <div>
        <label className="label">Items máx por día</label>
        <input
          type="number" min={1} max={5}
          className="input w-24"
          value={maxItems}
          onChange={(e) => setMaxItems(parseInt(e.target.value, 10) || 2)}
        />
      </div>
      <div className="flex justify-end gap-2 pt-1">
        <button type="button" onClick={onCancel} className="btn-ghost text-sm">Cancelar</button>
        <button type="submit" disabled={submitting} className="btn-primary text-sm">
          {submitting ? 'Guardando…' : 'Crear tema'}
        </button>
      </div>
    </form>
  );
}
