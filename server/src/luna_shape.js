// ============================================================
//  luna_shape.js — normalización pura de respuestas de LUNA
//  ────────────────────────────────────────────────────────
//  Función pura SIN dependencias externas, para que sea testeable
//  en aislamiento (test/luna_unwrap.test.js) sin arrastrar todo el
//  árbol de imports de luna_client.js.
// ============================================================

// Desempaca data.tickets / data.miembros / data.citas / data.leads /
// data.soas etc → array directo. CRÍTICO (AUDIT.md H1): una respuesta
// con forma INESPERADA NO se disfraza de lista vacía — devuelve
// shape_error, para que el caller sepa que LUNA sí mandó algo que no
// entendimos (este es el patrón que vació el team email).
export function unwrapArrayResponse(r, possibleKeys = ['tickets', 'miembros', 'leads', 'soas', 'citas', 'items', 'data']) {
  if (!r || !r.ok) return r;
  let arr = r.data;
  if (arr && !Array.isArray(arr)) {
    for (const k of possibleKeys) {
      if (Array.isArray(arr[k])) { arr = arr[k]; break; }
    }
  }
  if (!Array.isArray(arr)) {
    if (arr != null && typeof arr === 'object') {
      const keys = Object.keys(arr).slice(0, 8).join(',');
      console.warn(`[luna] respuesta con forma desconocida — keys: ${keys}`);
      return {
        ok: false,
        kind: 'shape_error',
        error: `LUNA respondió con una forma que no reconozco (keys: ${keys}). No es lista vacía — es formato inesperado.`,
        elapsed_ms: r.elapsed_ms,
      };
    }
    // null/undefined → legítimamente vacío
    arr = [];
  }
  return { ...r, data: arr };
}
