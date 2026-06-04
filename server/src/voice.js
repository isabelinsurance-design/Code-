// ============================================================
//  Llamadas telefónicas — Twilio ConversationRelay + Anthropic
//  ────────────────────────────────────────────────────────────
//  Cuando alguien llama el número de Isabel, Twilio devuelve el
//  TwiML <Connect><ConversationRelay url=ws://.../voice/relay/>
//  y abre un WebSocket con nosotros. ConversationRelay nos manda
//  el texto transcrito (STT del lado de Twilio) y reproduce el
//  texto que nosotros le mandamos (TTS del lado de Twilio, con
//  soporte para ElevenLabs si configuras tu voz clonada).
//
//  Nuestro WS handler:
//   1. Recibe "setup" → busca al caller en el CRM, mete contexto
//   2. Recibe "prompt" (texto del usuario) → corre Athena modo voz
//   3. Manda "text" (respuesta de Athena) → Twilio la habla
//   4. Recibe "end" → corre post-call: touchpoint, grabación, resumen
//
//  Modo voz vs modo WhatsApp:
//   - Sonnet 4.6 en vez de Opus (menos tokens/seg de espera)
//   - Pensamiento OFF (latencia > calidad cuando la persona espera)
//   - max_tokens 200 (respuestas conversacionales cortas)
//   - max_rounds 3 (cortamos rápido para no dejar silencio)
//   - sistema-prompt voice-flavored (sin markdown, sin listas)
// ============================================================
import { WebSocketServer } from 'ws';
import twilio from 'twilio';
import { anthropic } from './claude.js';
import { DIRECTORA } from './agents.js';
import { toolDefinitions, runTool } from './tools.js';
import { buildWikiContext, logActivity, remember } from './memory.js';
import {
  lunaConfigured,
  searchMember as lunaSearchMember,
  logActivityToLuna,
  addMemberNote as lunaAddMemberNote,
} from './luna_client.js';
import { upsertEntity } from './entities.js';

const VOICE_MODEL = process.env.VOICE_MODEL || 'claude-sonnet-4-6';
const VOICE_MAX_TOKENS = parseInt(process.env.VOICE_MAX_TOKENS || '200', 10);
const VOICE_MAX_ROUNDS = parseInt(process.env.VOICE_MAX_ROUNDS || '3', 10);

// Tools peligrosos para voz (mandan algo a terceros sin que Isabel
// pueda revisar). Athena los puede llamar igual cuando NO está en
// llamada — solo los bloqueamos durante voz.
const VOICE_TOOL_BLOCKLIST = new Set([
  'enviar_email',
  'enviar_sms',
  'mensaje_a_sami',
  'llamar_cliente', // no llamadas recursivas
]);

// ============================================================
//  TwiML endpoints (HTTP, antes de la WS)
// ============================================================
export function buildIncomingTwiml(req) {
  const publicHost = (process.env.PUBLIC_URL || '').replace(/^https?:\/\//, '').replace(/\/+$/, '');
  if (!publicHost) {
    console.error('[voice] buildIncomingTwiml: PUBLIC_URL no configurado — devuelvo TwiML de error');
    return `<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say voice="Polly.Lupe-Neural" language="es-MX">Configuración pendiente. Sami necesita poner la URL pública en Railway.</Say>
  <Hangup/>
</Response>`;
  }
  const motivo = (req?.query?.motivo || '').slice(0, 400);
  const elevenVoice = process.env.ELEVENLABS_VOICE_ID;

  // VOICE_SIMPLE_MODE=true → TwiML simple con <Say> + <Hangup>.
  //   Athena llama, dice el motivo, cuelga. Útil para [LLAMA] recordatorios
  //   de cita donde no necesitas hablar de vuelta. Más confiable porque
  //   no depende del WebSocket. Útil como fallback si ConversationRelay
  //   da problemas (ej. error 64101 por TwiML incompleto, problema con
  //   STT, etc.).
  // Default (sin env var): ConversationRelay con TwiML completo —
  //   bidireccional, puedes hablar con Athena por teléfono. Confirmado
  //   funcional en cuenta Twilio (mid-2025+).
  const useSimple = process.env.VOICE_SIMPLE_MODE === 'true';
  const useConversation = !useSimple;

  if (!useConversation) {
    // Modo simple: Athena saluda + dice el motivo + cuelga.
    const voiceAttrs = elevenVoice
      ? ` voice="${elevenVoice}"` // ElevenLabs via custom voice (si Twilio lo soporta)
      : ' voice="Polly.Lupe-Neural" language="es-MX"';
    const mensaje = motivo
      ? `Hola Isabel, soy Athena. Te llamo para recordarte: ${motivo}. Si necesitas más detalles, abre la app o el WhatsApp. Adiós.`
      : 'Hola Isabel, soy Athena. Te llamo para confirmar que el sistema de voz está funcionando. Adiós.';
    const twiml = `<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Say${voiceAttrs}>${escapeXml(mensaje)}</Say>
  <Hangup/>
</Response>`;
    console.log(`[voice] TwiML simple generado: motivo="${motivo}" eleven=${elevenVoice ? 'sí' : 'no'}`);
    return twiml;
  }

  // Modo ConversationRelay (avanzado — bidireccional con WebSocket).
  // Twilio requiere TODOS estos atributos para es-MX:
  //   - language
  //   - ttsProvider + voice
  //   - transcriptionProvider + speechModel
  // Sin uno se queja con error 64101 "Incomplete value set in TwiML".
  const wsUrl = `wss://${publicHost}/voice/relay`;
  // TTS para ConversationRelay — solo dos providers soportados:
  //   1. ElevenLabs (override absoluto si ELEVENLABS_VOICE_ID set)
  //   2. Google (default)
  //
  // VOZ COMBO PROBADA: es-US-Neural2-A + language=es-MX.
  // Esta combinación SÍ funcionó en pruebas reales (Twilio la acepta).
  // El locale del voice (es-US) NO tiene que matchear language (es-MX)
  // contrario a lo que sugería el error 64101 — para Neural2 voices,
  // language tag override el locale del voice. Studio es otra historia.
  //
  // Cambios futuros: VOICE_TTS_VOICE en env override. Cuidado al cambiar
  // a Studio (es-US-Studio-B) — esos requieren language=es-US exacto.
  // IMPORTANTE: para que ConversationRelay use ElevenLabs, Twilio MISMO
  // necesita tener integrada la cuenta de ElevenLabs (vía Twilio Console
  // → AI Studio → Integrations → ElevenLabs). NO basta con tener
  // ELEVENLABS_API_KEY en Railway — esa la usa nuestro server para
  // WhatsApp voice y PWA, NO Twilio. Si ElevenLabs no está integrada
  // EN Twilio, ConversationRelay tira error 64112 "voice_id not found".
  //
  // Para forzar ElevenLabs en llamadas tras integrar en Twilio Console:
  //   ENABLE_ELEVENLABS_IN_CALLS=true en Railway
  // Sin esa flag: usa Google (siempre funciona, voz menos personalizada).
  const callsUseEleven = process.env.ENABLE_ELEVENLABS_IN_CALLS === 'true';
  let ttsProvider = 'Google';
  let voice = process.env.VOICE_TTS_VOICE || 'es-US-Neural2-A';
  if (elevenVoice && callsUseEleven) {
    ttsProvider = 'ElevenLabs';
    voice = elevenVoice;
  }
  // STT: Google Telephony es lo más robusto para español por teléfono.
  const transcriptionProvider = 'Google';
  const speechModel = 'telephony';
  const language = 'es-MX';
  const welcome = motivo
    ? `Hola Isabel, soy Athena. Te llamo por esto: ${motivo}`
    : 'Hola, habla Athena, asistente de Isabel Fuentes. ¿En qué te ayudo?';
  const twiml = `<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Connect>
    <ConversationRelay url="${wsUrl}" ttsProvider="${ttsProvider}" voice="${voice}" transcriptionProvider="${transcriptionProvider}" speechModel="${speechModel}" language="${language}" welcomeGreeting="${escapeXml(welcome)}" />
  </Connect>
</Response>`;
  console.log(`[voice] TwiML ConversationRelay generado: wsUrl=${wsUrl} tts=${ttsProvider}/${voice} stt=${transcriptionProvider}/${speechModel} motivo="${motivo}"`);
  return twiml;
}

// Escape XML para evitar romper el TwiML con caracteres especiales en
// motivo o welcome — comilla doble es el más problemático en atributos.
function escapeXml(text) {
  return String(text || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');
}

// Twilio nos pega esto cuando termina una llamada — incluye RecordingUrl
// si tenemos record=true. Lo usamos para guardar la grabación contra el
// cliente del CRM.
export async function handleVoiceStatus(req, res) {
  const status = req.body.CallStatus;
  const callSid = req.body.CallSid;
  const recordingUrl = req.body.RecordingUrl;
  const from = req.body.From;
  logActivity({
    tool: 'voice_status',
    input_summary: `${status} ${callSid}`,
    result_summary: from || '',
  });
  if (status === 'completed' && recordingUrl) {
    // ConversationRelay ya nos dio la transcripción en vivo (vía WS),
    // así que aquí solo guardamos la grabación contra el cliente.
    await attachRecordingToClient(from, recordingUrl, callSid).catch((err) => {
      console.warn('[voice] attachRecording falló:', err.message);
    });
  }
  res.status(204).end();
}

async function attachRecordingToClient(fromPhone, recordingUrl, callSid) {
  if (!fromPhone || !lunaConfigured()) return;
  const r = await lunaSearchMember(fromPhone);
  const match = (r.data || [])[0];
  if (!match) return;
  await lunaAddMemberNote(match.id, `Recording: ${recordingUrl} (callSid=${callSid})`);
  logActivity({ tool: 'voice_recording_saved', input_summary: match.id, result_summary: recordingUrl });
}

// ============================================================
//  WebSocket: ConversationRelay ↔ Athena
// ============================================================
export function attachVoiceRelay(httpServer) {
  const wss = new WebSocketServer({ noServer: true });
  httpServer.on('upgrade', (req, socket, head) => {
    // Log defensivo: queremos saber EXACTAMENTE qué URL pide Twilio.
    // Sin esto, si el path no matchea exactamente, la upgrade se ignora
    // silenciosamente y Twilio dice "application error" al caller.
    console.log(`[voice] upgrade request url=${req.url} method=${req.method} headers.upgrade=${req.headers.upgrade}`);
    // Antes era === '/voice/relay'. Eso falla si Twilio agrega query
    // params (ej. ?session=abc) o trailing slash. Más permisivo:
    const urlPath = (req.url || '').split('?')[0].replace(/\/+$/, '');
    if (urlPath !== '/voice/relay') {
      console.log(`[voice] upgrade NO matchea /voice/relay, dejando pasar (path=${urlPath})`);
      return; // dejamos otros upgrades pasar
    }
    console.log('[voice] upgrade match — iniciando WS handshake');
    wss.handleUpgrade(req, socket, head, (ws) => {
      wss.emit('connection', ws, req);
    });
  });
  wss.on('connection', (ws, req) => {
    console.log(`[voice] cliente WS conectado from=${req.socket?.remoteAddress || '?'}`);
    runRelaySession(ws).catch((err) => {
      console.error('[voice] sesión murió:', err.message, err.stack);
      try { ws.close(); } catch { /* ignore */ }
    });
  });
  wss.on('error', (err) => {
    console.error('[voice] WSServer error:', err.message);
  });
  console.log('[voice] /voice/relay listo para llamadas (WSServer atachado al httpServer).');
}

async function runRelaySession(ws) {
  // Estado de sesión por llamada. Cada llamada es independiente.
  const session = {
    callSid: null,
    fromNumber: null,
    callerName: null,
    callerClientId: null,
    callerEntityId: null,
    messages: [],     // historial Anthropic
    transcript: [],   // [{role, text}] para resumen post-call
    closed: false,
  };

  ws.on('message', async (raw) => {
    let msg;
    try { msg = JSON.parse(raw.toString()); } catch { return; }
    switch (msg.type) {
      case 'setup':
        await onSetup(session, msg);
        break;
      case 'prompt':
        // ConversationRelay nos manda { type: 'prompt', voicePrompt: '...', last: true }
        // o un partial con last: false. Solo respondemos a last:true para no cortar.
        if (msg.last === false) return;
        await onUserText(session, ws, msg.voicePrompt || '');
        break;
      case 'interrupt':
        // Usuario habló encima de Athena — cortamos lo que estábamos diciendo.
        // Por ahora solo lo logueamos; ConversationRelay maneja la mecánica.
        console.log('[voice] interrupción del usuario.');
        break;
      case 'dtmf':
        // Marcado de tono. Lo tratamos como texto para que Athena pueda
        // reaccionar a "marca 1 para X".
        await onUserText(session, ws, `[DTMF] ${msg.digit}`);
        break;
      default:
        // 'error', 'info', etc. — ignoramos.
        break;
    }
  });
  ws.on('close', async () => {
    session.closed = true;
    console.log('[voice] WS cerrado, corriendo post-call.');
    await postCall(session).catch((e) => console.warn('[voice] postCall:', e.message));
  });
  ws.on('error', (err) => {
    console.warn('[voice] WS error:', err.message);
  });
}

async function onSetup(session, msg) {
  session.callSid = msg.callSid || msg.sessionId || null;
  // ConversationRelay envía customParameters + from + to en setup
  session.fromNumber = msg.from || '';
  // Buscar al caller en LUNA por teléfono
  if (session.fromNumber && lunaConfigured()) {
    try {
      const r = await lunaSearchMember(session.fromNumber);
      const match = (r.data || [])[0];
      if (match) {
        session.callerName = `${match.nombre || ''} ${match.apellido || ''}`.trim();
        session.callerClientId = match.id;
      }
    } catch { /* swallow — log shows it */ }
  }
  logActivity({
    tool: 'voice_call_start',
    input_summary: session.fromNumber || 'unknown',
    result_summary: session.callerName || 'desconocido',
  });
}

function buildVoiceSystem(session) {
  // Voice prompt es DELIBERADAMENTE corto. No metemos toda la wiki —
  // solo lo crítico + lo del caller si lo reconocimos.
  const callerCtx = session.callerName
    ? `\n\nESTE QUE LLAMA: ${session.callerName} (cliente del CRM, id=${session.callerClientId}). Antes de responder específicos, llama expediente_cliente con ese id para tener su contexto Medicare.`
    : `\n\nESTE QUE LLAMA: número ${session.fromNumber || 'desconocido'}. NO está en el CRM. Pregúntale su nombre amablemente.`;
  return `${DIRECTORA.system}

MODO LLAMADA EN VIVO: estás hablando POR TELÉFONO. Reglas estrictas:
- Respuestas CORTAS (1-2 frases máximo, ~20-30 palabras).
- 🚨 NUNCA uses caracteres de markdown — NUNCA asteriscos (*), guiones bajos (_), numerales (#), corchetes [], backticks (\`), bullets (-), ni nada visual. Todo lo que escribas se LEE EN VOZ ALTA literal. Un asterisco se lee "asterisco asterisco". Habla como si dictaras a una persona.
- NO leas listas ni números enumerados ("primero", "segundo"). Junta todo en frases naturales con comas y "y".
- Si la pregunta requiere look-up (CRM, calendar, etc.) llama UNA tool máximo por turno y resume en una frase NATURAL.
- Si la conversación se pone larga o técnica (planes, comparaciones), proponle a la persona que mejor le mandas detalles por WhatsApp o email, NO trates de leer todo en voz.
- Si el llamante NO es un cliente conocido, sé profesional pero no des info confidencial. Confírmale que le pasas el mensaje a Isabel.
- Si el llamante pide hablar con Isabel directamente, di: "Le aviso a Isabel ahora mismo, déjeme su número y el motivo de la llamada" — luego usa mensaje_a_sami con la nota.
- SOA + Medicare: si la conversación toca planes específicos, antes de discutir DEBES confirmar que tienes SOA firmada. Si NO la tienes, di "Para hablar de planes específicos necesito tener su Scope of Appointment firmada. Le mando el link por WhatsApp ahora mismo."
- NUNCA des consejos médicos o de inversión.${callerCtx}`;
}

// Strip de markdown defensivo — incluso si Athena se le escapa un
// asterisco o backtick, no llega al TTS. Doble red de seguridad.
function stripMarkdownForVoice(text) {
  if (!text) return '';
  return String(text)
    // **bold** / __bold__ → bold
    .replace(/\*\*([^*]+)\*\*/g, '$1')
    .replace(/__([^_]+)__/g, '$1')
    // *italic* / _italic_ → italic
    .replace(/\*([^*]+)\*/g, '$1')
    .replace(/_([^_]+)_/g, '$1')
    // `code` → code
    .replace(/`([^`]+)`/g, '$1')
    // # heading → heading
    .replace(/^#+\s+/gm, '')
    // - bullet → ", "
    .replace(/^[-•]\s+/gm, '')
    // [text](url) → text
    .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
    // Asteriscos sueltos al principio o final
    .replace(/\*+/g, '')
    // Doble espacio → un espacio
    .replace(/\s{2,}/g, ' ')
    .trim();
}

async function onUserText(session, ws, userText) {
  if (!userText.trim() || session.closed) return;
  session.transcript.push({ role: 'user', text: userText });
  session.messages.push({ role: 'user', content: userText });

  const reply = await runVoiceAthena(session).catch((err) => {
    console.error('[voice] Athena falló:', err.message);
    return 'Disculpa, tuve un problema técnico. Déjame mandarle un mensaje a Isabel.';
  });

  if (session.closed) return;
  session.transcript.push({ role: 'assistant', text: reply });
  // Strip markdown defensivo — el prompt dice "no asteriscos" pero los
  // modelos a veces se les escapa. Esto garantiza que el TTS nunca lee
  // "asterisco asterisco" en voz alta.
  const cleanReply = stripMarkdownForVoice(reply);
  // ConversationRelay espera { type: 'text', token: '<text>', last: true }
  try {
    ws.send(JSON.stringify({ type: 'text', token: cleanReply, last: true }));
  } catch (err) {
    console.warn('[voice] no se pudo enviar respuesta:', err.message);
  }
}

async function runVoiceAthena(session) {
  const system = [{
    type: 'text',
    text: buildVoiceSystem(session),
    cache_control: { type: 'ephemeral', ttl: '1h' },
  }];
  // Filtramos tools peligrosos para voz (envíos a terceros sin gate de Isabel).
  const tools = toolDefinitions.filter((t) => !VOICE_TOOL_BLOCKLIST.has(t.name));

  for (let i = 0; i < VOICE_MAX_ROUNDS; i++) {
    const res = await anthropic.messages.create({
      model: VOICE_MODEL,
      max_tokens: VOICE_MAX_TOKENS,
      system,
      tools,
      messages: session.messages,
    });
    session.messages.push({ role: 'assistant', content: res.content });
    if (res.stop_reason !== 'tool_use') {
      const text = res.content.filter((b) => b.type === 'text').map((b) => b.text).join(' ').trim();
      return text || 'Sí.';
    }
    const toolUses = res.content.filter((b) => b.type === 'tool_use');
    const results = await Promise.all(toolUses.map(async (tu) => {
      let content;
      try { content = await runTool(tu.name, tu.input); }
      catch (err) { content = `Error: ${err.message}`; }
      return { type: 'tool_result', tool_use_id: tu.id, content };
    }));
    session.messages.push({ role: 'user', content: results });
  }
  return 'Déjame revisar y te llamo enseguida con la respuesta.';
}

// Post-call: corre después de que cuelgan. Resume la conversación,
// crea touchpoint si el caller era cliente del CRM, anota la entidad
// si no lo era, y persiste el resumen en memoria para Isabel.
async function postCall(session) {
  if (!session.transcript.length) return;
  const conversation = session.transcript
    .map((t) => `${t.role === 'user' ? (session.callerName || 'Caller') : 'Athena'}: ${t.text}`)
    .join('\n');

  // Resumen rápido con Haiku (barato, no necesita ser brillante).
  let summary = '';
  try {
    const r = await anthropic.messages.create({
      model: 'claude-haiku-4-5-20251001',
      max_tokens: 250,
      messages: [{
        role: 'user',
        content: `Resume esta llamada telefónica en español, 3-4 líneas. Incluye: quién llamó, qué pidió, qué se acordó, próximo paso. Si menciona un compromiso (Athena va a mandar algo, llamar después, etc.) márcalo claro.

LLAMADA:
${conversation}

RESUMEN:`,
      }],
    });
    summary = (r.content?.[0]?.text || '').trim();
  } catch (err) {
    summary = `Llamada de ${session.fromNumber || 'desconocido'} (resumen falló: ${err.message}).`;
  }

  // Si era miembro de LUNA: registra la actividad de la llamada.
  if (session.callerClientId && lunaConfigured()) {
    try {
      await logActivityToLuna({
        tipo: 'LLAMADA',
        descripcion: summary.slice(0, 500),
        memberId: session.callerClientId,
      });
    } catch (err) {
      console.warn('[voice] logActivityToLuna:', err.message);
    }
  } else if (session.fromNumber) {
    // Si no era cliente, anotamos en entities por si Isabel lo quiere recordar.
    try {
      upsertEntity({
        canonical_name: session.fromNumber,
        type: 'other',
        nota: `Llamada entrante: ${summary.slice(0, 200)}`,
        salience: 6,
      });
    } catch { /* ignore */ }
  }

  // Y siempre lo metemos en la memoria general para que Isabel lo vea
  // en el briefing.
  remember(`Llamada (${session.fromNumber || 'desconocido'}, ${new Date().toISOString().slice(0, 16)}): ${summary}`);
  logActivity({
    tool: 'voice_call_summary',
    input_summary: session.callSid || '',
    result_summary: summary.slice(0, 200),
  });
  console.log('[voice] post-call OK:', summary.slice(0, 100));
}

// ============================================================
//  Outbound: Athena llama a alguien
// ============================================================
export async function placeOutboundCall({ to, motivo, cliente_id = null }) {
  const sid = process.env.TWILIO_ACCOUNT_SID;
  const token = process.env.TWILIO_AUTH_TOKEN;
  const from = process.env.TWILIO_VOICE_FROM || process.env.TWILIO_SMS_FROM;
  if (!sid || !token || !from) {
    throw new Error('Twilio Voice no configurado (faltan SID/token/TWILIO_VOICE_FROM).');
  }
  if (!process.env.PUBLIC_URL) {
    throw new Error('PUBLIC_URL es requerido para llamadas (Twilio jala el TwiML de ahí).');
  }
  const client = twilio(sid, token);
  // Pasamos motivo + cliente_id como parámetros custom para que el WS
  // los reciba en el setup y Athena entre en contexto.
  const params = new URLSearchParams({
    motivo: motivo || '',
    cliente_id: cliente_id || '',
  });
  const call = await client.calls.create({
    to,
    from,
    url: `${process.env.PUBLIC_URL.replace(/\/+$/, '')}/voice/incoming?${params.toString()}`,
    statusCallback: `${process.env.PUBLIC_URL.replace(/\/+$/, '')}/voice/status`,
    statusCallbackEvent: ['initiated', 'ringing', 'answered', 'completed'],
    record: true, // CMS-compliant
    recordingStatusCallback: `${process.env.PUBLIC_URL.replace(/\/+$/, '')}/voice/status`,
  });
  return { sid: call.sid, to, status: call.status };
}
