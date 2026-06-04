import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { api } from '../lib/api.js';
import VoiceInput from '../components/VoiceInput.jsx';

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
  // Auto-speak: cuando llega respuesta, se la lee en voz alta usando
  // el TTS nativo del browser (gratis, instantáneo, no necesita OpenAI).
  // Default true en iOS Safari porque Isabel quiere conversación de voz.
  const [autoSpeak, setAutoSpeak] = useState(() => {
    try {
      const saved = localStorage.getItem('athena_auto_speak');
      if (saved !== null) return saved === 'true';
    } catch { /* ignore */ }
    return true; // default ON
  });
  const scrollRef = useRef(null);
  // Ref al componente de mic, para apagarlo cuando se manda el mensaje
  // o cuando Athena está hablando (evita feedback loop).
  const micRef = useRef(null);

  // Persiste preferencia de auto-speak
  useEffect(() => {
    try { localStorage.setItem('athena_auto_speak', String(autoSpeak)); } catch { /* ignore */ }
  }, [autoSpeak]);

  // Audio player para TTS del servidor (OpenAI/ElevenLabs).
  const audioRef = useRef(null);

  function cleanForSpeech(text) {
    return String(text || '')
      .replace(/\*\*([^*]+)\*\*/g, '$1')
      .replace(/__([^_]+)__/g, '$1')
      .replace(/\*([^*]+)\*/g, '$1')
      .replace(/_([^_]+)_/g, '$1')
      .replace(/`([^`]+)`/g, '$1')
      .replace(/^#+\s+/gm, '')
      .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
      .replace(/\*+/g, '')
      .replace(/\s{2,}/g, ' ')
      .trim();
  }

  // Browser TTS fallback — solo si el servidor no responde.
  function speakBrowser(clean) {
    if (!window.speechSynthesis) return;
    try {
      window.speechSynthesis.cancel();
      const lang = localStorage.getItem('athena_voice_lang') || 'es-MX';
      const u = new SpeechSynthesisUtterance(clean);
      u.lang = lang;
      u.rate = 1.0;
      u.pitch = 1.0;
      const voices = window.speechSynthesis.getVoices();
      const matchingVoices = voices.filter((v) => v.lang.startsWith(lang.split('-')[0]));
      const FEMALE_HINT = /female|mujer|paulina|monica|mónica|paloma|lupe|penelope|penélope|sabina|esperanza|marisol|helena|elena|sofia|sofía|lucia|lucía|elvira|laura|samantha|victoria|karen|tessa|fiona|allison|ava|susan|zira|hazel|catherine/i;
      const MALE_HINT = /male|hombre|jorge|diego|carlos|juan|miguel|pablo|enrique|ricardo|david|mark|alex|daniel|fred|tom|james/i;
      const isFemale = (v) => FEMALE_HINT.test(v.name);
      const isMale = (v) => MALE_HINT.test(v.name);
      const femaleVoices = matchingVoices.filter(isFemale);
      const neutralVoices = matchingVoices.filter((v) => !isMale(v) && !isFemale(v));
      const pool = femaleVoices.length ? femaleVoices : (neutralVoices.length ? neutralVoices : matchingVoices);
      const preferred = pool.find((v) => /premium|enhanced|neural/i.test(v.name)) || pool[0];
      if (preferred) u.voice = preferred;
      window.speechSynthesis.speak(u);
    } catch (err) {
      console.warn('[chat] speakBrowser falló:', err.message);
    }
  }

  // Habla usando el TTS del servidor (femenino garantizado).
  // Si el servidor no tiene TTS configurado o falla, cae al navegador.
  async function speak(text) {
    const clean = cleanForSpeech(text);
    if (!clean) return;
    // Apaga el mic mientras Athena habla — sin esto el reconocimiento
    // capta la propia voz y se crea un loop.
    try { micRef.current?.stop(); } catch { /* ignore */ }
    try {
      if (audioRef.current) { audioRef.current.pause(); audioRef.current = null; }
      if (window.speechSynthesis) window.speechSynthesis.cancel();
      const r = await fetch('/api/tts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ text: clean }),
      });
      if (r.ok) {
        const { url } = await r.json();
        if (url) {
          const audio = new Audio(url);
          audioRef.current = audio;
          await audio.play();
          return;
        }
      }
      // Fallback al navegador si el servidor no respondió bien.
      speakBrowser(clean);
    } catch (err) {
      console.warn('[chat] speak servidor falló, uso browser:', err.message);
      speakBrowser(clean);
    }
  }

  function stopSpeaking() {
    try { window.speechSynthesis?.cancel(); } catch { /* ignore */ }
    try {
      if (audioRef.current) { audioRef.current.pause(); audioRef.current = null; }
    } catch { /* ignore */ }
  }

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
    // Apaga el mic al mandar — evita que siga escuchando mientras
    // procesamos y mientras Athena habla la respuesta.
    try { micRef.current?.stop(); } catch { /* ignore */ }
    setErr('');
    setMessages((m) => [...m, { role: 'user', content: text }]);
    setInput('');
    setSending(true);
    try {
      const r = await api.chat(coach, text);
      const replyText = r.reply || '(sin respuesta)';
      setMessages((m) => [...m, { role: 'assistant', content: replyText }]);
      // Auto-speak la respuesta si está activado.
      if (autoSpeak) speak(replyText);
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
        <div className="flex items-center gap-2 flex-wrap">
          <button
            onClick={() => {
              if (autoSpeak) stopSpeaking();
              setAutoSpeak((v) => !v);
            }}
            className={`px-3 py-2 rounded-lg text-xs font-medium transition-colors ${
              autoSpeak
                ? 'bg-lino-700 text-white'
                : 'bg-lino-100 text-ink-3 hover:bg-lino-200'
            }`}
            title={autoSpeak ? 'Auto-leer activado — toca para silenciar' : 'Auto-leer apagado — toca para activar'}
          >
            {autoSpeak ? '🔊 Lee' : '🔇 Silencio'}
          </button>
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
            <div className={`max-w-[80%] rounded-2xl px-4 py-2 text-sm whitespace-pre-wrap relative group ${
              m.role === 'user'
                ? 'bg-lino-600 text-white rounded-br-sm'
                : m.error
                ? 'bg-red/10 text-red rounded-bl-sm'
                : 'bg-lino-100 text-ink-1 rounded-bl-sm'
            }`}>
              {m.content}
              {/* Botón 🔊 en mensajes de Athena para re-escuchar */}
              {m.role === 'assistant' && !m.error && (
                <button
                  onClick={() => speak(m.content)}
                  className="ml-2 text-xs text-ink-3 hover:text-lino-800 inline-block"
                  title="Escuchar de nuevo"
                  aria-label="Escuchar de nuevo"
                >
                  🔊
                </button>
              )}
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

      <div className="flex gap-2 items-end">
        <textarea
          rows={2}
          className="input flex-1 resize-none"
          placeholder={inputHint || (coach === 'directora' ? 'Habla con Athena…' : 'Habla o escribe…')}
          value={input}
          onChange={(e) => setInput(e.target.value)}
          onKeyDown={onKey}
          disabled={sending}
        />
        <div className="flex flex-col gap-2">
          <VoiceInput
            ref={micRef}
            onTranscript={(text, isFinal) => {
              // Aggrega lo nuevo dictado al input existente.
              // Mientras es interim, REEMPLAZA la última parte (no
              // duplica). Cuando es final, lo deja fijo y limpia.
              if (isFinal) {
                setInput((prev) => (prev ? prev + ' ' : '') + text);
              } else {
                // Para visual feedback en tiempo real, podemos
                // appendear el interim — pero solo si es algo nuevo
                // distinto del último final. Mantenemos simple por ahora:
                // solo committeamos en final.
              }
            }}
          />
          <button onClick={send} disabled={sending || !input.trim()} className="btn-primary">
            {sending ? '…' : 'Enviar'}
          </button>
        </div>
      </div>
    </div>
  );
}
