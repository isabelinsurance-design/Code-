// ============================================================
//  Medicare Operations Report — análisis profundo de Pilar
//  ──────────────────────────────────────────────────────
//  Tira en paralelo TODOS los queries de LUNA, ensambla un
//  briefing crudo MUY denso, y se lo pasa a Pilar (vía Athena
//  consultar_especialistas) para que sintetice como Medicare
//  COO sénior — no como tabla.
// ============================================================
import { runDirectora } from './directora.js';

const REPORT_FILE_NAME = 'medicare_report_today.json';

function fechaEs() {
  return new Date().toLocaleDateString('es-MX', {
    weekday: 'long', day: 'numeric', month: 'long',
    timeZone: process.env.TIMEZONE || 'America/Los_Angeles',
  });
}

async function gatherLunaSnapshot() {
  const snapshot = { date: fechaEs(), generated_at: new Date().toISOString() };

  const client = await import('./luna_client.js');
  if (!client.lunaConfigured()) {
    snapshot.error = 'LUNA no está configurado';
    return snapshot;
  }

  // Pulla todo en paralelo
  const [
    briefing, openTk, pendingSoa, hotLeads, t65, carriers,
    recent, retention, todayAppts,
  ] = await Promise.all([
    client.fullBriefing().catch((e) => ({ ok: false, error: e.message })),
    client.openTickets({ priority: '' }).catch((e) => ({ ok: false, error: e.message })),
    client.pendingSoa().catch((e) => ({ ok: false, error: e.message })),
    client.hotLeads().catch((e) => ({ ok: false, error: e.message })),
    client.t65Alerts({ days: 90 }).catch((e) => ({ ok: false, error: e.message })),
    client.carriersBreakdown().catch((e) => ({ ok: false, error: e.message })),
    client.recentActivity({ limit: 50 }).catch((e) => ({ ok: false, error: e.message })),
    client.retentionAlerts().catch((e) => ({ ok: false, error: e.message })),
    client.todayAppointments().catch((e) => ({ ok: false, error: e.message })),
  ]);

  snapshot.briefing = briefing;
  snapshot.tickets = openTk;
  snapshot.pending_soa = pendingSoa;
  snapshot.hot_leads = hotLeads;
  snapshot.t65 = t65;
  snapshot.carriers = carriers;
  snapshot.recent_activity = recent;
  snapshot.retention = retention;
  snapshot.today_appointments = todayAppts;
  return snapshot;
}

function summarizeForPilar(snap) {
  const out = [];

  // Tickets — análisis crudo
  if (snap.tickets?.ok && Array.isArray(snap.tickets.data)) {
    const list = snap.tickets.data;
    const byAgent = {};
    const byPrio = { ALTA: 0, MEDIA: 0, BAJA: 0 };
    const byState = {};
    for (const t of list) {
      const a = t.asignado_nombre || 'sin asignar';
      byAgent[a] = (byAgent[a] || 0) + 1;
      const p = (t.prioridad || 'MEDIA').toUpperCase();
      byPrio[p] = (byPrio[p] || 0) + 1;
      const s = (t.estado || 'ABIERTO').toUpperCase();
      byState[s] = (byState[s] || 0) + 1;
    }
    out.push(`TICKETS (total no cerrados ${list.length}):`);
    out.push(`Por agente: ${Object.entries(byAgent).map(([k, v]) => `${k} ${v}`).join(', ')}`);
    out.push(`Por estado: ${Object.entries(byState).map(([k, v]) => `${k} ${v}`).join(', ')}`);
    out.push(`Por prioridad: ALTA ${byPrio.ALTA}, MEDIA ${byPrio.MEDIA}, BAJA ${byPrio.BAJA}`);

    // ALTA stancados
    const now = Date.now();
    const altaSt = list.filter((t) =>
      (t.prioridad || '').toUpperCase() === 'ALTA' &&
      (t.estado || '').toUpperCase() === 'ABIERTO' &&
      t.fecha_creacion &&
      (now - new Date(t.fecha_creacion).getTime()) >= 3 * 86_400_000
    );
    if (altaSt.length) {
      out.push(`${altaSt.length} ALTA estancados ≥3d:`);
      altaSt.slice(0, 8).forEach((t) => {
        const owner = t.asignado_nombre || 'sin asignar';
        const days = Math.floor((now - new Date(t.fecha_creacion).getTime()) / 86_400_000);
        out.push(`  #${t.id} ${owner} ${days}d: ${(t.descripcion || '').slice(0, 100)}`);
      });
    }
    out.push('');
  }

  // SOAs pendientes
  if (snap.pending_soa?.ok && Array.isArray(snap.pending_soa.data)) {
    out.push(`SOAs PENDIENTES: ${snap.pending_soa.data.length}`);
    snap.pending_soa.data.slice(0, 6).forEach((m) => {
      out.push(`  ${m.nombre || m.miembro_nombre || 'sin nombre'} (${m.carrier || ''}, ${m.dias_pendiente || '?'}d)`);
    });
    out.push('');
  }

  // Hot leads
  if (snap.hot_leads?.ok && Array.isArray(snap.hot_leads.data)) {
    out.push(`HOT LEADS: ${snap.hot_leads.data.length}`);
    snap.hot_leads.data.slice(0, 6).forEach((m) => {
      out.push(`  ${m.nombre || ''} (${m.dias_sin_contacto || '?'}d sin contacto)`);
    });
    out.push('');
  }

  // T65
  if (snap.t65?.ok && Array.isArray(snap.t65.data)) {
    out.push(`T65 alertas (90d): ${snap.t65.data.length}`);
    snap.t65.data.slice(0, 5).forEach((m) => {
      out.push(`  ${m.nombre || ''} cumple 65 en ${m.dias_para_65 || '?'}d`);
    });
    out.push('');
  }

  // Carriers
  if (snap.carriers?.ok) {
    const c = snap.carriers.data || snap.carriers;
    if (c && typeof c === 'object') {
      out.push('MIEMBROS POR CARRIER:');
      Object.entries(c).sort((a, b) => b[1] - a[1]).forEach(([k, v]) => {
        out.push(`  ${k}: ${v}`);
      });
      out.push('');
    }
  }

  // Citas hoy
  if (snap.today_appointments?.ok && Array.isArray(snap.today_appointments.data)) {
    out.push(`CITAS HOY: ${snap.today_appointments.data.length}`);
    snap.today_appointments.data.slice(0, 8).forEach((c) => {
      out.push(`  ${c.fecha_hora?.slice(11, 16) || '?'} ${c.miembro_nombre || ''} (${c.tipo || ''})`);
    });
    out.push('');
  }

  // Briefing original (lo que sea que devuelva LUNA)
  if (snap.briefing?.ok && snap.briefing.data) {
    const b = snap.briefing.data;
    if (typeof b === 'object') {
      out.push('OTROS NUMEROS DEL BRIEFING:');
      for (const [k, v] of Object.entries(b)) {
        if (typeof v === 'number') out.push(`  ${k}: ${v}`);
        else if (Array.isArray(v)) out.push(`  ${k}: ${v.length} items`);
      }
    }
  }

  return out.join('\n');
}

export async function generateMedicareReport() {
  const snap = await gatherLunaSnapshot();
  if (snap.error) {
    return {
      ok: false,
      error: snap.error,
      generated_at: snap.generated_at,
    };
  }

  const dataDump = summarizeForPilar(snap);

  const prompt = `[REPORTE OPERACIONAL DE MEDICARE — solicitud directa de Isabel]

Estos son los DATOS CRUDOS de LUNA hoy ${snap.date}:

${dataDump}

TU JOB como Pilar Medicare:
Sintetiza este dump como una Chief of Operations Medicare sénior le presentaría a Isabel. NO como tabla. NO como lista de números. Como ENSAYO BREVE de 4-6 párrafos que cubra:

1) ESTADO GENERAL: ¿qué historia cuentan estos números? (1 párrafo)
2) DÓNDE ESTÁ EL RIESGO MÁS GRANDE hoy y por qué (1 párrafo concreto, nómbralo)
3) OPORTUNIDAD QUE NADIE ESTÁ VIENDO (1 párrafo — por ejemplo carriers infrautilizados, segmento creciendo, lead pattern)
4) RECOMENDACIONES CONCRETAS PARA LAS PRÓXIMAS 48H — 3 acciones específicas que Isabel debe tomar o delegar (no genérico)
5) UN PATRÓN/PROBLEMA SISTÉMICO que está pasando y nadie está atacando (1 párrafo)

Reglas de forma (CRÍTICAS):
- TEXTO PLANO. Cero markdown. Cero asteriscos. Cero tablas. Cero emojis decorativos.
- Frases completas. Saltos de línea entre párrafos.
- Nombres específicos cuando los tengas (no "un cliente", sino "Maritza Hernández")
- Cifras incrustadas en la prosa, no listadas
- Tono: Chief of Operations directa, cariñosa, sin azúcar. Como Sheryl Sandberg si fuera Medicare COO.
- MÁXIMO 500 palabras. Concentrado, no genérico.`;

  const messages = [{ role: 'user', content: prompt }];

  // Forzamos consulta a Pilar via consultar_especialistas
  const { reply, messages: updated } = await runDirectora(messages, {
    tier: 'deep',  // vale la pena Opus para análisis estratégico
    persistHistory: false,
  });

  return {
    ok: true,
    date: snap.date,
    generated_at: new Date().toISOString(),
    report: reply,
    raw_snapshot: snap,
    data_dump: dataDump,
  };
}
