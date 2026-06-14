// ============================================================
//  Signals — disparadores basados en señales (no en reloj)
//  ───────────────────────────────────────────────────────
//  El audit de mayo 2026 marca cron-only proactivity como
//  "2023-era thinking." Esto la complementa: cada noche
//  computamos un set de señales (umbrales y patrones), y el
//  briefing de la mañana las consume para decidir qué traer
//  arriba sin reglas hardcoded en el prompt.
//
//  Tipos de señales:
//    threshold  — métrica cruzó valor (no peso en 4 días)
//    pattern    — keyword count en chats (cansada x3 en 7d)
//    state      — recuento de records (X clientes con MBI pendiente)
//    calendar   — evento de calendario importante en próximas 24h
//
//  La salida vive en data/signals.json y la lee briefing.js.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { getHistory, getWiki } from './memory.js';
import {
  staleClients,
  upcomingRenewals,
  upcomingBirthdays,
  clientsNeedingAnnualTouch,
  clientsWithMbiPending,
  clientsWithSoaIssue,
  t65Pipeline,
  isAepNow,
} from './crm.js';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'signals.json');

function save(signals) {
  if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true });
  atomicWriteJson(FILE, { ts: new Date().toISOString(), signals });
}

export function loadSignals() {
  try {
    if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8'));
  } catch { /* ignore */ }
  return { ts: null, signals: [] };
}

// Calcula todas las señales. Cada una es:
//   { tipo, severidad: 'info'|'aviso'|'alto', mensaje, datos? }
export function computeSignals() {
  const out = [];

  // ---- Señales de Isabel (de la wiki y del chat) ----
  const wiki = getWiki();
  const notas = wiki.notas || [];

  // Última nota de peso
  const ultPeso = notas.find((n) => /\bpeso\b|\blbs\b|\blibras\b|\bkilos\b/i.test(n.nota));
  if (ultPeso) {
    const dias = Math.round((Date.now() - new Date(ultPeso.fecha).getTime()) / 86_400_000);
    if (dias >= 5) {
      out.push({
        tipo: 'threshold',
        severidad: dias >= 10 ? 'alto' : 'aviso',
        mensaje: `Isabel no ha registrado peso en ${dias} días.`,
        datos: { metric: 'peso', ultima: ultPeso.fecha, dias },
      });
    }
  } else {
    out.push({
      tipo: 'threshold',
      severidad: 'info',
      mensaje: 'No hay ningún registro de peso en la wiki — Carmen debería pedir el primero.',
    });
  }

  // Última nota de entrenamiento
  const ultGym = notas.find((n) => /\b(tonal|gym|workout|entren|pilates|cardio)\b/i.test(n.nota));
  if (ultGym) {
    const dias = Math.round((Date.now() - new Date(ultGym.fecha).getTime()) / 86_400_000);
    if (dias >= 4) {
      out.push({
        tipo: 'threshold',
        severidad: dias >= 7 ? 'alto' : 'aviso',
        mensaje: `Sin nota de entrenamiento en ${dias} días.`,
        datos: { metric: 'workout', dias },
      });
    }
  }

  // Patrón de estado de ánimo en chat reciente (últimos 7 días)
  const chat = getHistory().filter((m) => m.role === 'user');
  const recientes = chat.slice(-60); // máx 60 turnos
  const sevenDaysAgo = Date.now() - 7 * 86_400_000;
  const recientText = recientes
    .map((m) => Array.isArray(m.content) ? m.content.filter((b) => b.type === 'text').map((b) => b.text).join(' ') : (m.content || ''))
    .join(' ').toLowerCase();
  const moodPatterns = [
    { kw: /\b(cansada|agotada|exhausta)\b/g, label: 'cansancio', coach: 'sofia' },
    { kw: /\b(estr[eé]s|estresada|abrumada)\b/g, label: 'estrés', coach: 'alma' },
    { kw: /\btriste|deprimida|down|bajón\b/g, label: 'ánimo bajo', coach: 'alma' },
    { kw: /\bansiosa|ansiedad|nervios\b/g, label: 'ansiedad', coach: 'alma' },
    { kw: /\bsola|loneliness\b/g, label: 'soledad', coach: 'esperanza' },
  ];
  for (const p of moodPatterns) {
    const matches = (recientText.match(p.kw) || []).length;
    if (matches >= 3) {
      out.push({
        tipo: 'pattern',
        severidad: matches >= 5 ? 'alto' : 'aviso',
        mensaje: `Isabel mencionó "${p.label}" ${matches} veces en chats recientes — considera traer a ${p.coach}.`,
        datos: { patrón: p.label, count: matches, coach: p.coach },
      });
    }
  }

  // ---- Señales del CRM ----
  const stale = staleClients(30);
  if (stale.length >= 3) {
    out.push({
      tipo: 'state',
      severidad: stale.length >= 8 ? 'alto' : 'aviso',
      mensaje: `${stale.length} clientes activos/prospects sin contacto en 30+ días.`,
      datos: { count: stale.length, sample: stale.slice(0, 3).map((c) => c.nombre) },
    });
  }

  const renewals = upcomingRenewals(30);
  if (renewals.length) {
    out.push({
      tipo: 'state',
      severidad: renewals.length >= 5 ? 'alto' : 'aviso',
      mensaje: `${renewals.length} renovaciones en los próximos 30 días.`,
      datos: { count: renewals.length, sample: renewals.slice(0, 5).map((c) => c.nombre) },
    });
  }

  const bdays = upcomingBirthdays(7);
  if (bdays.length) {
    out.push({
      tipo: 'state',
      severidad: 'info',
      mensaje: `${bdays.length} cumpleaños de clientes esta semana — toque humano gana retención.`,
      datos: { sample: bdays.slice(0, 3).map((c) => `${c.nombre} en ${c.diasFalta}d`) },
    });
  }

  // ---- Señales de compliance ----
  const annualTouch = clientsNeedingAnnualTouch();
  if (annualTouch.length) {
    out.push({
      tipo: 'state',
      severidad: 'alto',
      mensaje: `CMS regla 12-meses: ${annualTouch.length} clientes activos sin touchpoint en el último año.`,
      datos: { count: annualTouch.length },
    });
  }
  const soaIssue = clientsWithSoaIssue();
  if (soaIssue.length) {
    out.push({
      tipo: 'state',
      severidad: 'aviso',
      mensaje: `${soaIssue.length} clientes/leads sin SOA firmada o con SOA vencida.`,
      datos: { count: soaIssue.length },
    });
  }
  const mbiPending = clientsWithMbiPending();
  if (mbiPending.length) {
    out.push({
      tipo: 'state',
      severidad: 'aviso',
      mensaje: `${mbiPending.length} clientes con MBI pendiente de verificar.`,
      datos: { count: mbiPending.length },
    });
  }
  const t65 = t65Pipeline(3);
  if (t65.length) {
    out.push({
      tipo: 'state',
      severidad: 'alto',
      mensaje: `${t65.length} prospectos T65 entrando a su ICEP en los próximos 3 meses — ventana de oro.`,
      datos: { count: t65.length, sample: t65.slice(0, 3).map((c) => `${c.nombre} (${c.t65.meses_para_65}m)`) },
    });
  }
  if (isAepNow()) {
    out.push({
      tipo: 'calendar',
      severidad: 'alto',
      mensaje: 'AEP ACTIVO (15 oct – 7 dic). Toda comunicación de planes requiere SOA firmada 48h antes.',
    });
  }

  save(out);
  return out;
}

export function buildSignalsContext() {
  const { signals } = loadSignals();
  if (!signals?.length) return '';
  const byPrio = ['alto', 'aviso', 'info'];
  const sorted = signals.slice().sort((a, b) => byPrio.indexOf(a.severidad) - byPrio.indexOf(b.severidad));
  return `SEÑALES ACTIVAS (computadas anoche — úsalas para decidir qué traer arriba hoy):\n${sorted.map((s) => `  [${s.severidad}] ${s.mensaje}`).join('\n')}`;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  console.log(JSON.stringify(computeSignals(), null, 2));
  process.exit(0);
}
