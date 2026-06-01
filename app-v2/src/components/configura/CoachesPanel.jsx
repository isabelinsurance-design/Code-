import { useEffect, useState } from 'react';
import { api } from '../../lib/api.js';
import Section from '../Section.jsx';

const COACHES = [
  { id: 'carmen', name: 'Chef Carmen', domain: 'Nutrición' },
  { id: 'rivera', name: 'Coach Rivera', domain: 'Fitness / Tonal' },
  { id: 'sofia', name: 'Dra. Sofía', domain: 'Hormonas / sueño / vitaminas' },
  { id: 'paloma', name: 'Intimidad Paloma', domain: 'Deseo / placer / pareja' },
  { id: 'alma', name: 'Mente Alma', domain: 'Mindset / ansiedad' },
  { id: 'maria', name: 'María Medicare', domain: 'Pipeline clientes' },
  { id: 'elena', name: 'CFO Elena', domain: 'Finanzas' },
  { id: 'nora', name: 'Negocia Nora', domain: 'Ventas / negociación' },
  { id: 'victoria', name: 'Visión Victoria', domain: 'OKRs / rocas 90d' },
  { id: 'marisol', name: 'Brand Marisol', domain: 'Contenido / YouTube' },
  { id: 'beatriz', name: 'Network Beatriz', domain: 'Networking / PR' },
  { id: 'lucia', name: 'Voz Lucía', domain: 'Voz + public speaking' },
  { id: 'dolores', name: 'Cuidadora Dolores', domain: 'Cuidado padres mayores' },
  { id: 'esperanza', name: 'Guía Esperanza', domain: 'Faith / espiritual' },
  { id: 'rosa', name: 'Casa Rosa', domain: 'Organización + decor' },
  { id: 'luna', name: 'Beauty Luna', domain: 'Piel / beauty' },
  { id: 'valentina', name: 'Estilo Valentina', domain: 'Estilo / wardrobe' },
  { id: 'catalina', name: 'Viajes Catalina', domain: 'Travel' },
];

const CADENCIAS = [
  { value: 'diaria', label: 'Diaria' },
  { value: 'L-V', label: 'L–V' },
  { value: '3x_semana', label: '3x/sem (L/X/V)' },
  { value: 'lunes', label: 'Lunes' },
  { value: 'martes', label: 'Martes' },
  { value: 'miércoles', label: 'Miércoles' },
  { value: 'jueves', label: 'Jueves' },
  { value: 'viernes', label: 'Viernes' },
  { value: 'sabado', label: 'Sábado' },
  { value: 'domingo', label: 'Domingo' },
  { value: 'quincenal', label: 'Cada 15 días' },
  { value: 'mensual', label: 'Mensual (día 1)' },
  { value: 'trimestral', label: 'Trimestral (4x/año)' },
  { value: 'bajo_demanda', label: 'Bajo demanda' },
];

export default function CoachesPanel() {
  const [cadences, setCadences] = useState([]);
  const [today, setToday] = useState([]);
  const [loading, setLoading] = useState(true);
  const [editingCoach, setEditingCoach] = useState(null);

  async function reload() {
    setLoading(true);
    try {
      const [c, t] = await Promise.all([api.coachCadences(), api.coachCadencesToday()]);
      setCadences(c || []);
      setToday(t || []);
    } finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, []);

  const cadenceFor = (coach) => cadences.find((c) => c.coach === coach);

  async function onSet(coach, cadencia, hora) {
    const r = await api.coachCadenceSet({ coach, cadencia, hora: hora || null });
    if (r.ok) { setEditingCoach(null); await reload(); }
    else alert(r.error);
  }
  async function onRemove(coach) {
    if (!confirm(`¿Eliminar cadencia con ${coach}?`)) return;
    await api.coachCadenceRemove(coach); await reload();
  }
  async function onPause(coach) {
    await api.coachCadencePause(coach); await reload();
  }
  async function onSeed() {
    if (!confirm('Sembrar cadencias default (Carmen diaria, Rivera 3x/sem, Victoria lunes, etc)?')) return;
    await api.coachCadenceSeed(); await reload();
  }
  async function onMarkDone(coach) {
    await api.coachCadenceCheckIn(coach, 'completado', '');
    await reload();
  }

  return (
    <div className="space-y-4">
      {today.length > 0 && (
        <Section title="Hoy toca" subtitle={`${today.filter((c) => !c.ya_hecho).length} pendiente(s)`}>
          {today.map((c) => (
            <div key={c.id} className="flex items-center justify-between gap-3 py-2 border-b border-lino-200 last:border-0">
              <div>
                <span className="font-medium">{c.coach}</span>
                <span className="text-xs text-ink-3 ml-2">{c.hora ? `(${c.hora})` : ''} · {c.cadencia}</span>
              </div>
              <div className="flex gap-2">
                {c.ya_hecho ? (
                  <span className="text-xs text-lino-700 font-medium">✓ hecho</span>
                ) : (
                  <>
                    <a href={`/app/chat/${c.coach}`} className="text-xs text-lino-700 hover:underline">Abrir chat</a>
                    <button onClick={() => onMarkDone(c.coach)} className="text-xs text-ink-2 hover:underline">Marcar hecho</button>
                  </>
                )}
              </div>
            </div>
          ))}
        </Section>
      )}

      <Section
        title="Cadencias con coaches"
        subtitle="Define cuándo Athena te recuerda hacer check-in con cada una."
        action={
          !cadences.length && (
            <button onClick={onSeed} className="btn-ghost text-sm">Sembrar defaults</button>
          )
        }
      >
        {loading && <p className="text-ink-3 text-sm">Cargando…</p>}
        {!loading && COACHES.map((coach) => {
          const c = cadenceFor(coach.id);
          const isEditing = editingCoach === coach.id;
          return (
            <CoachRow
              key={coach.id}
              coach={coach}
              cadence={c}
              isEditing={isEditing}
              onEdit={() => setEditingCoach(coach.id)}
              onCancel={() => setEditingCoach(null)}
              onSet={(cad, hora) => onSet(coach.id, cad, hora)}
              onRemove={() => onRemove(coach.id)}
              onPause={() => onPause(coach.id)}
            />
          );
        })}
      </Section>
    </div>
  );
}

function CoachRow({ coach, cadence, isEditing, onEdit, onCancel, onSet, onRemove, onPause }) {
  const [cadValue, setCadValue] = useState(cadence?.cadencia || 'semanal');
  const [horaValue, setHoraValue] = useState(cadence?.hora || '');

  return (
    <div className="border-b border-lino-200 last:border-0 py-2">
      <div className="flex items-start justify-between gap-3">
        <div className="flex-1">
          <div className="flex items-baseline gap-2 flex-wrap">
            <span className="font-medium text-ink-1">{coach.name}</span>
            <span className="text-xs text-ink-3">— {coach.domain}</span>
          </div>
          {cadence && !isEditing && (
            <div className={`text-xs mt-1 ${cadence.pausada ? 'text-ink-3 italic' : 'text-lino-700'}`}>
              {cadence.pausada ? 'pausada · ' : ''}{CADENCIAS.find((c) => c.value === cadence.cadencia)?.label || cadence.cadencia}
              {cadence.hora && ` @ ${cadence.hora}`}
            </div>
          )}
          {!cadence && !isEditing && (
            <div className="text-xs text-ink-3 mt-1 italic">Sin cadencia configurada</div>
          )}
        </div>
        {!isEditing ? (
          <div className="flex gap-1 shrink-0">
            <button onClick={onEdit} className="text-xs text-lino-700 hover:underline px-2">
              {cadence ? 'Editar' : 'Configurar'}
            </button>
            {cadence && (
              <>
                <button onClick={onPause} className="text-xs text-ink-2 hover:underline px-2">
                  {cadence.pausada ? 'Reactivar' : 'Pausar'}
                </button>
                <button onClick={onRemove} className="text-xs text-red hover:underline px-2">Borrar</button>
              </>
            )}
          </div>
        ) : (
          <div className="flex flex-col gap-2 sm:flex-row sm:items-end shrink-0">
            <select className="input text-xs" value={cadValue} onChange={(e) => setCadValue(e.target.value)}>
              {CADENCIAS.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
            </select>
            <input type="time" className="input text-xs w-24" value={horaValue} onChange={(e) => setHoraValue(e.target.value)} placeholder="hora" />
            <div className="flex gap-1">
              <button onClick={() => onSet(cadValue, horaValue)} className="btn-primary text-xs">OK</button>
              <button onClick={onCancel} className="btn-ghost text-xs">×</button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
