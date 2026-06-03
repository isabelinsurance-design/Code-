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
  const scrollRef = useRef(null);

  useEffect(() => {
    api.chatCoaches().then(setCoaches).catch((e) => setErr(e.message));
  }, []);

  // Al cambiar de coach: hidratamos su hilo persistente desde el servidor.
  // 'directora' usa el history de WhatsApp (compartido) — no tiene endpoint
  // /coach_thread, así que mostramos lista vacía y dejamos que ella responda
  // sabiendo todo desde su lado.
  useEffect(() => {
    setErr('');
    if (coach === 'directora') {
      setMessages([]);
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
