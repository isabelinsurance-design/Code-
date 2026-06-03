<?php
/* ════════════════════════════════════════════════════════════════
   LUNA MEETINGS — Notas y acuerdos de la junta de equipo
   Medicare with Isabel

   Cierra el ciclo de la junta del sábado:
     1) Isabel registra los ACUERDOS y las TAREAS (con responsable y fecha).
     2) Quedan guardados con estado (pendiente/hecho/cancelado).
     3) LUNA les da SEGUIMIENTO: el reporte del viernes siguiente muestra lo
        que sigue pendiente, para revisarlo en la próxima junta.

   Lógica compartida por la API (luna_api.php) y el cron semanal
   (luna_weekly_cron.php). Tablas se crean solas.
════════════════════════════════════════════════════════════════ */

if (!function_exists('meetingEnsureTables')) {
  function meetingEnsureTables(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_meetings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        meeting_date DATE NOT NULL,
        resumen TEXT DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_date (meeting_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS luna_meeting_actions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        meeting_id INT NOT NULL,
        accion VARCHAR(500) NOT NULL,
        responsable VARCHAR(80) DEFAULT NULL,
        due_date DATE DEFAULT NULL,
        estado VARCHAR(12) DEFAULT 'pendiente',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        done_at DATETIME DEFAULT NULL,
        INDEX idx_meeting (meeting_id), INDEX idx_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
}

// Guarda una junta + sus tareas. $actions: [['accion','responsable','due_date'], ...]
// Devuelve el id de la junta.
if (!function_exists('meetingSave')) {
  function meetingSave(PDO $pdo, string $date, ?string $resumen, array $actions, ?int $uid): int {
    meetingEnsureTables($pdo);
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : date('Y-m-d');
    $pdo->prepare("INSERT INTO luna_meetings (meeting_date, resumen, created_by) VALUES (?,?,?)")
        ->execute([$d, $resumen !== null ? mb_substr($resumen, 0, 4000) : null, $uid]);
    $mid = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO luna_meeting_actions (meeting_id, accion, responsable, due_date)
                          VALUES (?,?,?,?)");
    foreach ($actions as $a) {
      $accion = trim((string)($a['accion'] ?? ''));
      if ($accion === '') continue;
      $resp = trim((string)($a['responsable'] ?? ''));
      $due  = trim((string)($a['due_date'] ?? ''));
      $ins->execute([
        $mid,
        mb_substr($accion, 0, 500),
        $resp !== '' ? mb_substr($resp, 0, 80) : null,
        preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) ? $due : null,
      ]);
    }
    return $mid;
  }
}

// Juntas recientes con sus tareas anidadas.
if (!function_exists('meetingList')) {
  function meetingList(PDO $pdo, int $limit = 12): array {
    meetingEnsureTables($pdo);
    $limit = max(1, min(50, $limit));
    $ms = $pdo->query("SELECT id, meeting_date, resumen, created_at
                       FROM luna_meetings ORDER BY meeting_date DESC, id DESC LIMIT $limit")
              ->fetchAll(PDO::FETCH_ASSOC);
    if (!$ms) return [];
    $ids = implode(',', array_map(fn($m) => (int)$m['id'], $ms));
    $acts = $pdo->query("SELECT id, meeting_id, accion, responsable, due_date, estado, done_at
                         FROM luna_meeting_actions WHERE meeting_id IN ($ids)
                         ORDER BY FIELD(estado,'pendiente','hecho','cancelado'), id ASC")
                ->fetchAll(PDO::FETCH_ASSOC);
    $byM = [];
    foreach ($acts as $a) { $byM[$a['meeting_id']][] = $a; }
    foreach ($ms as &$m) { $m['actions'] = $byM[$m['id']] ?? []; }
    return $ms;
  }
}

// Tareas pendientes de TODAS las juntas (para el seguimiento del viernes).
if (!function_exists('meetingOpenActions')) {
  function meetingOpenActions(PDO $pdo, int $limit = 50): array {
    meetingEnsureTables($pdo);
    $limit = max(1, min(100, $limit));
    return $pdo->query("SELECT a.id, a.accion, a.responsable, a.due_date, m.meeting_date
                        FROM luna_meeting_actions a
                        JOIN luna_meetings m ON m.id = a.meeting_id
                        WHERE a.estado = 'pendiente'
                        ORDER BY (a.due_date IS NULL), a.due_date ASC, m.meeting_date ASC
                        LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
  }
}

// Cambia el estado de una tarea (pendiente|hecho|cancelado).
if (!function_exists('meetingToggleAction')) {
  function meetingToggleAction(PDO $pdo, int $actionId, string $estado): bool {
    meetingEnsureTables($pdo);
    if (!in_array($estado, ['pendiente','hecho','cancelado'], true)) return false;
    $doneAt = $estado === 'hecho' ? date('Y-m-d H:i:s') : null;
    $st = $pdo->prepare("UPDATE luna_meeting_actions SET estado=?, done_at=? WHERE id=?");
    $st->execute([$estado, $doneAt, $actionId]);
    return $st->rowCount() > 0;
  }
}
