// ============================================================
//  Closing the Loop — EOD diario (Elite EA SOP pattern)
//  ────────────────────────────────────────────────────────────
//  El SOP del Elite Entrepreneur Coaching dice que cada EOD
//  el admin compila una "Closing the Loop" — lista de lo que se
//  cerró ese día. Es ritual de cierre + transparencia + claridad
//  de progreso.
//
//  Para Athena: cron 6pm-7pm (después del día laboral, antes
//  del evening check-in de las 9pm). Compila desde activity log:
//    - Tools ejecutados con éxito
//    - Drafts enviados (post-confirmación)
//    - Tareas completadas
//    - Llamadas hechas
//    - Citas creadas
//    - Decisiones documentadas
//    - Compromisos cumplidos
//    - Skills invocados
//
//  Manda 1 card en WhatsApp + push notif. No es brief evening
//  (eso pregunta wins). Esto REPORTA — lo que YA pasó.
// ============================================================
import { getActivity } from './memory.js';

// Tools que cuentan como "loop cerrado" cuando se ejecutan exitosamente
const CLOSING_TOOLS = new Set([
  'enviar_email', 'confirmar_envio',          // emails enviados
  'enviar_sms', 'mensaje_a_sami',              // mensajes
  'crear_cita', 'reagendar_cita', 'cancelar_cita', // calendario
  'llamar_cliente',                            // llamadas
  'completar_tarea',                           // tareas
  'marcar_cumplido',                           // compromisos cumplidos
  'skill_invocar',                             // skills ejecutados
  'recordar', 'entidad_anotar',                // memoria capturada
  'comprometer_entrega',                       // promesas recibidas
  // LUNA writes (vía maria especialista)
  'luna_agregar_nota', 'luna_registrar_actividad',
  'luna_crear_miembro', 'luna_crear_ticket', 'luna_crear_cita',
  // Brand
  'brand_idea_add', 'brand_calendar_add', 'brand_post_registrar',
  // Otros relevantes
  'crear_rutina', 'registrar_obligacion_legal', 'cumpli_obligacion',
]);

// Mapea cada tool a su label humano y emoji
const TOOL_LABELS = {
  'enviar_email': { icon: '📧', label: 'Email mandado' },
  'confirmar_envio': { icon: '✉️', label: 'Borrador enviado' },
  'enviar_sms': { icon: '💬', label: 'SMS enviado' },
  'mensaje_a_sami': { icon: '👋', label: 'Mensaje a Sami' },
  'crear_cita': { icon: '📅', label: 'Cita creada' },
  'reagendar_cita': { icon: '🔄', label: 'Cita movida' },
  'cancelar_cita': { icon: '❌', label: 'Cita cancelada' },
  'llamar_cliente': { icon: '📞', label: 'Llamada' },
  'completar_tarea': { icon: '✓', label: 'Tarea completada' },
  'marcar_cumplido': { icon: '✓✓', label: 'Compromiso cumplido' },
  'skill_invocar': { icon: '⚙️', label: 'Skill ejecutada' },
  'recordar': { icon: '📝', label: 'Nota capturada' },
  'entidad_anotar': { icon: '👤', label: 'Persona anotada' },
  'comprometer_entrega': { icon: '🤝', label: 'Promesa recibida' },
  'luna_agregar_nota': { icon: '📌', label: 'Nota LUNA' },
  'luna_registrar_actividad': { icon: '📋', label: 'Actividad LUNA' },
  'luna_crear_miembro': { icon: '➕', label: 'Cliente Medicare nuevo' },
  'luna_crear_ticket': { icon: '🎫', label: 'Ticket equipo' },
  'luna_crear_cita': { icon: '🗓️', label: 'Cita LUNA' },
  'brand_idea_add': { icon: '💡', label: 'Idea brand' },
  'brand_calendar_add': { icon: '📺', label: 'Pub agendada' },
  'brand_post_registrar': { icon: '🎬', label: 'Post registrado' },
  'crear_rutina': { icon: '🔁', label: 'Rutina nueva' },
  'registrar_obligacion_legal': { icon: '⚖️', label: 'Legal agregado' },
  'cumpli_obligacion': { icon: '⚖️✓', label: 'Legal cumplido' },
};

// Computa el closing-the-loop de HOY (en TZ de Isabel).
export function computeClosingLoop() {
  const TZ = process.env.TIMEZONE || 'America/Los_Angeles';
  const todayLocal = new Intl.DateTimeFormat('en-CA', {
    timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
  }).format(new Date()); // formato YYYY-MM-DD

  const all = getActivity() || [];
  const todays = all.filter((a) => {
    if (!CLOSING_TOOLS.has(a.tool)) return false;
    const ts = a.ts || a.timestamp || a.creado;
    if (!ts) return false;
    const localDate = new Intl.DateTimeFormat('en-CA', {
      timeZone: TZ, year: 'numeric', month: '2-digit', day: '2-digit',
    }).format(new Date(ts));
    return localDate === todayLocal;
  });

  // Agrupa por tool name
  const byTool = {};
  for (const a of todays) {
    if (!byTool[a.tool]) byTool[a.tool] = [];
    byTool[a.tool].push(a);
  }

  return {
    fecha: todayLocal,
    total: todays.length,
    por_tool: byTool,
    raw: todays,
  };
}

// Construye el mensaje WhatsApp para Isabel
export function buildClosingLoopMessage() {
  const loop = computeClosingLoop();
  if (loop.total === 0) {
    return null; // si nada que reportar, no mandar
  }

  const fecha = new Date().toLocaleDateString('es-MX', {
    weekday: 'long', day: 'numeric', month: 'long',
    timeZone: process.env.TIMEZONE || 'America/Los_Angeles',
  });

  const lines = [`🌅 Closing the Loop — ${fecha}`, ''];
  lines.push(`Cerramos ${loop.total} acción${loop.total !== 1 ? 'es' : ''} hoy:`);
  lines.push('');

  // Orden por importancia: outbound primero, después memory, después brand/legal
  const orderPriority = [
    'enviar_email', 'confirmar_envio', 'enviar_sms', 'mensaje_a_sami', 'llamar_cliente',
    'crear_cita', 'reagendar_cita', 'cancelar_cita',
    'luna_crear_miembro', 'luna_crear_ticket', 'luna_crear_cita',
    'luna_agregar_nota', 'luna_registrar_actividad',
    'completar_tarea', 'marcar_cumplido',
    'comprometer_entrega',
    'skill_invocar', 'crear_rutina',
    'brand_idea_add', 'brand_calendar_add', 'brand_post_registrar',
    'registrar_obligacion_legal', 'cumpli_obligacion',
    'entidad_anotar', 'recordar',
  ];

  for (const tool of orderPriority) {
    const entries = loop.por_tool[tool];
    if (!entries || !entries.length) continue;
    const meta = TOOL_LABELS[tool] || { icon: '•', label: tool };
    lines.push(`${meta.icon} ${meta.label} × ${entries.length}`);
  }

  lines.push('');
  lines.push('Mañana: revisamos en el briefing 6:30am.');

  return lines.join('\n');
}

// Para el cron — manda el mensaje si hay algo que reportar
export async function sendClosingLoop() {
  const { canSendProactive } = await import('./proactive.js');
  const { sendMessage } = await import('./whatsapp.js');
  const { bumpProactiveCount } = await import('./memory.js');

  const to = process.env.ISABEL_WHATSAPP;
  if (!to) {
    console.warn('[closing_loop] No hay ISABEL_WHATSAPP configurado.');
    return;
  }

  const gate = canSendProactive({ force: false });
  if (!gate.ok) {
    console.log(`[closing_loop] saltado: ${gate.reason}`);
    return;
  }

  const message = buildClosingLoopMessage();
  if (!message) {
    console.log('[closing_loop] nada que reportar hoy — saltado.');
    return;
  }

  bumpProactiveCount(gate.dayKey);
  await sendMessage(to, message);

  // Push notif también si está configurado
  try {
    const { sendToAll, pushEnabled } = await import('./push.js');
    if (pushEnabled()) {
      const loop = computeClosingLoop();
      await sendToAll({
        title: 'Closing the Loop',
        body: `${loop.total} acciones cerradas hoy`,
        url: '/app/actividad',
        tag: 'closing',
      });
    }
  } catch (e) { console.warn('[closing_loop] push falló:', e.message); }

  console.log(`[closing_loop] enviado (${computeClosingLoop().total} acciones).`);
}
