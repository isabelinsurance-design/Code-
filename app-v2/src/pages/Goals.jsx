import { useEffect, useState } from 'react';
import { api } from '../lib/api.js';

const AREA_COLORS = {
  personal: 'bg-blue/10 text-blue-700',
  trabajo: 'bg-purple/10 text-purple-700',
  salud: 'bg-green/10 text-green-700',
  finanzas: 'bg-yellow/30 text-ink-1',
  otro: 'bg-lino-200 text-ink-3',
};

export default function Goals() {
  const [items, setItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [status, setStatus] = useState('activa');
  const [composing, setComposing] = useState(false);
  const [draft, setDraft] = useState({ nombre: '', target: '', unidad: '', vence: '', area: 'personal', notas: '' });
  const [updatingId, setUpdatingId] = useState(null);
  const [progresoDraft, setProgresoDraft] = useState('');
  const [notaDraft, setNotaDraft] = useState('');
  const [err, setErr] = useState('');

  async function reload() {
    setLoading(true);
    try { setItems(await api.goalsList(status)); }
    catch (e) { setErr(e.message); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, [status]);

  async function submit() {
    setErr('');
    if (!draft.nombre.trim()) { setErr('Nombre requerido.'); return; }
    if (!draft.vence) { setErr('Fecha de vencimiento requerida.'); return; }
    try {
      await api.goalAdd({
        nombre: draft.nombre,
        target: draft.target ? Number(draft.target) : null,
        unidad: draft.unidad,
        vence: draft.vence,
        area: draft.area,
        notas: draft.notas,
      });
      setDraft({ nombre: '', target: '', unidad: '', vence: '', area: 'personal', notas: '' });
      setComposing(false);
      reload();
    } catch (e) { setErr(e.message); }
  }

  async function updateProgreso(id) {
    try {
      await api.goalUpdate(id, { progreso: Number(progresoDraft), nota: notaDraft });
      setUpdatingId(null);
      setProgresoDraft(''); setNotaDraft('');
      reload();
    } catch (e) { setErr(e.message); }
  }

  async function setGoalStatus(id, newStatus) {
    try {
      await api.goalUpdate(id, { status: newStatus });
      reload();
    } catch (e) { setErr(e.message); }
  }

  return (
    <div className="space-y-5">
      <header className="flex items-end justify-between flex-wrap gap-3">
        <div>
          <h2 className="font-serif text-3xl text-lino-800">Metas / OKRs</h2>
          <p className="text-ink-3 text-sm">Tus objetivos cuantitativos. Victoria te confronta con el dato real.</p>
        </div>
        <button onClick={() => setComposing((c) => !c)} className="btn-primary text-sm">
          {composing ? 'Cancelar' : '+ Nueva meta'}
        </button>
      </header>

      {err && <p className="text-red text-xs">{err}</p>}

      {composing && (
        <div className="card space-y-2">
          <input
            type="text"
            value={draft.nombre}
            onChange={(e) => setDraft({ ...draft, nombre: e.target.value })}
            placeholder="Nombre de la meta (ej: AEP 2026 - 40 applications)"
            className="input w-full text-sm"
          />
          <div className="grid grid-cols-2 gap-2">
            <input
              type="number"
              value={draft.target}
              onChange={(e) => setDraft({ ...draft, target: e.target.value })}
              placeholder="Target numérico (opcional)"
              className="input text-sm"
              step="0.1"
            />
            <input
              type="text"
              value={draft.unidad}
              onChange={(e) => setDraft({ ...draft, unidad: e.target.value })}
              placeholder="Unidad (apps, lbs, $...)"
              className="input text-sm"
            />
          </div>
          <div className="grid grid-cols-2 gap-2">
            <input
              type="date"
              value={draft.vence}
              onChange={(e) => setDraft({ ...draft, vence: e.target.value })}
              className="input text-sm"
            />
            <select
              value={draft.area}
              onChange={(e) => setDraft({ ...draft, area: e.target.value })}
              className="input text-sm"
            >
              <option value="personal">personal</option>
              <option value="trabajo">trabajo</option>
              <option value="salud">salud</option>
              <option value="finanzas">finanzas</option>
              <option value="otro">otro</option>
            </select>
          </div>
          <textarea
            rows={2}
            value={draft.notas}
            onChange={(e) => setDraft({ ...draft, notas: e.target.value })}
            placeholder="Notas (opcional)"
            className="input w-full text-sm resize-none"
          />
          <div className="flex justify-end">
            <button onClick={submit} className="btn-primary text-sm">Guardar</button>
          </div>
        </div>
      )}

      <div className="flex gap-2">
        {['activa', 'completada', 'abandonada'].map((s) => (
          <button
            key={s}
            onClick={() => setStatus(s)}
            className={`px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${
              status === s ? 'bg-lino-700 text-white' : 'bg-lino-100 text-ink-2 hover:bg-lino-200'
            }`}
          >
            {s}
          </button>
        ))}
      </div>

      {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
      {!loading && !items.length && (
        <p className="text-ink-3 text-sm italic">Sin metas {status}. {status === 'activa' && 'Empieza con + Nueva meta.'}</p>
      )}

      <div className="space-y-3">
        {items.map((m) => {
          const p = m.proyeccion;
          const pct = p?.pct_avance ?? null;
          const enTrack = p?.en_track;
          return (
            <div key={m.id} className="card">
              <div className="flex items-start justify-between gap-2 mb-2">
                <div className="flex-1">
                  <div className="flex items-center gap-2 mb-1">
                    <span className={`px-2 py-0.5 rounded text-xs ${AREA_COLORS[m.area] || AREA_COLORS.otro}`}>{m.area}</span>
                    {p && status === 'activa' && (
                      <span className={`text-xs ${enTrack ? 'text-green-700' : 'text-orange-700'}`}>
                        {enTrack ? '✓ en track' : '⚠ off-track'}
                      </span>
                    )}
                  </div>
                  <h3 className="font-medium text-ink-1">{m.nombre}</h3>
                  {m.notas && <p className="text-xs text-ink-3 mt-1">{m.notas}</p>}
                </div>
                {status === 'activa' && (
                  <div className="flex gap-1 shrink-0">
                    <button onClick={() => setGoalStatus(m.id, 'completada')} className="text-xs text-ink-3 hover:text-green-700" title="Marcar completada">✓</button>
                    <button onClick={() => setGoalStatus(m.id, 'abandonada')} className="text-xs text-ink-3 hover:text-red" title="Abandonar">✕</button>
                  </div>
                )}
              </div>

              {m.target !== null && (
                <div className="space-y-1">
                  <div className="flex justify-between text-xs text-ink-2">
                    <span>{m.progreso} / {m.target} {m.unidad}</span>
                    {pct !== null && <span>{pct}%</span>}
                  </div>
                  <div className="w-full bg-lino-200 rounded-full h-2 overflow-hidden">
                    <div
                      className={`h-full transition-all ${enTrack ? 'bg-green-700' : 'bg-orange'}`}
                      style={{ width: `${Math.min(pct || 0, 100)}%` }}
                    />
                  </div>
                  {p && (
                    <div className="flex justify-between text-xs text-ink-3 mt-1">
                      <span>{p.dias_restantes}d restantes</span>
                      {p.requerido_diario !== null && (
                        <span>Need {p.requerido_diario.toFixed(1)}{m.unidad ? ` ${m.unidad}` : ''}/d</span>
                      )}
                    </div>
                  )}
                </div>
              )}

              {status === 'activa' && (
                <div className="mt-3 pt-3 border-t border-lino-200">
                  {updatingId === m.id ? (
                    <div className="space-y-2">
                      <div className="flex gap-2">
                        <input
                          type="number"
                          value={progresoDraft}
                          onChange={(e) => setProgresoDraft(e.target.value)}
                          placeholder={`Nuevo progreso (actual: ${m.progreso})`}
                          className="input flex-1 text-sm"
                          step="0.1"
                          autoFocus
                        />
                        <button onClick={() => updateProgreso(m.id)} disabled={!progresoDraft} className="btn-primary text-sm">Guardar</button>
                        <button onClick={() => { setUpdatingId(null); setProgresoDraft(''); setNotaDraft(''); }} className="btn-ghost text-sm">×</button>
                      </div>
                      <input
                        type="text"
                        value={notaDraft}
                        onChange={(e) => setNotaDraft(e.target.value)}
                        placeholder="Nota del update (opcional)"
                        className="input w-full text-sm"
                      />
                    </div>
                  ) : (
                    <button onClick={() => { setUpdatingId(m.id); setProgresoDraft(String(m.progreso)); }} className="text-xs text-lino-700 hover:underline">
                      Actualizar progreso
                    </button>
                  )}
                </div>
              )}
            </div>
          );
        })}
      </div>
    </div>
  );
}
