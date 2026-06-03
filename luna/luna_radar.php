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

// Roster de agentes (id → nombre) para detectar cuáles NO se están usando.
// Mantener en sync con AGENTS de index.html.
if (!function_exists('radarRoster')) {
  function radarRoster(): array {
    return [
      'luna_main'   => 'LUNA',
      'comando'     => 'Centro de Comando',
      'analista'    => 'Analista',
      'estudio'     => 'Estudio Creativo',
      'compliance'  => 'Compliance',
      'sales_coach' => 'Sales Coach',
      'retencion'   => 'Retención & Servicio',
      'coach'       => 'Coach',
      'config'      => 'Configuración LUNA',
      'onboarding'  => 'Onboarding',
      'ads'         => 'Ads & Métricas',
    ];
  }
}

// Agrega el uso real (audit log) en una ventana de tiempo.
// $sinceDays = hace cuántos días empieza; $untilDays = hasta hace cuántos
// días (0 = ahora). Tolera que la tabla no exista todavía.
if (!function_exists('radarUsageWindow')) {
  function radarUsageWindow(PDO $pdo, int $sinceDays, int $untilDays = 0): array {
    $cond = "created_at >= DATE_SUB(NOW(), INTERVAL $sinceDays DAY)";
    if ($untilDays > 0) $cond .= " AND created_at < DATE_SUB(NOW(), INTERVAL $untilDays DAY)";
    $out = ['total'=>0,'chats'=>0,'writes'=>0,'denied'=>0,'alerts'=>0,'byAgent'=>[]];
    $count = function(string $w) use ($pdo, $cond) {
      try { return (int)$pdo->query("SELECT COUNT(*) FROM luna_audit_log WHERE $w AND $cond")->fetchColumn(); }
      catch (Exception $e) { return 0; }
    };
    $out['total']  = $count("1=1");
    $out['chats']  = $count("action LIKE '%CHAT%'");
    $out['writes'] = $count("action LIKE '%WRITE:%'");
    $out['denied'] = $count("action LIKE '%DENEGADO%'");
    $out['alerts'] = $count("action LIKE '%ALERTA%'");
    try {
      $rows = $pdo->query("SELECT detail FROM luna_audit_log WHERE action='CHAT' AND $cond")->fetchAll(PDO::FETCH_COLUMN);
      foreach ($rows as $d) {
        if (preg_match('/^\[([a-zA-Z0-9_\-]+)/', (string)$d, $m)) {
          $ag = $m[1];
          $out['byAgent'][$ag] = ($out['byAgent'][$ag] ?? 0) + 1;
        }
      }
    } catch (Exception $e) { /* tabla aún no existe */ }
    return $out;
  }
}

// Bloque de texto con el USO REAL para alimentar la lente "mejora".
// Daily: últimos 7 días. Weekly: esta semana vs la anterior + auto-evaluación.
if (!function_exists('radarUsageStats')) {
  function radarUsageStats(PDO $pdo, string $mode): string {
    $roster = radarRoster();
    $fmtAgents = function(array $by) use ($roster) {
      if (!$by) return 'ninguno registrado';
      arsort($by);
      $parts = [];
      foreach ($by as $id => $n) { $parts[] = ($roster[$id] ?? $id) . "×$n"; }
      return implode(', ', $parts);
    };
    $unused = function(array $by) use ($roster) {
      $miss = [];
      foreach ($roster as $id => $name) { if (empty($by[$id])) $miss[] = $name; }
      return $miss ? implode(', ', $miss) : 'ninguno (se usaron todos)';
    };

    if ($mode === 'weekly') {
      $now  = radarUsageWindow($pdo, 7, 0);
      $prev = radarUsageWindow($pdo, 14, 7);
      return
        "=== USO REAL DE LA PLATAFORMA (para tu auto-evaluación de Chief of Staff) ===\n"
        ."ESTA SEMANA: {$now['total']} eventos · {$now['chats']} consultas IA · {$now['writes']} acciones/escrituras · {$now['denied']} intentos bloqueados · {$now['alerts']} alertas.\n"
        ."  Agentes usados esta semana: " . $fmtAgents($now['byAgent']) . ".\n"
        ."  Agentes SIN usar esta semana: " . $unused($now['byAgent']) . ".\n"
        ."SEMANA ANTERIOR: {$prev['total']} eventos · {$prev['chats']} consultas IA · {$prev['writes']} acciones.\n"
        ."  Agentes usados la semana pasada: " . $fmtAgents($prev['byAgent']) . ".\n";
    }
    // daily
    $w = radarUsageWindow($pdo, 7, 0);
    return
      "=== USO REAL (últimos 7 días) ===\n"
      ."{$w['total']} eventos · {$w['chats']} consultas IA · {$w['writes']} acciones · {$w['denied']} intentos bloqueados.\n"
      ."Agentes usados: " . $fmtAgents($w['byAgent']) . ".\n"
      ."Agentes SIN usar: " . $unused($w['byAgent']) . ".\n";
  }
}

// Construye [system, user] según el modo.
if (!function_exists('radarPrompts')) {
  function radarPrompts(string $mode, PDO $pdo): array {
    $hoy   = date('Y-m-d');
    $snap  = radarSnapshot($pdo);
    $deep  = ($mode === 'weekly');
    $nItems = $deep ? '10 a 13' : '6 a 8';
    $nMejora = $deep ? '4 a 5' : '2 a 3';
    $usage = radarUsageStats($pdo, $mode);

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

    // Auto-evaluación: solo en el reporte semanal.
    $selfGrade = $deep
      ? "\nEN EL MODO SEMANAL, además, INCLUYE como PRIMER item de 'mejora' una AUTO-EVALUACIÓN: "
        ."pon en el título una nota tipo \"Semana: B+ (vs la pasada)\" comparando el uso de esta semana "
        ."contra la anterior (más/menos consultas, agentes nuevos usados o abandonados, acciones), explica "
        ."en 'porque' cómo te fue, y en 'accion' propón UN SOLO cambio concreto a ti misma (LUNA) para la "
        ."próxima semana. Sé honesta: si bajó el uso o hay agentes muertos, dilo.\n"
      : '';

    $user =
      "Fecha de hoy: $hoy. Modo: " . ($deep ? 'REPORTE SEMANAL PROFUNDO' : 'ESCANEO DIARIO RÁPIDO') . ".\n"
      . ($snap ? "$snap\n" : '')
      ."\n$usage\n"
      ."Dame de $nItems hallazgos en total. PRIORIDAD #1 = la lente 'mejora' (Chief of Staff): dedícale "
      ."$nMejora items, MÁS que a cualquier otra lente. Cubre las 5 lentes, pero 'mejora' va primero y pesa más.\n\n"
      ."LENTE PRINCIPAL:\n"
      ."• mejora      — como Chief of Staff, USANDO los datos de uso real de arriba: di qué agentes/funciones "
      ."NO se están usando y vale la pena probar (\"no estás usando X, úsalo para Y\"), qué flujo está lento o "
      ."manual y conviene automatizar, qué está funcionando y hay que hacer más, y qué cambiar o priorizar. "
      ."Sé específico con nombres de agentes y números.\n"
      . $selfGrade .
      "\nLAS OTRAS 4 LENTES (1-2 items c/u):\n"
      ."• viral       — contenido/ganchos/formatos que se están volviendo virales y se puedan adaptar a Medicare (ESP e ING).\n"
      ."• social      — qué funciona esta semana en Facebook, Instagram, TikTok y YouTube para llegar a 65+ y a sus hijos.\n"
      ."• medicare    — noticias/cambios de Medicare, CMS, carriers o planes que afecten cómo vendemos o retenemos.\n"
      ."• competencia — qué hacen otras agencias/agentes de Medicare y qué oportunidad podemos tomar.\n"
      ."\nResponde SOLO con JSON válido (sin markdown, sin ```), con esta forma EXACTA, con los items de 'mejora' PRIMERO:\n"
      ."{\n"
      ."  \"resumen\": \"" . ($deep ? "2-3 frases" : "1 frase") . " desde tu rol de Chief of Staff: lo más importante y la jugada principal\",\n"
      ."  \"items\": [\n"
      ."    {\"categoria\":\"mejora|viral|social|medicare|competencia\", \"titulo\":\"título corto\", \"porque\":\"por qué importa para nosotros\", \"accion\":\"qué hacer, concreto\", \"fuente\":\"https://... o vacío\", \"prioridad\":\"alta|media|baja\"}\n"
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
