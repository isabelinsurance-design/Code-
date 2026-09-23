<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_GOOGLE_CALENDAR.PHP — sincroniza Citas con Google Calendar.
 *  Pedido de Isabel: "conectar Citas con Google Calendar" — UN solo
 *  calendario (el de Isabel) recibe TODAS las citas de todos los
 *  agentes, para verlas todas juntas ahí también.
 *
 *  Llamada directa a la API de Google por cURL (sin SDK/Composer),
 *  igual que lib_twilio.php hace con Twilio.
 *
 *  Sin GOOGLE_CLIENT_ID/GOOGLE_CLIENT_SECRET configurados en config.php,
 *  Citas sigue funcionando exactamente igual que siempre — la
 *  sincronización simplemente no hace nada (ver google_calendar_configurado()).
 *
 *  Todo lo que toca la API de Google es "mejor esfuerzo": si Google
 *  está lento o caído, NUNCA debe tronar ni atrasar el guardar una cita
 *  — por eso cada llamada tiene un timeout corto y todo queda envuelto
 *  en try/catch en google_calendar_sync_cita().
 * ═══════════════════════════════════════════════════════════════════ */

function google_calendar_configurado(): bool {
    return defined('GOOGLE_CLIENT_ID') && GOOGLE_CLIENT_ID
        && defined('GOOGLE_CLIENT_SECRET') && GOOGLE_CLIENT_SECRET
        && defined('GOOGLE_REDIRECT_URI') && GOOGLE_REDIRECT_URI;
}

function asegurarTablaGoogleCalendar(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS google_calendar_tokens (
            id TINYINT PRIMARY KEY DEFAULT 1,
            access_token TEXT,
            refresh_token TEXT,
            expira_en DATETIME DEFAULT NULL,
            calendar_id VARCHAR(255) DEFAULT 'primary',
            cuenta_email VARCHAR(255) DEFAULT NULL,
            conectado_por INT DEFAULT NULL,
            conectado_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) {}
    // citas.google_event_id — self-healing: la tabla 'citas' ya existía de
    // antes en el CRM (no se crea aquí), así que solo se agrega la columna
    // si falta, igual que las demás migraciones aditivas del proyecto.
    try {
        $col = $pdo->query("SHOW COLUMNS FROM citas LIKE 'google_event_id'")->fetch();
        if (!$col) $pdo->exec("ALTER TABLE citas ADD COLUMN google_event_id VARCHAR(255) DEFAULT NULL");
    } catch (Exception $e) {}
}

/* Estado de la conexión, para mostrar en la pantalla — no valida si el
 * token sigue vivo, solo si hay una cuenta conectada. */
function google_calendar_estado(PDO $pdo): array {
    asegurarTablaGoogleCalendar($pdo);
    try {
        $row = $pdo->query("SELECT cuenta_email, calendar_id, conectado_at FROM google_calendar_tokens WHERE id=1")->fetch();
        if ($row) return ['conectado' => true, 'email' => $row['cuenta_email'], 'conectado_at' => $row['conectado_at']];
    } catch (Exception $e) {}
    return ['conectado' => false];
}

function google_calendar_desconectar(PDO $pdo): void {
    asegurarTablaGoogleCalendar($pdo);
    try { $pdo->exec("DELETE FROM google_calendar_tokens WHERE id=1"); } catch (Exception $e) {}
}

/* URL a la que se manda a Isabel para que le dé permiso al CRM. $state
 * se vuelve a revisar en el callback (protección CSRF del OAuth). */
function google_calendar_auth_url(string $state): string {
    $params = [
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'https://www.googleapis.com/auth/calendar.events',
        'access_type'   => 'offline',
        // 'consent' fuerza que Google vuelva a mandar el refresh_token —
        // sin esto, si ya habías conectado antes, Google NO manda uno
        // nuevo y la reconexión se queda sin forma de renovar el acceso.
        'prompt'        => 'consent',
        'state'         => $state,
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/* POST genérico al endpoint de tokens de Google (no lleva Bearer, va
 * client_id/secret en el cuerpo). */
function _google_token_request(array $params): array {
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'error' => 'Error de conexión con Google: ' . $err];
    $data = json_decode($resp, true) ?: [];
    if (!empty($data['access_token'])) return ['ok' => true, 'data' => $data];
    return ['ok' => false, 'error' => $data['error_description'] ?? $data['error'] ?? 'Google no devolvió un token'];
}

/* Cambia el "code" que Google mandó al callback por los tokens reales, y
 * los guarda. Se llama UNA vez, justo después de que Isabel dé permiso. */
function google_calendar_exchange_code(PDO $pdo, string $code, int $uid): array {
    if (!google_calendar_configurado()) return ['ok' => false, 'error' => 'Google Calendar no está configurado en config.php'];
    asegurarTablaGoogleCalendar($pdo);
    $r = _google_token_request([
        'code'          => $code,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'grant_type'    => 'authorization_code',
    ]);
    if (!$r['ok']) return $r;
    $d = $r['data'];
    if (empty($d['refresh_token'])) {
        return ['ok' => false, 'error' => 'Google no mandó permiso de acceso permanente — desconecta esta cuenta desde myaccount.google.com/permissions y vuelve a intentar conectar.'];
    }
    $expira = date('Y-m-d H:i:s', time() + (int)($d['expires_in'] ?? 3600) - 60);

    // El email de la cuenta es solo para mostrarlo en pantalla ("Conectado
    // como fulano@gmail.com") — si esta llamada falla no pasa nada grave.
    $email = null;
    try {
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $d['access_token']],
            CURLOPT_TIMEOUT        => 8,
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);
        $info = json_decode($resp ?: '', true);
        $email = $info['email'] ?? null;
    } catch (Exception $e) {}

    $pdo->prepare("INSERT INTO google_calendar_tokens (id, access_token, refresh_token, expira_en, calendar_id, cuenta_email, conectado_por, conectado_at)
                   VALUES (1, ?, ?, ?, 'primary', ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE access_token=VALUES(access_token), refresh_token=VALUES(refresh_token),
                       expira_en=VALUES(expira_en), cuenta_email=VALUES(cuenta_email), conectado_por=VALUES(conectado_por), conectado_at=NOW()")
        ->execute([$d['access_token'], $d['refresh_token'], $expira, $email, $uid]);
    return ['ok' => true, 'email' => $email];
}

/* Pide un access_token nuevo usando el refresh_token guardado (el
 * refresh_token casi nunca cambia, Google no manda uno nuevo cada vez). */
function _google_calendar_refrescar(PDO $pdo, string $refreshToken): ?string {
    $r = _google_token_request([
        'refresh_token' => $refreshToken,
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'grant_type'    => 'refresh_token',
    ]);
    if (!$r['ok']) return null;
    $d = $r['data'];
    $expira = date('Y-m-d H:i:s', time() + (int)($d['expires_in'] ?? 3600) - 60);
    $pdo->prepare("UPDATE google_calendar_tokens SET access_token=?, expira_en=? WHERE id=1")
        ->execute([$d['access_token'], $expira]);
    return $d['access_token'];
}

/* El access_token que hay que mandar en cada llamada a la API de
 * Calendar — lo renueva solo si ya venció. Regresa null si no hay nada
 * conectado (o si la reconexión falló, ej. Isabel revocó el permiso
 * desde su cuenta de Google). */
function google_calendar_access_token(PDO $pdo): ?string {
    if (!google_calendar_configurado()) return null;
    asegurarTablaGoogleCalendar($pdo);
    try {
        $row = $pdo->query("SELECT access_token, refresh_token, expira_en FROM google_calendar_tokens WHERE id=1")->fetch();
    } catch (Exception $e) { return null; }
    if (!$row || empty($row['refresh_token'])) return null;
    if (!empty($row['access_token']) && !empty($row['expira_en']) && strtotime($row['expira_en']) > time()) {
        return $row['access_token'];
    }
    return _google_calendar_refrescar($pdo, $row['refresh_token']);
}

/* Llamada genérica a la API de Calendar (v3) ya autenticada. */
function _google_calendar_api(string $method, string $url, ?array $body, string $accessToken): array {
    $ch = curl_init($url);
    $headers = ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json'];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 10,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'code' => 0, 'error' => $err];
    $data = $resp !== '' ? json_decode($resp, true) : [];
    if ($code >= 200 && $code < 300) return ['ok' => true, 'code' => $code, 'data' => $data];
    return ['ok' => false, 'code' => $code, 'data' => $data, 'error' => $data['error']['message'] ?? ('Google respondió ' . $code)];
}

/* Arma el "evento" que se manda a Google a partir de una fila de citas
 * (con miembro_nombre/miembro_telefono ya resueltos, igual que las trae
 * render_citas_panel/get_cita). */
function google_calendar_evento_payload(array $cita): array {
    $tz = defined('GOOGLE_CALENDAR_TZ') && GOOGLE_CALENDAR_TZ ? GOOGLE_CALENDAR_TZ : 'America/Los_Angeles';
    $cli = trim($cita['miembro_nombre'] ?? '');
    if ($cli === ', ' || $cli === '') $cli = trim($cita['cliente'] ?? '') ?: 'Sin nombre';
    $hora = $cita['hora'] ?? '09:00:00';
    $inicio = $cita['fecha'] . 'T' . $hora;
    $finTs  = strtotime($cita['fecha'] . ' ' . $hora) + 30 * 60; // 30 min por default — citas no guarda duración
    $fin    = date('Y-m-d\TH:i:s', $finTs);
    $desc = trim(($cita['tipo'] ?? '') . ' — ' . ($cita['modalidad'] ?? ''));
    if (!empty($cita['miembro_telefono'])) $desc .= "\nTeléfono: " . $cita['miembro_telefono'];
    if (!empty($cita['notas'])) $desc .= "\n\n" . $cita['notas'];
    return [
        'summary'     => $cli . ' — ' . ($cita['tipo'] ?? 'Cita'),
        'description' => $desc,
        'start'       => ['dateTime' => $inicio, 'timeZone' => $tz],
        'end'         => ['dateTime' => $fin,    'timeZone' => $tz],
    ];
}

/* Trae una cita con lo que hace falta para sincronizarla (nombre/teléfono
 * del miembro ya resueltos) — un solo lugar para no repetir este JOIN en
 * cada action de api.php que guarda/cambia una cita. */
function google_calendar_obtener_cita_para_sync(PDO $pdo, int $id): ?array {
    // Como google_calendar_sync_cita(), esto es "mejor esfuerzo" — un
    // tropiezo aquí NUNCA debe impedir que guardar/completar/cancelar una
    // cita responda bien al usuario.
    try {
        $stmt = $pdo->prepare("SELECT c.*, CONCAT(m.apellido,', ',m.nombre) as miembro_nombre, m.telefono as miembro_telefono
                                FROM citas c LEFT JOIN miembros m ON c.miembro_id = m.id WHERE c.id=?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/* El hook principal — se llama después de guardar/completar/cancelar una
 * cita. SIEMPRE de "mejor esfuerzo": si algo falla (Google caído, token
 * revocado, sin internet), no debe tronar ni atrasar la respuesta al
 * usuario — por eso todo está envuelto aquí adentro, y quien llama a
 * esta función nunca necesita su propio try/catch. */
function google_calendar_sync_cita(PDO $pdo, array $cita): void {
    try {
        if (!google_calendar_configurado()) return;
        $token = google_calendar_access_token($pdo);
        if (!$token) return;
        $calendarId = 'primary';
        try {
            $row = $pdo->query("SELECT calendar_id FROM google_calendar_tokens WHERE id=1")->fetch();
            if ($row && !empty($row['calendar_id'])) $calendarId = $row['calendar_id'];
        } catch (Exception $e) {}
        $base = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events';

        if (($cita['estado'] ?? '') === 'CANCELADA') {
            if (!empty($cita['google_event_id'])) {
                _google_calendar_api('DELETE', $base . '/' . rawurlencode($cita['google_event_id']), null, $token);
                $pdo->prepare("UPDATE citas SET google_event_id=NULL WHERE id=?")->execute([$cita['id']]);
            }
            return;
        }

        $payload = google_calendar_evento_payload($cita);
        if (!empty($cita['google_event_id'])) {
            $r = _google_calendar_api('PATCH', $base . '/' . rawurlencode($cita['google_event_id']), $payload, $token);
            if ($r['ok']) return;
            // El evento ya no existe del lado de Google (ej. Isabel lo borró
            // a mano) — se crea uno nuevo en vez de quedar sin sincronizar.
            if (($r['code'] ?? 0) !== 404) return;
        }
        $r = _google_calendar_api('POST', $base, $payload, $token);
        if ($r['ok'] && !empty($r['data']['id'])) {
            $pdo->prepare("UPDATE citas SET google_event_id=? WHERE id=?")->execute([$r['data']['id'], $cita['id']]);
        }
    } catch (Exception $e) {
        // Nunca dejar que un problema con Google tumbe el guardado de la cita.
    }
}
