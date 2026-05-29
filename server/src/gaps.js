// ============================================================
//  Gaps — "what Athena doesn't know yet but should"
//  ────────────────────────────────────────────────
//  Inspirada por gbrain (Garry Tan, YC) — en vez de esperar
//  a que Isabel pregunte "qué sabes de María", Athena le surface
//  lo que FALTA por saber. Concretamente:
//
//   - Por cliente: MBI no verificado, SOA faltante, TCPA sin
//     consentir, sin drug list para MAPD, sin proveedores, sin
//     touchpoint en 12+ meses (riesgo CMS).
//   - Por entidad: personas mencionadas 2+ veces que siguen tipo
//     'other', o entidades sin notas con varias menciones.
//   - Por compromisos: vencidos sin evidencia y sin nudge enviado.
//
//  Severidades:
//    alto  — bloqueador (no se puede enrollar / CMS violation)
//    aviso — gap operacional importante
//    info  — nice-to-have
//
//  El briefing matutino le pregunta a Athena que llame esta
//  herramienta y trae arriba los más urgentes para que Sami o
//  Isabel los cierren ese día.
// ============================================================
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const DATA_DIR = join(__dirname, '..', 'data');
const CRM_FILE = join(DATA_DIR, 'crm.json');
const ENTITIES_FILE = join(DATA_DIR, 'entities.json');
const COMMITMENTS_FILE = join(DATA_DIR, 'commitments.json');

function readJsonSafe(file, fallback) {
  try {
    if (existsSync(file)) return JSON.parse(readFileSync(file, 'utf8'));
  } catch { /* ignore */ }
  return fallback;
}

function touchpointsLast12m(c) {
  const cutoff = Date.now() - 12 * 30 * 86_400_000;
  return (c.aep_touchpoints || []).filter((t) => new Date(t.ts).getTime() >= cutoff).length;
}

// ---- Cómputo principal ----
export function computeGaps({ limit = 40 } = {}) {
  const gaps = [];
  const clients = readJsonSafe(CRM_FILE, []);

  for (const c of clients) {
    if (c.status === 'inactive') continue;
    const isActiveish = c.status === 'active' || c.status === 'prospect';
    const isMaPdOrMapd = /MAPD|MA|PDP|MA-PD/i.test(c.plan || '');

    // Bloqueadores duros para activos/prospects
    if (isActiveish) {
      if (!c.fecha_nacimiento) {
        gaps.push(mkGap('cliente', c, 'fecha_nacimiento', 'alto', 'No tiene fecha de nacimiento — bloquea cualquier verificación de elegibilidad y T65.'));
      }
      if (!c.mbi) {
        gaps.push(mkGap('cliente', c, 'mbi', 'alto', 'No tiene MBI capturado. Sin él no se puede enrollar.'));
      } else if ((c.mbi_verified?.status || 'pending') !== 'verified') {
        gaps.push(mkGap('cliente', c, 'mbi_verified', 'alto', `MBI registrado pero NO verificado (status: ${c.mbi_verified?.status || 'pending'}). Verifica vía card_photo o portal del carrier.`));
      }
      const soaStatus = c.soa?.status || 'none';
      if (soaStatus !== 'signed') {
        gaps.push(mkGap('cliente', c, 'soa', 'alto', `SOA en estado "${soaStatus}". Sin SOA firmada (48h antes) no puedes hablar de planes — regla CMS.`));
      }
      if (!c.tcpa_consent?.granted) {
        gaps.push(mkGap('cliente', c, 'tcpa', 'alto', 'Sin consentimiento TCPA registrado. Llamarle o textearle viola ley federal.'));
      }
      if (touchpointsLast12m(c) === 0) {
        gaps.push(mkGap('cliente', c, 'touchpoint_12m', 'alto', 'Sin touchpoint en los últimos 12 meses — la Final Rule 2027 endurece esta regla.'));
      }
    }

    // Avisos operacionales
    if (isActiveish) {
      if (!c.telefono) {
        gaps.push(mkGap('cliente', c, 'telefono', 'aviso', 'No tiene teléfono — bloquea cualquier outreach por SMS o llamada.'));
      }
      if (!c.email) {
        gaps.push(mkGap('cliente', c, 'email', 'aviso', 'No tiene email registrado.'));
      }
      if (!c.renewal_date) {
        gaps.push(mkGap('cliente', c, 'renewal_date', 'aviso', 'Sin fecha de renovación — no entra al calendario de retención.'));
      }
      if (isMaPdOrMapd && !c.drug_list?.length) {
        gaps.push(mkGap('cliente', c, 'drug_list', 'aviso', `Plan ${c.plan || 'MAPD/PDP'} pero drug list vacía — no se puede comparar formulary en Plan Finder.`));
      }
      if (isMaPdOrMapd && !c.providers?.length) {
        gaps.push(mkGap('cliente', c, 'providers', 'info', 'Sin proveedores listados — no se puede verificar red de doctores.'));
      }
    }

    // Leads: gaps más livianos
    if (c.status === 'lead') {
      if (!c.telefono && !c.email) {
        gaps.push(mkGap('cliente', c, 'contacto', 'aviso', 'Lead sin teléfono NI email — no se puede dar seguimiento.'));
      }
      if (!c.fuente) {
        gaps.push(mkGap('cliente', c, 'fuente', 'info', 'No registramos cómo llegó este lead. Útil para entender qué canales funcionan.'));
      }
    }
  }

  // Entidades: gente mencionada que sigue como 'other'
  const entities = readJsonSafe(ENTITIES_FILE, []);
  for (const e of entities) {
    if (e.type === 'other' && (e.notas?.length || 0) >= 2) {
      gaps.push({
        kind: 'entidad',
        target_id: e.id,
        target_name: e.canonical_name,
        missing_field: 'type',
        severidad: 'info',
        mensaje: `Mencionada ${e.notas.length} veces pero sin tipo definido. ¿Es familia / cliente / vendor / otra?`,
        accion: `entidad_anotar(persona="${e.canonical_name}", tipo="<el correcto>", nota="<contexto>")`,
      });
    }
  }

  // Compromisos vencidos sin evidencia y que no avisamos
  const commits = readJsonSafe(COMMITMENTS_FILE, []);
  for (const c of commits) {
    if (c.status !== 'pendiente') continue;
    if (!c.vence) continue;
    const overdue = new Date(c.vence).getTime() < Date.now();
    if (overdue && !c.avisada_isabel) {
      gaps.push({
        kind: 'compromiso',
        target_id: c.id,
        target_name: c.persona,
        missing_field: 'cumplimiento',
        severidad: 'aviso',
        mensaje: `Compromiso vencido: ${c.persona} debía ${c.descripcion} (vía ${c.canal}). Aún no le mandamos nudge.`,
        accion: `Llama marcar_cumplido si ya llegó, o esperate al chase automático.`,
      });
    }
  }

  // Ordenar por severidad y devolver top N
  const byPrio = ['alto', 'aviso', 'info'];
  return gaps
    .sort((a, b) => byPrio.indexOf(a.severidad) - byPrio.indexOf(b.severidad))
    .slice(0, limit);
}

function mkGap(kind, c, field, severidad, mensaje) {
  return {
    kind,
    target_id: c.id,
    target_name: c.nombre,
    missing_field: field,
    severidad,
    mensaje,
    accion: suggestedAction(field, c),
  };
}

function suggestedAction(field, c) {
  switch (field) {
    case 'fecha_nacimiento': return `actualizar_cliente(id="${c.id}", fecha_nacimiento="YYYY-MM-DD")`;
    case 'mbi': return `actualizar_cliente(id="${c.id}", mbi="...")  +  cliente_mbi_estado(id="${c.id}", status="verified", source="card_photo")`;
    case 'mbi_verified': return `cliente_mbi_estado(id="${c.id}", status="verified", source="<card_photo|carrier_portal|mymedicare>")`;
    case 'soa': return `Mandar SOA por DocuSign o link de carrier; luego cliente_soa_firmar(id="${c.id}", productos_discutidos=[...])`;
    case 'tcpa': return `cliente_tcpa(id="${c.id}", idioma="es")`;
    case 'touchpoint_12m': return `cliente_touchpoint(id="${c.id}", tipo="<call|email|sms>", resumen="...")`;
    case 'telefono':
    case 'email':
    case 'renewal_date':
    case 'fuente':
    case 'contacto':
      return `actualizar_cliente(id="${c.id}", ${field}="...")`;
    case 'drug_list': return `cliente_medicamento_agregar(id="${c.id}", nombre="...", dosis="...", frecuencia="...")`;
    case 'providers': return `cliente_doctor_agregar(id="${c.id}", nombre="Dr...", especialidad="...")`;
    default: return null;
  }
}

// Gaps específicos de UN cliente (para preparar una llamada).
export function gapsForClient(clientId) {
  return computeGaps({ limit: 200 }).filter((g) => g.target_id === clientId);
}

// Resumen ultracorto para el contexto de Athena (1 línea por categoría).
export function buildGapsSummary() {
  const gaps = computeGaps({ limit: 100 });
  if (!gaps.length) return '';
  const counts = { alto: 0, aviso: 0, info: 0 };
  const byField = {};
  for (const g of gaps) {
    counts[g.severidad] = (counts[g.severidad] || 0) + 1;
    byField[g.missing_field] = (byField[g.missing_field] || 0) + 1;
  }
  const top3Fields = Object.entries(byField)
    .sort((a, b) => b[1] - a[1])
    .slice(0, 3)
    .map(([f, n]) => `${n}× ${f}`)
    .join(', ');
  return `KNOWN-UNKNOWNS: ${counts.alto} altas · ${counts.aviso} avisos · ${counts.info} info. Top campos faltantes: ${top3Fields}. Llama gaps_overview para detalle.`;
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const { config } = await import('dotenv');
  config();
  console.log(JSON.stringify(computeGaps(), null, 2));
  process.exit(0);
}
