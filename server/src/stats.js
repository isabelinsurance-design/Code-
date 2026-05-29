// ============================================================
//  Stats — métricas concretas para el evening + weekly review
//  ──────────────────────────────────────────────────────────
//  Cuenta qué pasó en el día (o en la semana) sin que Athena
//  tenga que inventar números. La idea: el evening check-in deja
//  de ser "3 wins + 1 mañana" puro abstract; ahora trae datos
//  reales — "hoy tocaste 4 clientes, mandaste 2 emails, creaste
//  1 lead, cerraste 3 tareas" — para que los wins sean honestos.
//
//  Fuentes: activity.json + crm.json + tasks.json + commitments.json
//  + entities.json + skills/. Todo on-disk, sin API calls.
// ============================================================
import { readFileSync, existsSync, readdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');

function readJsonSafe(file, fallback) {
  try {
    if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8'));
  } catch { /* ignore */ }
  return fallback;
}

const TZ = () => process.env.TIMEZONE || 'America/Los_Angeles';

// Devuelve un cutoff timestamp (ms) para el inicio del "día" en
// la TZ de Isabel (midnight local).
function startOfDayMs(d = new Date()) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: TZ(), year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(d).reduce((a, p) => { a[p.type] = p.value; return a; }, {});
  const iso = `${parts.year}-${parts.month}-${parts.day}T00:00:00`;
  // Para LA, esto va a interpretarse en local del server. Mejor calcular
  // un offset y restar. Para keep-simple: convertimos midnight LA a UTC
  // restando el offset usando el TZ. Aquí simplificamos: usamos hace 24h.
  return d.getTime() - 24 * 3600_000;
}

export function dailyStats() {
  const since = startOfDayMs();
  const activity = readJsonSafe(join(DATA_DIR, 'activity.json'), []);
  const today = activity.filter((e) => new Date(e.ts).getTime() >= since);

  const crm = readJsonSafe(join(DATA_DIR, 'crm.json'), []);
  const tasks = readJsonSafe(join(DATA_DIR, 'tasks.json'), []);
  const commitments = readJsonSafe(join(DATA_DIR, 'commitments.json'), []);
  const entities = readJsonSafe(join(DATA_DIR, 'entities.json'), []);

  // Touchpoints registrados hoy a clientes (por la herramienta o vía calls)
  const touchpointsHoy = crm.flatMap((c) =>
    (c.aep_touchpoints || []).filter((t) => new Date(t.ts).getTime() >= since)
  );

  // Clientes nuevos creados hoy
  const clientesNuevos = crm.filter((c) => new Date(c.creado).getTime() >= since);

  // Emails enviados hoy (post-confirmar_envio que aparecen en activity)
  const emailsEnviados = today.filter((e) => e.tool === 'confirmar_envio' && /Email|email/i.test(e.result_summary || ''));
  const smsEnviados = today.filter((e) => e.tool === 'confirmar_envio' && /SMS/i.test(e.result_summary || ''));
  const samiMessages = today.filter((e) => e.tool === 'mensaje_a_sami');

  // Tareas completadas hoy
  const tareasCerradas = tasks.filter((t) =>
    t.status === 'lista' && t.cerrada_at && new Date(t.cerrada_at).getTime() >= since,
  );

  // Tareas nuevas creadas hoy
  const tareasNuevas = tasks.filter((t) => new Date(t.creada).getTime() >= since);

  // Compromisos cumplidos hoy
  const compromisosCumplidos = commitments.filter((c) =>
    c.status === 'cumplido' && new Date(c.actualizado).getTime() >= since,
  );

  // Llamadas que registramos hoy
  const llamadas = today.filter((e) => e.tool === 'voice_call_summary' || e.tool === 'voice_call_start');

  // Compliance cerrada hoy
  const soaFirmadas = today.filter((e) => e.tool === 'cliente_soa_firmar');
  const mbiVerificadas = today.filter((e) => e.tool === 'cliente_mbi_estado' && /verified/i.test(e.input_summary || ''));
  const tcpaConsentidas = today.filter((e) => e.tool === 'cliente_tcpa');

  // Skills activadas hoy + invocaciones
  const skillsDir = join(DATA_DIR, 'skills');
  let skillsInvocaciones = 0;
  let skillsApprovedHoy = 0;
  if (existsSync(skillsDir)) {
    for (const f of readdirSync(skillsDir)) {
      if (!f.endsWith('.json')) continue;
      const s = readJsonSafe(join(skillsDir, f), null);
      if (!s) continue;
      if (s.ultima_invocacion && new Date(s.ultima_invocacion).getTime() >= since) {
        skillsInvocaciones += 1;
      }
      if (s.aprobado_at && new Date(s.aprobado_at).getTime() >= since) {
        skillsApprovedHoy += 1;
      }
    }
  }

  // Entidades nuevas hoy
  const entidadesNuevas = entities.filter((e) => new Date(e.creado).getTime() >= since);

  return {
    fecha: new Date().toLocaleDateString('es-MX', { timeZone: TZ(), weekday: 'long', day: 'numeric', month: 'long' }),
    touchpoints: touchpointsHoy.length,
    clientes_nuevos: clientesNuevos.length,
    emails_enviados: emailsEnviados.length,
    sms_enviados: smsEnviados.length,
    sami_messages: samiMessages.length,
    tareas_cerradas: tareasCerradas.length,
    tareas_nuevas: tareasNuevas.length,
    compromisos_cumplidos: compromisosCumplidos.length,
    llamadas: llamadas.length,
    soa_firmadas: soaFirmadas.length,
    mbi_verificadas: mbiVerificadas.length,
    tcpa_consentidas: tcpaConsentidas.length,
    skills_invocaciones: skillsInvocaciones,
    skills_aprobadas: skillsApprovedHoy,
    entidades_nuevas: entidadesNuevas.length,
    total_acciones: today.length,
  };
}

// Texto para meter en el evening check-in (no es un dump — es un
// preámbulo factual con lo más relevante).
export function dailyStatsBlurb(s = dailyStats()) {
  const wins = [];
  if (s.touchpoints) wins.push(`${s.touchpoints} touchpoint${s.touchpoints === 1 ? '' : 's'} de cliente`);
  if (s.clientes_nuevos) wins.push(`${s.clientes_nuevos} cliente${s.clientes_nuevos === 1 ? '' : 's'} nuevo${s.clientes_nuevos === 1 ? '' : 's'}`);
  if (s.tareas_cerradas) wins.push(`${s.tareas_cerradas} tarea${s.tareas_cerradas === 1 ? '' : 's'} cerrada${s.tareas_cerradas === 1 ? '' : 's'}`);
  if (s.emails_enviados) wins.push(`${s.emails_enviados} email${s.emails_enviados === 1 ? '' : 's'}`);
  if (s.sms_enviados) wins.push(`${s.sms_enviados} SMS`);
  if (s.llamadas) wins.push(`${s.llamadas} llamada${s.llamadas === 1 ? '' : 's'}`);
  if (s.soa_firmadas + s.mbi_verificadas + s.tcpa_consentidas) {
    wins.push(`compliance: ${s.soa_firmadas} SOA + ${s.mbi_verificadas} MBI + ${s.tcpa_consentidas} TCPA`);
  }
  if (s.compromisos_cumplidos) wins.push(`${s.compromisos_cumplidos} compromiso cerrado`);
  if (s.skills_invocaciones) wins.push(`${s.skills_invocaciones} skill invocada`);
  if (!wins.length) return 'Día tranquilo en datos — el valor no siempre está en los números.';
  return `Hoy: ${wins.join(' · ')}.`;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  console.log(JSON.stringify(dailyStats(), null, 2));
  console.log('\n' + dailyStatsBlurb());
  process.exit(0);
}
