<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_CUMPLEANOS_SMS.PHP — SMS automático de cumpleaños (con la
 *  tarjeta de "Feliz Cumpleaños" de Isabel adjunta como MMS), pedido de
 *  Isabel. Se manda UNA sola vez por miembro por año, el mismo día de
 *  su cumpleaños (a diferencia del widget de CUMPLEAÑOS del Dashboard,
 *  que corre el aviso al lunes si cae domingo — eso es solo para que el
 *  equipo lo note en la oficina; el SMS automático no depende de que
 *  haya alguien trabajando ese día, así que se manda el día real).
 *
 *  Quién llama a cumpleanos_sms_procesar(): cron_cumpleanos_sms.php,
 *  disparado por un Cron Job de cPanel una vez al día (no hace falta
 *  cada 15-30 min como el de citas — los cumpleaños no tienen prisa).
 * ═══════════════════════════════════════════════════════════════════ */

// Migración self-healing — mismo patrón que el resto del proyecto.
function asegurarColumnaCumpleanosSms(PDO $pdo): void {
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM miembros")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('cumpleanos_sms_anio', $cols, true)) {
            $pdo->exec("ALTER TABLE miembros ADD COLUMN cumpleanos_sms_anio INT DEFAULT NULL");
        }
    } catch (Exception $e) {}
    try {
        asegurarTablaSmsMensajes($pdo);
        $colsSms = $pdo->query("SHOW COLUMNS FROM sms_mensajes")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('tipo', $colsSms, true)) {
            $pdo->exec("ALTER TABLE sms_mensajes ADD COLUMN tipo VARCHAR(30) DEFAULT 'MANUAL'");
        }
    } catch (Exception $e) {}
}

function cumpleanos_texto_mensaje(string $nombre, bool $esIngles): string {
    if ($esIngles) {
        return "Happy birthday, {$nombre}! From Isabel Fuentes. Today we celebrate your life and we're grateful you're part of our community. Remember I'm here to help with your plan and support you every step of the way.\n(Automated message, do not reply to this number. For anything, call us at 323-402-4145.)";
    }
    return "¡Feliz cumpleaños, {$nombre}! De parte de Isabel Fuentes. Hoy celebramos tu vida y agradecemos que formes parte de nuestra comunidad. Recuerda que estoy aquí para ayudarte con tu plan y acompañarte en cada paso.\n(Mensaje automático, no responda a este número. Para cualquier cosa, llámenos al 323-402-4145.)";
}

// Procesa el lote completo — lo llama cron_cumpleanos_sms.php. Devuelve un
// resumen para dejar registro de lo que pasó en esa corrida.
function cumpleanos_sms_procesar(PDO $pdo): array {
    asegurarColumnaCumpleanosSms($pdo);

    $resumen = ['revisados' => 0, 'enviados' => 0, 'omitidos_optout' => 0, 'omitidos_sin_telefono' => 0, 'fallidos' => 0];

    $anioActual = (int) date('Y');
    $mediaUrl = twilio_url_publica('assets/cumpleanos.jpg');

    // Cumple HOY (mes y día exactos) que no hayan recibido ya el SMS este
    // mismo año — así, aunque el cron corra varias veces el mismo día, o
    // se vuelva a correr después, nunca se le manda dos veces a la misma
    // persona en un año.
    $stmt = $pdo->prepare("SELECT id, nombre, apellido, telefono, telefono2, idioma, agente_id
                            FROM miembros
                            WHERE dob IS NOT NULL
                              AND MONTH(dob) = MONTH(CURDATE())
                              AND DAY(dob) = DAY(CURDATE())
                              AND (cumpleanos_sms_anio IS NULL OR cumpleanos_sms_anio <> ?)");
    $stmt->execute([$anioActual]);
    $miembros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($miembros as $m) {
        $resumen['revisados']++;
        try {
            $telefono = normalizar_tel($m['telefono'] ?: ($m['telefono2'] ?? ''));
            if ($telefono === '') {
                $resumen['omitidos_sin_telefono']++;
                continue; // no se marca el año — si le agregan teléfono después, se manda en la siguiente corrida de hoy mismo
            }

            $nombre = trim($m['nombre'] ?? '');
            $esIngles = strtoupper(trim($m['idioma'] ?? 'ESP')) === 'ENG';
            $mensaje = cumpleanos_texto_mensaje($nombre, $esIngles);

            if (sms_esta_optout($pdo, $telefono)) {
                $pdo->prepare("INSERT INTO sms_mensajes (telefono, miembro_id, direccion, cuerpo, estado, agente_id, tipo)
                               VALUES (?, ?, 'SALIENTE', ?, 'omitido_optout', ?, 'CUMPLEANOS')")
                    ->execute([$telefono, $m['id'], $mensaje, $m['agente_id']]);
                $pdo->prepare("UPDATE miembros SET cumpleanos_sms_anio = ? WHERE id = ?")->execute([$anioActual, $m['id']]);
                $resumen['omitidos_optout']++;
                continue;
            }

            $res = twilio_enviar_sms($telefono, $mensaje, $mediaUrl);
            $costo = $res['ok'] ? sms_calcular_costo($mensaje, true, true) : 0;
            $pdo->prepare("INSERT INTO sms_mensajes (telefono, miembro_id, direccion, cuerpo, estado, twilio_sid, agente_id, tipo, costo_estimado)
                           VALUES (?, ?, 'SALIENTE', ?, ?, ?, ?, 'CUMPLEANOS', ?)")
                ->execute([
                    $telefono, $m['id'], $mensaje,
                    $res['ok'] ? ($res['estado'] ?? 'enviado') : 'error',
                    $res['sid'] ?? null, $m['agente_id'], $costo,
                ]);

            if ($res['ok']) {
                $pdo->prepare("UPDATE miembros SET cumpleanos_sms_anio = ? WHERE id = ?")->execute([$anioActual, $m['id']]);
                $resumen['enviados']++;
            } else {
                // NO se marca el año — si fue un fallo pasajero, la siguiente
                // corrida del cron (más tarde el mismo día) lo vuelve a
                // intentar. Si el número está de verdad muerto,
                // sms_registrar_fallo_envio ya se encarga de bloquearlo.
                sms_registrar_fallo_envio($pdo, $telefono, $res['codigo'] ?? null, $res['error'] ?? null);
                $resumen['fallidos']++;
            }
        } catch (Exception $e) {
            $resumen['fallidos']++;
        }
    }

    return $resumen;
}
