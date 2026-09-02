<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_ROW_RENDER.PHP — piezas chiquitas de HTML (avatar, badge de
 *  estado) compartidas por los endpoints de "una sola fila" (ej.
 *  member_row.php, ticket_row.php) que refrescan un renglón de una
 *  tabla sin recargar todo el CRM. Duplica a propósito badge()/av() de
 *  index.php en vez de incluir ese archivo completo — así estos
 *  endpoints se quedan chiquitos y rápidos.
 * ═══════════════════════════════════════════════════════════════════ */
function row_badge(?string $s, bool $sm = false): string {
    $s = $s ?? '';
    $map = [
        'ACTIVE'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'IN PROCESS'=>['#1B5E8C','#EBF5FB','#A9D0E8'],
        'PLAN CHANGE'=>['#5B3FAF','#F3F0FB','#C2B0E8'],'SIN HACER'=>['#C07A1A','#FEF8EE','#F5D5A0'],
        'SIN FIRMAR'=>['#C05C1A','#FEF2EB','#F5C4A0'],'CANCELED'=>['#B83232','#FDF0EE','#EFA09A'],
        'DENIED'=>['#B83232','#FDF0EE','#EFA09A'],'DISENROLLED'=>['#993C1D','#FAECE7','#F0997B'],
        'PROSPECT'=>['#1E7A8C','#EAF4F6','#8DC8D0'],'ABIERTO'=>['#B83232','#FDF0EE','#EFA09A'],
        'PENDIENTE'=>['#1B5E8C','#EBF5FB','#A9D0E8'],'EN PROCESO'=>['#C07A1A','#FEF8EE','#F5D5A0'],
        'CERRADO'=>['#1E7A5C','#EAF5F0','#8DCFBA'],'ALTA'=>['#B83232','#FDF0EE','#EFA09A'],
        'MEDIA'=>['#C07A1A','#FEF8EE','#F5D5A0'],'BAJA'=>['#1E7A8C','#EAF4F6','#8DC8D0'],
    ];
    $c = $map[$s] ?? ['#7A90A4','#F4F8FC','#C8DFF0'];
    $p = $sm ? '2px 8px' : '3px 10px'; $f = $sm ? '9px' : '10px';
    return "<span style=\"padding:$p;border-radius:20px;font-size:$f;font-weight:800;background:{$c[1]};color:{$c[0]};border:1px solid {$c[2]};white-space:nowrap;letter-spacing:.5px;text-transform:uppercase\">".h($s)."</span>";
}
function row_av(string $i, string $c, int $z = 24): string {
    return "<div style=\"width:{$z}px;height:{$z}px;border-radius:50%;background:$c;display:flex;align-items:center;justify-content:center;font-size:".round($z*.32)."px;font-weight:900;color:#fff;flex-shrink:0;font-family:'DM Sans',sans-serif\">$i</div>";
}
