import { useEffect, useRef, useState } from 'react';

// Botón de mic que usa Web Speech API nativa del browser (gratis, sin
// dependencias de OpenAI/Whisper). Funciona en Safari iOS, Chrome,
// Edge. Limitación: en Firefox no está soportado todavía.
//
// Spanglish handling: Web Speech API NO auto-detecta idioma. Hay que
// elegir uno. Soluciones:
//   1) Toggle ES/EN en la UI (la que implementé acá) — guardamos
//      la última elección en localStorage. Cambias antes de hablar.
//   2) (Futuro) backend con Whisper que SÍ detecta idioma automático,
//      pero requiere OpenAI credit + roundtrip server.
//
// Props:
//   onTranscript(text, isFinal): callback con la transcripción.
//   defaultLang: idioma inicial (sobrescrito por localStorage si existe).
//   className: clases extra.
const LANG_OPTIONS = [
  { code: 'es-MX', label: 'ES', name: 'Español' },
  { code: 'en-US', label: 'EN', name: 'English' },
];

export default function VoiceInput({ onTranscript, defaultLang = 'es-MX', className = '' }) {
  const [supported, setSupported] = useState(true);
  const [recording, setRecording] = useState(false);
  const [error, setError] = useState('');
  const [lang, setLang] = useState(() => {
    try {
      const saved = localStorage.getItem('athena_voice_lang');
      if (saved && LANG_OPTIONS.find((l) => l.code === saved)) return saved;
    } catch { /* ignore */ }
    return defaultLang;
  });
  const recognitionRef = useRef(null);

  // Persiste la elección del usuario para que no tenga que cambiar
  // cada vez que abre la PWA.
  useEffect(() => {
    try { localStorage.setItem('athena_voice_lang', lang); } catch { /* ignore */ }
  }, [lang]);

  useEffect(() => {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) {
      setSupported(false);
      return;
    }
    const rec = new SR();
    rec.lang = lang;
    rec.continuous = true; // sigue escuchando hasta que tú lo pares
    rec.interimResults = true; // muestra texto mientras hablas
    rec.maxAlternatives = 1;

    rec.onresult = (event) => {
      let interim = '';
      let final = '';
      for (let i = event.resultIndex; i < event.results.length; i++) {
        const transcript = event.results[i][0].transcript;
        if (event.results[i].isFinal) {
          final += transcript;
        } else {
          interim += transcript;
        }
      }
      // Mandamos al parent. isFinal=true cuando es texto definitivo.
      if (final) {
        onTranscript(final.trim(), true);
      } else if (interim) {
        onTranscript(interim.trim(), false);
      }
    };

    rec.onerror = (event) => {
      const errMsg = event.error || 'voice_error';
      // "no-speech" pasa cuando el usuario no dice nada — no es realmente error.
      if (errMsg !== 'no-speech' && errMsg !== 'aborted') {
        setError(errMsg);
      }
      setRecording(false);
    };

    rec.onend = () => {
      setRecording(false);
    };

    recognitionRef.current = rec;
    return () => {
      try { rec.abort(); } catch { /* ignore */ }
    };
  }, [lang, onTranscript]);

  function toggle() {
    if (!recognitionRef.current) return;
    setError('');
    if (recording) {
      try { recognitionRef.current.stop(); } catch { /* ignore */ }
      setRecording(false);
    } else {
      try {
        recognitionRef.current.start();
        setRecording(true);
      } catch (err) {
        setError(err.message || 'no pude empezar');
      }
    }
  }

  if (!supported) {
    return (
      <button
        disabled
        className={`px-3 py-2 rounded-lg bg-lino-100 text-ink-3 text-xs ${className}`}
        title="Tu browser no soporta voz. Usa Safari (iOS) o Chrome."
      >
        🎤 ✗
      </button>
    );
  }

  // Cambia idioma. Si estaba grabando, lo detiene primero (el nuevo
  // idioma se aplica al siguiente "Hablar" gracias al useEffect que
  // re-crea el recognition).
  function switchLang(newLang) {
    if (newLang === lang) return;
    if (recording) {
      try { recognitionRef.current?.stop(); } catch { /* ignore */ }
      setRecording(false);
    }
    setLang(newLang);
  }

  return (
    <div className="relative">
      <div className="flex items-center gap-1">
        {/* Toggle ES/EN */}
        <div className="flex bg-lino-100 rounded-lg overflow-hidden text-xs">
          {LANG_OPTIONS.map((opt) => (
            <button
              key={opt.code}
              onClick={() => switchLang(opt.code)}
              className={`px-2 py-2 transition-colors ${
                lang === opt.code
                  ? 'bg-lino-700 text-white font-medium'
                  : 'text-ink-3 hover:bg-lino-200'
              }`}
              title={opt.name}
              aria-label={`Cambiar a ${opt.name}`}
            >
              {opt.label}
            </button>
          ))}
        </div>
        {/* Mic button */}
        <button
          onClick={toggle}
          className={`px-3 py-2 rounded-lg transition-all ${
            recording
              ? 'bg-red text-white animate-pulse'
              : 'bg-lino-100 text-ink-2 hover:bg-lino-200'
          } ${className}`}
          title={recording ? 'Toca para parar' : `Toca y habla (${lang === 'es-MX' ? 'Español' : 'English'})`}
          aria-label={recording ? 'Parar grabación' : 'Empezar grabación'}
        >
          {recording ? '⏹' : '🎤'}
        </button>
      </div>
      {error && (
        <p className="absolute top-full left-0 mt-1 text-xs text-red whitespace-nowrap">
          {error === 'not-allowed' ? 'Permite acceso al micrófono en Settings → Safari' : error}
        </p>
      )}
    </div>
  );
}
