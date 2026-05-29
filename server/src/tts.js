// ============================================================
//  Text-to-Speech para respuestas de voz (multi-provider)
//  ──────────────────────────────────────────────────────
//  Cuando Isabel manda voice note (o pide voz), Athena le responde
//  con audio. Generamos MP3, lo dejamos en data/audio/<id>.mp3,
//  y le pasamos a Twilio una URL pública para que lo entregue.
//
//  Soportamos dos proveedores. El switch es TTS_PROVIDER:
//
//   OPENAI (default — barato, decente, fácil):
//     OPENAI_API_KEY=sk-...
//     TTS_VOICE=nova            (alloy/ash/ballad/coral/echo/fable/nova/onyx/sage/shimmer)
//     TTS_MODEL=tts-1           (o tts-1-hd para mejor calidad/+costo)
//
//   ELEVENLABS (premium — clonación de Isabel, prosodia mejor en español):
//     TTS_PROVIDER=elevenlabs
//     ELEVENLABS_API_KEY=...
//     ELEVENLABS_VOICE_ID=...   (tu voz clonada — sigue las instrucciones del README)
//     ELEVENLABS_MODEL=eleven_flash_v2_5   (Flash: <500ms TTFA, 32 langs)
//                                          (o eleven_multilingual_v2 para máxima calidad)
//
//  Para clonar tu voz una vez tengas la cuenta:
//    1. elevenlabs.io → Voices → Add → Instant Voice Clone
//    2. Sube 1-5 minutos de TI hablando en español/spanglish, audio limpio
//    3. Copia el voice_id que te da → pégalo en ELEVENLABS_VOICE_ID
//    4. Setea TTS_PROVIDER=elevenlabs. Listo.
//
//  PUBLIC_URL es requerido en ambos casos (Twilio jala el MP3 de ahí).
//  Cleanup: index.js corre un cron horario que borra audio >24h.
// ============================================================
import { writeFileSync, mkdirSync, existsSync, readdirSync, statSync, unlinkSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
export const AUDIO_DIR = join(__dirname, '..', 'data', 'audio');

const PROVIDER = (process.env.TTS_PROVIDER || 'openai').toLowerCase();
const TTS_MAX_CHARS = parseInt(process.env.TTS_MAX_CHARS || '1200', 10);

export function ttsProvider() {
  return PROVIDER;
}

export function ttsConfigured() {
  if (!process.env.PUBLIC_URL) return false;
  if (PROVIDER === 'elevenlabs') {
    return Boolean(process.env.ELEVENLABS_API_KEY && process.env.ELEVENLABS_VOICE_ID);
  }
  // default = openai
  return Boolean(process.env.OPENAI_API_KEY);
}

function newId() {
  return `a${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
}

function clip(text) {
  if (text.length <= TTS_MAX_CHARS) return text;
  return text.slice(0, TTS_MAX_CHARS).replace(/\s+\S*$/, '') + '…';
}

async function synthOpenAI(text) {
  const model = process.env.TTS_MODEL || 'tts-1';
  const voice = process.env.TTS_VOICE || 'nova';
  const r = await fetch('https://api.openai.com/v1/audio/speech', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${process.env.OPENAI_API_KEY}`,
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({ model, voice, input: clip(text), response_format: 'mp3' }),
  });
  if (!r.ok) {
    const errTxt = await r.text().catch(() => '');
    throw new Error(`OpenAI TTS ${r.status}: ${errTxt.slice(0, 200)}`);
  }
  return Buffer.from(await r.arrayBuffer());
}

async function synthElevenLabs(text) {
  const voiceId = process.env.ELEVENLABS_VOICE_ID;
  const modelId = process.env.ELEVENLABS_MODEL || 'eleven_flash_v2_5';
  const url = `https://api.elevenlabs.io/v1/text-to-speech/${voiceId}?output_format=mp3_44100_128`;
  const r = await fetch(url, {
    method: 'POST',
    headers: {
      'xi-api-key': process.env.ELEVENLABS_API_KEY,
      'Accept': 'audio/mpeg',
      'Content-Type': 'application/json',
    },
    body: JSON.stringify({
      text: clip(text),
      model_id: modelId,
      // Settings probados para español natural — la voz queda expresiva
      // sin volverse caricaturesca. Subir similarity_boost si el clon
      // suena genérico; bajar stability si suena monótono.
      voice_settings: {
        stability: 0.5,
        similarity_boost: 0.8,
        style: 0.2,
        use_speaker_boost: true,
      },
    }),
  });
  if (!r.ok) {
    const errTxt = await r.text().catch(() => '');
    throw new Error(`ElevenLabs ${r.status}: ${errTxt.slice(0, 200)}`);
  }
  return Buffer.from(await r.arrayBuffer());
}

export async function synthToPublicUrl(text) {
  if (!ttsConfigured()) return null;
  if (!existsSync(AUDIO_DIR)) mkdirSync(AUDIO_DIR, { recursive: true });

  const buf = PROVIDER === 'elevenlabs' ? await synthElevenLabs(text) : await synthOpenAI(text);
  const id = newId();
  const file = join(AUDIO_DIR, `${id}.mp3`);
  writeFileSync(file, buf);
  const base = (process.env.PUBLIC_URL || '').replace(/\/+$/, '');
  return `${base}/audio/${id}.mp3`;
}

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
