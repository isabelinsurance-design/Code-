<?php
/**
 * nomina_calc.php
 * Cálculo de nómina por quincena — compartido entre reporte_nomina.php
 * y reporte_pagos.php para que ambos usen exactamente la misma fórmula.
 */

// ── Límites de fechas de una quincena ───────────────────────────────────────
function quincena_rango(int $year, int $month, int $q): array {
    if ($q === 1) {
        $inicio = sprintf('%04d-%02d-01', $year, $month);
        $fin    = sprintf('%04d-%02d-15', $year, $month);
    } else {
        $ultimo_dia = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
        $inicio = sprintf('%04d-%02d-16', $year, $month);
        $fin    = sprintf('%04d-%02d-%02d', $year, $month, $ultimo_dia);
    }
    return [$inicio, $fin];
}

// ── Segundos trabajados en un registro de asistencia ────────────────────────
function segundos_trabajados(PDO $pdo, array $r): int {
    if (!$r['check_in'] || !$r['check_out']) return 0;
    $ci = strtotime('1970-01-01 ' . $r['check_in']);
    $co = strtotime('1970-01-01 ' . $r['check_out']);
    $t  = max(0, $co - $ci);
    // descontar almuerzo
    if ($r['lunch_out'] && $r['lunch_in']) {
        $lo = strtotime('1970-01-01 ' . $r['lunch_out']);
        $li = strtotime('1970-01-01 ' . $r['lunch_in']);
        $t -= max(0, $li - $lo);
    }
    // descontar el primer break del día
    if (!empty($r['break_out']) && !empty($r['break_in'])) {
        $bo = strtotime('1970-01-01 ' . $r['break_out']);
        $bi = strtotime('1970-01-01 ' . $r['break_in']);
        $t -= max(0, $bi - $bo);
    }
    // descontar breaks adicionales (más de uno por día)
    if (!empty($r['id'])) {
        try {
            $bx = $pdo->prepare("SELECT break_out, break_in FROM asistencia_breaks WHERE asistencia_id=? AND break_in IS NOT NULL");
            $bx->execute([$r['id']]);
            foreach ($bx->fetchAll() as $b) {
                $t -= max(0, strtotime('1970-01-01 ' . $b['break_in']) - strtotime('1970-01-01 ' . $b['break_out']));
            }
        } catch (Exception $e) {}
    }
    return max(0, $t);
}

// ── Días laborables en el rango para un agente ──────────────────────────────
function dias_laborables_rango(array $agent, string $inicio, string $fin): array {
    $dias_trabajo = [
        2 => (bool)$agent['trabaja_lunes'],
        3 => (bool)$agent['trabaja_martes'],
        4 => (bool)$agent['trabaja_miercoles'],
        5 => (bool)$agent['trabaja_jueves'],
        6 => (bool)$agent['trabaja_viernes'],
        7 => (bool)$agent['trabaja_sabado'],
        1 => false, // domingo
    ];
    $cur   = strtotime($inicio);
    $end   = strtotime($fin);
    $total = 0;
    $horas = 0.0;
    while ($cur <= $end) {
        $dow_mysql = (int)date('w', $cur) + 1; // 1=dom .. 7=sab (como DAYOFWEEK de MySQL)
        if ($dias_trabajo[$dow_mysql] ?? false) {
            $total++;
            if ($dow_mysql == 7) { // sábado
                $horas += (float)$agent['horas_sabado'];
            } else {
                $horas += (float)$agent['horas_semana'];
            }
        }
        $cur = strtotime('+1 day', $cur);
    }
    return ['dias' => $total, 'horas' => $horas];
}

/**
 * Calcula el desglose completo de nómina de un agente para una quincena:
 * horas trabajadas (check-in/out + ajustes manuales), horas esperadas,
 * horas extra y el pago correspondiente.
 */
function calcular_nomina_agente(PDO $pdo, array $ag, int $year, int $month, int $q, string $fecha_inicio, string $fecha_fin): array {
    $stmt = $pdo->prepare(
        "SELECT a.*, DAYOFWEEK(a.fecha) as dow
         FROM asistencia a
         WHERE a.agente_id = ? AND a.fecha BETWEEN ? AND ?
         ORDER BY a.fecha"
    );
    $stmt->execute([$ag['id'], $fecha_inicio, $fecha_fin]);
    $registros = $stmt->fetchAll();

    $seg_trabajados   = 0;
    $dias_con_checkin = 0;
    $detalle = [];
    foreach ($registros as $r) {
        $seg = segundos_trabajados($pdo, $r);
        $seg_trabajados += $seg;
        if ($r['check_in'] && $r['check_out']) $dias_con_checkin++;
        $detalle[] = [
            'fecha'     => $r['fecha'],
            'dow'       => $r['dow'],
            'check_in'  => $r['check_in'],
            'check_out' => $r['check_out'],
            'horas'     => round($seg / 3600, 2),
        ];
    }

    $esperado         = dias_laborables_rango($ag, $fecha_inicio, $fecha_fin);
    $horas_esperadas  = $esperado['horas'];
    $dias_esperados   = $esperado['dias'];
    $horas_trabajadas = round($seg_trabajados / 3600, 2);

    $stmt_ajustes = $pdo->prepare(
        "SELECT * FROM nomina_ajustes WHERE agente_id=? AND anio=? AND mes=? AND quincena=? ORDER BY created_at"
    );
    $stmt_ajustes->execute([$ag['id'], $year, $month, $q]);
    $ajustes      = $stmt_ajustes->fetchAll();
    $horas_ajuste = round(array_sum(array_column($ajustes, 'horas')), 2);

    $horas_trabajadas_total = round($horas_trabajadas + $horas_ajuste, 2);

    $salario_base = (float)$ag['salario_quincenal'];
    $ratio_horas  = $horas_esperadas > 0 ? ($horas_trabajadas_total / $horas_esperadas) : 0;
    $pago_base    = round(min(1, $ratio_horas) * $salario_base, 2);

    $horas_extra = max(0, round($horas_trabajadas_total - $horas_esperadas, 2));
    $valor_hora  = $horas_esperadas > 0 ? ($salario_base / $horas_esperadas) : 0;
    $pago_extra  = round($horas_extra * $valor_hora * 1, 2); // 1x tiempo y medio

    $pago_calculado = $pago_base + $pago_extra;

    $dias_ausente = max(0, $dias_esperados - $dias_con_checkin);
    $porcentaje   = $horas_esperadas > 0
        ? min(100, round(($horas_trabajadas_total / $horas_esperadas) * 100, 1))
        : 0;

    return [
        'ag'                     => $ag,
        'horas_esperadas'        => $horas_esperadas,
        'horas_trabajadas'       => $horas_trabajadas,
        'horas_ajuste'           => $horas_ajuste,
        'horas_trabajadas_total' => $horas_trabajadas_total,
        'ajustes'                => $ajustes,
        'horas_extra'            => $horas_extra,
        'dias_esperados'         => $dias_esperados,
        'dias_presentes'         => $dias_con_checkin,
        'dias_ausentes'          => $dias_ausente,
        'salario_base'           => $salario_base,
        'pago_base'              => $pago_base,
        'pago_extra'             => $pago_extra,
        'pago_calculado'         => $pago_calculado,
        'porcentaje'             => $porcentaje,
        'valor_hora'             => round($valor_hora, 4),
        'detalle'                => $detalle,
    ];
}
