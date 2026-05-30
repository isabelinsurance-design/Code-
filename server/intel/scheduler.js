// SCHEDULER EN-PROCESO  (Playbook patron #21 — autonomia)
//
// El latido de SAMIA: corre trabajos a sus horas sin que nadie los dispare.
//   - 02:00  reflexion nocturna (consolida memoria, recomputa señales)
//   - 06:30  briefing matutino (lo deja listo para cuando abre el equipo)
//   - lun 07:00  repaso semanal
//   - cada hora :00  "task tick" (revisa compromisos vencidos, refresca señales)
//
// HONESTIDAD SOBRE EL ENTORNO: esto solo corre mientras el proceso del servidor
// esta vivo. En un entorno efimero (sandbox) los crons NO siguen tras cerrar la
// sesion. Para que disparen de verdad a las 6:30am hay que desplegar en algo
// always-on (Railway, etc.). Aqui construimos la maquinaria y se verifica
// disparando los trabajos a mano.
//
// CATCH-UP: si el server estuvo caido y se perdio una hora programada, al
// arrancar (o en el siguiente tick) el trabajo corre UNA vez. Guardamos lastRun
// por trabajo en data/scheduler.json para no duplicar ni saltar.

import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import { DATA_DIR } from '../config.js';
import { runReflection } from './reflection.js';
import { generateBriefing } from './briefing.js';
import { reviewCommitments } from './commitments.js';
import { refreshSignals } from './signals.js';

const FILE = resolve(DATA_DIR, 'scheduler.json');
const nowIso = () => new Date().toISOString();

function readState() {
  try {
    return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch {
    return { lastRun: {}, log: [] };
  }
}
function writeState(s) {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
  writeFileSync(FILE, JSON.stringify(s, null, 1));
}

// --- REGISTRO DE TRABAJOS ---
// schedule: {type:'daily',hour,minute} | {type:'weekly',dow,hour,minute} | {type:'hourly',minute}
export const JOBS = [
  { name: 'nightly-reflection', schedule: { type: 'daily', hour: 2, minute: 0 }, run: (now) => runReflection(now) },
  { name: 'morning-briefing', schedule: { type: 'daily', hour: 6, minute: 30 }, run: (now) => generateBriefing(now) },
  { name: 'weekly-review', schedule: { type: 'weekly', dow: 1, hour: 7, minute: 0 }, run: (now) => runReflection(now) },
  { name: 'task-tick', schedule: { type: 'hourly', minute: 0 }, run: (now) => taskTick(now) },
];

// El task tick: mantenimiento ligero cada hora.
function taskTick(now) {
  const { overdue, due } = reviewCommitments(now);
  const signals = refreshSignals(now);
  return { overdue: overdue.length, dueToday: due.length, signals: signals.length };
}

// Ocurrencia programada mas reciente <= now (para detectar si toca correr).
export function lastScheduledOccurrence(schedule, now) {
  const d = new Date(now);
  d.setSeconds(0, 0);
  if (schedule.type === 'hourly') {
    const o = new Date(d);
    o.setMinutes(schedule.minute || 0);
    if (o > now) o.setHours(o.getHours() - 1);
    return o;
  }
  if (schedule.type === 'daily') {
    const o = new Date(d);
    o.setHours(schedule.hour, schedule.minute, 0, 0);
    if (o > now) o.setDate(o.getDate() - 1);
    return o;
  }
  if (schedule.type === 'weekly') {
    const o = new Date(d);
    o.setHours(schedule.hour, schedule.minute, 0, 0);
    let back = (o.getDay() - schedule.dow + 7) % 7;
    o.setDate(o.getDate() - back);
    if (o > now) o.setDate(o.getDate() - 7);
    return o;
  }
  return null;
}

// Corre los trabajos pendientes una vez. Devuelve los que se ejecutaron.
// `force` corre todos sin importar lastRun (para /api/intel/run-jobs en pruebas).
export async function tick(now = new Date(), { force = false, only = null } = {}) {
  const state = readState();
  const ran = [];
  for (const job of JOBS) {
    if (only && job.name !== only) continue;
    const occ = lastScheduledOccurrence(job.schedule, now);
    if (!occ) continue;
    const last = state.lastRun[job.name] ? new Date(state.lastRun[job.name]) : null;
    const due = force || !last || last < occ;
    if (!due) continue;
    let result = null;
    let error = null;
    try {
      result = await job.run(now);
    } catch (e) {
      error = String(e?.message || e);
    }
    state.lastRun[job.name] = nowIso();
    const entry = { job: job.name, at: nowIso(), occ: occ.toISOString(), error, ok: !error };
    state.log.push(entry);
    ran.push({ ...entry, result });
  }
  state.log = state.log.slice(-100);
  writeState(state);
  return ran;
}

let timer = null;

// Arranca el latido. Hace un catch-up inmediato y luego revisa cada minuto.
export function startScheduler() {
  if (timer) return;
  // catch-up al arrancar (no bloquea el listen)
  tick().catch(() => {});
  timer = setInterval(() => tick().catch(() => {}), 60_000);
  if (timer.unref) timer.unref(); // no mantener vivo el proceso solo por esto
  return timer;
}

export function stopScheduler() {
  if (timer) clearInterval(timer);
  timer = null;
}

export function schedulerStatus() {
  const state = readState();
  return {
    running: !!timer,
    jobs: JOBS.map((j) => ({ name: j.name, schedule: j.schedule, lastRun: state.lastRun[j.name] || null })),
    recent: state.log.slice(-10).reverse(),
  };
}
