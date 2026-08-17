<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_TELEFONO.PHP — normaliza números de teléfono antes de guardar
 *  ─────────────────────────────────────────────────────────────────
 *  Deja todos los números en el mismo formato sin importar cómo se
 *  hayan escrito (con paréntesis, guiones, espacios, con o sin +1):
 *  siempre "+" seguido solo de dígitos, ej. +13102700626.
 *  Si el número tiene 10 dígitos (formato típico de EEUU sin código
 *  de país) se le agrega el 1 al inicio. Si ya trae 11 dígitos (con
 *  el 1) o cualquier otra cantidad (otro país, número incompleto),
 *  se deja tal cual con el "+" al frente, para no inventar dígitos.
 * ═══════════════════════════════════════════════════════════════════ */
function normalizar_tel(?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    $digitos = preg_replace('/\D/', '', $raw);
    if ($digitos === '') return '';
    if (strlen($digitos) === 10) { $digitos = '1' . $digitos; }
    return '+' . $digitos;
}
