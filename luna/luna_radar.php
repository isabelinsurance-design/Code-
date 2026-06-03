<?php
/* ════════════════════════════════════════════════════════════════
   LUNA RADAR — Investigación automática de tendencias
   Medicare with Isabel

   "Siempre saber qué está pegando y qué hacer para ser los mejores."

   Cada día (escaneo rápido) y cada semana (reporte profundo) LUNA usa
   BÚSQUEDA WEB EN VIVO para investigar 5 frentes y devolver ACCIONES
   concretas — pensando como un Chief of Staff:

     • viral       → contenido/ganchos que se están volviendo virales
     • social      → qué funciona ahora en FB/IG/TikTok/YouTube para 65+
     • medicare    → noticias y cambios de Medicare/CMS/carriers
     • competencia → qué hacen otras agencias + oportunidades
     • mejora      → cómo mejorar el negocio y a LUNA misma (qué funciona,
                     qué cambiar, qué priorizar)

   Lógica compartida por el cron (luna/cron/luna_radar_cron.php) y la API
   (luna_api.php → luna_radar_run / luna_radar_latest). DRY: una sola fuente.

   Requiere luna_ai.php (lunaAIWeb). Degradación elegante: si no hay key o
   la API falla, guarda un run con ok=0 y no rompe nada.
════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/luna_ai.php';

if (!function_exists('radarEnsureTables')) {
  function radarEnsureTables(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_radar_runs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        modo VARCHAR(10) NOT NULL DEFAULT 'daily',
        resumen TEXT DEFAULT NULL,
        item_count INT DEFAULT 0,
        ok TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_modo (modo), INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_radar_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        run_id INT NOT NULL,
        categoria VARCHAR(20) DEFAULT 'viral',
        titulo VARCHAR(240) NOT NULL,
        porque TEXT DEFAULT NULL,
        accion TEXT DEFAULT NULL,
        fuente VARCHAR(400) DEFAULT NULL,
        prioridad VARCHAR(10) DEFAULT 'media',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_run (run_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
}

// Contexto ligero del negocio para aterrizar las sugerencias (tolera tablas
// faltantes: cada conteo va en su propio try, nunca rompe).
if (!function_exists('radarSnapshot')) {
  function radarSnapshot(PDO $pdo): string {
    $q = function(string $sql) use ($pdo) {
      try { return (int)$pdo->query($sql)->fetchColumn(); } catch (Exception $e) { return null; }
    };
    $bits = [];
    $hot = $q("SELECT COUNT(*) FROM miembros WHERE estado='HOT LEAD'");
    if ($hot !== null)  $bits[] = "$hot hot leads";
    $act = $q("SELECT COUNT(*) FROM miembros WHERE estado='ACTIVO'");
    if ($act !== null)  $bits[] = "$act clientes activos";
    $pro = $q("SELECT COUNT(*) FROM miembros WHERE estado='PROSPECTO'");
    if ($pro !== null)  $bits[] = "$pro prospectos";
    $t65 = $q("SELECT COUNT(*) FROM miembros WHERE estado!='ACTIVO' AND DATE_ADD(dob,INTERVAL 65 YEAR) BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)");
    if ($t65 !== null)  $bits[] = "$t65 cumplen 65 en <30 días";
    return $bits ? ('Foto rápida del CRM hoy: ' . implode(' · ', $bits) . '.') : '';
  }
}

// Construye [system, user] según el modo.
if (!function_exists('radarPrompts')) {
  function radarPrompts(string $mode, PDO $pdo): array {
    $hoy   = date('Y-m-d');
    $snap  = radarSnapshot($pdo);
    $deep  = ($mode === 'weekly');
    $nItems = $deep ? '9 a 12' : '5 a 7';

    $system =
      "Eres el Radar de LUNA: el Chief of Staff de \"Medicare with Isabel\", una agencia de "
      ."seguros Medicare BILINGÜE (español e inglés) en California. Audiencia: personas de 65+ "
      ."y sus familias (muchos hispanos). Tu trabajo es investigar EN LA WEB qué está pasando "
      ."AHORA y traducirlo en ACCIONES concretas para que el equipo sea el mejor.\n\n"
      ."Piensa como un Chief of Staff de verdad: prioriza, sé específico, no des consejos genéricos. "
      ."Cada hallazgo debe poder ejecutarse esta " . ($deep ? 'semana' : 'mañana') . ".\n\n"
      ."REGLAS DE CUMPLIMIENTO CMS (obligatorio): nada de claims engañosos, ni \"el mejor plan\", "
      ."\"gratis\", \"el más barato\" ni garantías. Las ideas de marketing deben respetar las reglas "
      ."de mercadeo de Medicare. Si una táctica viral es riesgosa para CMS, dilo y propón una versión segura.\n\n"
      ."Usa búsqueda web para datos frescos y CITA la fuente (URL) cuando exista. No inventes.";

    $user =
      "Fecha de hoy: $hoy. Modo: " . ($deep ? 'REPORTE SEMANAL PROFUNDO' : 'ESCANEO DIARIO RÁPIDO') . ".\n"
      . ($snap ? "$snap\n" : '')
      ."\nInvestiga estos 5 frentes y dame de $nItems hallazgos en total (cubre los 5 frentes):\n"
      ."1) viral       — contenido/ganchos/formatos que se están volviendo virales y que se puedan adaptar a Medicare (ESP e ING).\n"
      ."2) social      — qué funciona ESTA " . ($deep ? 'semana' : 'semana') . " en Facebook, Instagram, TikTok y YouTube para llegar a 65+ y a sus hijos.\n"
      ."3) medicare    — noticias/cambios de Medicare, CMS, carriers o planes que afecten cómo vendemos o retenemos.\n"
      ."4) competencia — qué están haciendo otras agencias/agentes de Medicare y qué oportunidad podemos tomar.\n"
      ."5) mejora      — como Chief of Staff: cómo mejorar el negocio Y a LUNA misma. Qué está funcionando y conviene hacer más, qué cambiar, qué automatizar o priorizar.\n"
      ."\nResponde SOLO con JSON válido (sin markdown, sin ```), con esta forma EXACTA:\n"
      ."{\n"
      ."  \"resumen\": \"" . ($deep ? "2-3 frases" : "1 frase") . " con lo más importante de hoy y la jugada principal\",\n"
      ."  \"items\": [\n"
      ."    {\"categoria\":\"viral|social|medicare|competencia|mejora\", \"titulo\":\"título corto\", \"porque\":\"por qué importa para nosotros\", \"accion\":\"qué hacer, concreto\", \"fuente\":\"https://... o vacío\", \"prioridad\":\"alta|media|baja\"}\n"
      ."  ]\n"
      ."}\n"
      ."Escribe en español. Sé concreto y breve en cada campo.";

    return [$system, $user];
  }
}

// Extrae el objeto JSON de la respuesta del modelo (tolera fences/ruido).
if (!function_exists('radarParse')) {
  function radarParse(?string $text): ?array {
    if (!$text) return null;
    $t = trim($text);
    $t = preg_replace('/^```(?:json)?/i', '', $t);
    $t = preg_replace('/```$/', '', trim($t));
    $i = strpos($t, '{'); $j = strrpos($t, '}');
    if ($i === false || $j === false || $j <= $i) return null;
    $obj = json_decode(substr($t, $i, $j - $i + 1), true);
    if (!is_array($obj) || empty($obj['items']) || !is_array($obj['items'])) return null;
    return $obj;
  }
}

// Corre la investigación y la guarda. Devuelve el run (con items) o un run ok=0.
if (!function_exists('radarRun')) {
  function radarRun(PDO $pdo, string $mode = 'daily'): array {
    $mode = ($mode === 'weekly') ? 'weekly' : 'daily';
    radarEnsureTables($pdo);

    [$system, $user] = radarPrompts($mode, $pdo);
    $maxTok = ($mode === 'weekly') ? 3200 : 2200;
    $raw    = function_exists('lunaAIWeb') ? lunaAIWeb($system, $user, $maxTok, $mode === 'weekly' ? 8 : 5) : null;
    $parsed = radarParse($raw);

    $validCats = ['viral','social','medicare','competencia','mejora'];
    $validPrio = ['alta','media','baja'];

    if (!$parsed) {
      $pdo->prepare("INSERT INTO luna_radar_runs (modo, resumen, item_count, ok) VALUES (?,?,0,0)")
          ->execute([$mode, 'No se pudo generar el radar (sin API key o la búsqueda falló). Reintenta más tarde.']);
      $runId = (int)$pdo->lastInsertId();
      return ['run_id'=>$runId, 'modo'=>$mode, 'ok'=>false, 'resumen'=>'', 'items'=>[]];
    }

    $resumen = mb_substr(trim((string)($parsed['resumen'] ?? '')), 0, 1000);
    $items = [];
    foreach ($parsed['items'] as $it) {
      if (!is_array($it)) continue;
      $cat = strtolower(trim((string)($it['categoria'] ?? 'viral')));
      if (!in_array($cat, $validCats, true)) $cat = 'viral';
      $prio = strtolower(trim((string)($it['prioridad'] ?? 'media')));
      if (!in_array($prio, $validPrio, true)) $prio = 'media';
      $titulo = mb_substr(trim((string)($it['titulo'] ?? '')), 0, 240);
      if ($titulo === '') continue;
      $items[] = [
        'categoria' => $cat,
        'titulo'    => $titulo,
        'porque'    => mb_substr(trim((string)($it['porque'] ?? '')), 0, 1200),
        'accion'    => mb_substr(trim((string)($it['accion'] ?? '')), 0, 1200),
        'fuente'    => mb_substr(trim((string)($it['fuente'] ?? '')), 0, 400),
        'prioridad' => $prio,
      ];
    }

    $pdo->prepare("INSERT INTO luna_radar_runs (modo, resumen, item_count, ok) VALUES (?,?,?,1)")
        ->execute([$mode, $resumen, count($items)]);
    $runId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO luna_radar_items
        (run_id, categoria, titulo, porque, accion, fuente, prioridad)
        VALUES (?,?,?,?,?,?,?)");
    foreach ($items as $it) {
      $ins->execute([$runId, $it['categoria'], $it['titulo'], $it['porque'], $it['accion'], $it['fuente'], $it['prioridad']]);
    }

    return ['run_id'=>$runId, 'modo'=>$mode, 'ok'=>true, 'resumen'=>$resumen, 'items'=>$items];
  }
}

// Último run (opcionalmente filtrado por modo) con sus items.
if (!function_exists('radarLatest')) {
  function radarLatest(PDO $pdo, ?string $mode = null): ?array {
    radarEnsureTables($pdo);
    if ($mode === 'daily' || $mode === 'weekly') {
      $st = $pdo->prepare("SELECT * FROM luna_radar_runs WHERE modo=? AND ok=1 ORDER BY id DESC LIMIT 1");
      $st->execute([$mode]);
    } else {
      $st = $pdo->query("SELECT * FROM luna_radar_runs WHERE ok=1 ORDER BY id DESC LIMIT 1");
    }
    $run = $st->fetch(PDO::FETCH_ASSOC);
    if (!$run) return null;
    $it = $pdo->prepare("SELECT categoria, titulo, porque, accion, fuente, prioridad
                         FROM luna_radar_items WHERE run_id=? ORDER BY
                         FIELD(prioridad,'alta','media','baja'), id ASC");
    $it->execute([$run['id']]);
    $run['items'] = $it->fetchAll(PDO::FETCH_ASSOC);
    return $run;
  }
}
