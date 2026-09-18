<?php
/* ═══════════════════════════════════════════════════════════════════
 *  LIB_TWILIO.PHP — enviar SMS por Twilio (llamada directa a su API,
 *  sin necesitar el SDK/Composer — solo cURL).
 *  ─────────────────────────────────────────────────────────────────
 *  Requiere que config.php defina estas 3 constantes:
 *    TWILIO_SID          → el "Account SID" de Twilio
 *    TWILIO_AUTH_TOKEN   → el "Auth Token" de Twilio
 *    TWILIO_FROM_NUMBER  → el número de Twilio desde el que se envía,
 *                           en formato +1XXXXXXXXXX
 *  Si no están definidas, twilio_enviar_sms() regresa ok=false con un
 *  mensaje claro en vez de tronar — así el resto del CRM sigue
 *  funcionando aunque Twilio todavía no esté configurado.
 * ═══════════════════════════════════════════════════════════════════ */
function twilio_configurado(): bool {
    return defined('TWILIO_SID') && TWILIO_SID
        && defined('TWILIO_AUTH_TOKEN') && TWILIO_AUTH_TOKEN
        && defined('TWILIO_FROM_NUMBER') && TWILIO_FROM_NUMBER;
}

// ═══════════════════════════════════════════════════════════════════
//  sms_mensajes — se define AQUÍ (no en api.php) porque los webhooks
//  (sms_webhook.php, sms_status_webhook.php) no cargan api.php pero sí
//  necesitan la MISMA tabla — antes cada archivo traía su propia copia
//  del CREATE TABLE, fácil de desincronizar al agregar columnas nuevas.
// ═══════════════════════════════════════════════════════════════════
function asegurarTablaSmsMensajes(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sms_mensajes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telefono VARCHAR(20) NOT NULL,
            miembro_id INT NULL,
            direccion VARCHAR(10) NOT NULL,
            cuerpo TEXT,
            estado VARCHAR(30) DEFAULT NULL,
            twilio_sid VARCHAR(64) DEFAULT NULL,
            agente_id INT NULL,
            leido TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_telefono (telefono),
            INDEX idx_miembro (miembro_id)
        )");
        // Migración: columnas para el reporte de GASTOS de Campañas — no
        // truena si la tabla ya existía de antes de este cambio.
        $cols = $pdo->query("SHOW COLUMNS FROM sms_mensajes")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('campana_id', $cols, true))     $pdo->exec("ALTER TABLE sms_mensajes ADD COLUMN campana_id INT DEFAULT NULL");
        if (!in_array('es_mms', $cols, true))         $pdo->exec("ALTER TABLE sms_mensajes ADD COLUMN es_mms TINYINT(1) DEFAULT 0");
        if (!in_array('costo_estimado', $cols, true)) $pdo->exec("ALTER TABLE sms_mensajes ADD COLUMN costo_estimado DECIMAL(8,4) DEFAULT 0.0000");

        // Único por twilio_sid — si Twilio REENVÍA el mismo webhook (timeout,
        // red) casi al mismo tiempo que la primera entrega todavía se está
        // procesando, un simple "busca-si-existe-y-si-no-inserta" puede dejar
        // pasar las dos peticiones (ninguna alcanzó a ver la fila de la otra
        // todavía) y duplicar el mensaje — y su costo — en el reporte de
        // GASTOS. Con esta llave, MySQL rechaza la segunda inserción sola,
        // sin ninguna ventana de tiempo en medio. Los NULL (envíos que Twilio
        // nunca aceptó) no cuentan como duplicados entre sí.
        if (!$pdo->query("SHOW INDEX FROM sms_mensajes WHERE Key_name = 'uniq_twilio_sid'")->fetch()) {
            try {
                $pdo->exec("ALTER TABLE sms_mensajes ADD UNIQUE KEY uniq_twilio_sid (twilio_sid)");
            } catch (Exception $e) {
                // Ya había sids duplicados guardados de antes de esta
                // protección (posiblemente por la misma condición de carrera
                // que esto corrige) — se limpian (se deja el de menor id) y
                // se reintenta una sola vez.
                try {
                    $pdo->exec("DELETE s1 FROM sms_mensajes s1
                                 INNER JOIN sms_mensajes s2 ON s1.twilio_sid = s2.twilio_sid AND s1.id > s2.id
                                 WHERE s1.twilio_sid IS NOT NULL");
                    $pdo->exec("ALTER TABLE sms_mensajes ADD UNIQUE KEY uniq_twilio_sid (twilio_sid)");
                } catch (Exception $e2) {}
            }
        }
    } catch (Exception $e) {}
}

// ═══════════════════════════════════════════════════════════════════
//  CANDADO DE ENVÍO MASIVO — evita que la MISMA campaña se mande dos
//  veces al mismo tiempo (dos pestañas, o la misma persona dándole doble
//  clic) y protege contra "cerré la pestaña a mitad del envío y lo volví
//  a abrir": en vez de reiniciar el conteo de "a quién ya le mandé" desde
//  cero (lo que podría volver a texear y cobrarle a gente que ya había
//  recibido el mensaje en el intento anterior), el envío nuevo HEREDA el
//  punto de partida ("desde") del intento abandonado, así que la
//  exclusión de "ya se le mandó en este envío" sigue protegiendo aunque
//  técnicamente sea una petición nueva.
// ═══════════════════════════════════════════════════════════════════
const CAMPANA_ENVIO_LOCK_STALE_SEG = 90; // sin "latido" por más de esto = abandonado

function asegurarTablaCampanaEnvioLock(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS campana_envio_lock (
            campana_id    INT NOT NULL PRIMARY KEY,
            desde         DATETIME NOT NULL,
            iniciado_por  INT DEFAULT NULL,
            ultimo_latido TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) {}
}

// Se llama SOLO al iniciar un envío (cursor=0). Devuelve el "desde" que se
// debe usar (heredado de un intento abandonado, o uno nuevo), o null si YA
// hay un envío realmente activo para esta campaña ahora mismo (otra
// pestaña, u otro agente) y no se debe dejar mandar.
function campana_envio_lock_tomar(PDO $pdo, int $campana_id, int $uid): ?string {
    asegurarTablaCampanaEnvioLock($pdo);
    $q = $pdo->prepare("SELECT desde, ultimo_latido FROM campana_envio_lock WHERE campana_id = ?");
    $q->execute([$campana_id]);
    $existente = $q->fetch(PDO::FETCH_ASSOC);

    if ($existente) {
        $segundosDesdeLatido = time() - strtotime($existente['ultimo_latido']);
        if ($segundosDesdeLatido < CAMPANA_ENVIO_LOCK_STALE_SEG) {
            return null; // hay un envío activo de verdad ahora mismo — no se puede empezar otro
        }
        // Abandonado — se retoma heredando su "desde" para no volver a
        // mandarle a quien ya recibió el mensaje en el intento anterior.
        $pdo->prepare("UPDATE campana_envio_lock SET iniciado_por = ?, ultimo_latido = NOW() WHERE campana_id = ?")
            ->execute([$uid, $campana_id]);
        return $existente['desde'];
    }

    // "desde" se calcula con NOW() de la BASE DE DATOS (no con la hora de
    // PHP) — es la misma hora que usa "created_at" en sms_mensajes al
    // guardar cada envío. Si se calculara con la hora del servidor web y
    // ese reloj estuviera aunque sea unos segundos adelantado del reloj de
    // la base de datos (común en hosting compartido, donde no siempre son
    // la misma máquina), la comparación "created_at >= desde" podría fallar
    // y dejar mandar dos veces al mismo número dentro del mismo envío.
    $pdo->prepare("INSERT INTO campana_envio_lock (campana_id, desde, iniciado_por) VALUES (?, NOW(), ?)")
        ->execute([$campana_id, $uid]);
    $q2 = $pdo->prepare("SELECT desde FROM campana_envio_lock WHERE campana_id = ?");
    $q2->execute([$campana_id]);
    return $q2->fetchColumn();
}

// Late en cada lote (incluido el primero) para que el candado no se vea
// "abandonado" solo porque el envío de una campaña grande está tardando.
function campana_envio_lock_latido(PDO $pdo, int $campana_id): void {
    try {
        $pdo->prepare("UPDATE campana_envio_lock SET ultimo_latido = NOW() WHERE campana_id = ?")->execute([$campana_id]);
    } catch (Exception $e) {}
}

// Se llama cuando el envío termina (o se cancela) para soltar el candado
// de inmediato, en vez de esperar a que se detecte como abandonado.
function campana_envio_lock_soltar(PDO $pdo, int $campana_id): void {
    try {
        $pdo->prepare("DELETE FROM campana_envio_lock WHERE campana_id = ?")->execute([$campana_id]);
    } catch (Exception $e) {}
}

// ═══════════════════════════════════════════════════════════════════
//  COSTO ESTIMADO — Twilio no manda el precio real en el status callback
//  (solo se sabe consultando cada mensaje por separado en su API, lo cual
//  sería una llamada extra por cada SMS). En vez de eso se ESTIMA con las
//  tarifas típicas de EEUU y el mismo cálculo de "segmentos" que usa
//  cualquier proveedor de SMS — sirve para llevar un historial de gastos
//  sin depender de la API. Para el cobro EXACTO, la consola de Twilio
//  (Billing) siempre es la fuente real.
//  Ajustable: si tu tarifa real es distinta, define estas 4 constantes en
//  config.php ANTES de que se cargue este archivo y se usan esas en vez.
// ═══════════════════════════════════════════════════════════════════
if (!defined('TWILIO_COSTO_SMS_SEGMENTO_OUT')) define('TWILIO_COSTO_SMS_SEGMENTO_OUT', 0.0079);
if (!defined('TWILIO_COSTO_SMS_SEGMENTO_IN'))  define('TWILIO_COSTO_SMS_SEGMENTO_IN',  0.0075);
if (!defined('TWILIO_COSTO_MMS_OUT'))          define('TWILIO_COSTO_MMS_OUT',          0.02);
if (!defined('TWILIO_COSTO_MMS_IN'))           define('TWILIO_COSTO_MMS_IN',           0.01);

// Cuenta los "segmentos" de un SMS igual que lo hace cualquier operador:
// si el texto trae SOLO caracteres del alfabeto GSM-7 caben 160 por
// segmento (153 si son varios); en cuanto aparece un caracter fuera de
// ese conjunto (muy común en español: "á, é, í, ó, ú" NO están en GSM-7,
// aunque "ñ, ¿, ¡" sí) el mensaje completo se manda en UCS-2 y caben
// solo 70 por segmento (67 si son varios) — por eso un mensaje en
// español casi siempre pesa más segmentos de lo que parece por su
// longitud. Los caracteres de la "tabla extendida" de GSM-7 (^ { } \ [ ~
// ] | €) cuentan DOBLE dentro de un mensaje GSM-7 — sin este detalle un
// mensaje con "€", por ejemplo, se subestimaba.
function sms_calcular_segmentos(string $texto): int {
    static $gsm7Basico    = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
    static $gsm7Extendido = "^{}\\[~]|€";
    $len = mb_strlen($texto);
    if ($len === 0) return 1;
    $esGsm7 = true;
    $unidadesGsm7 = 0;
    for ($i = 0; $i < $len; $i++) {
        $c = mb_substr($texto, $i, 1);
        if (mb_strpos($gsm7Basico, $c) !== false) { $unidadesGsm7 += 1; continue; }
        if (mb_strpos($gsm7Extendido, $c) !== false) { $unidadesGsm7 += 2; continue; }
        $esGsm7 = false; break;
    }
    if ($esGsm7) {
        $porSegmento = $unidadesGsm7 <= 160 ? 160 : 153;
        return max(1, (int) ceil($unidadesGsm7 / $porSegmento));
    }
    $porSegmento = $len <= 70 ? 70 : 67;
    return max(1, (int) ceil($len / $porSegmento));
}

// Costo estimado de UN mensaje — $saliente=false es para mensajes
// ENTRANTES (Twilio también cobra por recibir, no solo por mandar).
function sms_calcular_costo(string $texto, bool $esMms, bool $saliente): float {
    if ($esMms) return $saliente ? TWILIO_COSTO_MMS_OUT : TWILIO_COSTO_MMS_IN;
    $segmentos = sms_calcular_segmentos($texto);
    return round($segmentos * ($saliente ? TWILIO_COSTO_SMS_SEGMENTO_OUT : TWILIO_COSTO_SMS_SEGMENTO_IN), 4);
}

// Rellena el costo estimado de los mensajes que ya existían ANTES de que
// esta columna existiera (todo el historial de COMUNICACIÓN de antes de
// este cambio tiene costo_estimado=0 porque nunca se calculó) — así el
// reporte de GASTOS de Campañas no empieza en ceros, sino con todo el
// historial real de SMS que ya se habían mandado/recibido. Se corre solo
// (nada que Isabel tenga que hacer a mano) la primera vez que se abre esa
// pantalla, en tandas, hasta que ya no quede nada pendiente por calcular.
function sms_backfill_costos_historicos(PDO $pdo, int $limite = 1500): int {
    asegurarTablaSmsMensajes($pdo);
    try {
        // No se toca un envío que de plano falló (Twilio nunca lo aceptó,
        // por lo tanto nunca se cobró) — a esos les toca quedarse en 0.
        // COALESCE(estado,'') porque en SQL "algo = 'error'" da NULL (ni
        // verdadero ni falso) cuando estado es NULL, y NOT(NULL) sigue
        // siendo NULL — la fila se hubiera colado fuera del WHERE sin
        // avisar, dejando su gasto real sin contar para siempre.
        $q = $pdo->prepare("SELECT id, cuerpo, direccion FROM sms_mensajes
                             WHERE costo_estimado = 0
                               AND cuerpo IS NOT NULL AND cuerpo != ''
                               AND NOT (direccion = 'SALIENTE' AND COALESCE(estado,'') = 'error')
                             LIMIT $limite");
        $q->execute();
        $filas = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return 0; }
    if (!$filas) return 0;

    $upd = $pdo->prepare("UPDATE sms_mensajes SET costo_estimado = ? WHERE id = ?");
    $actualizados = 0;
    foreach ($filas as $f) {
        // El historial viejo nunca guardó si algo era MMS (esa columna
        // tampoco existía) — se asume que no, ya que el envío de flyers
        // por MMS es una función nueva y no existía antes de este cambio.
        $costo = sms_calcular_costo($f['cuerpo'], false, $f['direccion'] === 'SALIENTE');
        try { $upd->execute([$costo, $f['id']]); $actualizados++; } catch (Exception $e) {}
    }
    return $actualizados;
}

// Convierte una ruta relativa dentro del CRM (ej. "uploads/flyers/x.jpg",
// tal como se guarda en la base de datos) en una URL pública completa —
// para un MMS, Twilio necesita poder DESCARGAR la imagen desde internet,
// no le sirve una ruta de archivo del servidor.
function twilio_url_publica(string $rutaRelativa): string {
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    $dir   = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $proto . '://' . $host . $dir . '/' . ltrim($rutaRelativa, '/');
}

// $mediaUrl (opcional) manda un MMS con imagen (ej. un flyer) en vez de un
// SMS de solo texto — debe ser una URL pública (https) donde Twilio pueda
// descargar la imagen; $body puede ir vacío si solo se manda la imagen.
function twilio_enviar_sms(string $to, string $body, ?string $mediaUrl = null): array {
    if (!twilio_configurado()) {
        return ['ok' => false, 'error' => 'Twilio no está configurado (faltan TWILIO_SID/TWILIO_AUTH_TOKEN/TWILIO_FROM_NUMBER en config.php)'];
    }
    $to = normalizar_tel($to);
    if ($to === '') return ['ok' => false, 'error' => 'Número de destino inválido'];
    if (trim($body) === '' && !$mediaUrl) return ['ok' => false, 'error' => 'Mensaje vacío'];

    $url = 'https://api.twilio.com/2010-04-01/Accounts/' . TWILIO_SID . '/Messages.json';
    $campos = [
        'From' => TWILIO_FROM_NUMBER,
        'To'   => $to,
        'Body' => $body,
    ];
    if ($mediaUrl) $campos['MediaUrl'] = $mediaUrl;
    // Aceptar un mensaje para enviarlo no es lo mismo que ENTREGARLO — Twilio
    // cobra por intentarlo aunque nunca llegue. Sin este StatusCallback, el
    // CRM nunca se entera de que un número está muerto y le sigue intentando
    // mandar (dinero perdido) en cada envío masivo futuro.
    try { $campos['StatusCallback'] = twilio_url_publica('sms_status_webhook.php'); } catch (Exception $e) {}
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => TWILIO_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_POSTFIELDS     => http_build_query($campos),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'error' => 'Error de conexión con Twilio: ' . $err];
    }
    $data = json_decode($resp, true);
    if ($code >= 200 && $code < 300 && !empty($data['sid'])) {
        return ['ok' => true, 'sid' => $data['sid'], 'estado' => $data['status'] ?? 'queued'];
    }
    return ['ok' => false, 'error' => $data['message'] ?? ('Twilio respondió con error ' . $code), 'codigo' => isset($data['code']) ? (string)$data['code'] : null];
}

// ═══════════════════════════════════════════════════════════════════
//  OPT-OUT (STOP) — cuando alguien responde STOP no se le debe volver a
//  escribir. Se guarda por TELÉFONO (no por miembro_id) porque muchos
//  contactos de campaña todavía no son miembros del CRM.
// ═══════════════════════════════════════════════════════════════════
function asegurarTablaSmsOptOut(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sms_opt_out (
            telefono   VARCHAR(20) NOT NULL PRIMARY KEY,
            motivo     VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) {}
}

// Igual que hace Twilio: se compara el mensaje COMPLETO contra la palabra,
// no como substring — así "cancelar mi cita" no dispara un opt-out.
const SMS_PALABRAS_STOP  = ['STOP', 'STOPALL', 'UNSUBSCRIBE', 'CANCEL', 'END', 'QUIT', 'ALTO', 'BAJA'];
const SMS_PALABRAS_START = ['START', 'UNSTOP', 'YES'];

function sms_es_palabra_stop(string $cuerpo): bool {
    return in_array(strtoupper(trim($cuerpo)), SMS_PALABRAS_STOP, true);
}
function sms_es_palabra_start(string $cuerpo): bool {
    return in_array(strtoupper(trim($cuerpo)), SMS_PALABRAS_START, true);
}

// ¿Este teléfono ya nos pidió que no le mandemos más mensajes?
function sms_esta_optout(PDO $pdo, string $telefono): bool {
    asegurarTablaSmsOptOut($pdo);
    $telefono = normalizar_tel($telefono);
    if ($telefono === '') return false;
    $q = $pdo->prepare("SELECT 1 FROM sms_opt_out WHERE telefono = ?");
    $q->execute([$telefono]);
    return (bool) $q->fetchColumn();
}

// ═══════════════════════════════════════════════════════════════════
//  NÚMEROS QUE NUNCA ENTREGAN (dinero perdido) — Twilio cobra por
//  INTENTAR mandar el SMS, no por que de verdad llegue. Un número
//  desconectado, mal escrito, o que bloqueó los mensajes de Twilio va a
//  fallar SIEMPRE — sin esto, cada envío masivo futuro le vuelve a
//  intentar (y a cobrar) sin que nadie se dé cuenta de por qué el número
//  de fallidos no baja.
// ═══════════════════════════════════════════════════════════════════
function asegurarTablaSmsFallos(PDO $pdo): void {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS sms_fallos (
            telefono      VARCHAR(20) NOT NULL PRIMARY KEY,
            veces         INT NOT NULL DEFAULT 0,
            ultimo_codigo VARCHAR(20) DEFAULT NULL,
            ultimo_error  VARCHAR(255) DEFAULT NULL,
            updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");
    } catch (Exception $e) {}
}

// Códigos de error de Twilio que SIEMPRE significan que ese número nunca
// va a poder recibir un SMS — no hace falta esperar un segundo fallo para
// bloquearlo (número mal formado, no es celular/no soporta SMS, etc.).
const SMS_CODIGOS_PERMANENTES = ['21211', '21214', '21614', '30006'];
// A partir de cuántos fallos (con cualquier otro código, ej. "buzón no
// disponible" o "bloqueado por el carrier") se asume que ya no vale la
// pena seguir intentándole — puede ser temporal la primera vez, pero no
// dos veces seguidas.
const SMS_FALLOS_PARA_BLOQUEAR = 2;

// Se llama tanto si Twilio rechaza el envío al instante (número mal
// formado — se sabe en el momento) como cuando avisa DESPUÉS, por el
// status callback, que no se pudo entregar (número desconectado,
// bloqueado...) — un solo lugar para decidir cuándo ya es un número
// "perdido" y agregarlo a la misma lista que ya usa el STOP.
function sms_registrar_fallo_envio(PDO $pdo, string $telefono, ?string $codigo, ?string $error): void {
    $telefono = normalizar_tel($telefono);
    if ($telefono === '') return;
    asegurarTablaSmsFallos($pdo);
    try {
        $pdo->prepare("INSERT INTO sms_fallos (telefono, veces, ultimo_codigo, ultimo_error) VALUES (?, 1, ?, ?)
                       ON DUPLICATE KEY UPDATE veces = veces + 1, ultimo_codigo = VALUES(ultimo_codigo), ultimo_error = VALUES(ultimo_error)")
            ->execute([$telefono, $codigo, $error ? mb_substr($error, 0, 255) : null]);
        $q = $pdo->prepare("SELECT veces FROM sms_fallos WHERE telefono = ?");
        $q->execute([$telefono]);
        $veces = (int) $q->fetchColumn();
    } catch (Exception $e) { $veces = 1; }

    $esPermanente = $codigo && in_array((string) $codigo, SMS_CODIGOS_PERMANENTES, true);
    if ($esPermanente || $veces >= SMS_FALLOS_PARA_BLOQUEAR) {
        asegurarTablaSmsOptOut($pdo);
        $motivo = $esPermanente
            ? ('Número inválido (Twilio ' . $codigo . ')')
            : ('Falló ' . $veces . ' veces al enviar — no se le vuelve a intentar');
        try {
            $pdo->prepare("INSERT INTO sms_opt_out (telefono, motivo) VALUES (?, ?)
                           ON DUPLICATE KEY UPDATE motivo = VALUES(motivo)")->execute([$telefono, $motivo]);
        } catch (Exception $e) {}
    }
}

// Cuando SÍ se confirma la entrega, el historial de fallos viejo ya no
// sirve de nada (el número resultó estar bien después de todo).
function sms_limpiar_fallos_envio(PDO $pdo, string $telefono): void {
    $telefono = normalizar_tel($telefono);
    if ($telefono === '') return;
    asegurarTablaSmsFallos($pdo);
    try { $pdo->prepare("DELETE FROM sms_fallos WHERE telefono = ?")->execute([$telefono]); } catch (Exception $e) {}
}

// Valida que un webhook (SMS o de voz/SIP) realmente venga de Twilio —
// mismo cálculo que ya se usa en sms_webhook.php (HMAC-SHA1 de la URL
// completa + los parámetros del POST, con el Auth Token como llave).
// Sin esto, cualquiera podría llamar a la URL del webhook e inventar
// llamadas/mensajes falsos (y, en el caso de voz, hacer que tu cuenta de
// Twilio marque números por su cuenta con tu tarjeta).
function twilio_firma_valida(): bool {
    if (!defined('TWILIO_AUTH_TOKEN') || !TWILIO_AUTH_TOKEN) return false;
    $firma_recibida = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
    if (!$firma_recibida) return false;
    $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http');
    $host  = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? '');
    $url   = $proto . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '');
    $datos = $url;
    $params = $_POST;
    ksort($params);
    foreach ($params as $k => $v) { $datos .= $k . $v; }
    $firma_esperada = base64_encode(hash_hmac('sha1', $datos, TWILIO_AUTH_TOKEN, true));
    return hash_equals($firma_esperada, $firma_recibida);
}
