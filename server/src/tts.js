// ============================================================
//  Text-to-Speech para respuestas de voz
//  ──────────────────────────────────────
//  Cuando Isabel manda voice note (o pide voz explícito), Athena
//  responde con audio. Generamos MP3 con OpenAI TTS, lo dejamos
//  en data/audio/<id>.mp3, y le pasamos a Twilio una URL pública
//  para que lo entregue por WhatsApp.
//
//  Requisitos:
//    OPENAI_API_KEY    (mismo que Whisper)
//    PUBLIC_URL        (https://athena.tu-host.com — visible a Twilio)
//
//  Cleanup: index.js corre un cron horario que borra audio >24h.
// ============================================================
import { writeFileSync, mkdirSync, existsSync, readdirSync, statSync, unlinkSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
export const AUDIO_DIR = join(__dirname, '..', 'data', 'audio');

const OPENAI_API_KEY = process.env.OPENAI_API_KEY;
const TTS_MODEL = process.env.TTS_MODEL || 'tts-1';        // tts-1-hd para mejor calidad / +costo
const TTS_VOICE = process.env.TTS_VOICE || 'nova';         // alloy, ash, ballad, coral, echo, fable, nova, onyx, sage, shimmer
const TTS_MAX_CHARS = parseInt(process.env.TTS_MAX_CHARS || '1200', 10); // límite suave por audio

export function ttsConfigured() {
  return Boolean(OPENAI_API_KEY && process.env.PUBLIC_URL);
}

function newId() {
  return `a${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

// Genera el MP3 y devuelve la URL pública. null si TTS no está
// configurado — el caller debe fallback a texto.
export async function synthToPublicUrl(text) {
  if (!ttsConfigured()) return null;
  if (!existsSync(AUDIO_DIR)) mkdirSync(AUDIO_DIR, { recursive: true });

  // Recortar si es muy largo (TTS cobra por carácter en algunos modelos
  // y WhatsApp voice notes muy largas son incómodas).
  const input = text.length > TTS_MAX_CHARS
    ? text.slice(0, TTS_MAX_CHARS).replace(/\s+\S*$/, '') + '…'
    : text;

  const r = await fetch('https://api.openai.com/v1/audio/speech', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${OPENAI_API_KEY}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      model: TTS_MODEL,
      voice: TTS_VOICE,
      input,
      response_format: 'mp3',
    }),
  });
  if (!r.ok) {
    const errTxt = await r.text().catch(() => '');
    throw new Error(`TTS falló: ${r.status} ${errTxt.slice(0, 200)}`);
  }
  const buf = Buffer.from(await r.arrayBuffer());
  const id = newId();
  const file = join(AUDIO_DIR, `${id}.mp3`);
  writeFileSync(file, buf);
  // Twilio necesita URL pública HTTPS. Asumimos PUBLIC_URL sin trailing slash.
  const base = (process.env.PUBLIC_URL || '').replace(/\/+$/, '');
  return `${base}/audio/${id}.mp3`;
}

// Borra audios > MAX_AGE_HOURS. Lo llama un cron horario.
export function cleanupOldAudio(maxAgeHours = 24) {
  if (!existsSync(AUDIO_DIR)) return 0;
  const cutoff = Date.now() - maxAgeHours * 3600_000;
  let removed = 0;
  for (const name of readdirSync(AUDIO_DIR)) {
    if (!name.endsWith('.mp3')) continue;
    const path = join(AUDIO_DIR, name);
    try {
      const st = statSync(path);
      if (st.mtimeMs < cutoff) {
        unlinkSync(path);
        removed += 1;
      }
    } catch { /* ignore */ }
  }
  return removed;
}
