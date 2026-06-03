import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { api } from '../lib/api.js';

export default function Chat() {
  const params = useParams();
  const [coaches, setCoaches] = useState([]);
  const [coach, setCoach] = useState(params.coach || 'directora');

  // Si vienen con /chat/:coach pre-cargamos el prompt sugerido como hint
  const [inputHint, setInputHint] = useState('');
  useEffect(() => {
    if (params.coach && params.coach !== 'directora') {
      api.coachCadencePrompt(params.coach)
        .then((r) => setInputHint(r.prompt || ''))
        .catch(() => {});
    }
  }, [params.coach]);
  const [messages, setMessages] = useState([]);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [err, setErr] = useState('');
  const [plan, setPlan] = useState(null);
  const [planOpen, setPlanOpen] = useState(true);
  const [newPlanText, setNewPlanText] = useState('');
  const [notes, setNotes] = useState(null);
  const [notesOpen, setNotesOpen] = useState(false);
  const scrollRef = useRef(null);

  useEffect(() => {
    api.chatCoaches().then(setCoaches).catch((e) => setErr(e.message));
  }, []);

  async function reloadPlan(c = coach) {
    if (c === 'directora') {
      setPlan(null);
      return;
    }
    try {
      const p = await api.coachPlan(c);
      setPlan(p);
    } catch (e) {
      setErr(e.message);
    }
  }

  async function reloadNotes(c = coach) {
    if (c === 'directora') {
      setNotes(null);
      return;
    }
    try {
      const n = await api.coachNotes(c);
      setNotes(n);
    } catch { /* expediente vacío no es error */ }
  }

  async function clearNotes() {
    if (coach === 'directora') return;
    if (!confirm(`¿Borrar TODO el expediente de ${coaches.find((c) => c.id === coach)?.name || coach}? La coach perderá todo lo que ha aprendido de ti.`)) return;
    try {
      await api.coachNotesClear(coach);
      setNotes({ coach_id: coach, notes: '', actualizado: null });
    } catch (e) {
      setErr(e.message);
    }
  }

  // Al cambiar de coach: hidratamos su hilo persistente desde el servidor.
  // 'directora' usa el history de WhatsApp (compartido) — no tiene endpoint
  // /coach_thread, así que mostramos lista vacía y dejamos que ella responda
  // sabiendo todo desde su lado.
  useEffect(() => {
    setErr('');
    if (coach === 'directora') {
      setMessages([]);
      setPlan(null);
      setNotes(null);
      return;
    }
    let cancelled = false;
    setMessages([]);
    api.coachThread(coach)
      .then((r) => {
        if (cancelled) return;
        const hist = (r.messages || []).map((m) => ({ role: m.role, content: m.content }));
        setMessages(hist);
      })
      .catch((e) => { if (!cancelled) setErr(e.message); });
    reloadPlan(coach);
    reloadNotes(coach);
    return () => { cancelled = true; };
  }, [coach]);

  async function clearThread() {
    if (coach === 'directora') return;
    if (!confirm(`¿Borrar el historial completo con ${coaches.find((c) => c.id === coach)?.name || coach}? Esta acción no se puede deshacer.`)) return;
    try {
      await api.coachThreadClear(coach);
      setMessages([]);
    } catch (e) {
      setErr(e.message);
    }
  }

  async function addPlanItem() {
    const text = newPlanText.trim();
    if (!text) return;
    try {
      const p = await api.coachPlanAdd(coach, text);
      setPlan(p);
      setNewPlanText('');
    } catch (e) {
      setErr(e.message);
    }
  }

  async function updatePlanStatus(itemId, status) {
    try {
      const p = await api.coachPlanUpdate(coach, itemId, { status });
      setPlan(p);
    } catch (e) {
      setErr(e.message);
    }
  }

  async function removePlanItem(itemId) {
    if (!confirm('¿Borrar este item del plan?')) return;
    try {
      const p = await api.coachPlanRemove(coach, itemId);
      setPlan(p);
    } catch (e) {
      setErr(e.message);
    }
  }

  useEffect(() => {
    if (scrollRef.current) {
      scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
    }
  }, [messages, sending]);

  async function send() {
    const text = input.trim();
    if (!text || sending) return;
    setErr('');
    setMessages((m) => [...m, { role: 'user', content: text }]);
    setInput('');
    setSending(true);
    try {
      const r = await api.chat(coach, text);
      setMessages((m) => [...m, { role: 'assistant', content: r.reply || '(sin respuesta)' }]);
      // La coach pudo haber actualizado su plan o expediente vía tools — refrescamos.
      if (coach !== 'directora') {
        reloadPlan(coach);
        reloadNotes(coach);
      }
    } catch (e) {
      setErr(e.message);
      setMessages((m) => [...m, { role: 'assistant', content: `[error: ${e.message}]`, error: true }]);
    } finally {
      setSending(false);
    }
  }

  function onKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      send();
    }
  }

  return (
    <div className="space-y-4 h-full flex flex-col">
      <header className="flex items-end justify-between gap-3 flex-wrap">
        <div>
          <h2 className="font-serif text-3xl text-lino-800">Chat</h2>
          <p className="text-ink-3 text-sm">Habla con cualquiera de tus coaches.</p>
        </div>
        <div className="flex items-center gap-2">
          <select className="input text-sm" value={coach} onChange={(e) => setCoach(e.target.value)}>
            {coaches.map((c) => (
              <option key={c.id} value={c.id}>{c.name}{c.role ? ` — ${c.role}` : ''}</option>
            ))}
          </select>
          {coach !== 'directora' && messages.length > 0 && (
            <button
              onClick={clearThread}
              className="text-xs text-ink-3 hover:text-red underline"
              title="Borrar el historial completo con esta coach"
            >
              limpiar
            </button>
          )}
        </div>
      </header>

      {notes && notes.notes && (
        <div className="card bg-lino-50 border border-lino-200">
          <div className="flex items-center justify-between mb-2">
            <button
              onClick={() => setNotesOpen((o) => !o)}
              className="text-sm font-semibold text-lino-800 flex items-center gap-2"
            >
              <span>{notesOpen ? '▾' : '▸'}</span>
              <span>Expediente de {coaches.find((c) => c.id === coach)?.name || coach} sobre ti</span>
              <span className="text-xs text-ink-3 font-normal">
                ({notes.notes.length} chars{notes.actualizado ? ` · actualizado ${new Date(notes.actualizado).toLocaleDateString('es-MX', { month: 'short', day: 'numeric' })}` : ''})
              </span>
            </button>
            <button onClick={clearNotes} className="text-xs text-ink-3 hover:text-red" title="Borrar expediente completo">✕</button>
          </div>
          {notesOpen && (
            <pre className="text-xs text-ink-1 whitespace-pre-wrap font-sans leading-relaxed">{notes.notes}</pre>
          )}
        </div>
      )}

      {plan && (plan.items?.length > 0 || coach !== 'directora') && (
        <div className="card bg-lino-50 border border-lino-200">
          <div className="flex items-center justify-between mb-2">
            <button
              onClick={() => setPlanOpen((o) => !o)}
              className="text-sm font-semibold text-lino-800 flex items-center gap-2"
            >
              <span>{planOpen ? '▾' : '▸'}</span>
              <span>Plan vigente de {coaches.find((c) => c.id === coach)?.name || coach}</span>
              {plan.items?.length > 0 && (
                <span className="text-xs text-ink-3 font-normal">
                  ({plan.items.filter((i) => i.status === 'active').length} activo,
                  {' '}{plan.items.filter((i) => i.status === 'paused').length} pausado,
                  {' '}{plan.items.filter((i) => i.status === 'done').length} hecho)
                </span>
              )}
            </button>
          </div>
          {planOpen && (
            <div className="space-y-2">
              {!plan.items?.length && (
                <p className="text-xs text-ink-3 italic">
                  Sin items todavía. Cuando esta coach te recomiende algo concreto, lo va a agregar acá automáticamente. O agrégalo tú abajo.
                </p>
              )}
              {plan.items?.map((item) => (
                <div key={item.id} className="flex items-start gap-2 text-sm">
                  <span className={`shrink-0 px-2 py-0.5 rounded text-xs font-mono ${
                    item.status === 'active' ? 'bg-green/20 text-green' :
                    item.status === 'paused' ? 'bg-yellow/30 text-ink-2' :
                    'bg-lino-200 text-ink-3 line-through'
                  }`}>
                    {item.status}
                  </span>
                  <span className={`flex-1 ${item.status === 'done' ? 'line-through text-ink-3' : 'text-ink-1'}`}>
                    {item.text}
                  </span>
                  <div className="flex gap-1 shrink-0">
                    {item.status !== 'active' && (
                      <button onClick={() => updatePlanStatus(item.id, 'active')} className="text-xs text-ink-3 hover:text-lino-700" title="Reactivar">▶</button>
                    )}
                    {item.status === 'active' && (
                      <button onClick={() => updatePlanStatus(item.id, 'paused')} className="text-xs text-ink-3 hover:text-yellow" title="Pausar">⏸</button>
                    )}
                    {item.status !== 'done' && (
                      <button onClick={() => updatePlanStatus(item.id, 'done')} className="text-xs text-ink-3 hover:text-green" title="Marcar hecho">✓</button>
                    )}
                    <button onClick={() => removePlanItem(item.id)} className="text-xs text-ink-3 hover:text-red" title="Borrar">✕</button>
                  </div>
                </div>
              ))}
              <div className="flex gap-2 pt-2 border-t border-lino-200">
                <input
                  type="text"
                  value={newPlanText}
                  onChange={(e) => setNewPlanText(e.target.value)}
                  onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addPlanItem(); } }}
                  placeholder="Agregar item manualmente…"
                  className="input flex-1 text-sm"
                />
                <button onClick={addPlanItem} disabled={!newPlanText.trim()} className="btn-secondary text-sm">+</button>
              </div>
            </div>
          )}
        </div>
      )}

      <div ref={scrollRef} className="card flex-1 overflow-y-auto min-h-[400px] max-h-[calc(100vh-280px)] space-y-3">
        {!messages.length && (
          <p className="text-ink-3 text-sm text-center py-8">
            {coach === 'directora'
              ? 'Athena tiene tu history de WhatsApp. Escríbele lo que sea.'
              : 'Empieza la conversación con esta coach. Va a recordar todo lo que hablen entre sesiones.'}
          </p>
        )}
        {messages.map((m, i) => (
          <div key={i} className={`flex ${m.role === 'user' ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[80%] rounded-2xl px-4 py-2 text-sm whitespace-pre-wrap ${
              m.role === 'user'
                ? 'bg-lino-600 text-white rounded-br-sm'
                : m.error
                ? 'bg-red/10 text-red rounded-bl-sm'
                : 'bg-lino-100 text-ink-1 rounded-bl-sm'
            }`}>
              {m.content}
            </div>
          </div>
        ))}
        {sending && (
          <div className="flex justify-start">
            <div className="bg-lino-100 text-ink-3 rounded-2xl rounded-bl-sm px-4 py-2 text-sm italic">
              pensando…
            </div>
          </div>
        )}
      </div>

      {err && <p className="text-red text-xs">{err}</p>}

      <div className="flex gap-2">
        <textarea
          rows={2}
          className="input flex-1 resize-none"
          placeholder={inputHint || (coach === 'directora' ? 'Habla con Athena…' : 'Escribe tu pregunta…')}
          value={input}
          onChange={(e) => setInput(e.target.value)}
          onKeyDown={onKey}
          disabled={sending}
        />
        <button onClick={send} disabled={sending || !input.trim()} className="btn-primary">
          {sending ? '…' : 'Enviar'}
        </button>
      </div>
    </div>
  );
}
