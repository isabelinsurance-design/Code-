// REVIEW HOOK DE CUMPLIMIENTO  (Playbook patron #7 / #33)
//
// Convierte los NO-NEGOCIABLES de la constitucion en un guardrail AUTOMATICO.
// Escanea texto que el agente piensa DECIR o ENVIAR a un miembro (un script, una
// carta, un mensaje) y marca lo que CMS prohibe, con severidad y un arreglo.
//
//   block (alto)  -> prohibido por CMS; NO se puede usar asi con un miembro.
//   warn  (aviso) -> riesgoso o falta un requisito; revisar antes de usar.
//   info          -> recordatorio.
//
// Es DETERMINISTA (reglas, sin red) para que el guardrail funcione siempre — un
// guardrail que depende de la API no es un guardrail. La reescritura "compliant"
// opcional si usa LLM, pero la deteccion no.
//
// IMPORTANTE: se escanea contenido DIRIGIDO AL MIEMBRO, no el coaching de SAMIA.
// (SAMIA dice "no le digas 'gratis'"; eso NO debe marcarse. Por eso el hook corre
// sobre drafts que el agente envia al endpoint, no sobre las respuestas de SAMIA.)

const rule = (id, severity, re, label, fix) => ({ id, severity, re, label, fix });

// Reglas bilingues (el equipo trabaja en español e ingles).
export const RULES = [
  // Superlativos / comparativos sin sustento (CMS MCMG).
  rule('superlativos', 'block',
    /\b(el|los)?\s*mejor(es)?\s+plan(es)?\b|\bbest\s+plan\b|\b#?\s*1\b|\bn[uú]mero\s+uno\b|\bel\s+m[aá]s\s+barato\b|\bthe\s+best\b/i,
    'Superlativo sin sustento',
    'CMS prohibe "el mejor/#1/el mas barato". Di "un plan que puede convenirle segun sus necesidades".'),

  // Implicar respaldo del gobierno.
  rule('respaldo-gobierno', 'block',
    /\b(de\s+parte\s+de\s+medicare|oficial\s+de\s+medicare|medicare\s+me\s+(envi[oó]|mand[oó])|enviad[oa]\s+por\s+medicare|de\s+(parte\s+de\s+)?(cms|seguro\s+social)|from\s+medicare\b|on\s+behalf\s+of\s+medicare)\b/i,
    'Implica respaldo del gobierno',
    'No impliques que vienes de Medicare/CMS/SSA. Aclara que eres un agente independiente.'),

  // Presion / urgencia.
  rule('presion', 'block',
    /\b(debe\s+inscribirse\s+(hoy|ya|ahora)|inscr[ií]base\s+(ya|hoy|ahora\s+mismo)|[uú]ltima\s+oportunidad|tiempo\s+limitado|solo\s+por\s+hoy|act\s+now|enroll\s+today|limited\s+time|last\s+chance)\b/i,
    'Tactica de presion/urgencia',
    'CMS prohibe presionar. Deja que el miembro decida sin urgencia artificial.'),

  // Garantizar aceptacion/cobertura.
  rule('garantia', 'block',
    /\b(garantizad[oa]s?|garantiz[oa]\s+(que|su|la|le)|guaranteed\s+(approval|coverage|acceptance)|100\s*%\s+(aprobad|cubiert))\b/i,
    'Garantia de aceptacion/cobertura',
    'No garantices aceptacion ni cobertura. Depende del plan y la elegibilidad.'),

  // Prometer beneficio no confirmado en el SB (no-negociable #2).
  rule('beneficio-no-confirmado', 'warn',
    /\b(todos?\s+los?\s+(planes?|medicamentos?)\s+(est[aá]n\s+)?(gratis|cubiertos?)|cubre\s+todo|cubre\s+todos?\s+sus?\s+medicamentos?|incluye\s+todo)\b/i,
    'Beneficio absoluto sin confirmar',
    'No prometas "cubre todo". Verifica el Summary of Benefits del plan especifico.'),

  // "Gratis" suelto (un $0 exacto si se puede, pero "gratis" a secas es riesgoso).
  rule('gratis', 'warn',
    /\b(gratis|free|sin\s+costo\s+alguno|completamente\s+gratis|no\s+le\s+cuesta\s+nada)\b/i,
    '"Gratis" sin matiz',
    'Usa "$0 de prima/copago" si es exacto y esta en el SB; evita "gratis" a secas.'),

  // SOA antes de presentar planes (no-negociable #3).
  rule('sin-soa', 'block',
    /\b(no\s+(necesita|hace\s+falta|requiere)\s+(el\s+)?soa|sin\s+soa|skip\s+the\s+soa|no\s+soa\s+needed)\b/i,
    'Saltarse el SOA',
    'El SOA debe estar firmado ANTES de presentar planes. No se puede omitir.'),

  // Recolectar pago en cita de ventas.
  rule('datos-de-pago', 'warn',
    /\b(n[uú]mero\s+de\s+(tarjeta|cuenta)|tarjeta\s+de\s+cr[eé]dito|cuenta\s+bancaria|routing\s+number|credit\s+card\s+number|bank\s+account)\b/i,
    'Datos de pago en venta',
    'No recolectes datos de pago durante una cita de ventas de Medicare.'),

  // Discriminacion / cherry-picking por salud.
  rule('discriminacion', 'block',
    /\b(solo\s+si\s+est[aá]\s+san|no\s+acepto\s+(enfermos?|gente\s+enferma)|rechaz\w*\s+por\s+(su\s+)?(enfermedad|condici[oó]n)|cherry\s*-?pick)\b/i,
    'Seleccion por estado de salud',
    'No puedes seleccionar o rechazar miembros por su salud. Es discriminatorio e ilegal.'),

  // Grabacion: California es consentimiento de 2 partes (no-negociable #6).
  // Esto es un INFO (recordatorio), no se detecta por texto sino por contexto de llamada.
];

// Identificadores sensibles (PHI/PII) que no deben circular en canales inseguros.
export const PII_RULES = [
  rule('ssn', 'warn', /\b\d{3}-\d{2}-\d{4}\b/, 'SSN detectado', 'Maneja el SSN solo en sistemas seguros; no en chat/email sin cifrar.'),
  rule('mbi', 'warn', /\b[1-9][A-Za-z][0-9A-Za-z]\d[A-Za-z][0-9A-Za-z]\d[A-Za-z]{2}\d{2}\b/, 'MBI (Medicare ID) detectado', 'El MBI es PII; mantenlo en sistemas seguros.'),
  rule('tarjeta', 'warn', /\b(?:\d[ -]?){15,16}\b/, 'Posible numero de tarjeta', 'No compartas numeros de tarjeta por canales inseguros.'),
];

const LEVEL_RANK = { block: 3, warn: 2, info: 1, ok: 0 };

// Revisa un draft dirigido al miembro. Devuelve flags + nivel agregado.
export function review(text, { includePII = true } = {}) {
  const t = String(text || '');
  const flags = [];
  for (const r of [...RULES, ...(includePII ? PII_RULES : [])]) {
    const m = t.match(r.re);
    if (m) flags.push({ id: r.id, severity: r.severity, label: r.label, fix: r.fix, match: m[0].slice(0, 60) });
  }
  // nivel agregado = la peor severidad encontrada
  let level = 'ok';
  for (const f of flags) if (LEVEL_RANK[f.severity] > LEVEL_RANK[level]) level = f.severity;
  return {
    level,
    pass: level === 'ok',
    flags,
    summary: flags.length
      ? `${flags.length} hallazgo(s) — peor: ${level}`
      : 'Sin problemas de cumplimiento detectados.',
  };
}

// Escanea solo PII (para el hook ligero del chat sobre el input del agente).
export function scanPII(text) {
  return review(text, { includePII: true }).flags.filter((f) => PII_RULES.some((r) => r.id === f.id));
}
