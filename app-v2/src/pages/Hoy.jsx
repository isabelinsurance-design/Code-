import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Volume2, VolumeX, RefreshCw, Mic } from 'lucide-react';
import { api } from '../lib/api.js';
import AthenaAvatar from '../components/AthenaAvatar.jsx';
import VoiceInput from '../components/VoiceInput.jsx';

// Hoy — Versión C "Magazine Spread".
// Tratamiento editorial inspirado en Cereal Magazine, The Gentlewoman, Frieze.
// Filosofía: cero cards, cero shadows, hairlines y running heads como en revista.
// Each section = un "article" con su running head a la izquierda + cuerpo serif.
// Composer flotante anclado abajo. Athena al frente como lead photo.

function greeting() {
  const h = new Date().getHours();
  if (h < 12) return 'Buenos días';
  if (h < 19) return 'Buenas tardes';
  return 'Buenas noches';
}

function issueNumber() {
  // Día del año — "ATHENA No. 271" tipo masthead de revista
  const start = new Date(new Date().getFullYear(), 0, 0);
  const diff = Date.now() - start.getTime();
  return Math.floor(diff / 86_400_000);
}

function nowHHMM() {
  return new Date().toLocaleTimeString('es-MX', { hour: '2-digit', minute: '2-digit' });
}

export default function Hoy() {
  const navigate = useNavigate();
  const [briefing, setBriefing] = useState(null);
  const [briefingLoading, setBriefingLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [state, setState] = useState(null);
  const [stats, setStats] = useState({});
  const [err, setErr] = useState('');
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [recentMessages, setRecentMessages] = useState([]);
  const [autoSpeak, setAutoSpeak] = useState(() => {
    try { return localStorage.getItem('athena_auto_speak') === 'true'; } catch { return false; }
  });
  const micRef = useRef(null);
  const audioRef = useRef(null);
  const textareaRef = useRef(null);

  useEffect(() => {
    api.hoyState().then(setState).catch((e) => setErr(e.message));
    api.briefingToday()
      .then((b) => setBriefing(b))
      .catch(() => setBriefing(null))
      .finally(() => setBriefingLoading(false));
    api.chatHistory(6)
      .then((r) => setRecentMessages(r.messages || []))
      .catch(() => { /* silent */ });

    (async () => {
      const next = {};
      try { const t = await api.trends('pending'); next.trends = t.items?.length || 0; } catch {}
      try { const r = await api.readingList('pending'); next.reading = r?.length || 0; } catch {}
      try { const g = await api.goalsList('activa'); next.goals = g?.length || 0; } catch {}
      try { const j = await api.journalList(7); next.journal = j?.length || 0; } catch {}
      try {
        const p = await api.coachPlansAll();
        next.planes = (p || []).reduce((acc, c) => acc + c.items.filter((i) => i.status === 'active').length, 0);
      } catch {}
      try { const rap = await api.rapport(1); next.peso = rap.trend?.latest?.peso_lbs || null; } catch {}
      setStats((s) => ({ ...s, ...next }));
    })();
  }, []);

  async function refreshBriefing() {
    setRefreshing(true);
    setErr('');
    try {
      const b = await api.briefingRefresh();
      setBriefing(b);
    } catch (e) { setErr(e.message); }
    finally { setRefreshing(false); }
  }

  function cleanForSpeech(text) {
    return String(text || '')
      .replace(/\*\*([^*]+)\*\*/g, '$1')
      .replace(/\*([^*]+)\*/g, '$1')
      .replace(/`([^`]+)`/g, '$1')
      .replace(/^#+\s+/gm, '')
      .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
      .replace(/\*+/g, '')
      .replace(/\s{2,}/g, ' ')
      .trim();
  }

  const SILENT_WAV = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=';
  function unlockAudio() {
    if (!audioRef.current) {
      audioRef.current = new Audio();
      audioRef.current.preload = 'auto';
    }
    if (!audioRef.current.src) {
      audioRef.current.src = SILENT_WAV;
      audioRef.current.play().catch(() => { /* silent */ });
    }
  }

  async function speak(text) {
    const clean = cleanForSpeech(text);
    if (!clean) return;
    try { micRef.current?.stop(); } catch { /* ignore */ }
    try {
      if (audioRef.current) audioRef.current.pause();
      const r = await fetch('/api/tts', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ text: clean }),
      });
      if (r.ok) {
        const { url } = await r.json();
        if (url) {
          if (!audioRef.current) {
            audioRef.current = new Audio();
            audioRef.current.preload = 'auto';
          }
          audioRef.current.src = url;
          try { await audioRef.current.play(); }
          catch (playErr) { console.warn('[hoy] audio.play rechazado:', playErr.message); }
        }
      }
    } catch { /* silent */ }
  }

  async function sendToAthena() {
    const text = input.trim();
    if (!text || sending) return;
    try { micRef.current?.stop(); } catch { /* ignore */ }
    if (autoSpeak) unlockAudio();
    setSending(true);
    setErr('');
    setRecentMessages((m) => [...m, { role: 'user', content: text }]);
    setInput('');
    try {
      const r = await api.chatToAthena(text);
      setRecentMessages((m) => [...m, { role: 'assistant', content: r.reply || '' }]);
      if (autoSpeak && r.reply) speak(r.reply);
    } catch (e) {
      setErr(e.message);
      setRecentMessages((m) => [...m, { role: 'assistant', content: `[error: ${e.message}]`, error: true }]);
    } finally { setSending(false); }
  }

  function onKey(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      sendToAthena();
    }
  }

  function toggleAutoSpeak() {
    const next = !autoSpeak;
    setAutoSpeak(next);
    if (next) unlockAudio();
    try { localStorage.setItem('athena_auto_speak', String(next)); } catch { /* ignore */ }
  }

  if (err && !state) return <p className="text-red text-sm">{err}</p>;

  const fechaTexto = state?.fecha || new Date().toLocaleDateString('es-MX', {
    weekday: 'long', day: 'numeric', month: 'long',
  });
  const fechaUpper = fechaTexto.toUpperCase();

  const hasBriefing = briefing?.cards?.length > 0;
  const briefingStale = briefing?.stale;

  // El primer card del briefing es "lo más importante" → lo tratamos como hero.
  // Los demás como artículos secundarios.
  const heroCard = hasBriefing ? briefing.cards[0] : null;
  const otherCards = hasBriefing ? briefing.cards.slice(1) : [];

  return (
    <div className="pb-32" style={{ paddingBottom: 'calc(8rem + env(safe-area-inset-bottom))' }}>
      {/* MASTHEAD — como portada de revista */}
      <header className="flex items-end justify-between border-b border-ink-1 pt-2 pb-3 mb-8">
        <div className="font-serif text-sm tracking-wide text-ink-1">
          ATHENA <span className="font-mono text-xs text-ink-3 ml-2">No. {issueNumber()}</span>
        </div>
        <div className="font-mono text-[10px] tracking-widest text-ink-3 uppercase">
          {fechaUpper} · {nowHHMM()}
        </div>
      </header>

      {/* LEAD — foto + greeting estilo cover story */}
      <section className="flex items-start gap-4 mb-12">
        <AthenaAvatar size={80} className="shrink-0" />
        <div>
          <h1 className="font-serif text-[2rem] leading-[1.05] tracking-tight text-ink-1">
            {greeting()},<br />
            <span className="italic font-light">Isabel</span>.
          </h1>
          <p className="font-mono text-[10px] tracking-[0.2em] text-ink-3 uppercase mt-3">
            Tu día empieza con calma
          </p>
        </div>
      </section>

      {/* ARTICLE 1 — Editorial / lo más importante */}
      <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
        <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
          Editorial
        </div>
        <div className="border-b border-lino-400 pb-6">
          {briefingLoading && <p className="text-ink-3 text-sm italic font-serif">Cargando tu día…</p>}
          {!briefingLoading && !hasBriefing && (
            <>
              <p className="font-serif text-lg italic text-ink-3 leading-snug">
                Todavía no hay briefing hoy.
              </p>
              <button
                onClick={refreshBriefing}
                disabled={refreshing}
                className="mt-3 font-mono text-[10px] uppercase tracking-[0.18em] text-ink-1 border-b border-ink-1 pb-0.5 hover:text-lino-700 hover:border-lino-700 transition-colors"
              >
                {refreshing ? 'Generando…' : 'Pedirle uno'}
              </button>
            </>
          )}
          {hasBriefing && (
            <>
              <h2 className="font-serif text-[1.4rem] leading-tight text-ink-1 font-normal whitespace-pre-wrap">
                {heroCard}
              </h2>
              {briefingStale && (
                <p className="font-mono text-[9px] tracking-widest uppercase text-amber mt-3">
                  Briefing del día anterior · <button onClick={refreshBriefing} className="underline">pedir uno nuevo</button>
                </p>
              )}
              <button
                onClick={refreshBriefing}
                disabled={refreshing}
                className="mt-4 font-mono text-[9px] uppercase tracking-[0.22em] text-ink-3 hover:text-ink-1 inline-flex items-center gap-1.5 transition-colors"
              >
                <RefreshCw size={10} strokeWidth={1.5} className={refreshing ? 'animate-spin' : ''} />
                {refreshing ? 'Actualizando' : 'Actualizar briefing'}
              </button>
            </>
          )}
        </div>
      </article>

      {/* ARTICLES 2..N — resto del briefing */}
      {otherCards.map((card, i) => (
        <article key={i} className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
            {['Agenda', 'Cuerpo', 'Mente', 'Equipo', 'Negocio'][i] || `0${i + 2}`}
          </div>
          <div className="border-b border-lino-400 pb-6">
            <p className="font-serif text-base leading-relaxed text-ink-1 whitespace-pre-wrap">
              {card}
            </p>
          </div>
        </article>
      ))}

      {/* DATA LINE — stats como typográfica, no como tiles */}
      <div className="font-mono text-[11px] text-ink-3 border-t border-b border-ink-1 py-3 my-10 leading-loose tracking-tight">
        <Link to="/plans" className="hover:text-ink-1">PLANES {String(stats.planes ?? 0).padStart(2, '0')}</Link>
        <span className="mx-2 text-lino-400">·</span>
        <Link to="/goals" className="hover:text-ink-1">METAS {String(stats.goals ?? 0).padStart(2, '0')}</Link>
        <span className="mx-2 text-lino-400">·</span>
        <Link to="/trends" className="hover:text-ink-1">TRENDS {String(stats.trends ?? 0).padStart(2, '0')}</Link>
        <span className="mx-2 text-lino-400">·</span>
        <Link to="/reading" className="hover:text-ink-1">READ {String(stats.reading ?? 0).padStart(2, '0')}</Link>
        <span className="mx-2 text-lino-400">·</span>
        <Link to="/journal" className="hover:text-ink-1">JOURNAL {String(stats.journal ?? 0).padStart(2, '0')}</Link>
        {stats.peso && (
          <>
            <span className="mx-2 text-lino-400">·</span>
            <Link to="/rapport" className="hover:text-ink-1">PESO {stats.peso}</Link>
          </>
        )}
      </div>

      {/* CONVERSACIÓN RECIENTE — como diálogos en un artículo */}
      {recentMessages.length > 0 && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
            Diálogo
          </div>
          <div className="border-b border-lino-400 pb-6 space-y-3">
            {recentMessages.slice(-6).map((m, i) => (
              <div key={i} className="font-serif">
                {m.role === 'user' ? (
                  <p className="text-ink-2 text-base leading-relaxed pl-4 border-l-2 border-lino-400 italic">
                    {m.content}
                  </p>
                ) : (
                  <p className={`text-base leading-relaxed ${m.error ? 'text-red' : 'text-ink-1'}`}>
                    {m.content}
                  </p>
                )}
              </div>
            ))}
            {sending && (
              <p className="font-mono text-[10px] uppercase tracking-widest text-ink-3 animate-pulse">
                Athena escribiendo…
              </p>
            )}
          </div>
        </article>
      )}

      {/* FOCUS BLOCKS y LEGAL — secciones opcionales como artículos cortos */}
      {state?.focus_blocks?.length > 0 && (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-ink-3 pt-1.5">
            Tiempo
          </div>
          <div className="border-b border-lino-400 pb-6">
            {state.focus_blocks.map((b) => (
              <p key={b.id} className="font-serif text-sm text-ink-1 leading-relaxed">
                <span className="font-medium">{b.titulo}</span>
                <span className="text-ink-3 font-mono text-[10px] ml-3 tracking-wide">
                  {b.modo.toUpperCase()} · {b.inicio_hhmm}–{b.fin_hhmm}
                </span>
              </p>
            ))}
          </div>
        </article>
      )}

      {state?.legal_alerts && (state.legal_alerts.vencidas?.length || state.legal_alerts['7']?.length) ? (
        <article className="grid grid-cols-[60px_1fr] gap-4 mb-8">
          <div className="font-mono text-[9px] tracking-[0.25em] uppercase text-amber pt-1.5">
            Legal
          </div>
          <div className="border-b border-lino-400 pb-6 space-y-1">
            {state.legal_alerts.vencidas?.map((o) => (
              <p key={o.id} className="font-serif text-sm text-red leading-relaxed">
                <span className="font-mono text-[10px] uppercase tracking-wide">Vencida</span> · {o.descripcion} ({o.dias_vencida}d)
              </p>
            ))}
            {state.legal_alerts['7']?.map((o) => (
              <p key={o.id} className="font-serif text-sm text-amber leading-relaxed">
                {o.descripcion} <span className="font-mono text-[10px] uppercase tracking-wide">en {o.dias_falt}d</span>
              </p>
            ))}
          </div>
        </article>
      ) : null}

      {err && <p className="text-red text-xs font-mono uppercase tracking-wide mt-4">{err}</p>}

      {/* COMPOSER — flotante al pie, estilo notebook margin */}
      <div
        className="fixed left-0 right-0 bottom-0 border-t border-ink-1 bg-lino-100/95 backdrop-blur-md"
        style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}
      >
        <div className="max-w-4xl mx-auto px-4 md:px-8 py-4">
          <div className="font-serif italic text-sm text-ink-3 mb-2">
            ¿Qué quieres mover hoy?
          </div>
          <div className="flex items-center gap-3">
            <button
              onClick={toggleAutoSpeak}
              className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 transition-colors shrink-0 inline-flex items-center gap-1.5"
              title={autoSpeak ? 'Lee activado' : 'Lee apagado'}
            >
              {autoSpeak
                ? <><Volume2 size={12} strokeWidth={1.5} /> Lee</>
                : <><VolumeX size={12} strokeWidth={1.5} /> Mute</>}
            </button>
            <textarea
              ref={textareaRef}
              rows={1}
              className="flex-1 bg-transparent border-b border-ink-1 font-sans text-base text-ink-1 outline-none resize-none py-1.5 placeholder:italic placeholder:font-serif placeholder:text-ink-3"
              placeholder="Habla normal, ella entiende…"
              value={input}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={onKey}
              disabled={sending}
            />
            <VoiceInput
              ref={micRef}
              inputRef={textareaRef}
              onTranscript={(text, isFinal) => {
                if (!isFinal) return;
                const SEND_RE = /[,.!?\s]*(env[ií]a(lo)?|m[aá]ndalo|send( it)?|manda(lo)?)[.!?\s]*$/i;
                const clean = text.replace(SEND_RE, '').trim();
                setInput((prev) => (prev ? prev + ' ' : '') + clean);
                if (SEND_RE.test(text)) setTimeout(() => sendToAthena(), 50);
              }}
              className="shrink-0"
            />
            <button
              onClick={sendToAthena}
              disabled={sending || !input.trim()}
              className="font-mono text-[10px] uppercase tracking-[0.18em] px-3 py-2 border border-ink-1 text-ink-1 hover:bg-ink-1 hover:text-lino-100 transition-colors disabled:opacity-30 shrink-0"
            >
              Enviar
            </button>
            <button
              onClick={() => navigate('/chat')}
              className="font-mono text-[10px] uppercase tracking-[0.18em] text-ink-3 hover:text-ink-1 transition-colors shrink-0 hidden md:block"
            >
              Ver todo
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
