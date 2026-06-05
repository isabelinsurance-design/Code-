// ============================================================
//  LUNA tools — exclusivos de Pilar Medicare
//  ──────────────────────────────────────────
//  Estas 14 tools NO viven en el tool registry global de Athena.
//  Solo Pilar las recibe cuando es consultada via consultar_
//  especialistas. Athena la directora NO puede llamarlas
//  directamente — tiene que delegar a Pilar.
//
//  Filosofía: LUNA es un producto separado. Solo una "embajadora"
//  desde Athena (Pilar) puede hablar con ella.
//
//  Excepción documentada: voice.js llama a luna_client directo
//  para identificar al caller antes de iniciar conversación. Es
//  infraestructura (no capa conversacional) — la directora no
//  gana acceso a LUNA por eso.
// ============================================================
import {
  lunaConfigured,
  searchMember as lunaSearchMember,
  memberDetail as lunaMemberDetail,
  pipelineSummary as lunaPipelineSummary,
  fullBriefing as lunaFullBriefing,
  t65Alerts as lunaT65Alerts,
  retentionAlerts as lunaRetentionAlerts,
  hotLeads as lunaHotLeads,
  pendingSoa as lunaPendingSoa,
  openTickets as lunaOpenTickets,
  todayAppointments as lunaTodayAppointments,
  recentActivity as lunaRecentActivity,
  carriersBreakdown as lunaCarriersBreakdown,
  addMemberNote as lunaAddMemberNote,
  logActivityToLuna,
  createMember as lunaCreateMember,
  createTicket as lunaCreateTicket,
  createAppointment as lunaCreateAppointment,
  formatMemberCard,
} from './luna_client.js';

// Schemas en el formato que Anthropic SDK espera
export const LUNA_TOOL_DEFINITIONS = [
  {
    name: 'luna_buscar_miembro',
    description: 'Busca un miembro en LUNA (el CRM real del equipo) por nombre / apellido / teléfono / MBI. Devuelve la lista de matches con id, estado y carrier. ÚSALA cuando necesites confirmar quién es un cliente antes de hacer cualquier acción.',
    input_schema: {
      type: 'object',
      properties: { query: { type: 'string', description: 'Nombre, teléfono, o MBI a buscar.' } },
      required: ['query'],
    },
  },
  {
    name: 'luna_expediente_miembro',
    description: 'Trae el expediente COMPLETO de un miembro de LUNA: datos, pólizas, SOA, drug list, providers, touchpoints, tickets abiertos, citas. ÚSALA antes de aconsejar sobre cualquier cliente Medicare — sin esto estás dando consejo a ciegas.',
    input_schema: {
      type: 'object',
      properties: { miembro_id: { type: 'string', description: 'ID del miembro en LUNA.' } },
      required: ['miembro_id'],
    },
  },
  {
    name: 'luna_briefing_completo',
    description: 'Snapshot del día completo de LUNA: pipeline por estado, citas de hoy, hot leads sin contactar, T65 urgentes, retención del día, tickets ALTA, SOAs pendientes, callbacks. Una sola llamada que trae todo lo accionable.',
    input_schema: { type: 'object', properties: {}, required: [] },
  },
  {
    name: 'luna_pipeline_resumen',
    description: 'Conteo en vivo de miembros por estado en LUNA. Más ligero que briefing_completo.',
    input_schema: { type: 'object', properties: {}, required: [] },
  },
  {
    name: 'luna_t65_alertas',
    description: 'Miembros que cumplen 65 años en los próximos N días (default 90). Cada uno tiene ventana IEP que cierra y multas vitalicias si tarda.',
    input_schema: {
      type: 'object',
      properties: { dias: { type: 'integer', description: 'Ventana en días. Default 90.' } },
      required: [],
    },
  },
  {
    name: 'luna_hot_leads',
    description: 'Lista de HOT LEADs en LUNA — calificados, listos para que Isabel presente. Marca cuántos llevan días sin contacto.',
    input_schema: { type: 'object', properties: {}, required: [] },
  },
  {
    name: 'luna_compliance_pendiente',
    description: 'SOAs faltantes + retención del día + callbacks pendientes — huecos de compliance que el equipo tiene en LUNA en este momento.',
    input_schema: { type: 'object', properties: {}, required: [] },
  },
  {
    name: 'luna_actividad_reciente',
    description: 'Últimas N acciones en LUNA (notas, llamadas, cambios de estado, tickets) hechas por cualquier miembro del equipo.',
    input_schema: {
      type: 'object',
      properties: { limite: { type: 'integer', description: 'Cuántas. Default 20.' } },
      required: [],
    },
  },
  {
    name: 'luna_tickets_abiertos',
    description: 'Lista los tickets ABIERTOS de LUNA (los pendientes del equipo: Skarleth, Arlette, Samia). Opcionalmente filtra por prioridad (ALTA, MEDIA, BAJA). Úsala cuando Isabel pida "reporte de tickets", "qué tiene pendiente el equipo", "tickets abiertos", "qué falta por hacer en LUNA".',
    input_schema: {
      type: 'object',
      properties: {
        prioridad: { type: 'string', enum: ['', 'ALTA', 'MEDIA', 'BAJA'], description: 'Filtro opcional por prioridad. Vacío = todos.' },
      },
      required: [],
    },
  },
  {
    name: 'luna_citas_hoy',
    description: 'Lista las citas programadas para HOY en LUNA. Úsala cuando Isabel pregunte por agenda del día, citas pendientes, qué clientes vienen hoy.',
    input_schema: { type: 'object', properties: {}, required: [] },
  },
  {
    name: 'luna_carriers_breakdown',
    description: 'Conteo de miembros por carrier en LUNA (Anthem, SCAN, LA Care, Alignment, Humana, Molina, Health Net, UHC, Blue Shield).',
    input_schema: { type: 'object', properties: {}, required: [] },
  },
  {
    name: 'luna_agregar_nota',
    description: 'ESCRIBE una nota al expediente de un miembro en LUNA. Skarleth/Samia/Arlette lo ven en tiempo real desde su workspace.',
    input_schema: {
      type: 'object',
      properties: {
        miembro_id: { type: 'string', description: 'ID del miembro en LUNA.' },
        nota: { type: 'string', description: 'Contenido de la nota.' },
      },
      required: ['miembro_id', 'nota'],
    },
  },
  {
    name: 'luna_registrar_actividad',
    description: 'ESCRIBE una entrada al log de actividad en LUNA. Útil para registrar llamadas hechas, intentos de contacto, decisiones de Isabel.',
    input_schema: {
      type: 'object',
      properties: {
        tipo: { type: 'string', description: 'NOTA / LLAMADA / EMAIL / SMS / DECISION / OTRO. Default NOTA.' },
        descripcion: { type: 'string', description: 'Qué pasó.' },
        miembro_id: { type: 'string', description: 'Opcional. ID del miembro si la actividad es sobre alguien.' },
      },
      required: ['descripcion'],
    },
  },
  {
    name: 'luna_crear_miembro',
    description: 'ESCRIBE un nuevo miembro a LUNA. Default estado=PROSPECTO. Cuando Isabel agarre un lead en la calle/teléfono/evento y necesite que entre al CRM YA para que Skarleth haga seguimiento.',
    input_schema: {
      type: 'object',
      properties: {
        nombre: { type: 'string' },
        apellido: { type: 'string' },
        telefono: { type: 'string' },
        email: { type: 'string' },
        fecha_nacimiento: { type: 'string', description: 'YYYY-MM-DD' },
        estado: { type: 'string', description: 'Default PROSPECTO. Solo cámbialo si Isabel lo pide.' },
        ciudad: { type: 'string' },
        fuente: { type: 'string', description: 'De dónde salió el lead.' },
      },
      required: ['nombre'],
    },
  },
  {
    name: 'luna_crear_ticket',
    description: `ESCRIBE un ticket en LUNA — para delegarle algo a Skarleth, Arlette o Samia.

🚨 REGLA CRÍTICA — NUNCA inventes miembro_id:
- Si la tarea es SOBRE un cliente específico, OBLIGATORIAMENTE llama luna_buscar_miembro PRIMERO con el nombre del cliente.
- Usa el id REAL que devuelve esa búsqueda — NO inventes números (12345, 123, 1, etc.).
- Si la búsqueda devuelve 0 resultados, NO crees el ticket — pregúntale a Isabel cómo se llama el cliente exacto o si quizá cambió de nombre.
- Si la búsqueda devuelve VARIOS matches, pregunta cuál es antes de crear.
- Si el ticket NO es sobre ningún cliente específico (tarea interna del equipo: marketing, entrenamiento, proyecto), DEJA miembro_id vacío. Mejor sin id que con uno inventado.

🚨 REGLA — asignado_a también debe ser real:
- 7=Skarleth, 9=Arlette, 10=Samia, 6=Isabel — esos sí son válidos.
- Si Isabel dice "para alguien" sin nombre, pregúntale a quién específicamente.

Un ticket orfano (con miembro_id inexistente) NO aparece en el CRM del equipo. Es como no haberlo creado.`,
    input_schema: {
      type: 'object',
      properties: {
        tipo: {
          type: 'string',
          enum: [
            'FOLLOW UP', 'QUEJA', 'CAMBIO DE DOCTOR', 'CLIENTE', 'CITA',
            'APLICACION', 'SERVICIO AL CLIENTE', 'LLAMADA', 'LLAMADA PERDIDA',
            'CITA DENTAL', 'URGENTE', 'SOPORTE', 'TASK', 'MARKETING',
            'NEXTIVA', 'ENTRENAMIENTO', 'CRM', 'PROYECTO', 'OTRO',
          ],
          description: 'Tipo del ticket — DEBE ser uno de los 19 valores del ENUM en MySQL. Más usados: FOLLOW UP (seguimiento general, default si no hay tipo específico), LLAMADA (hacer llamada), LLAMADA PERDIDA (devolver llamada), CITA (agendar/preparar cita), CITA DENTAL (cita en dentista), APLICACION (proceso de enrollment Medicare), QUEJA (cliente molesto/problema), CAMBIO DE DOCTOR (cambiar doctor primario), URGENTE (cualquier cosa que NO espera), SERVICIO AL CLIENTE (preguntas generales), SOPORTE (problema técnico LUNA/Nextiva), TASK (tarea interna), MARKETING (campañas/contenido), NEXTIVA (cosa del sistema de llamadas), ENTRENAMIENTO (capacitar equipo), CRM (admin LUNA), PROYECTO (iniciativa larga), CLIENTE (info del cliente), OTRO (catch-all si nada aplica).',
        },
        prioridad: {
          type: 'string',
          enum: ['ALTA', 'MEDIA', 'BAJA'],
          description: 'Prioridad del ticket. Default MEDIA.',
        },
        descripcion: { type: 'string', description: 'Qué hay que hacer. Sé específico.' },
        miembro_id: {
          type: 'string',
          description: 'ID del cliente al que se refiere. DEBE venir de luna_buscar_miembro — NUNCA inventes este número. Si la tarea no es sobre ningún cliente específico, deja vacío.',
        },
        asignado_a: {
          type: 'string',
          description: 'User ID del responsable: 7=Skarleth, 9=Arlette, 10=Samia, 6=Isabel. Si no sabes a quién, pregunta antes de crear.',
        },
      },
      required: ['descripcion'],
    },
  },
  {
    name: 'luna_crear_cita',
    description: 'ESCRIBE una cita en la tabla citas de LUNA. NO es Google Calendar — esto es la agenda interna del equipo.',
    input_schema: {
      type: 'object',
      properties: {
        miembro_id: { type: 'string' },
        fecha: { type: 'string', description: 'YYYY-MM-DD' },
        hora: { type: 'string', description: 'HH:MM' },
        tipo: { type: 'string', description: 'CONSULTA / RENOVACION / AEP_REVIEW / etc.' },
        modalidad: { type: 'string', description: 'TELÉFONO / VIDEO / PRESENCIAL' },
      },
      required: ['miembro_id', 'fecha'],
    },
  },
];

// Dispatcher — Pilar llama esto durante la conversación
export async function runLunaTool(name, input = {}) {
  if (!lunaConfigured()) return 'LUNA no está configurado (LUNA_BASE_URL / LUNA_API_KEY).';
  try {
    switch (name) {
      case 'luna_buscar_miembro': {
        const r = await lunaSearchMember(input.query);
        if (!r.ok) return `LUNA: ${r.error || 'sin resultados'}`;
        const list = r.data || [];
        if (!list.length) return `Sin matches en LUNA para "${input.query}".`;
        return list
          .slice(0, 10)
          .map((m) => `• ${m.nombre || ''} ${m.apellido || ''} · id=${m.id} · ${m.estado || '?'}${m.carrier ? ` · ${m.carrier}` : ''}`)
          .join('\n');
      }
      case 'luna_expediente_miembro': {
        const r = await lunaMemberDetail(input.miembro_id);
        if (!r.ok) return `LUNA: ${r.error}`;
        const m = r.data?.miembro || r.data;
        if (!m) return 'Sin datos.';
        const lines = [formatMemberCard(m)];
        if (r.data?.polizas?.length) lines.push(`\nPólizas: ${r.data.polizas.length}`);
        if (r.data?.touchpoints?.length) lines.push(`Últimos touchpoints: ${r.data.touchpoints.length}`);
        if (r.data?.tickets_abiertos?.length) lines.push(`Tickets abiertos: ${r.data.tickets_abiertos.length}`);
        if (r.data?.citas_proximas?.length) lines.push(`Próximas citas: ${r.data.citas_proximas.length}`);
        return lines.join('\n');
      }
      case 'luna_briefing_completo': {
        const r = await lunaFullBriefing();
        if (!r.ok) return `LUNA: ${r.error}`;
        const d = r.data || {};
        const lines = [];
        if (d.estados) lines.push(`Pipeline: ${Object.entries(d.estados).map(([k, v]) => `${k}=${v}`).join(' · ')}`);
        if (d.hot_leads_frios?.length) lines.push(`🔥 ${d.hot_leads_frios.length} hot leads sin contacto`);
        if (d.t65_urgentes?.length) lines.push(`🎂 ${d.t65_urgentes.length} T65 urgentes`);
        if (d.retencion_hoy?.length) lines.push(`📞 ${d.retencion_hoy.length} retención HOY`);
        if (d.soa_pendiente) lines.push(`⚠️ ${d.soa_pendiente} SOAs faltantes`);
        if (d.tickets_urgentes?.length) lines.push(`🚨 ${d.tickets_urgentes.length} tickets ALTA`);
        if (d.callbacks) lines.push(`☎️ ${d.callbacks} callbacks pendientes`);
        if (d.citas_hoy?.length) lines.push(`📅 ${d.citas_hoy.length} citas hoy`);
        return lines.length ? lines.join('\n') : 'LUNA limpio — sin alertas activas.';
      }
      case 'luna_pipeline_resumen': {
        const r = await lunaPipelineSummary();
        if (!r.ok) return `LUNA: ${r.error}`;
        const d = r.data?.estados || r.data || {};
        const total = r.data?.total_miembros || Object.values(d).reduce((a, b) => a + (b || 0), 0);
        return `Pipeline (${total}):\n${Object.entries(d).map(([k, v]) => `  ${k}: ${v}`).join('\n')}`;
      }
      case 'luna_t65_alertas': {
        const r = await lunaT65Alerts({ days: parseInt(input.dias, 10) || 90 });
        if (!r.ok) return `LUNA: ${r.error}`;
        const list = r.data || [];
        if (!list.length) return 'Sin T65 en la ventana.';
        return `${list.length} T65:\n${list.slice(0, 10).map((m) => `  • ${m.nombre} ${m.apellido} — ${m.dias_para_65}d`).join('\n')}`;
      }
      case 'luna_hot_leads': {
        const r = await lunaHotLeads();
        if (!r.ok) return `LUNA: ${r.error}`;
        const list = r.data || [];
        if (!list.length) return 'Sin HOT LEADs.';
        return `${list.length} HOT LEADs:\n${list.slice(0, 10).map((m) => `  • ${m.nombre} ${m.apellido} · id=${m.id}${m.dias_sin_contacto ? ` · ${m.dias_sin_contacto}d sin contacto` : ''}`).join('\n')}`;
      }
      case 'luna_compliance_pendiente': {
        const [soa, ret] = await Promise.all([lunaPendingSoa(), lunaRetentionAlerts()]);
        const lines = [];
        if (soa.ok) lines.push(`SOAs faltantes: ${(soa.data || []).length}`);
        if (ret.ok) lines.push(`Retención hoy: ${(ret.data || []).length}`);
        return lines.length ? lines.join('\n') : `LUNA: ${soa.error || ret.error || 'sin datos'}`;
      }
      case 'luna_actividad_reciente': {
        const r = await lunaRecentActivity({ limit: parseInt(input.limite, 10) || 20 });
        if (!r.ok) return `LUNA: ${r.error}`;
        const list = r.data || [];
        if (!list.length) return 'Sin actividad reciente.';
        return list.slice(0, 15).map((a) => `${(a.fecha || '').slice(11, 16)} ${a.usuario || '?'} · ${a.tipo || ''} · ${(a.descripcion || '').slice(0, 70)}`).join('\n');
      }
      case 'luna_carriers_breakdown': {
        const r = await lunaCarriersBreakdown();
        if (!r.ok) return `LUNA: ${r.error}`;
        const d = r.data || {};
        return `Por carrier:\n${Object.entries(d).sort((a, b) => b[1] - a[1]).map(([k, v]) => `  ${k}: ${v}`).join('\n')}`;
      }
      case 'luna_tickets_abiertos': {
        const prioridad = (input.prioridad || '').toUpperCase();
        const r = await lunaOpenTickets({ priority: prioridad });
        if (!r.ok) return `LUNA: ${r.error}`;
        const list = r.data || [];
        if (!list.length) return prioridad
          ? `Sin tickets abiertos de prioridad ${prioridad}.`
          : 'Sin tickets abiertos en LUNA.';
        const header = `${list.length} ticket${list.length === 1 ? '' : 's'} abierto${list.length === 1 ? '' : 's'}${prioridad ? ` (${prioridad})` : ''}:`;
        const rows = list.slice(0, 30).map((t) => {
          const id = t.id || '?';
          const tipo = t.tipo || '?';
          const prio = t.prioridad ? ` [${t.prioridad}]` : '';
          const asignado = t.asignado_a || t.asignado_nombre || '—';
          const cliente = t.miembro_nombre || (t.miembro_id ? `#${t.miembro_id}` : 'sin cliente');
          const desc = (t.descripcion || t.titulo || '').slice(0, 60);
          return `#${id}${prio} · ${tipo} · ${cliente} · → ${asignado} · ${desc}`;
        }).join('\n');
        return `${header}\n${rows}`;
      }
      case 'luna_citas_hoy': {
        const r = await lunaTodayAppointments();
        if (!r.ok) return `LUNA: ${r.error}`;
        const list = r.data || [];
        if (!list.length) return 'Sin citas en LUNA hoy.';
        return `${list.length} cita${list.length === 1 ? '' : 's'} hoy:\n${list.slice(0, 20).map((c) => {
          const hora = (c.fecha_hora || c.hora || '').slice(11, 16);
          const cliente = c.miembro_nombre || `#${c.miembro_id || '?'}`;
          const tipo = c.tipo || '';
          const lugar = c.lugar || c.modalidad || '';
          return `${hora} · ${cliente} · ${tipo}${lugar ? ` · ${lugar}` : ''}`;
        }).join('\n')}`;
      }
      case 'luna_agregar_nota': {
        const r = await lunaAddMemberNote(input.miembro_id, input.nota);
        if (!r.ok) return `No pude escribir la nota en LUNA: ${r.error}`;
        return `Nota agregada al expediente ${input.miembro_id} en LUNA. El equipo lo ve en tiempo real.`;
      }
      case 'luna_registrar_actividad': {
        const r = await logActivityToLuna({
          tipo: input.tipo || 'NOTA',
          descripcion: input.descripcion,
          memberId: input.miembro_id,
        });
        if (!r.ok) return `No pude registrar actividad en LUNA: ${r.error}`;
        return `Actividad registrada en LUNA${input.miembro_id ? ` (miembro ${input.miembro_id})` : ''}.`;
      }
      case 'luna_crear_miembro': {
        const r = await lunaCreateMember(input);
        if (!r.ok) return `No pude crear el miembro en LUNA: ${r.error}`;
        return `Miembro creado en LUNA: ${input.nombre} ${input.apellido || ''} (${input.estado || 'PROSPECTO'}) · id=${r.data?.id || '?'}. Skarleth lo verá en su workspace.`;
      }
      case 'luna_crear_ticket': {
        const r = await lunaCreateTicket(input);
        if (!r.ok) return `No pude crear el ticket en LUNA: ${r.error}`;
        const asignado = { '6': 'Isabel', '7': 'Skarleth', '9': 'Arlette', '10': 'Samia' }[String(input.asignado_a)] || 'sin asignar';
        // Auto-grouping
        let autoStr = '';
        try {
          const { autoGroupItem } = await import('./project_classifier.js');
          const ag = await autoGroupItem({
            kind: 'ticket_luna',
            itemId: r.data?.id,
            title: input.titulo || input.descripcion || 'ticket LUNA',
            description: input.descripcion || '',
            context: input.miembro_nombre ? `Cliente: ${input.miembro_nombre}` : '',
          });
          if (ag.auto_grouped) autoStr = ` · vinculado a "${ag.project_nombre}".`;
        } catch { /* ignore */ }
        return `Ticket ${input.tipo || 'SEGUIMIENTO'}/${input.prioridad || 'MEDIA'} creado en LUNA · asignado a ${asignado} · id=${r.data?.id || '?'}.${autoStr}`;
      }
      case 'luna_crear_cita': {
        const r = await lunaCreateAppointment(input);
        if (!r.ok) return `No pude crear la cita en LUNA: ${r.error}`;
        return `Cita creada en LUNA (miembro ${input.miembro_id}, ${input.fecha}${input.hora ? ` ${input.hora}` : ''}).`;
      }
      default:
        return `LUNA tool desconocida: ${name}`;
    }
  } catch (err) {
    return `Error ejecutando ${name}: ${err.message}`;
  }
}
