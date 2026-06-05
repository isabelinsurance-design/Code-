import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api.js';

// Mission Bar — barra de status fija arriba de cada pantalla.
// Se actualiza cada 30s. Muestra:
//   · estado de Athena (verde si activa)
//   · decisiones pendientes (clickeable → /decisiones)
//   · acciones autónomas hoy
//   · alertas (vencidos)
//   · pulse animado si Athena está trabajando ahora mismo

const REFRESH_MS = 30_000;

function timeAgo(iso) {
  if (!iso) return '';
  const sec = Math.floor((Date.now() - new Date(iso).getTime()) / 1000);
  if (sec < 60) return `${sec}s`;
  const min = Math.floor(sec / 60);
  if (min < 60) return `${min}m`;
  return `${Math.floor(min / 60)}h`;
}

function summarizeTool(tool) {
  const map = {
    morning_briefing: 'redactando briefing',
    closing_loop: 'cerrando el día',
    triage_inbox: 'triageando inbox',
    inbox_idle_react: 'reaccionando a email',
    ticket_monitor_alert: 'revisando tickets',
    commitment_chase: 'persiguiendo promesa',
    luna_crear_ticket: 'creando ticket LUNA',
    luna_buscar_miembro: 'buscando en LUNA',
    luna_expediente_miembro: 'leyendo expediente',
    enviar_email: 'redactando email',
    confirmar_envio: 'enviando',
    mensaje_a_sami: 'avisando a Sami',
    llamar_cliente: 'llamando cliente',
    template_usar: 'aplicando template',
    commitment_nudge: 'nudgeando promesa',
    consultar_especialistas: 'consultando equipo',
    web_search: 'investigando',
  };
  return map[tool] || tool.replace(/_/g, ' ');
}

export default function MissionBar() {
  const [status, setStatus] = useState(null);
  const [error, setError] = useState(false);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      try {
        const s = await api.commandStatus();
        if (!cancelled) { setStatus(s); setError(false); }
      } catch {
        if (!cancelled) setError(true);
      }
    }
    load();
    const id = setInterval(load, REFRESH_MS);
    return () => { cancelled = true; clearInterval(id); };
  }, []);

  if (error && !status) return null;
  if (!status) {
    return (
      <div className="bg-ink-1 text-lino-100 px-4 py-1.5 font-mono text-[10px] tracking-widest uppercase flex items-center gap-3">
        <span className="inline-block w-2 h-2 rounded-full bg-lino-400 animate-pulse"></span>
        <span>Athena · cargando</span>
      </div>
    );
  }

  const decisions = status.decisions_pending || 0;
  const decisionsHigh = status.decisions_high || 0;
  const autonomous = status.autonomous_today || 0;
  const alerts = status.alerts || 0;
  const currentActivity = status.current_activity;

  return (
    <div className="bg-ink-1 text-lino-100 px-4 py-2 font-mono text-[10px] tracking-widest uppercase flex items-center gap-4 overflow-x-auto whitespace-nowrap">
      {/* Estado */}
      <span className="inline-flex items-center gap-2 shrink-0">
        <span className={`inline-block w-2 h-2 rounded-full ${currentActivity ? 'bg-amber animate-pulse' : 'bg-green-400'}`}></span>
        <span>Athena</span>
      </span>

      <span className="text-lino-400">·</span>

      {/* Decisiones */}
      <Link to="/decisiones" className="inline-flex items-center gap-1.5 hover:text-amber shrink-0">
        <span className={decisionsHigh > 0 ? 'text-amber' : ''}>{decisions}</span>
        <span className={decisionsHigh > 0 ? 'text-amber' : 'text-lino-300'}>decisiones</span>
        {decisionsHigh > 0 && <span className="text-amber">●</span>}
      </Link>

      <span className="text-lino-400">·</span>

      {/* Autónomas */}
      <span className="inline-flex items-center gap-1.5 shrink-0">
        <span>{autonomous}</span>
        <span className="text-lino-300">autónomas hoy</span>
      </span>

      <span className="text-lino-400">·</span>

      {/* Alertas */}
      <Link to="/tareas" className={`inline-flex items-center gap-1.5 shrink-0 hover:text-amber ${alerts > 0 ? 'text-amber' : ''}`}>
        <span>{alerts}</span>
        <span className={alerts > 0 ? '' : 'text-lino-300'}>alertas</span>
      </Link>

      {/* Activity actual — animada */}
      {currentActivity && (
        <>
          <span className="text-lino-400">·</span>
          <span className="inline-flex items-center gap-1.5 text-amber animate-pulse shrink-0">
            <span>▸</span>
            <span>{summarizeTool(currentActivity.tool)}</span>
            <span className="text-lino-400 normal-case tracking-normal">({timeAgo(currentActivity.ts)})</span>
          </span>
        </>
      )}
    </div>
  );
}
