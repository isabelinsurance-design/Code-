import { useState } from 'react';
import { useLocation } from 'react-router-dom';
import { api } from '../lib/api.js';
import VoiceInput from './VoiceInput.jsx';

// Floating action button + modal para captura rápida desde cualquier
// página. Reduce fricción para journal, task, URL, rapport sin tener
// que navegar a cada página específica.
//
// Se OCULTA en /hoy y /chat (donde ya hay un composer de Athena anclado
// abajo). Antes chocaba visualmente con el composer fijo.
export default function QuickAdd() {
  const location = useLocation();
  const [open, setOpen] = useState(false);
  const PATHS_WITH_BOTTOM_COMPOSER = ['/hoy', '/chat', '/'];
  const hideFab = PATHS_WITH_BOTTOM_COMPOSER.some(
    (p) => location.pathname === p || location.pathname.startsWith(p + '/')
  );
  if (hideFab && !open) return null;
  const [mode, setMode] = useState(null); // null | 'journal' | 'task' | 'url' | 'rapport'
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [ok, setOk] = useState('');

  // Form state per mode
  const [text, setText] = useState('');
  const [owner, setOwner] = useState('isabel');
  const [url, setUrl] = useState('');
  const [peso, setPeso] = useState('');
  const [sentires, setSentires] = useState('');

  function reset() {
    setMode(null);
    setText(''); setUrl(''); setPeso(''); setSentires('');
    setOwner('isabel');
    setErr(''); setOk('');
  }

  function close() {
    reset();
    setOpen(false);
  }

  async function submit() {
    setBusy(true); setErr(''); setOk('');
    try {
      if (mode === 'journal') {
        if (!text.trim()) throw new Error('Escribe algo.');
        await api.journalAdd({ texto: text, tipo: 'journal' });
        setOk('✓ Guardado en journal');
      } else if (mode === 'task') {
        if (!text.trim()) throw new Error('Describe la tarea.');
        await api.taskCreate?.({ descripcion: text, responsable: owner });
        // Fallback si taskCreate no existe en lib/api.js
        if (!api.taskCreate) {
          await fetch('/api/tasks', { method: 'POST', headers: { 'Content-Type': 'application/json' }, credentials: 'include', body: JSON.stringify({ descripcion: text, responsable: owner }) });
        }
        setOk('✓ Tarea creada');
      } else if (mode === 'url') {
        if (!url.trim()) throw new Error('URL requerida.');
        await api.readingAdd({ url, notas: text || null });
        setOk('✓ Agregada a reading list');
      } else if (mode === 'rapport') {
        if (!peso && !sentires) throw new Error('Mínimo peso o sentires.');
        await api.rapportAdd({
          peso_lbs: peso ? Number(peso) : null,
          sentires: sentires || null,
        });
        setOk('✓ Rapport guardado');
      }
      setTimeout(() => { close(); }, 800);
    } catch (e) {
      setErr(e.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      {/* FAB */}
      <button
        onClick={() => setOpen(true)}
        className="fixed bottom-6 right-6 z-40 w-14 h-14 rounded-full bg-lino-700 text-white text-2xl shadow-lg hover:bg-lino-800 hover:scale-105 transition-transform"
        title="Captura rápida"
        aria-label="Captura rápida"
      >
        +
      </button>

      {/* Modal */}
      {open && (
        <div className="fixed inset-0 z-50 bg-black/40 flex items-end md:items-center justify-center p-4" onClick={close}>
          <div className="bg-lino-50 rounded-t-2xl md:rounded-2xl w-full max-w-md p-5 space-y-3" onClick={(e) => e.stopPropagation()}>
            <div className="flex items-center justify-between">
              <h3 className="font-serif text-xl text-lino-800">
                {mode === 'journal' ? 'Journal' :
                 mode === 'task' ? 'Tarea' :
                 mode === 'url' ? 'Reading' :
                 mode === 'rapport' ? 'Rapport' : 'Captura rápida'}
              </h3>
              <button onClick={close} className="text-ink-3 hover:text-ink-1 text-xl">×</button>
            </div>

            {!mode && (
              <div className="grid grid-cols-2 gap-2">
                <button onClick={() => setMode('journal')} className="card hover:bg-lino-100 text-center py-4">
                  <div className="text-2xl mb-1">✑</div>
                  <div className="text-sm font-medium">Journal</div>
                  <div className="text-xs text-ink-3">cómo te sientes</div>
                </button>
                <button onClick={() => setMode('task')} className="card hover:bg-lino-100 text-center py-4">
                  <div className="text-2xl mb-1">✎</div>
                  <div className="text-sm font-medium">Tarea</div>
                  <div className="text-xs text-ink-3">algo por hacer</div>
                </button>
                <button onClick={() => setMode('url')} className="card hover:bg-lino-100 text-center py-4">
                  <div className="text-2xl mb-1">☷</div>
                  <div className="text-sm font-medium">URL</div>
                  <div className="text-xs text-ink-3">para leer después</div>
                </button>
                <button onClick={() => setMode('rapport')} className="card hover:bg-lino-100 text-center py-4">
                  <div className="text-2xl mb-1">◉</div>
                  <div className="text-sm font-medium">Rapport</div>
                  <div className="text-xs text-ink-3">peso / sentires</div>
                </button>
              </div>
            )}

            {mode === 'journal' && (
              <>
                <textarea
                  rows={4}
                  value={text}
                  onChange={(e) => setText(e.target.value)}
                  placeholder="Lo que estás sintiendo, pensando, procesando…"
                  className="input w-full text-sm resize-none"
                  autoFocus
                />
                <VoiceInput
                  onTranscript={(t, isFinal) => {
                    if (isFinal) setText((prev) => (prev ? prev + ' ' : '') + t);
                  }}
                />
              </>
            )}

            {mode === 'task' && (
              <>
                <textarea
                  rows={2}
                  value={text}
                  onChange={(e) => setText(e.target.value)}
                  placeholder="Qué necesita hacerse…"
                  className="input w-full text-sm resize-none"
                  autoFocus
                />
                <VoiceInput
                  onTranscript={(t, isFinal) => {
                    if (isFinal) setText((prev) => (prev ? prev + ' ' : '') + t);
                  }}
                />
                <select value={owner} onChange={(e) => setOwner(e.target.value)} className="input w-full text-sm">
                  <option value="isabel">Para mí (Isabel)</option>
                  <option value="athena">Para Athena</option>
                  <option value="sami">Para Sami</option>
                </select>
              </>
            )}

            {mode === 'url' && (
              <>
                <input
                  type="url"
                  value={url}
                  onChange={(e) => setUrl(e.target.value)}
                  placeholder="https://…"
                  className="input w-full text-sm"
                  autoFocus
                />
                <input
                  type="text"
                  value={text}
                  onChange={(e) => setText(e.target.value)}
                  placeholder="Por qué te interesa (opcional)"
                  className="input w-full text-sm"
                />
              </>
            )}

            {mode === 'rapport' && (
              <>
                <input
                  type="number"
                  value={peso}
                  onChange={(e) => setPeso(e.target.value)}
                  placeholder="Peso (lbs)"
                  className="input w-full text-sm"
                  step="0.1"
                  autoFocus
                />
                <textarea
                  rows={2}
                  value={sentires}
                  onChange={(e) => setSentires(e.target.value)}
                  placeholder="Cómo te sientes esta semana (opcional)"
                  className="input w-full text-sm resize-none"
                />
              </>
            )}

            {mode && (
              <>
                {err && <p className="text-red text-xs">{err}</p>}
                {ok && <p className="text-green-700 text-xs">{ok}</p>}
                <div className="flex justify-between">
                  <button onClick={() => { reset(); }} className="text-xs text-ink-3 hover:text-ink-1">← Cambiar tipo</button>
                  <button onClick={submit} disabled={busy} className="btn-primary text-sm">
                    {busy ? 'Guardando…' : 'Guardar'}
                  </button>
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </>
  );
}
