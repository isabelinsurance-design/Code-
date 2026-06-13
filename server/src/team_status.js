// ============================================================
//  team_status.js — ¿un miembro del equipo está de licencia?
//  ──────────────────────────────────────────────────────────
//  Para pausar a alguien temporalmente (cirugía, vacaciones, baja)
//  sin sacarlo del sistema. Mientras está de licencia, Athena no le
//  manda emails/tareas; cuando pasa la fecha, se reactiva SOLA.
//
//  Se controla con una variable de entorno por persona:
//     <NOMBRE>_ON_LEAVE_UNTIL=YYYY-MM-DD
//  Ej:  SAMI_ON_LEAVE_UNTIL=2026-07-13
//  (En Railway → Variables. Borrar la variable también la reactiva.)
// ============================================================

function leaveKey(nombre) {
  return `${String(nombre || '').trim().toUpperCase()}_ON_LEAVE_UNTIL`;
}

// ¿Está de licencia AHORA? true solo si hay fecha válida y aún no pasa.
export function isOnLeave(nombre, env = process.env, now = new Date()) {
  const until = env[leaveKey(nombre)];
  if (!until) return false;
  const d = new Date(until);
  if (Number.isNaN(d.getTime())) return false; // fecha mal escrita → no la tratamos como licencia
  return now < d;
}

// Fecha hasta la que está de licencia (para mensajes), o null.
export function leaveUntil(nombre, env = process.env) {
  return env[leaveKey(nombre)] || null;
}

// Opción A: lo que iría a un miembro de licencia REBOTA a Isabel con nota,
// para que nada se quede colgado. Devuelve a quién va y la nota a agregar.
// (El responsable de tareas 'sami' mapea al nombre de roster 'Sami'.)
export function reassignIfOnLeave(responsable, env = process.env, now = new Date()) {
  if (responsable === 'sami' && isOnLeave('Sami', env, now)) {
    const hasta = leaveUntil('Sami', env);
    return {
      responsable: 'isabel',
      reasignado_de: 'sami',
      note: `↪️ Era para Sami (de licencia${hasta ? ` hasta ${hasta}` : ''}). Decide: hazlo tú, espera, o pásalo a Skarleth/Arlette.`,
    };
  }
  return { responsable, reasignado_de: null, note: null };
}
