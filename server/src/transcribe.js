// Transcripción de audio entrante por WhatsApp.
// Twilio nos manda voice notes como MediaUrl con content-type audio/ogg
// (WhatsApp) o audio/amr (algunos casos). Usamos OpenAI Whisper —
// la API directa, no la SDK, para no agregar peso al servidor.
//
// Costo: ~$0.006/min al precio público de mayo 2026.
// Si no hay OPENAI_API_KEY, devolvemos un placeholder claro.

const OPENAI_API_KEY = process.env.OPENAI_API_KEY;
const WHISPER_MODEL = process.env.WHISPER_MODEL || 'whisper-1';

function twilioAuthHeader() {
  const sid = process.env.TWILIO_ACCOUNT_SID;
  const token = process.env.TWILIO_AUTH_TOKEN;
  if (!sid || !token) return null;
  return 'Basic ' + Buffer.from(`${sid}:${token}`).toString('base64');
}

export async function transcribeWhatsAppAudio(mediaUrl, contentType) {
  if (!OPENAI_API_KEY) {
    return {
      ok: false,
      transcript: '',
      note: '[Isabel mandó una nota de voz pero OPENAI_API_KEY no está configurada — no la pude transcribir.]',
    };
  }
  // 1) Baja el audio desde Twilio (autenticado).
  const auth = twilioAuthHeader();
  const r = await fetch(mediaUrl, {
    headers: auth ? { Authorization: auth } : {},
    redirect: 'follow',
  });
  if (!r.ok) {
    return { ok: false, transcript: '', note: `[Audio fetch falló: ${r.status}]` };
  }
  const buf = Buffer.from(await r.arrayBuffer());

  // 2) Manda a Whisper. Usamos FormData nativo de Node 22.
  const ext = contentType.includes('ogg') ? 'ogg' : contentType.includes('amr') ? 'amr' : contentType.includes('mp3') ? 'mp3' : 'm4a';
  const form = new FormData();
  form.append('file', new Blob([buf], { type: contentType }), `voice.${ext}`);
  form.append('model', WHISPER_MODEL);
  // Idioma: dejamos que Whisper detecte (Isabel usa spanglish).

  const w = await fetch('https://api.openai.com/v1/audio/transcriptions', {
    method: 'POST',
    headers: { Authorization: `Bearer ${OPENAI_API_KEY}` },
    body: form,
  });
  if (!w.ok) {
    const errTxt = await w.text().catch(() => '');
    return { ok: false, transcript: '', note: `[Transcripción falló: ${w.status} ${errTxt.slice(0, 200)}]` };
  }
  const data = await w.json();
  const text = (data.text || '').trim();
  return { ok: true, transcript: text, note: '' };
}

// Transcribe un buffer de audio crudo (del PWA — grabado con MediaRecorder).
// Whisper auto-detecta idioma → no necesitamos toggle ES/EN. Perfecto para
// spanglish ("voy a llamar a Maritza for the appointment" sale bien).
export async function transcribeAudioBuffer(buf, mimeType = 'audio/webm') {
  if (!OPENAI_API_KEY) {
    return { ok: false, transcript: '', error: 'OPENAI_API_KEY no configurada' };
  }
  const ext = mimeType.includes('webm') ? 'webm'
    : mimeType.includes('ogg') ? 'ogg'
    : mimeType.includes('mp4') || mimeType.includes('m4a') ? 'm4a'
    : mimeType.includes('mp3') ? 'mp3'
    : mimeType.includes('wav') ? 'wav'
    : 'webm';
  const form = new FormData();
  form.append('file', new Blob([buf], { type: mimeType }), `voice.${ext}`);
  form.append('model', WHISPER_MODEL);
  // No fijamos idioma — Whisper detecta. Spanglish friendly.
  const w = await fetch('https://api.openai.com/v1/audio/transcriptions', {
    method: 'POST',
    headers: { Authorization: `Bearer ${OPENAI_API_KEY}` },
    body: form,
  });
  if (!w.ok) {
    const errTxt = await w.text().catch(() => '');
    return { ok: false, transcript: '', error: `Whisper ${w.status}: ${errTxt.slice(0, 200)}` };
  }
  const data = await w.json();
  return { ok: true, transcript: (data.text || '').trim() };
}
