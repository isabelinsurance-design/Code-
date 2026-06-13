// ============================================================
//  config_check.js — autodiagnóstico de configuración al arrancar
//  ────────────────────────────────────────────────────────────
//  Para una dueña sin equipo técnico: Athena dice ella misma, al boot,
//  qué integraciones están configuradas y cuáles le faltan. Así Isabel
//  ve "qué está roto" en los logs sin necesitar a nadie que lea código.
//  No prueba conectividad viva (eso es más caro) — solo checa que las
//  variables de entorno existan.
// ============================================================

// Lógica pura/testeable: dado un env, devuelve el estado de cada pieza.
// critical=true → si falta, algo importante NO funciona.
export function checkConfig(env = process.env) {
  const has = (...keys) => keys.every((k) => Boolean(env[k] && String(env[k]).trim()));
  const checks = [
    { name: 'Cerebro (Anthropic)', critical: true, ok: has('ANTHROPIC_API_KEY'),
      note: 'Sin esto Athena no piensa ni contesta.' },
    { name: 'Voz/transcripción (OpenAI)', critical: false, ok: has('OPENAI_API_KEY'),
      note: 'Notas de voz y TTS.' },
    { name: 'WhatsApp (Twilio)', critical: true, ok: has('TWILIO_ACCOUNT_SID', 'TWILIO_AUTH_TOKEN', 'TWILIO_WHATSAPP_FROM'),
      note: 'Sin esto Athena no manda ni recibe WhatsApp.' },
    { name: 'URL pública (PUBLIC_URL)', critical: true, ok: has('PUBLIC_URL'),
      note: 'Twilio necesita esta URL para webhooks, voz y audio.' },
    { name: 'WhatsApp de Isabel', critical: true, ok: has('ISABEL_WHATSAPP'),
      note: 'A dónde Athena te escribe.' },
    { name: 'Email (Gmail)', critical: false, ok: has('GMAIL_USER', 'GMAIL_APP_PASSWORD'),
      note: 'Triage de inbox y mandar correos.' },
    { name: 'Bridge a LUNA (CRM)', critical: false, ok: has('LUNA_BASE_URL', 'LUNA_API_KEY'),
      note: 'Datos del equipo Medicare.' },
    { name: 'Backups a R2 (red de seguridad)', critical: false, ok: has('BACKUP_S3_BUCKET', 'BACKUP_S3_ACCESS_KEY_ID'),
      note: 'Sin esto NO hay restore automático de la memoria.' },
    { name: 'Calendario (Google)', critical: false, ok: has('GOOGLE_CALENDAR_CLIENT_ID', 'GOOGLE_CALENDAR_REFRESH_TOKEN'),
      note: 'Crear/ver citas.' },
    { name: 'Secreto de sesión (APP_SECRET)', critical: false, ok: has('APP_SECRET') || has('SESSION_SECRET'),
      note: 'Si falta, la sesión del PWA se cierra en cada deploy.' },
    { name: 'Firma de Twilio en prod', critical: false, ok: env.TWILIO_REQUIRE_SIGNATURE !== 'false',
      note: 'Debe estar ON en producción.' },
  ];
  const missingCritical = checks.filter((c) => c.critical && !c.ok);
  return { checks, missingCritical, allCriticalOk: missingCritical.length === 0 };
}

// Lo que llama el arranque: imprime un resumen claro y grita lo crítico que falte.
export function logConfigStatus(env = process.env) {
  const { checks, missingCritical } = checkConfig(env);
  const lines = checks.map((c) => {
    const mark = c.ok ? '✅' : (c.critical ? '🛑' : '⚪');
    return `   ${mark} ${c.name}${c.ok ? '' : ` — FALTA: ${c.note}`}`;
  });
  console.log('[config] Estado de configuración:\n' + lines.join('\n'));
  if (missingCritical.length) {
    console.warn(`[config] ⚠️ FALTAN ${missingCritical.length} cosa(s) CRÍTICA(s): ${missingCritical.map((c) => c.name).join(', ')}. Athena no funcionará completa hasta configurarlas en Railway → Variables.`);
  }
  return { checks, missingCritical };
}
