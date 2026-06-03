import { useEffect, useRef, useState } from 'react';

// Botón de mic que usa Web Speech API nativa del browser.
//
// Manejo de pausas (iOS-friendly):
//   Safari iOS NO respeta continuous=true confiablemente — termina la
//   sesión a los pocos segundos aunque le digamos que siga. Estrategia:
//     1) Mientras user está en "recording mode" (botón rojo activo),
//        si el recognition termina por su cuenta, lo RESTARTAMOS auto.
//        Da apariencia de continuous real en iOS.
//     2) Detección de silencio: si pasan SILENCE_MS sin recibir ningún
//        resultado (final ni interim), paramos de verdad. Default 5s.
//     3) El usuario también puede parar manual con el botón ⏹.
//
// Spanglish: toggle ES/EN. Web Speech NO auto-detecta idioma.

const LANG_OPTIONS = [
  { code: 'es-MX', label: 'ES', name: 'Español' },
  { code: 'en-US', label: 'EN', name: 'English' },
];
const SILENCE_MS = 5000; // 5 segundos de silencio → para

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
  // Refs para que cambios NO re-creen el recognition.
  const recognitionRef = useRef(null);
  const recordingRef = useRef(false);
  const silenceTimerRef = useRef(null);
  const restartingRef = useRef(false);

  useEffect(() => {
    try { localStorage.setItem('athena_voice_lang', lang); } catch { /* ignore */ }
  }, [lang]);

  function resetSilenceTimer() {
    if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
    silenceTimerRef.current = setTimeout(() => {
      console.log('[voice] silencio detectado, parando');
      stopRecording();
    }, SILENCE_MS);
  }

  function stopRecording() {
    recordingRef.current = false;
    setRecording(false);
    if (silenceTimerRef.current) {
      clearTimeout(silenceTimerRef.current);
      silenceTimerRef.current = null;
    }
    try { recognitionRef.current?.stop(); } catch { /* ignore */ }
  }

  useEffect(() => {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) {
      setSupported(false);
      return;
    }
    const rec = new SR();
    rec.lang = lang;
    rec.continuous = true;
    rec.interimResults = true;
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
      if (final) {
        onTranscript(final.trim(), true);
        resetSilenceTimer();
      } else if (interim) {
        resetSilenceTimer();
      }
    };

    rec.onerror = (event) => {
      const errMsg = event.error || 'voice_error';
      if (errMsg === 'no-speech' || errMsg === 'aborted') return;
      if (errMsg === 'not-allowed') {
        setError('Permite acceso al mic en Settings → Safari');
        recordingRef.current = false;
        setRecording(false);
        return;
      }
      setError(errMsg);
    };

    rec.onend = () => {
      // iOS Safari termina la sesión a los pocos segundos aunque
      // continuous=true. Si el usuario sigue queriendo grabar (botón
      // rojo activo), reiniciamos automáticamente.
      if (recordingRef.current && !restartingRef.current) {
        restartingRef.current = true;
        setTimeout(() => {
          if (recordingRef.current) {
            try {
              rec.start();
            } catch (err) {
              console.warn('[voice] restart falló:', err.message);
              recordingRef.current = false;
              setRecording(false);
              setError(err.message);
            }
          }
          restartingRef.current = false;
        }, 100);
      } else {
        setRecording(false);
      }
    };

    recognitionRef.current = rec;
    return () => {
      try { rec.abort(); } catch { /* ignore */ }
      if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current);
    };
  }, [lang, onTranscript]);

  function switchLang(newLang) {
    if (newLang === lang) return;
    if (recordingRef.current) stopRecording();
    setLang(newLang);
  }

  function toggle() {
    if (!recognitionRef.current) return;
    setError('');
    if (recordingRef.current) {
      stopRecording();
    } else {
      try {
        recordingRef.current = true;
        setRecording(true);
        recognitionRef.current.start();
        resetSilenceTimer();
      } catch (err) {
        recordingRef.current = false;
        setRecording(false);
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
          title={
            recording
              ? 'Hablando… (5s de silencio para. o tócala)'
              : `Toca y habla (${lang === 'es-MX' ? 'Español' : 'English'})`
          }
          aria-label={recording ? 'Parar grabación' : 'Empezar grabación'}
        >
          {recording ? '⏹' : '🎤'}
        </button>
      </div>
      {error && (
        <p className="absolute top-full left-0 mt-1 text-xs text-red whitespace-nowrap">
          {error}
        </p>
      )}
    </div>
  );
}
