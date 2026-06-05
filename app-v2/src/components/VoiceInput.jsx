import { forwardRef, useEffect, useImperativeHandle, useRef, useState } from 'react';
import { Mic, MicOff, Square, Loader2 } from 'lucide-react';

// Botón de mic que graba con MediaRecorder y manda el audio al servidor.
// El servidor lo transcribe con Whisper, que AUTO-DETECTA idioma — por eso
// ya no hay toggle ES/EN. Spanglish ("voy a llamar a Maritza for the
// appointment") sale bien naturalmente.
//
// Flujo:
//   1) Tap mic → pide permiso de micrófono, empieza a grabar (botón rojo)
//   2) Tap stop → para de grabar, sube el blob a /api/transcribe
//   3) Whisper transcribe → texto llega → onTranscript(texto, true)
//
// Auto-stop por silencio: 3s sin sonido detectable → para automáticamente
// (analiza el RMS del stream). Usuario también puede parar manual con stop.
//
// Trade-off vs Web Speech: ~1-2 seg de espera después de parar (Whisper
// procesa), pero a cambio: real auto-detect, mejor precisión, spanglish.

const SILENCE_MS = 3000;         // 3s de silencio total → para
const SILENCE_THRESHOLD = 0.012;  // RMS por debajo de esto = silencio

function VoiceInputInner({ onTranscript, className = '' }, ref) {
  const [supported, setSupported] = useState(true);
  const [recording, setRecording] = useState(false);
  const [busy, setBusy] = useState(false);  // subiendo / transcribiendo
  const [error, setError] = useState('');

  const mediaRecorderRef = useRef(null);
  const streamRef = useRef(null);
  const audioCtxRef = useRef(null);
  const analyserRef = useRef(null);
  const silenceTimerRef = useRef(null);
  const lastSoundAtRef = useRef(0);
  const rafRef = useRef(null);
  const chunksRef = useRef([]);
  const cancelledRef = useRef(false);

  useEffect(() => {
    if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
      setSupported(false);
    }
    return () => { cleanup(); };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function cleanup() {
    try { if (rafRef.current) cancelAnimationFrame(rafRef.current); } catch { /* ignore */ }
    rafRef.current = null;
    try { if (silenceTimerRef.current) clearTimeout(silenceTimerRef.current); } catch { /* ignore */ }
    silenceTimerRef.current = null;
    try { streamRef.current?.getTracks().forEach((t) => t.stop()); } catch { /* ignore */ }
    streamRef.current = null;
    try { audioCtxRef.current?.close(); } catch { /* ignore */ }
    audioCtxRef.current = null;
    analyserRef.current = null;
    mediaRecorderRef.current = null;
  }

  useImperativeHandle(ref, () => ({
    stop: () => {
      if (mediaRecorderRef.current && mediaRecorderRef.current.state === 'recording') {
        try { mediaRecorderRef.current.stop(); } catch { /* ignore */ }
      }
    },
    cancel: () => {
      cancelledRef.current = true;
      if (mediaRecorderRef.current && mediaRecorderRef.current.state === 'recording') {
        try { mediaRecorderRef.current.stop(); } catch { /* ignore */ }
      }
    },
    isRecording: () => recording,
  }), [recording]);

  function pickMimeType() {
    const candidates = [
      'audio/webm;codecs=opus',
      'audio/webm',
      'audio/mp4',
      'audio/ogg;codecs=opus',
    ];
    for (const t of candidates) {
      if (window.MediaRecorder.isTypeSupported?.(t)) return t;
    }
    return '';  // browser default
  }

  async function startRecording() {
    setError('');
    cancelledRef.current = false;
    chunksRef.current = [];
    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true },
      });
      streamRef.current = stream;

      const mimeType = pickMimeType();
      const rec = mimeType ? new MediaRecorder(stream, { mimeType }) : new MediaRecorder(stream);
      mediaRecorderRef.current = rec;

      rec.ondataavailable = (e) => {
        if (e.data && e.data.size > 0) chunksRef.current.push(e.data);
      };

      rec.onstop = async () => {
        const wasCancelled = cancelledRef.current;
        cleanup();
        setRecording(false);
        if (wasCancelled) return;
        const blob = new Blob(chunksRef.current, { type: rec.mimeType || 'audio/webm' });
        chunksRef.current = [];
        if (blob.size < 1000) {
          // Muy corto — probablemente no dijiste nada útil.
          return;
        }
        setBusy(true);
        try {
          const r = await fetch('/api/transcribe', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': blob.type },
            body: blob,
          });
          if (!r.ok) {
            const e = await r.json().catch(() => ({}));
            throw new Error(e.error || `whisper ${r.status}`);
          }
          const { text } = await r.json();
          if (text) onTranscript(text, true);
        } catch (err) {
          setError(err.message || 'no se pudo transcribir');
        } finally {
          setBusy(false);
        }
      };

      // Silence detection via WebAudio.
      try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        const ctx = new AudioCtx();
        audioCtxRef.current = ctx;
        const source = ctx.createMediaStreamSource(stream);
        const analyser = ctx.createAnalyser();
        analyser.fftSize = 2048;
        source.connect(analyser);
        analyserRef.current = analyser;
        const buf = new Float32Array(analyser.fftSize);
        lastSoundAtRef.current = Date.now();
        const tick = () => {
          if (!analyserRef.current) return;
          analyser.getFloatTimeDomainData(buf);
          let sum = 0;
          for (let i = 0; i < buf.length; i++) sum += buf[i] * buf[i];
          const rms = Math.sqrt(sum / buf.length);
          if (rms > SILENCE_THRESHOLD) lastSoundAtRef.current = Date.now();
          if (Date.now() - lastSoundAtRef.current > SILENCE_MS) {
            // Auto-stop.
            try { rec.stop(); } catch { /* ignore */ }
            return;
          }
          rafRef.current = requestAnimationFrame(tick);
        };
        rafRef.current = requestAnimationFrame(tick);
      } catch (err) {
        console.warn('[voice] WebAudio falló, sin auto-silencio:', err.message);
      }

      rec.start();
      setRecording(true);
    } catch (err) {
      cleanup();
      setRecording(false);
      if (err.name === 'NotAllowedError') {
        setError('Permite acceso al mic en Ajustes del navegador');
      } else {
        setError(err.message || 'no pude abrir el mic');
      }
    }
  }

  function toggle() {
    if (busy) return;
    if (recording) {
      try { mediaRecorderRef.current?.stop(); } catch { /* ignore */ }
    } else {
      startRecording();
    }
  }

  if (!supported) {
    return (
      <button
        disabled
        className={`px-3 py-2 rounded-lg bg-lino-100 text-ink-3 ${className}`}
        title="Tu navegador no soporta grabación de voz."
        aria-label="Voz no soportada"
      >
        <MicOff size={18} strokeWidth={1.5} />
      </button>
    );
  }

  return (
    <div className="relative">
      <button
        onClick={toggle}
        disabled={busy}
        className={`px-3 py-2 rounded-lg transition-all ${
          recording
            ? 'bg-red text-white animate-pulse'
            : busy
              ? 'bg-lino-200 text-ink-3'
              : 'bg-lino-100 text-ink-2 hover:bg-lino-200'
        } ${className}`}
        title={
          busy
            ? 'Transcribiendo…'
            : recording
              ? 'Grabando — toca para parar (o 3s de silencio)'
              : 'Toca y habla (detecta ES/EN automáticamente)'
        }
        aria-label={recording ? 'Parar grabación' : 'Empezar grabación'}
      >
        {busy
          ? <Loader2 size={18} strokeWidth={1.5} className="animate-spin" />
          : recording
            ? <Square size={18} strokeWidth={1.5} fill="currentColor" />
            : <Mic size={18} strokeWidth={1.5} />}
      </button>
      {error && (
        <p className="absolute top-full left-0 mt-1 text-xs text-red whitespace-nowrap">
          {error}
        </p>
      )}
    </div>
  );
}

const VoiceInput = forwardRef(VoiceInputInner);
export default VoiceInput;
