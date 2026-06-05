import { useEffect, useRef, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Volume2, VolumeX, RefreshCw } from 'lucide-react';
import { api } from '../lib/api.js';
import AthenaAvatar from '../components/AthenaAvatar.jsx';
import VoiceInput from '../components/VoiceInput.jsx';

// Hoy en estilo "Athena primero":
//   1) Saludo + fecha
//   2) Briefing del día (cards generadas por el cron 6:30am)
//   3) Caja para hablar con Athena ahí mismo
//   4) Tu día en números — strip horizontal compacto al final
//
// Diseño: lino cálido, serif para títulos, lots of whitespace.
// NO dashboard denso. Athena al frente, datos como apoyo.

function greeting() {
  const h = new Date().getHours();
  if (h < 12) return 'Buenos días';
  if (h < 19) return 'Buenas tardes';
  return 'Buenas noches';
}

export default function Hoy() {
  const navigate = useNavigate();
  const [briefing, setBriefing] = useState(null);
  const [briefingLoading, setBriefingLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [state, setState] = useState(null);
  const [stats, setStats] = useState({
    trends_pending: 0,
    reading_pending: 0,
    goals_active: 0,
    journal_week: 0,
    plans_total_active: 0,
    rapport_latest: null,
  });
  const [err, setErr] = useState('');
  // Quick chat con Athena en línea
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [lastReply, setLastReply] = useState('');
  const [autoSpeak, setAutoSpeak] = useState(() => {
    try { return localStorage.getItem('athena_auto_speak') === 'true'; } catch { return false; }
  });
  const micRef = useRef(null);
  const audioRef = useRef(null);

  useEffect(() => {
    api.hoyState().then(setState).catch((e) => setErr(e.message));
    api.briefingToday()
      .then((b) => setBriefing(b))
      .catch(() => setBriefing(null))
      .finally(() => setBriefingLoading(false));

    (async () => {
      const next = {};
      try { const t = await api.trends('pending'); next.trends_pending = t.items?.length || 0; } catch {}
      try { const r = await api.readingList('pending'); next.reading_pending = r?.length || 0; } catch {}
      try { const g = await api.goalsList('activa'); next.goals_active = g?.length || 0; } catch {}
      try { const j = await api.journalList(7); next.journal_week = j?.length || 0; } catch {}
      try {
        const p = await api.coachPlansAll();
        next.plans_total_active = (p || []).reduce((acc, c) => acc + c.items.filter((i) => i.status === 'active').length, 0);
      } catch {}
      try { const rap = await api.rapport(1); next.rapport_latest = rap.trend?.latest || null; } catch {}
      setStats((s) => ({ ...s, ...next }));
    })();
  }, []);

  async function refreshBriefing() {
    setRefreshing(true);
    setErr('');
    try {
      const b = await api.briefingRefresh();
      setBriefing(b);
    } catch (e) {
      setErr(e.message);
    } finally {
      setRefreshing(false);
    }
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

  async function speak(text) {
    const clean = cleanForSpeech(text);
    if (!clean) return;
    try { micRef.current?.stop(); } catch { /* ignore */ }
    try {
      if (audioRef.current) { audioRef.current.pause(); audioRef.current = null; }
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
        }
      }
    } catch { /* silent fail */ }
  }

  async function sendToAthena() {
    const text = input.trim();
    if (!text || sending) return;
    try { micRef.current?.stop(); } catch { /* ignore */ }
    setSending(true);
    setErr('');
    try {
      const r = await api.chatToAthena(text);
      setLastReply(r.reply || '');
      setInput('');
      if (autoSpeak && r.reply) speak(r.reply);
    } catch (e) {
      setErr(e.message);
    } finally {
      setSending(false);
    }
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
    try { localStorage.setItem('athena_auto_speak', String(next)); } catch { /* ignore */ }
  }

  if (err && !state) return <p className="text-red text-sm">{err}</p>;

  const fechaTexto = state?.fecha || new Date().toLocaleDateString('es-MX', {
    weekday: 'long', day: 'numeric', month: 'long',
  });

  const hasBriefing = briefing?.cards?.length > 0;
  const briefingStale = briefing?.stale;

  return (
    <div className="space-y-8">
      {/* 1) Saludo */}
      <header className="flex items-center gap-4">
        <AthenaAvatar size={56} className="hidden sm:block" />
        <div>
          <h2 className="font-serif text-3xl md:text-4xl text-lino-800 leading-tight">
            {greeting()}, Isabel
          </h2>
          <p className="text-ink-3 text-sm capitalize mt-1">{fechaTexto}</p>
        </div>
      </header>

      {/* 2) Briefing del día */}
      <section>
        <div className="flex items-center justify-between mb-3">
          <h3 className="font-serif text-lg text-lino-800">Tu briefing de hoy</h3>
          <button
            onClick={refreshBriefing}
            disabled={refreshing}
            className="text-xs text-ink-3 hover:text-lino-800 inline-flex items-center gap-1.5"
            title={hasBriefing ? 'Pedir un briefing fresco' : 'Generar briefing ahora'}
          >
            <RefreshCw size={12} strokeWidth={1.5} className={refreshing ? 'animate-spin' : ''} />
            {refreshing ? 'Pensando…' : hasBriefing ? 'Actualizar' : 'Generar'}
          </button>
        </div>

        {briefingLoading && <p className="text-ink-3 text-sm">Cargando tu briefing…</p>}

        {!briefingLoading && !hasBriefing && (
          <div className="card text-center py-8">
            <p className="text-ink-3 text-sm mb-3">Todavía no hay briefing hoy.</p>
            <button
              onClick={refreshBriefing}
              disabled={refreshing}
              className="btn-primary text-sm"
            >
              {refreshing ? 'Generando…' : 'Pedirle uno a Athena'}
            </button>
          </div>
        )}

        {!briefingLoading && hasBriefing && (
          <>
            {briefingStale && (
              <p className="text-xs text-amber mb-2">
                Este briefing es de un día previo. Pide uno nuevo si quieres el de hoy.
              </p>
            )}
            <div className="space-y-3">
              {briefing.cards.map((card, i) => (
                <article
                  key={i}
                  className="card whitespace-pre-wrap text-sm text-ink-1 leading-relaxed"
                >
                  {card}
                </article>
              ))}
            </div>
          </>
        )}
      </section>

      {/* 3) Habla con Athena */}
      <section>
        <div className="flex items-center justify-between mb-3">
          <h3 className="font-serif text-lg text-lino-800">Habla con Athena</h3>
          <button
            onClick={toggleAutoSpeak}
            className="text-xs text-ink-3 hover:text-lino-800 inline-flex items-center gap-1.5"
            title={autoSpeak ? 'Auto-leer activado' : 'Auto-leer apagado'}
          >
            {autoSpeak
              ? <><Volume2 size={12} strokeWidth={1.5} /> Lee</>
              : <><VolumeX size={12} strokeWidth={1.5} /> Silencio</>}
          </button>
        </div>

        <div className="card">
          <textarea
            rows={3}
            className="input w-full resize-none border-0 focus:ring-0 p-0"
            placeholder="Cuéntale lo que tienes en mente…"
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={onKey}
            disabled={sending}
          />
          <div className="flex items-center justify-between mt-3 pt-3 border-t border-lino-200">
            <VoiceInput
              ref={micRef}
              onTranscript={(text, isFinal) => {
                if (!isFinal) return;
                const SEND_RE = /[,.!?\s]*(env[ií]a(lo)?|m[aá]ndalo|send( it)?|manda(lo)?)[.!?\s]*$/i;
                const clean = text.replace(SEND_RE, '').trim();
                setInput((prev) => (prev ? prev + ' ' : '') + clean);
                if (SEND_RE.test(text)) setTimeout(() => sendToAthena(), 50);
              }}
            />
            <div className="flex items-center gap-3">
              <button
                onClick={() => navigate('/chat')}
                className="text-xs text-ink-3 hover:text-lino-800"
                title="Abrir chat completo"
              >
                Ver todo
              </button>
              <button
                onClick={sendToAthena}
                disabled={sending || !input.trim()}
                className="btn-primary text-sm"
              >
                {sending ? '…' : 'Enviar'}
              </button>
            </div>
          </div>
        </div>

        {lastReply && (
          <article className="mt-3 card bg-white/70 text-sm text-ink-1 whitespace-pre-wrap leading-relaxed">
            <div className="flex items-start gap-3">
              <AthenaAvatar size={28} className="shrink-0 mt-1" />
              <div className="flex-1">{lastReply}</div>
            </div>
          </article>
        )}
      </section>

      {/* 4) Tu día en números — strip compacto */}
      <section>
        <div className="flex items-center justify-between mb-3">
          <h3 className="font-serif text-lg text-lino-800">Tu día en números</h3>
        </div>
        <div className="grid grid-cols-3 md:grid-cols-6 gap-2 text-center">
          <Link to="/tareas" className="card-mini hover:bg-lino-50">
            <div className="font-serif text-xl text-lino-800">{stats.plans_total_active}</div>
            <div className="text-[10px] text-ink-3 uppercase tracking-wider mt-0.5">Plan activo</div>
          </Link>
          <Link to="/goals" className="card-mini hover:bg-lino-50">
            <div className="font-serif text-xl text-lino-800">{stats.goals_active}</div>
            <div className="text-[10px] text-ink-3 uppercase tracking-wider mt-0.5">Metas</div>
          </Link>
          <Link to="/trends" className="card-mini hover:bg-lino-50">
            <div className="font-serif text-xl text-lino-800">{stats.trends_pending}</div>
            <div className="text-[10px] text-ink-3 uppercase tracking-wider mt-0.5">Trends</div>
          </Link>
          <Link to="/reading" className="card-mini hover:bg-lino-50">
            <div className="font-serif text-xl text-lino-800">{stats.reading_pending}</div>
            <div className="text-[10px] text-ink-3 uppercase tracking-wider mt-0.5">Reading</div>
          </Link>
          <Link to="/journal" className="card-mini hover:bg-lino-50">
            <div className="font-serif text-xl text-lino-800">{stats.journal_week}</div>
            <div className="text-[10px] text-ink-3 uppercase tracking-wider mt-0.5">Journal</div>
          </Link>
          <Link to="/rapport" className="card-mini hover:bg-lino-50">
            <div className="font-serif text-xl text-lino-800">
              {stats.rapport_latest?.peso_lbs || '—'}
            </div>
            <div className="text-[10px] text-ink-3 uppercase tracking-wider mt-0.5">Peso lbs</div>
          </Link>
        </div>
      </section>

      {/* Bloques de detalle solo si hay data — más discretos */}
      {state?.focus_blocks?.length > 0 && (
        <section>
          <h3 className="font-serif text-lg text-lino-800 mb-3">Tiempo protegido hoy</h3>
          <ul className="card space-y-1">
            {state.focus_blocks.map((b) => (
              <li key={b.id} className="text-sm text-ink-2">
                <span className="font-medium">{b.titulo}</span>
                <span className="text-ink-3"> · {b.modo} · {b.inicio_hhmm}–{b.fin_hhmm}</span>
              </li>
            ))}
          </ul>
        </section>
      )}

      {state?.legal_alerts && (state.legal_alerts.vencidas?.length || state.legal_alerts['7']?.length) && (
        <section>
          <h3 className="font-serif text-lg text-lino-800 mb-3">Legal — atención</h3>
          <div className="card border-amber/40 bg-amber/5 space-y-1">
            {state.legal_alerts.vencidas?.map((o) => (
              <p key={o.id} className="text-sm text-red">
                <strong>Vencida:</strong> {o.descripcion} ({o.dias_vencida}d)
              </p>
            ))}
            {state.legal_alerts['7']?.map((o) => (
              <p key={o.id} className="text-sm text-amber">{o.descripcion} en {o.dias_falt} días</p>
            ))}
          </div>
        </section>
      )}
    </div>
  );
}
