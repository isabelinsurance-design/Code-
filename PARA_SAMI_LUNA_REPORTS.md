# Para Sami — agregar endpoints `open_tickets` y `today_appointments` en LUNA

Athena (vía Pilar) tiene dos tools nuevos:
- `luna_tickets_abiertos` → llama `?action=luna_open_tickets&priority=...`
- `luna_citas_hoy` → llama `?action=luna_today_appointments`

Isabel los está pidiendo en WhatsApp y en el PWA. Ahora mismo fallan con 404
porque esos `case` no existen en `luna_api.php` en Bluehost.

## Lo que hay que agregar

Abre `luna_api.php` en Bluehost (cPanel → File Manager → `public_html/`).

Busca el `switch ($action)` grande. Agrega estos dos casos antes del `default:`.

### Case 1 — Tickets abiertos

```php
case 'luna_open_tickets':
    $priority = $_GET['priority'] ?? '';
    $sql = "SELECT t.id, t.tipo, t.prioridad, t.descripcion, t.titulo, t.estado,
                   t.asignado_a, u.nombre AS asignado_nombre,
                   t.miembro_id, m.nombre AS miembro_nombre,
                   t.fecha_creacion
            FROM tickets t
            LEFT JOIN usuarios u ON t.asignado_a = u.id
            LEFT JOIN miembros m ON t.miembro_id = m.id
            WHERE t.estado IN ('ABIERTO', 'EN_PROCESO', 'PENDIENTE')";
    $params = [];
    if (!empty($priority)) {
        $sql .= " AND t.prioridad = ?";
        $params[] = $priority;
    }
    $sql .= " ORDER BY FIELD(t.prioridad, 'ALTA', 'MEDIA', 'BAJA'), t.fecha_creacion DESC
              LIMIT 50";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $rows]);
    break;
```

### Case 2 — Citas de hoy

```php
case 'luna_today_appointments':
    $sql = "SELECT c.id, c.tipo, c.fecha_hora, c.modalidad, c.lugar, c.notas,
                   c.miembro_id, m.nombre AS miembro_nombre,
                   c.asignado_a, u.nombre AS asignado_nombre
            FROM citas c
            LEFT JOIN miembros m ON c.miembro_id = m.id
            LEFT JOIN usuarios u ON c.asignado_a = u.id
            WHERE DATE(c.fecha_hora) = CURDATE()
            ORDER BY c.fecha_hora ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => $rows]);
    break;
```

## Importante

1. **Verifica los nombres de columnas y tablas** — usé los más comunes
   (`tickets`, `citas`, `miembros`, `usuarios`). Si en tu schema se llaman
   distinto (`citas` puede ser `appointments`, etc.), ajusta antes de subir.

2. **Verifica los valores de `estado`** en `tickets` — yo asumí `ABIERTO`,
   `EN_PROCESO`, `PENDIENTE`. Si son distintos (ej. `OPEN`, `OPEN_IN_PROGRESS`),
   ajusta el `WHERE`.

3. **Backup primero** — siempre baja `luna_api.php` antes de editarlo.

4. Una vez subido, prueba en el browser:
   `https://tu-dominio.com/luna_api.php?action=luna_open_tickets&priority=ALTA`
   con el header `X-LUNA-Key: TU_LLAVE`. Debería devolver JSON con `ok: true`.

## Mientras tanto

Ya le dije a Pilar que si estos endpoints fallan con 404, use
`luna_briefing_completo` como respaldo (ese ya existe y trae conteo de
tickets ALTA + citas de hoy). Isabel no se queda sin respuesta, pero el
reporte va a ser limitado (solo conteo + alta prioridad).
