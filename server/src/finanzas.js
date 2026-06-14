// ============================================================
//  Finanzas — Elena CFO ahora tiene datos reales
//  ─────────────────────────────────────────────
//  Captura gastos / ingresos / ahorros con categoría. Soporta
//  el framework Profit First que Elena usa (dividir cada
//  comisión en: Profit, Owner Pay, Tax, Operating).
//
//  Sin estos datos, Elena hablaba en genérico. Con ellos:
//    - Sabe cómo va tu mes vs presupuesto
//    - Detecta categorías que se inflaron
//    - Calcula tu runway / ahorros / tax saved
//    - Te confronta con cariño si estás overspending
//
//  Las comisiones del negocio Medicare viven en LUNA. Para
//  esos números, Elena puede pedirle a Pilar que consulte
//  LUNA. Este módulo trackea TODO LO PERSONAL — Sprouts,
//  gimnasio, gas, eat-out, marketing, tu sueldo, otros
//  ingresos.
// ============================================================
import { readFileSync, writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { atomicWriteJson } from './storage.js';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const FILE = join(DATA_DIR, 'finanzas.json');

export const CATEGORIAS_GASTO = ['oficina', 'marketing', 'salarios', 'personal', 'gas', 'comida', 'salud', 'gym', 'tax', 'otro'];
export const CATEGORIAS_INGRESO = ['comision', 'salario', 'bonus', 'otro'];

function ensureDir() { if (!existsSync(DATA_DIR)) mkdirSync(DATA_DIR, { recursive: true }); }
function load() { try { if (existsSync(FILE)) return JSON.parse(readFileSync(FILE, 'utf8')); } catch {} return []; }
function save(d) { ensureDir(); atomicWriteJson(FILE, d.slice(-3000)); }
function newId() { return `f_${Date.now().toString(36)}${Math.random().toString(36).slice(2, 5)}`; }
function monthKey(d = new Date()) { return d.toISOString().slice(0, 7); }
function dayKey(d = new Date()) { return d.toISOString().slice(0, 10); }

export function registrarGasto({ monto, categoria = 'otro', concepto = '', dia = null }) {
  if (typeof monto !== 'number' || monto <= 0) return { ok: false, error: 'Monto inválido.' };
  if (!CATEGORIAS_GASTO.includes(categoria)) categoria = 'otro';
  const data = load();
  const entry = {
    id: newId(), tipo: 'gasto', monto: Math.round(monto * 100) / 100, categoria,
    concepto: String(concepto).slice(0, 150), dia: dia || dayKey(), mes: monthKey(),
    ts: new Date().toISOString(),
  };
  data.push(entry); save(data);
  return { ok: true, entry };
}

export function registrarIngreso({ monto, categoria = 'comision', concepto = '', dia = null }) {
  if (typeof monto !== 'number' || monto <= 0) return { ok: false, error: 'Monto inválido.' };
  if (!CATEGORIAS_INGRESO.includes(categoria)) categoria = 'otro';
  const data = load();
  const entry = {
    id: newId(), tipo: 'ingreso', monto: Math.round(monto * 100) / 100, categoria,
    concepto: String(concepto).slice(0, 150), dia: dia || dayKey(), mes: monthKey(),
    ts: new Date().toISOString(),
  };
  data.push(entry); save(data);
  return { ok: true, entry };
}

export function statsMes(mes = null) {
  const m = mes || monthKey();
  const entries = load().filter((e) => e.mes === m);
  const gastos = {};
  let totalGastos = 0, totalIngresos = 0;
  for (const e of entries) {
    if (e.tipo === 'gasto') {
      totalGastos += e.monto;
      gastos[e.categoria] = (gastos[e.categoria] || 0) + e.monto;
    } else if (e.tipo === 'ingreso') {
      totalIngresos += e.monto;
    }
  }
  return {
    mes: m,
    total_ingresos: Math.round(totalIngresos * 100) / 100,
    total_gastos: Math.round(totalGastos * 100) / 100,
    neto: Math.round((totalIngresos - totalGastos) * 100) / 100,
    gastos_por_categoria: gastos,
    n_transacciones: entries.length,
  };
}

export function buildFinanzasInline() {
  const s = statsMes();
  if (!s.n_transacciones) return '';
  const ratio = s.total_ingresos > 0 ? Math.round((s.neto / s.total_ingresos) * 100) : null;
  return `mes: ingresos $${s.total_ingresos} · gastos $${s.total_gastos} · neto $${s.neto}${ratio !== null ? ` (${ratio}%)` : ''}`;
}

export function buildFinanzasForCoach() {
  const s = statsMes();
  if (!s.n_transacciones) return '';
  const lines = [`💰 FINANZAS DEL MES (${s.mes})`];
  lines.push(`Ingresos: $${s.total_ingresos} · Gastos: $${s.total_gastos} · Neto: $${s.neto}`);
  const topCat = Object.entries(s.gastos_por_categoria).sort((a, b) => b[1] - a[1]).slice(0, 5);
  if (topCat.length) {
    lines.push(`Top categorías gasto: ${topCat.map(([k, v]) => `${k} $${v}`).join(' · ')}`);
  }
  return `\n\nDATOS REALES DE FINANZAS (Profit First framework — la directora es Isabel):\n${lines.join('\n')}\n\nUsa estos datos para coachear con la verdad. Si una categoría se infló, dilo. Si el neto va bajo, propón ajustes concretos. Recuerda Profit First: separar 5% Profit / 50% Owner Pay / 15% Tax / 30% Operating de CADA ingreso.`;
}
