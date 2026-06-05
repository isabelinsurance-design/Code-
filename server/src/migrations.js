// ============================================================
//  Migraciones automáticas — corren al boot
//  ──────────────────────────────────────
//  Mueven datos cuando renombramos ids para mantener histórico
//  vivo sin necesidad de intervención manual. Cada migración es
//  idempotente (si ya corrió, no hace nada).
// ============================================================
import { existsSync, readFileSync, writeFileSync, renameSync, readdirSync, mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');

function safeRename(from, to) {
  try {
    if (existsSync(from) && !existsSync(to)) {
      const dir = dirname(to);
      if (!existsSync(dir)) mkdirSync(dir, { recursive: true });
      renameSync(from, to);
      return true;
    }
    return false;
  } catch (e) {
    console.warn(`[migration] rename ${from} → ${to} falló:`, e.message);
    return false;
  }
}

function patchJsonInPlace(filePath, transform) {
  try {
    if (!existsSync(filePath)) return false;
    const orig = readFileSync(filePath, 'utf8');
    const data = JSON.parse(orig);
    const patched = transform(data);
    const newJson = JSON.stringify(patched, null, 2);
    if (newJson !== orig) {
      writeFileSync(filePath, newJson);
      return true;
    }
    return false;
  } catch (e) {
    console.warn(`[migration] patch ${filePath} falló:`, e.message);
    return false;
  }
}

// ============================================================
//  Migración 2026-06: rename 'pilar' → 'luna', 'luna' → 'aurora'
//  ────────────────────────────────────────────────────────
//  Pilar (Medicare CRM) ahora se llama LUNA conceptual + id.
//  Beauty Luna (skincare) ahora se llama Aurora.
//
//  Orden CRÍTICO: primero mover 'luna' → 'aurora' (para liberar
//  el slot), después 'pilar' → 'luna'. Si invertimos pisamos data.
// ============================================================
export function migratePilarToLuna() {
  const ops = [];
  const SUBDIRS = ['coach_threads', 'coach_notes', 'coach_plans'];

  // PASO 1: luna (skincare) → aurora
  for (const sub of SUBDIRS) {
    const lunaFile = join(DATA_DIR, sub, 'luna.json');
    const auroraFile = join(DATA_DIR, sub, 'aurora.json');
    if (safeRename(lunaFile, auroraFile)) {
      ops.push(`${sub}/luna.json → ${sub}/aurora.json`);
    }
  }

  // PASO 2: pilar (Medicare) → luna
  for (const sub of SUBDIRS) {
    const pilarFile = join(DATA_DIR, sub, 'pilar.json');
    const lunaFile = join(DATA_DIR, sub, 'luna.json');
    if (safeRename(pilarFile, lunaFile)) {
      ops.push(`${sub}/pilar.json → ${sub}/luna.json`);
    }
  }

  // PASO 3: standing_orders — actualizar referencias textuales
  const ordersFile = join(DATA_DIR, 'standing_orders.json');
  const patched = patchJsonInPlace(ordersFile, (orders) => {
    if (!Array.isArray(orders)) return orders;
    return orders.map((o) => {
      let regla = o.regla || '';
      // Solo reemplazar Pilar capitalizado en texto, no en URLs/snake ids
      regla = regla.replace(/\bPilar\b/g, 'LUNA').replace(/\bpilar\b(?![_-])/g, 'LUNA');
      // Beauty Luna → Aurora
      regla = regla.replace(/\bBeauty Luna\b/g, 'Aurora');
      return regla !== o.regla ? { ...o, regla } : o;
    });
  });
  if (patched) ops.push('standing_orders.json (referencias textuales)');

  // PASO 4: coach_cadence — renombrar entries
  const cadenceFile = join(DATA_DIR, 'coach_cadence.json');
  const cadencePatched = patchJsonInPlace(cadenceFile, (data) => {
    if (!Array.isArray(data)) return data;
    return data.map((entry) => {
      if (entry.coach === 'pilar') return { ...entry, coach: 'luna' };
      if (entry.coach === 'luna') return { ...entry, coach: 'aurora' };
      return entry;
    });
  });
  if (cadencePatched) ops.push('coach_cadence.json (entries)');

  // PASO 5: trends — si hay coach_id references
  const trendsFile = join(DATA_DIR, 'trends.json');
  const trendsPatched = patchJsonInPlace(trendsFile, (data) => {
    if (!Array.isArray(data)) return data;
    return data.map((t) => {
      if (t.coach_id === 'pilar') return { ...t, coach_id: 'luna' };
      if (t.coach_id === 'luna') return { ...t, coach_id: 'aurora' };
      return t;
    });
  });
  if (trendsPatched) ops.push('trends.json (coach_id)');

  // PASO 6: improvements — referencias a coach
  const impFile = join(DATA_DIR, 'improvements.json');
  const impPatched = patchJsonInPlace(impFile, (data) => {
    if (!Array.isArray(data)) return data;
    return data.map((m) => {
      if (m.coach === 'pilar') return { ...m, coach: 'luna' };
      if (m.coach === 'luna') return { ...m, coach: 'aurora' };
      return m;
    });
  });
  if (impPatched) ops.push('improvements.json (coach)');

  if (ops.length) {
    console.log('[migration] pilar→luna, luna→aurora aplicada:');
    ops.forEach((o) => console.log(`  · ${o}`));
  } else {
    console.log('[migration] pilar→luna ya estaba aplicada o no había data.');
  }

  return ops;
}

// Corre todas las migraciones registradas al boot
export function runAllMigrations() {
  try {
    migratePilarToLuna();
  } catch (e) {
    console.error('[migration] error:', e.message);
  }
}
