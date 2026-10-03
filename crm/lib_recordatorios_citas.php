<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_RECORDATORIOS_CITAS.PHP — recordatorios automáticos por SMS de
 *  las citas, usando Twilio (lib_twilio.php) — pedido de Isabel.
 *  ─────────────────────────────────────────────────────────────────
 *  Dos recordatorios por cita, cada uno se manda UNA sola vez:
 *    - 48 horas antes
 *    - 2 horas antes (el mismo día)
 *  Solo aplica a citas PENDIENTE con un miembro ligado (miembro_id) que
 *  tenga teléfono guardado — desde este cambio, toda cita nueva lo exige
 *  (ver save_cita/update_cita en api.php). Las citas viejas que solo
 *  tenían un nombre libre ("cliente") simplemente no reciben recordatorio
 *  hasta que se les ligue un miembro.
 *
 *  Quién llama a recordatorios_citas_procesar(): cron_recordatorios_citas.php,
 *  disparado por un Cron Job real de cPanel cada 15-30 minutos (este CRM
 *  corre en hosting compartido, no hay ningún otro mecanismo de "tarea
 *  programada" disponible).
 * ═══════════════════════════════════════════════════════════════════ */

// Migración self-healing — mismo patrón que usa el resto del proyecto
// (ver tipo_persona en citas, o campana_id/es_mms en sms_mensajes).
function asegurarColumnasRecordatorioCitas(PDO $pdo): void {
    try {
        $colsCitas = $pdo->query("SHOW COLUMNS FROM citas")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('recordatorio_48h_enviado_at', $colsCitas, true)) {
            $pdo->exec("ALTER TABLE citas ADD COLUMN recordatorio_48h_enviado_at DATETIME DEFAULT NULL");
        }
        if (!in_array('recordatorio_2h_enviado_at', $colsCitas, true)) {
            $pdo->exec("ALTER TABLE citas ADD COLUMN recordatorio_2h_enviado_at DATETIME DEFAULT NULL");
        }
    } catch (Exception $e) {}
    try {
        asegurarTablaSmsMensajes($pdo);
        $colsSms = $pdo->query("SHOW COLUMNS FROM sms_mensajes")->fetchAll(PDO::FETCH_COLUMN);
        // 'tipo' distingue un recordatorio automático de un SMS manual o de
        // campaña — sin esto no se puede filtrar el reporte de Isabel ni
        // distinguirlo en el hilo de SMS del perfil del miembro.
        if (!in_array('tipo', $colsSms, true)) {
            $pdo->exec("ALTER TABLE sms_mensajes ADD COLUMN tipo VARCHAR(30) DEFAULT 'MANUAL'");
        }
        if (!in_array('cita_id', $colsSms, true)) {
            $pdo->exec("ALTER TABLE sms_mensajes ADD COLUMN cita_id INT DEFAULT NULL");
        }
    } catch (Exception $e) {}
}

// "YYYY-MM-DD" + "HH:MM:SS" → "lunes 10 de noviembre a la 1:30 pm", sin
// depender del locale del servidor (varía entre hostings — ver strftime_es
// en lib_row_render.php, mismo motivo aquí).
function recordatorio_fecha_legible(string $fecha, string $hora): string {
    $dias  = [0=>'domingo',1=>'lunes',2=>'martes',3=>'miércoles',4=>'jueves',5=>'viernes',6=>'sábado'];
    $meses = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    $ts = strtotime($fecha . ' ' . $hora);
    if ($ts === false) return $fecha . ' ' . substr($hora, 0, 5);
    $diaSemana = $dias[(int) date('w', $ts)] ?? '';
    $diaMes    = (int) date('j', $ts);
    $mes       = $meses[(int) date('n', $ts)] ?? '';
    $h24       = (int) date('G', $ts);
    $min       = (int) date('i', $ts);
    $ampm      = $h24 < 12 ? 'am' : 'pm';
    $h12       = $h24 % 12; if ($h12 === 0) $h12 = 12;
    $horaTxt   = $min === 0 ? ($h12 . ' ' . $ampm) : ($h12 . ':' . str_pad((string)$min, 2, '0', STR_PAD_LEFT) . ' ' . $ampm);
    $laOLos    = $h12 === 1 ? 'la' : 'las';
    return "{$diaSemana} {$diaMes} de {$mes} a {$laOLos} {$horaTxt}";
}

function recordatorio_modalidad_legible(string $modalidad): string {
    $map = [
        'OFICINA'        => 'en la oficina',
        'TELÉFONO'       => 'por teléfono',
        'VIDEO'          => 'por video',
        'EN CASA'        => 'en su casa',
        'EN RESTAURANTE' => 'en el restaurante',
    ];
    return $map[$modalidad] ?? strtolower($modalidad);
}

// Arma el texto del SMS para un tipo de recordatorio ('48H' o '2H').
function recordatorio_texto_mensaje(string $tipo, array $cita): string {
    $nombre      = trim($cita['nombre'] ?? '');
    $fechaHora   = recordatorio_fecha_legible($cita['fecha'], $cita['hora']);
    $modalidad   = recordatorio_modalidad_legible($cita['modalidad'] ?? '');
    $saludoNombre = $nombre !== '' ? "Hola {$nombre}, " : 'Hola, ';

    if ($tipo === '2H') {
        return "{$saludoNombre}le recordamos que su cita con Medicare with Isabel es HOY, {$fechaHora} ({$modalidad}). ¡Nos vemos pronto!";
    }
    return "{$saludoNombre}le recordamos su cita con Medicare with Isabel el {$fechaHora} ({$modalidad}). Si necesita cambiarla, responda este mensaje o llámenos.";
}

// Procesa el lote completo — lo llama cron_recordatorios_citas.php. Devuelve
// un resumen para dejar registro de lo que pasó en esa corrida.
function recordatorios_citas_procesar(PDO $pdo): array {
    asegurarColumnasRecordatorioCitas($pdo);

    $resumen = ['revisadas' => 0, 'enviados' => 0, 'omitidos_optout' => 0, 'omitidos_sin_telefono' => 0, 'fallidos' => 0];

    // Pre-filtro en SQL (barato, usa el índice de fecha/estado) — la
    // decisión FINA de qué tipo de recordatorio toca se recalcula abajo con
    // minutos_restantes, calculado por la BASE DE DATOS (NOW()), no por PHP
    // — así no importa si el reloj del servidor web está desincronizado del
    // de MySQL (común en hosting compartido).
    $sql = "SELECT c.id, c.miembro_id, c.agente_id, c.tipo, c.modalidad, c.fecha, c.hora,
                   c.recordatorio_48h_enviado_at, c.recordatorio_2h_enviado_at,
                   m.nombre, m.apellido, m.telefono, m.telefono2,
                   TIMESTAMPDIFF(MINUTE, NOW(), TIMESTAMP(c.fecha, c.hora)) AS minutos_restantes
            FROM citas c
            INNER JOIN miembros m ON c.miembro_id = m.id
            WHERE c.estado = 'PENDIENTE'
              AND c.miembro_id IS NOT NULL
              AND TIMESTAMP(c.fecha, c.hora) > NOW()
              AND (
                   (c.recordatorio_48h_enviado_at IS NULL AND TIMESTAMP(c.fecha, c.hora) <= NOW() + INTERVAL 48 HOUR)
                OR (c.recordatorio_2h_enviado_at  IS NULL AND TIMESTAMP(c.fecha, c.hora) <= NOW() + INTERVAL 2 HOUR)
              )";
    $citas = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach ($citas as $cita) {
        $resumen['revisadas']++;
        try {
            $horasRestantes = ((int) $cita['minutos_restantes']) / 60;

            // Ventanas EXCLUYENTES (ver lib_recordatorios_citas.php arriba):
            // si ya estamos a 2h o menos, el recordatorio de "48h antes" ya
            // no tiene caso mandarlo (llegaría confuso, hablando de "en 2
            // días" cuando falta una hora) — se deja pasar en silencio.
            if ($cita['recordatorio_2h_enviado_at'] === null && $horasRestantes <= 2) {
                $tipo = '2H';
            } elseif ($cita['recordatorio_48h_enviado_at'] === null && $horasRestantes <= 48 && $horasRestantes > 2) {
                $tipo = '48H';
            } else {
                continue;
            }

            $telefono = normalizar_tel($cita['telefono'] ?: ($cita['telefono2'] ?? ''));
            if ($telefono === '') {
                $resumen['omitidos_sin_telefono']++;
                continue; // no se marca como enviado — si le agregan teléfono después, se manda en la siguiente corrida
            }

            $mensaje = recordatorio_texto_mensaje($tipo, $cita);
            $colEnviado = $tipo === '2H' ? 'recordatorio_2h_enviado_at' : 'recordatorio_48h_enviado_at';
            $tipoSms = 'RECORDATORIO_CITA_' . $tipo;

            if (sms_esta_optout($pdo, $telefono)) {
                // Se marca como "ya tratado" para no revisarlo en cada corrida
                // del cron para siempre — pero queda el registro de por qué
                // nunca se le mandó nada.
                $pdo->prepare("INSERT INTO sms_mensajes (telefono, miembro_id, direccion, cuerpo, estado, agente_id, cita_id, tipo)
                               VALUES (?, ?, 'SALIENTE', ?, 'omitido_optout', ?, ?, ?)")
                    ->execute([$telefono, $cita['miembro_id'], $mensaje, $cita['agente_id'], $cita['id'], $tipoSms]);
                $pdo->prepare("UPDATE citas SET {$colEnviado} = NOW() WHERE id = ?")->execute([$cita['id']]);
                $resumen['omitidos_optout']++;
                continue;
            }

            $res = twilio_enviar_sms($telefono, $mensaje);
            $costo = $res['ok'] ? sms_calcular_costo($mensaje, false, true) : 0;
            $pdo->prepare("INSERT INTO sms_mensajes (telefono, miembro_id, direccion, cuerpo, estado, twilio_sid, agente_id, cita_id, tipo, costo_estimado)
                           VALUES (?, ?, 'SALIENTE', ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $telefono, $cita['miembro_id'], $mensaje,
                    $res['ok'] ? ($res['estado'] ?? 'enviado') : 'error',
                    $res['sid'] ?? null, $cita['agente_id'], $cita['id'], $tipoSms, $costo,
                ]);

            if ($res['ok']) {
                $pdo->prepare("UPDATE citas SET {$colEnviado} = NOW() WHERE id = ?")->execute([$cita['id']]);
                $resumen['enviados']++;
            } else {
                // NO se marca como enviado — si fue un fallo pasajero, la
                // siguiente corrida del cron lo vuelve a intentar. Si el
                // número está de verdad muerto, sms_registrar_fallo_envio ya
                // se encarga de bloquearlo (mismo mecanismo que usan las
                // campañas) para que no se le siga intentando para siempre.
                sms_registrar_fallo_envio($pdo, $telefono, $res['codigo'] ?? null, $res['error'] ?? null);
                $resumen['fallidos']++;
            }
        } catch (Exception $e) {
            $resumen['fallidos']++;
        }
    }

    return $resumen;
}
