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
        // 'direccion' también se agrega en save_cita/update_cita (api.php),
        // pero el cron (cron_recordatorios_citas.php) NUNCA pasa por ahí —
        // sin esto aquí también, el cron truena con "Unknown column" en un
        // sitio donde nadie haya guardado o editado una cita todavía.
        if (!in_array('direccion', $colsCitas, true)) {
            $pdo->exec("ALTER TABLE citas ADD COLUMN direccion VARCHAR(255) DEFAULT NULL");
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

// Misma idea que recordatorio_fecha_legible() pero en inglés — "Monday
// November 10 at 1:30 pm" — para miembros con idioma='ENG' (ver columna
// miembros.idioma, ya existente en el sistema).
function recordatorio_fecha_legible_en(string $fecha, string $hora): string {
    $dias  = [0=>'Sunday',1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday'];
    $meses = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];
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
    return "{$diaSemana} {$mes} {$diaMes} at {$horaTxt}";
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

function recordatorio_modalidad_legible_en(string $modalidad): string {
    $map = [
        'OFICINA'        => 'at the office',
        'TELÉFONO'       => 'by phone',
        'VIDEO'          => 'by video',
        'EN CASA'        => 'at your home',
        'EN RESTAURANTE' => 'at the restaurant',
    ];
    return $map[$modalidad] ?? strtolower($modalidad);
}

// Arma el texto del SMS para un tipo de recordatorio ('48H' o '2H'). El
// idioma viene de miembros.idioma ('ESP' por default, 'ENG' si el miembro
// habla inglés — mismo campo que ya usa el resto del CRM) para que un
// miembro que habla inglés reciba el recordatorio en su idioma.
function recordatorio_texto_mensaje(string $tipo, array $cita): string {
    $nombre    = trim($cita['nombre'] ?? '');
    $esIngles  = strtoupper(trim($cita['idioma'] ?? 'ESP')) === 'ENG';
    $direccion = trim($cita['direccion'] ?? '');

    if ($esIngles) {
        $fechaHora    = recordatorio_fecha_legible_en($cita['fecha'], $cita['hora']);
        $modalidad    = recordatorio_modalidad_legible_en($cita['modalidad'] ?? '');
        $saludoNombre = $nombre !== '' ? "Hi {$nombre}, " : 'Hi, ';
        $lineaDireccion = $direccion !== '' ? "\nAddress: {$direccion}" : '';
        if ($tipo === '2H') {
            return "{$saludoNombre}this is a reminder that your appointment with Isabel Fuentes is TODAY, {$fechaHora} ({$modalidad}).{$lineaDireccion}\nSee you soon!\n(Automated message, do not reply to this number. For changes call 323-402-4145.)";
        }
        return "{$saludoNombre}this is a reminder of your appointment with Isabel Fuentes on {$fechaHora} ({$modalidad}).{$lineaDireccion}\nIf you need to reschedule, please call us at 323-402-4145.\n(Automated message, do not reply to this number.)";
    }

    $fechaHora   = recordatorio_fecha_legible($cita['fecha'], $cita['hora']);
    $modalidad   = recordatorio_modalidad_legible($cita['modalidad'] ?? '');
    $saludoNombre = $nombre !== '' ? "Hola {$nombre}, " : 'Hola, ';
    // Pedido de Isabel: aunque diga "en la oficina", conviene mandar
    // también la dirección para que no tengan que preguntar dónde es.
    $lineaDireccion = $direccion !== '' ? "\nDirección: {$direccion}" : '';

    // Pedido de Isabel: NO invitar a "responder este mensaje" — eso llegaría
    // al número de Twilio, que ella no está revisando para esto. En vez de
    // eso, se les pide llamar directo a su número real.
    if ($tipo === '2H') {
        return "{$saludoNombre}le recordamos que su cita con Isabel Fuentes es HOY, {$fechaHora} ({$modalidad}).{$lineaDireccion}\n¡Nos vemos pronto!\n(Mensaje automático, no responda a este número. Para cambios llame al 323-402-4145.)";
    }
    return "{$saludoNombre}le recordamos su cita con Isabel Fuentes el {$fechaHora} ({$modalidad}).{$lineaDireccion}\nSi necesita cambiarla, llámenos al 323-402-4145.\n(Mensaje automático, no responda a este número.)";
}

// Pedido de Isabel: además de los recordatorios de 48h/2h (que esperan a
// que se acerque la fecha), al agendar o cambiar una cita se manda un SMS
// de confirmación AL INSTANTE. Mismo idioma automático que los
// recordatorios (miembros.idioma).
function recordatorio_texto_confirmacion(array $cita, bool $esReagendada = false): string {
    $nombre    = trim($cita['nombre'] ?? '');
    $esIngles  = strtoupper(trim($cita['idioma'] ?? 'ESP')) === 'ENG';
    $direccion = trim($cita['direccion'] ?? '');

    if ($esIngles) {
        $fechaHora      = recordatorio_fecha_legible_en($cita['fecha'], $cita['hora']);
        $modalidad      = recordatorio_modalidad_legible_en($cita['modalidad'] ?? '');
        $saludoNombre   = $nombre !== '' ? "Hi {$nombre}, " : 'Hi, ';
        $lineaDireccion = $direccion !== '' ? "\nAddress: {$direccion}" : '';
        $accion         = $esReagendada ? 'rescheduled' : 'scheduled';
        return "{$saludoNombre}your appointment with Isabel Fuentes has been {$accion} for {$fechaHora} ({$modalidad}).{$lineaDireccion}\n(Automated message, do not reply to this number. For changes call 323-402-4145.)";
    }

    $fechaHora      = recordatorio_fecha_legible($cita['fecha'], $cita['hora']);
    $modalidad      = recordatorio_modalidad_legible($cita['modalidad'] ?? '');
    $saludoNombre   = $nombre !== '' ? "Hola {$nombre}, " : 'Hola, ';
    $lineaDireccion = $direccion !== '' ? "\nDirección: {$direccion}" : '';
    $accion         = $esReagendada ? 'reagendada' : 'agendada';
    return "{$saludoNombre}su cita con Isabel Fuentes quedó {$accion} para el {$fechaHora} ({$modalidad}).{$lineaDireccion}\n(Mensaje automático, no responda a este número. Para cambios llame al 323-402-4145.)";
}

// La llaman save_cita y update_cita (api.php) justo después de
// guardar/actualizar la cita — manda la confirmación de inmediato, sin
// esperar a los recordatorios programados. No toca recordatorio_48h/2h_at
// (esas columnas son solo para los recordatorios de 48h/2h); esta
// confirmación es un mensaje aparte y no se vuelve a repetir sola.
function enviar_confirmacion_cita(PDO $pdo, int $citaId, bool $esReagendada = false): void {
    try {
        asegurarColumnasRecordatorioCitas($pdo);
        $stmt = $pdo->prepare("SELECT c.id, c.miembro_id, c.agente_id, c.modalidad, c.fecha, c.hora, c.direccion,
                                      m.nombre, m.telefono, m.telefono2, m.idioma
                               FROM citas c
                               INNER JOIN miembros m ON c.miembro_id = m.id
                               WHERE c.id = ?");
        $stmt->execute([$citaId]);
        $cita = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cita) return;

        $telefono = normalizar_tel($cita['telefono'] ?: ($cita['telefono2'] ?? ''));
        if ($telefono === '') return;

        $mensaje = recordatorio_texto_confirmacion($cita, $esReagendada);
        $tipoSms = $esReagendada ? 'CONFIRMACION_CITA_REAGENDADA' : 'CONFIRMACION_CITA';

        // Antes esto se salía en silencio sin dejar ningún rastro — si el
        // número estaba en la lista de opt-out, ni Isabel ni este código
        // tenían forma de saber que el SMS de confirmación nunca se intentó
        // mandar. Ahora, igual que hace el cron de recordatorios, se deja
        // un registro en sms_mensajes aunque no se mande nada de verdad.
        if (sms_esta_optout($pdo, $telefono)) {
            $pdo->prepare("INSERT INTO sms_mensajes (telefono, miembro_id, direccion, cuerpo, estado, agente_id, cita_id, tipo)
                           VALUES (?, ?, 'SALIENTE', ?, 'omitido_optout', ?, ?, ?)")
                ->execute([$telefono, $cita['miembro_id'], $mensaje, $cita['agente_id'], $cita['id'], $tipoSms]);
            return;
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
        if (!$res['ok']) {
            sms_registrar_fallo_envio($pdo, $telefono, $res['codigo'] ?? null, $res['error'] ?? null);
        } else {
            // Si la cita es dentro de las próximas 48 horas, la confirmación que se
            // acaba de mandar ya dice el día y la hora: se da por hecho el
            // recordatorio de "48h antes" (antes llegaban dos SMS casi seguidos).
            $hrs = (strtotime($cita['fecha'] . ' ' . $cita['hora']) - time()) / 3600;
            if ($hrs <= 48 && $hrs > 2) {
                $pdo->prepare("UPDATE citas SET recordatorio_48h_enviado_at=NOW() WHERE id=? AND recordatorio_48h_enviado_at IS NULL")->execute([$cita['id']]);
            }
        }
    } catch (Exception $e) {}
}

// Diagnóstico temporal — para detectar si el servidor de MySQL tiene una
// hora/zona horaria distinta a la del servidor web (muy común en hosting
// compartido), que haría que "revisadas" siempre salga en 0 aunque sí haya
// citas próximas: todas las comparaciones de tiempo del cron se hacen con
// NOW() de MySQL, así que si ese reloj está desfasado varias horas respecto
// a la hora real, las ventanas de 48h/2h nunca calzan.
function recordatorios_citas_diagnostico(PDO $pdo): array {
    asegurarColumnasRecordatorioCitas($pdo);
    $db = $pdo->query("SELECT NOW() AS db_now, @@session.time_zone AS db_tz, @@global.time_zone AS db_tz_global")->fetch(PDO::FETCH_ASSOC);
    $ahora = date('Y-m-d H:i:s');
    // LEFT JOIN (no INNER) a propósito — así se ve de una vez si el
    // miembro_id de la cita en realidad NO tiene fila en miembros (lo que
    // haría que la consulta real de recordatorios_citas_procesar(), que sí
    // usa INNER JOIN, descarte la cita en silencio sin ningún error).
    // minutos_restantes ahora se calcula con la hora de PHP ($ahora), NO con
    // NOW() de MySQL — mismo cambio que recordatorios_citas_procesar(), para
    // que lo que se ve aquí sea justo lo que de verdad va a usar el cron.
    $stmt = $pdo->prepare("SELECT c.id, c.fecha, c.hora, c.estado, c.miembro_id, c.direccion,
                                  c.recordatorio_48h_enviado_at, c.recordatorio_2h_enviado_at,
                                  TIMESTAMPDIFF(MINUTE, ?, TIMESTAMP(c.fecha, c.hora)) AS minutos_restantes,
                                  m.id AS miembro_encontrado_id, m.nombre AS miembro_nombre,
                                  m.telefono AS miembro_telefono, m.telefono2 AS miembro_telefono2
                           FROM citas c
                           LEFT JOIN miembros m ON c.miembro_id = m.id
                           WHERE c.fecha BETWEEN CURDATE() - INTERVAL 1 DAY AND CURDATE() + INTERVAL 3 DAY
                           ORDER BY c.fecha, c.hora");
    $stmt->execute([$ahora]);
    $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return [
        'hora_del_servidor_web_php' => $ahora,
        'hora_de_la_base_de_datos_mysql' => $db['db_now'] ?? null,
        'zona_horaria_mysql_sesion' => $db['db_tz'] ?? null,
        'zona_horaria_mysql_global' => $db['db_tz_global'] ?? null,
        'citas_proximos_dias' => $citas,
    ];
}

// Procesa el lote completo — lo llama cron_recordatorios_citas.php. Devuelve
// un resumen para dejar registro de lo que pasó en esa corrida.
function recordatorios_citas_procesar(PDO $pdo): array {
    asegurarColumnasRecordatorioCitas($pdo);

    $resumen = ['revisadas' => 0, 'enviados' => 0, 'omitidos_optout' => 0, 'omitidos_sin_telefono' => 0, 'fallidos' => 0];

    // Se usa la hora de PHP (date()), NO la de MySQL (NOW()) — en hosting
    // compartido es común que el servidor de base de datos sea una máquina
    // aparte con su reloj/zona horaria mal configurada (le pasó a Isabel:
    // su MySQL marcaba una hora completa adelantada respecto a la hora real
    // de Los Ángeles, mientras que el servidor web sí estaba bien). Se pasa
    // la hora de PHP como parámetro a la consulta en vez de confiar en
    // NOW()/INTERVAL de SQL.
    $ahora     = date('Y-m-d H:i:s');
    $limite48h = date('Y-m-d H:i:s', strtotime('+48 hours'));
    $limite2h  = date('Y-m-d H:i:s', strtotime('+2 hours'));

    // Pre-filtro en SQL (barato, usa el índice de fecha/estado) — la
    // decisión FINA de qué tipo de recordatorio toca se recalcula abajo en
    // PHP, con ese mismo $ahora, para no volver a depender del reloj de
    // MySQL en ningún momento.
    $sql = "SELECT c.id, c.miembro_id, c.agente_id, c.tipo, c.modalidad, c.fecha, c.hora, c.direccion,
                   c.recordatorio_48h_enviado_at, c.recordatorio_2h_enviado_at,
                   m.nombre, m.apellido, m.telefono, m.telefono2, m.idioma
            FROM citas c
            INNER JOIN miembros m ON c.miembro_id = m.id
            WHERE c.estado = 'PENDIENTE'
              AND c.miembro_id IS NOT NULL
              AND TIMESTAMP(c.fecha, c.hora) > ?
              AND (
                   (c.recordatorio_48h_enviado_at IS NULL AND TIMESTAMP(c.fecha, c.hora) <= ?)
                OR (c.recordatorio_2h_enviado_at  IS NULL AND TIMESTAMP(c.fecha, c.hora) <= ?)
              )";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ahora, $limite48h, $limite2h]);
    $citas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($citas as $cita) {
        $resumen['revisadas']++;
        try {
            $horasRestantes = (strtotime($cita['fecha'] . ' ' . $cita['hora']) - strtotime($ahora)) / 3600;

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
                $pdo->prepare("UPDATE citas SET {$colEnviado} = ? WHERE id = ?")->execute([$ahora, $cita['id']]);
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
                $pdo->prepare("UPDATE citas SET {$colEnviado} = ? WHERE id = ?")->execute([$ahora, $cita['id']]);
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
