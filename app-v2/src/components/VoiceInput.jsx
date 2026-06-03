import { useEffect, useRef, useState } from 'react';

// Botón de mic que usa Web Speech API nativa del browser (gratis, sin
// dependencias de OpenAI/Whisper). Funciona en Safari iOS, Chrome,
// Edge. Limitación: en Firefox no está soportado todavía.
//
// Uso:
//   <VoiceInput onTranscript={(text) => setInput(text)} />
// O para appendear a lo que ya hay escrito:
//   <VoiceInput onTranscript={(text, isFinal) => {
//     if (isFinal) setInput(prev => prev + ' ' + text);
//   }} />
//
// Props:
//   onTranscript(text, isFinal): callback con la transcripción.
//   lang: idioma ('es-MX' default, también 'es-US', 'en-US', etc).
//   autoSend: si true, llama onTranscript con isFinal=true y termina.
//   className: clases extra para el botón.
export default function VoiceInput({ onTranscript, lang = 'es-MX', className = '' }) {
  const [supported, setSupported] = useState(true);
  const [recording, setRecording] = useState(false);
  const [error, setError] = useState('');
  const recognitionRef = useRef(null);

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

  return (
    <div className="relative">
      <button
        onClick={toggle}
        className={`px-3 py-2 rounded-lg transition-all ${
          recording
            ? 'bg-red text-white animate-pulse'
            : 'bg-lino-100 text-ink-2 hover:bg-lino-200'
        } ${className}`}
        title={recording ? 'Toca para parar' : 'Toca y habla'}
        aria-label={recording ? 'Parar grabación' : 'Empezar grabación'}
      >
        {recording ? '⏹ Parar' : '🎤 Hablar'}
      </button>
      {error && (
        <p className="absolute top-full left-0 mt-1 text-xs text-red whitespace-nowrap">
          {error === 'not-allowed' ? 'Permite acceso al micrófono en Settings → Safari' : error}
        </p>
      )}
    </div>
  );
}
