import { useEffect, useState } from 'react';
import { MapPin, Users } from 'lucide-react';
import { api } from '../lib/api.js';
import Section from '../components/Section.jsx';

export default function Calendar() {
  const [status, setStatus] = useState(null);
  const [events, setEvents] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showForm, setShowForm] = useState(false);
  const [hours, setHours] = useState(168); // default 7 días

  async function reload() {
    setLoading(true);
    try {
      const s = await api.calendarStatus();
      setStatus(s);
      if (s.configured) {
        // El endpoint devuelve {ok, events, reason} — extraemos events.
        // Si ok=false o events es undefined, dejamos array vacío.
        const r = await api.calendarUpcoming(hours, 50);
        const list = Array.isArray(r) ? r : (r?.events || []);
        setEvents(list);
      }
    } catch (e) { console.error(e); }
    finally { setLoading(false); }
  }
  useEffect(() => { reload(); }, [hours]);

  async function onCreate(data) {
    try {
      const r = await api.calendarCreate(data);
      if (r.ok === false) {
        if (r.reason === 'conflicto') {
          const list = (r.conflictos || []).map((c) => c.titulo || c.summary).join(', ');
          alert(`Conflicto con: ${list}. Vuelve a intentar con otro horario o márcalo para crear igual.`);
        } else {
          alert(r.reason || 'No se pudo crear.');
        }
      } else {
        setShowForm(false);
        await reload();
      }
    } catch (e) { alert(e.message); }
  }

  async function onDelete(id) {
    if (!confirm('¿Cancelar este evento? Se notifica a los asistentes.')) return;
    try { await api.calendarDelete(id); await reload(); }
    catch (e) { alert(e.message); }
  }

  if (status && !status.configured) {
    return (
      <div className="space-y-5">
        <header>
          <h2 className="font-serif text-3xl text-lino-800">Agenda</h2>
        </header>
        <div className="card bg-amber/5 border-amber/30">
          <p className="text-sm text-ink-2">
            <strong>Google Calendar no configurado todavía.</strong> Para activarlo, Sami necesita poner
            en Railway: <code className="bg-lino-200 px-1 rounded">GOOGLE_CALENDAR_CLIENT_ID</code>, <code className="bg-lino-200 px-1 rounded">GOOGLE_CALENDAR_CLIENT_SECRET</code> y <code className="bg-lino-200 px-1 rounded">GOOGLE_CALENDAR_REFRESH_TOKEN</code>.
            <br /><br />
            Mientras tanto Athena puede agendar cosas vía Marisol / Pilar por WhatsApp, pero no se sincronizan a Google.
          </p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-5">
      <header className="flex items-end justify-between gap-3 flex-wrap">
        <div>
          <h2 className="font-serif text-3xl text-lino-800">Agenda</h2>
          <p className="text-ink-3 text-sm">Tus próximos eventos de Google Calendar.</p>
        </div>
        <div className="flex gap-2">
          <select className="input text-sm" value={hours} onChange={(e) => setHours(parseInt(e.target.value, 10))}>
            <option value="24">24h</option>
            <option value="72">3 días</option>
            <option value="168">1 semana</option>
            <option value="720">1 mes</option>
          </select>
          <button onClick={() => setShowForm((s) => !s)} className="btn-primary text-sm">
            {showForm ? 'Cancelar' : '+ Evento'}
          </button>
        </div>
      </header>

      {showForm && <EventForm onSubmit={onCreate} onCancel={() => setShowForm(false)} />}

      <Section title="Próximos eventos" subtitle={loading ? 'Cargando…' : `${events.length} evento(s) en las próximas ${hours}h`}>
        {!loading && !events.length && <p className="text-ink-3 text-sm">Nada agendado en ese rango.</p>}
        {events.map((ev) => (
          <EventRow key={ev.id} ev={ev} onDelete={() => onDelete(ev.id)} />
        ))}
      </Section>
    </div>
  );
}

function EventRow({ ev, onDelete }) {
  const start = ev.inicio || ev.start;
  const fechaStr = start ? new Date(start).toLocaleString('es-MX', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';

  return (
    <div className="border-b border-lino-200 last:border-0 py-2 flex items-start justify-between gap-3">
      <div className="flex-1">
        <div className="font-medium text-ink-1">{ev.titulo || ev.summary}</div>
        <div className="text-xs text-ink-3 mt-0.5 inline-flex items-center gap-1 flex-wrap">
          <span>{fechaStr}</span>
          {ev.ubicacion && <span className="inline-flex items-center gap-1">· <MapPin size={11} strokeWidth={1.5} /> {ev.ubicacion}</span>}
          {ev.meetLink && <>· <a href={ev.meetLink} target="_blank" rel="noreferrer" className="text-lino-700 hover:underline">Meet ↗</a></>}
        </div>
        {ev.asistentes?.length > 0 && (
          <div className="text-xs text-ink-3 mt-0.5 inline-flex items-center gap-1">
            <Users size={11} strokeWidth={1.5} />
            {ev.asistentes.map((a) => a.email || a).slice(0, 3).join(', ')}
            {ev.asistentes.length > 3 && ` +${ev.asistentes.length - 3}`}
          </div>
        )}
        {ev.descripcion && <div className="text-xs text-ink-2 mt-1 line-clamp-2">{ev.descripcion}</div>}
      </div>
      <button onClick={onDelete} className="text-xs text-red hover:underline shrink-0">Cancelar</button>
    </div>
  );
}

function EventForm({ onSubmit, onCancel }) {
  const [titulo, setTitulo] = useState('');
  const [fecha, setFecha] = useState('');
  const [hora, setHora] = useState('10:00');
  const [duracion, setDuracion] = useState(30);
  const [ubicacion, setUbicacion] = useState('');
  const [conferencia, setConferencia] = useState(false);
  const [asistentesText, setAsistentesText] = useState('');
  const [descripcion, setDescripcion] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function submit(e) {
    e.preventDefault();
    if (!titulo.trim() || !fecha) { alert('Pon título y fecha.'); return; }
    const inicio = new Date(`${fecha}T${hora}:00`).toISOString();
    const asistentes = asistentesText.split(/[,\s]+/).filter((a) => a.includes('@'));
    setSubmitting(true);
    try {
      await onSubmit({
        titulo, inicio, duracion_min: duracion,
        descripcion, ubicacion, asistentes, conferencia,
      });
    } finally { setSubmitting(false); }
  }

  return (
    <form onSubmit={submit} className="bg-lino-50 border border-lino-300 rounded-xl p-4 space-y-3">
      <div>
        <label className="label">Título</label>
        <input className="input w-full" value={titulo} onChange={(e) => setTitulo(e.target.value)} autoFocus placeholder="Llamada con Cliente X" />
      </div>
      <div className="grid grid-cols-3 gap-3">
        <div>
          <label className="label">Fecha</label>
          <input type="date" className="input w-full" value={fecha} onChange={(e) => setFecha(e.target.value)} />
        </div>
        <div>
          <label className="label">Hora</label>
          <input type="time" className="input w-full" value={hora} onChange={(e) => setHora(e.target.value)} />
        </div>
        <div>
          <label className="label">Duración (min)</label>
          <input type="number" min="15" step="15" className="input w-full" value={duracion} onChange={(e) => setDuracion(parseInt(e.target.value, 10))} />
        </div>
      </div>
      <div>
        <label className="label">Ubicación (opcional)</label>
        <input className="input w-full" value={ubicacion} onChange={(e) => setUbicacion(e.target.value)} placeholder="Oficina / Zoom / dirección" />
      </div>
      <div>
        <label className="label">Asistentes — emails separados por coma (opcional)</label>
        <input className="input w-full" value={asistentesText} onChange={(e) => setAsistentesText(e.target.value)} placeholder="cliente@gmail.com, sami@..." />
      </div>
      <div>
        <label className="label">Descripción (opcional)</label>
        <textarea rows={2} className="input w-full" value={descripcion} onChange={(e) => setDescripcion(e.target.value)} />
      </div>
      <div className="flex items-center gap-2">
        <input type="checkbox" id="meet" checked={conferencia} onChange={(e) => setConferencia(e.target.checked)} />
        <label htmlFor="meet" className="text-sm text-ink-2">Agregar link de Google Meet</label>
      </div>
      <div className="flex justify-end gap-2 pt-1">
        <button type="button" onClick={onCancel} className="btn-ghost text-sm">Cancelar</button>
        <button type="submit" disabled={submitting} className="btn-primary text-sm">
          {submitting ? 'Creando…' : 'Crear evento'}
        </button>
      </div>
    </form>
  );
}
