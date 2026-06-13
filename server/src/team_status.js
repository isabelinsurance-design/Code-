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
