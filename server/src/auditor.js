// ============================================================
//  Auditor — calidad de datos del CRM
//  ──────────────────────────────────
//  Distinto a gaps.js. gaps.js encuentra "campos que faltan en un
//  cliente". auditor.js encuentra "errores estructurales" en el
//  CRM completo: duplicados, inconsistencias, stale, huérfanos,
//  patrones raros. Es el "dame un repaso de calidad antes de AEP".
//
//  Severidades:
//    alto  — daña tu negocio (duplicados que confunden, MBI mal
//            verificado, cliente activo sin compliance básico)
//    aviso — operacional (stale, huérfanos)
//    info  — sospechoso pero quizá intencional
//
//  Cada finding incluye accion sugerida (qué tool llamar para
//  arreglarlo) para que Athena lo proponga en una sola línea.
// ============================================================
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const CRM_FILE = join(DATA_DIR, 'crm.json');
const ENTITIES_FILE = join(DATA_DIR, 'entities.json');

function readJsonSafe(file, fallback) {
  try {
    if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8'));
  } catch { /* ignore */ }
  return fallback;
}

// Normaliza un string para comparación (sin acentos, lowercase, sin puntos).
function norm(s) {
  return String(s || '')
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^\w\s]/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

function digitsOnly(s) {
  return String(s || '').replace(/\D/g, '');
}

// ---- 1. Duplicados ----
function findDuplicates(clients) {
  const out = [];
  // Por MBI exacto
  const byMbi = new Map();
  for (const c of clients) {
    if (!c.mbi) continue;
    const k = c.mbi.toUpperCase().replace(/[\s-]/g, '');
    if (!byMbi.has(k)) byMbi.set(k, []);
    byMbi.get(k).push(c);
  }
  for (const [mbi, group] of byMbi) {
    if (group.length > 1) {
      out.push({
        kind: 'duplicado',
        severidad: 'alto',
        target_id: group.map((c) => c.id).join(','),
        target_name: group.map((c) => c.nombre).join(' / '),
        mensaje: `${group.length} clientes con mismo MBI ${mbi}. Bug grave — uno de ellos está mal.`,
        accion: `Decide cuál es el real: revisa expediente_cliente de cada uno. Borra los duplicados con actualizar_cliente(status="inactive") o renombra.`,
      });
    }
  }
  // Por email exacto
  const byEmail = new Map();
  for (const c of clients) {
    if (!c.email) continue;
    const k = c.email.toLowerCase().trim();
    if (!byEmail.has(k)) byEmail.set(k, []);
    byEmail.get(k).push(c);
  }
  for (const [email, group] of byEmail) {
    if (group.length > 1) {
      out.push({
        kind: 'duplicado',
        severidad: 'alto',
        target_id: group.map((c) => c.id).join(','),
        target_name: group.map((c) => c.nombre).join(' / '),
        mensaje: `${group.length} clientes con mismo email ${email}. Probable misma persona.`,
        accion: `Compara expedientes; fusiona notas y deja UNO solo activo.`,
      });
    }
  }
  // Por nombre normalizado + último-4-dígitos del teléfono
  const byNamePhone = new Map();
  for (const c of clients) {
    const n = norm(c.nombre);
    const d = digitsOnly(c.telefono);
    if (!n || !d) continue;
    const k = `${n}::${d.slice(-4)}`;
    if (!byNamePhone.has(k)) byNamePhone.set(k, []);
    byNamePhone.get(k).push(c);
  }
  for (const [key, group] of byNamePhone) {
    if (group.length > 1) {
      out.push({
        kind: 'duplicado',
        severidad: 'aviso',
        target_id: group.map((c) => c.id).join(','),
        target_name: group.map((c) => c.nombre).join(' / '),
        mensaje: `${group.length} clientes con nombre y últimos 4 dígitos similares (${key}). Probable misma persona.`,
        accion: `Revisa expedientes; si son el mismo, fusiona notas y marca uno inactive.`,
      });
    }
  }
  return out;
}

// ---- 2. Inconsistencias ----
function findInconsistencies(clients) {
  const out = [];
  for (const c of clients) {
    // Status "active" pero sin carrier ni plan
    if (c.status === 'active' && !c.carrier && !c.plan) {
      out.push({
        kind: 'inconsistencia',
        severidad: 'alto',
        target_id: c.id,
        target_name: c.nombre,
        mensaje: 'Status="active" pero sin carrier ni plan. ¿Cómo está "active" sin nada inscrito?',
        accion: `actualizar_cliente(id="${c.id}", carrier="...", plan="...") o cambia status.`,
      });
    }
    // effective_date > renewal_date
    if (c.effective_date && c.renewal_date) {
      const eff = new Date(c.effective_date).getTime();
      const ren = new Date(c.renewal_date).getTime();
      if (!isNaN(eff) && !isNaN(ren) && eff > ren) {
        out.push({
          kind: 'inconsistencia',
          severidad: 'aviso',
          target_id: c.id,
          target_name: c.nombre,
          mensaje: `Effective date (${c.effective_date.slice(0, 10)}) es POSTERIOR a renewal_date (${c.renewal_date.slice(0, 10)}). Imposible.`,
          accion: `actualizar_cliente(id="${c.id}", effective_date o renewal_date).`,
        });
      }
    }
    // MBI con verificación "verified" pero sin source
    if (c.mbi_verified?.status === 'verified' && !c.mbi_verified?.source) {
      out.push({
        kind: 'inconsistencia',
        severidad: 'aviso',
        target_id: c.id,
        target_name: c.nombre,
        mensaje: 'MBI marcado "verified" pero sin source documentada.',
        accion: `cliente_mbi_estado(id="${c.id}", status="verified", source="<card_photo|carrier_portal|mymedicare>")`,
      });
    }
    // Cliente con DOB indicando >65 años pero status "lead" (debería ser prospect/active)
    if (c.fecha_nacimiento && c.status === 'lead') {
      const dob = new Date(c.fecha_nacimiento);
      const ageYears = (Date.now() - dob.getTime()) / (365.25 * 86_400_000);
      if (ageYears >= 65) {
        out.push({
          kind: 'inconsistencia',
          severidad: 'info',
          target_id: c.id,
          target_name: c.nombre,
          mensaje: `Tiene ${Math.floor(ageYears)} años pero status="lead". Ya pasó el T65 — ¿debería ser prospect o active?`,
          accion: `actualizar_cliente(id="${c.id}", status="prospect" o "active").`,
        });
      }
    }
    // SOA firmada pero retention_until ya pasado
    if (c.soa?.status === 'signed' && c.soa?.retention_until) {
      const until = new Date(c.soa.retention_until).getTime();
      if (!isNaN(until) && until < Date.now()) {
        out.push({
          kind: 'inconsistencia',
          severidad: 'aviso',
          target_id: c.id,
          target_name: c.nombre,
          mensaje: `SOA "signed" pero retention_until ya pasó (${c.soa.retention_until.slice(0, 10)}). Necesita nueva SOA antes de hablar de planes.`,
          accion: `cliente_soa_firmar(id="${c.id}", version="<nueva>") cuando la firme.`,
        });
      }
    }
  }
  return out;
}

// ---- 3. Stale ----
function findStale(clients) {
  const out = [];
  const cutoff12m = Date.now() - 12 * 30 * 86_400_000;
  const cutoff18m = Date.now() - 18 * 30 * 86_400_000;
  for (const c of clients) {
    if (c.status === 'inactive') continue;
    const updated = new Date(c.actualizado || c.creado || 0).getTime();
    if (updated < cutoff18m && c.status === 'active') {
      out.push({
        kind: 'stale',
        severidad: 'aviso',
        target_id: c.id,
        target_name: c.nombre,
        mensaje: `Activo pero NADA cambió en 18+ meses. ¿Sigue siendo cliente real?`,
        accion: `Verifica con cliente_touchpoint o cambia status a "inactive".`,
      });
    } else if (updated < cutoff12m && c.status === 'active') {
      out.push({
        kind: 'stale',
        severidad: 'info',
        target_id: c.id,
        target_name: c.nombre,
        mensaje: `Activo pero sin cambios en 12+ meses. Posible candidato para review.`,
      });
    }
    // Lead que lleva 6+ meses sin movimiento = probablemente perdido
    const cutoff6m = Date.now() - 6 * 30 * 86_400_000;
    if (c.status === 'lead' && updated < cutoff6m) {
      out.push({
        kind: 'stale',
        severidad: 'info',
        target_id: c.id,
        target_name: c.nombre,
        mensaje: `Lead frío: 6+ meses sin movimiento. Considera marcarlo "inactive".`,
        accion: `actualizar_cliente(id="${c.id}", status="inactive")`,
      });
    }
  }
  return out;
}

// ---- 4. Huérfanos ----
function findOrphans(clients) {
  const out = [];
  for (const c of clients) {
    // Touchpoint con summary vacío
    const emptyTps = (c.aep_touchpoints || []).filter((t) => !t.summary?.trim());
    if (emptyTps.length) {
      out.push({
        kind: 'orfano',
        severidad: 'info',
        target_id: c.id,
        target_name: c.nombre,
        mensaje: `${emptyTps.length} touchpoint(s) con summary vacío. ¿Qué pasó en esas interacciones?`,
        accion: `Edita manualmente o agrega context adicional con nota_cliente.`,
      });
    }
    // Cliente activo sin teléfono Y sin email
    if ((c.status === 'active' || c.status === 'prospect') && !c.telefono && !c.email) {
      out.push({
        kind: 'orfano',
        severidad: 'alto',
        target_id: c.id,
        target_name: c.nombre,
        mensaje: 'Sin teléfono NI email. No hay forma de contactarl@.',
        accion: `actualizar_cliente(id="${c.id}", telefono o email).`,
      });
    }
    // Cliente "active" sin status_history (sin notas)
    if (c.status === 'active' && (!c.notas || c.notas.length === 0)) {
      out.push({
        kind: 'orfano',
        severidad: 'aviso',
        target_id: c.id,
        target_name: c.nombre,
        mensaje: 'Activo pero sin NINGUNA nota en su expediente. ¿De dónde salió?',
        accion: `nota_cliente(id="${c.id}", nota="...") con contexto.`,
      });
    }
  }
  return out;
}

// ---- 5. Patrones raros ----
function findWeirdPatterns(clients) {
  const out = [];

  // Demasiados leads con misma fuente y CERO touchpoints
  const bySource = new Map();
  for (const c of clients) {
    if (c.status !== 'lead') continue;
    const src = c.fuente || '(sin fuente)';
    if (!bySource.has(src)) bySource.set(src, []);
    bySource.get(src).push(c);
  }
  for (const [src, leads] of bySource) {
    const noTp = leads.filter((c) => !(c.aep_touchpoints?.length));
    if (noTp.length >= 5) {
      out.push({
        kind: 'patron',
        severidad: 'aviso',
        target_id: noTp.slice(0, 3).map((c) => c.id).join(','),
        target_name: `${noTp.length} leads de "${src}"`,
        mensaje: `${noTp.length} leads de fuente "${src}" sin un solo touchpoint. ¿Está fallando ese canal o no estás dando seguimiento?`,
        accion: `Revisa la lista con buscar_cliente y prioriza outreach.`,
      });
    }
  }

  // Active clients con CERO touchpoints en toda su vida
  const activosSinTp = clients.filter((c) => c.status === 'active' && !(c.aep_touchpoints?.length));
  if (activosSinTp.length >= 3) {
    out.push({
      kind: 'patron',
      severidad: 'aviso',
      target_id: activosSinTp.slice(0, 3).map((c) => c.id).join(','),
      target_name: `${activosSinTp.length} activos`,
      mensaje: `${activosSinTp.length} clientes activos con CERO touchpoints registrados. ¿No los has tocado o no lo estás registrando?`,
      accion: `Revisa la lista y cierra el gap con cliente_touchpoint donde corresponda.`,
    });
  }

  // Muchos clientes MAPD/MA-PD sin drug list (esperable poca cobertura — bandera si pasa de 30%)
  const mapd = clients.filter((c) => /MAPD|MA-PD/i.test(c.plan || ''));
  const mapdSinDrugs = mapd.filter((c) => !(c.drug_list?.length));
  if (mapd.length >= 5 && mapdSinDrugs.length / mapd.length > 0.3) {
    out.push({
      kind: 'patron',
      severidad: 'info',
      target_id: '',
      target_name: `${mapdSinDrugs.length} de ${mapd.length}`,
      mensaje: `${Math.round(100 * mapdSinDrugs.length / mapd.length)}% de tus MAPD no tienen drug list. Para Plan Finder lo necesitas — captúralo en el próximo touchpoint.`,
    });
  }

  return out;
}

// ---- API principal ----
export function auditCrm({ limit = 50 } = {}) {
  const clients = readJsonSafe(CRM_FILE, []);
  if (!clients.length) return [];
  const findings = [
    ...findDuplicates(clients),
    ...findInconsistencies(clients),
    ...findStale(clients),
    ...findOrphans(clients),
    ...findWeirdPatterns(clients),
  ];
  const byPrio = ['alto', 'aviso', 'info'];
  return findings
    .sort((a, b) => byPrio.indexOf(a.severidad) - byPrio.indexOf(b.severidad))
    .slice(0, limit);
}

export function formatAuditFinding(f) {
  const icon = f.severidad === 'alto' ? '🛑' : f.severidad === 'aviso' ? '⚠️' : 'ℹ️';
  return `${icon} [${f.kind}] ${f.target_name} — ${f.mensaje}${f.accion ? `\n   → ${f.accion}` : ''}`;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  const findings = auditCrm();
  console.log(`${findings.length} hallazgos:\n`);
  for (const f of findings) console.log(formatAuditFinding(f), '\n');
  process.exit(0);
}
