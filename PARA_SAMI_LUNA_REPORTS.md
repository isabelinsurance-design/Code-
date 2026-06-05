# Para Sami — endpoints `open_tickets` y `today_appointments` en LUNA

> ⚠️ **ACTUALIZADO** después de descubrir que la query actual de `open_tickets`
> NO devuelve `asignado_a`, lo que hizo que Athena agrupara todos los tickets
> bajo "Isabel" (la creadora) en vez del agente real al que están asignados.

Athena (vía Pilar) tiene dos tools:
- `luna_tickets_abiertos` → llama `?action=luna_open_tickets&priority=...`
- `luna_citas_hoy` → llama `?action=luna_today_appointments`

Para que Athena pueda decirle a Isabel "Arlette tiene 16 tickets / Skarleth 5",
la query de open_tickets DEBE devolver el campo `asignado_a` (id del agente
asignado) — no solo `agente_ini`/`agente_nombre` (que son del CREADOR).

## El SQL correcto

Reemplaza el `case 'luna_open_tickets':` actual con este:

```php
case 'luna_open_tickets':
    $priority = $_GET['priority'] ?? '';
    $sql = "SELECT t.id, t.tipo, t.prioridad, t.descripcion, t.titulo, t.estado,
                   t.asignado_a,
                   COALESCE(u.nombre, CASE
                       WHEN t.asignado_a IS NULL THEN 'sin asignar'
                       ELSE CONCAT('id ', t.asignado_a)
                   END) AS asignado_nombre,
                   t.agente_ini, t.agente_nombre,
                   t.miembro_id, m.nombre AS miembro_nombre,
                   t.fecha_creacion
            FROM tickets t
            LEFT JOIN usuarios u ON t.asignado_a = u.id
            LEFT JOIN miembros m ON t.miembro_id = m.id
            WHERE t.estado IN ('ABIERTO', 'EN PROCESO', 'PENDIENTE')";
    $params = [];
    if (!empty($priority)) {
        $sql .= " AND t.prioridad = ?";
        $params[] = $priority;
    }
    $sql .= " ORDER BY FIELD(t.prioridad, 'ALTA', 'MEDIA', 'BAJA'), t.fecha_creacion DESC
              LIMIT 250";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => ['tickets' => $rows]]);
    break;
```

### Cambios clave vs la versión anterior

1. **Devuelve `t.asignado_a`** (id numérico) y `asignado_nombre` (vía JOIN
   a `usuarios.nombre`). Es lo que Athena necesita para agrupar por agente.
2. **`COALESCE` para "sin asignar":** si `asignado_a IS NULL`, devuelve el
   texto literal `'sin asignar'`. Eso hace que esos 37 tickets huérfanos
   aparezcan como un grupo identificable, no se pierdan en NULL.
3. **`'EN PROCESO'` con espacio** (no underscore) — match al schema real.
4. **LIMIT 250** (era 50) — el conteo real de ABIERTO es ~89, con todos
   los estados puede pasar de 100.
5. **Mantiene `agente_ini`/`agente_nombre`** — son útiles como info
   secundaria (quién creó el ticket). Athena ahora distingue creador de
   asignado.
6. **Envuelve en `{tickets: rows}`** — match al shape que ya devuelves.
   Athena ya lo desempaca correctamente.

## Citas hoy (sin cambios)

```php
case 'luna_today_appointments':
    $sql = "SELECT c.id, c.tipo, c.fecha_hora, c.modalidad, c.lugar, c.notas,
                   c.miembro_id, m.nombre AS miembro_nombre,
                   c.asignado_a, COALESCE(u.nombre, 'sin asignar') AS asignado_nombre
            FROM citas c
            LEFT JOIN miembros m ON c.miembro_id = m.id
            LEFT JOIN usuarios u ON c.asignado_a = u.id
            WHERE DATE(c.fecha_hora) = CURDATE()
            ORDER BY c.fecha_hora ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'data' => ['citas' => $rows]]);
    break;
```

## Verificar antes de subir

Prueba con curl desde tu computadora:

```bash
curl -s -H "X-LUNA-Key: <la llave>" \
     "https://[tu-dominio]/luna_api.php?action=luna_open_tickets" \
     | python3 -c "import json,sys; d=json.load(sys.stdin); tickets=d['data']['tickets']; \
       from collections import Counter; \
       c=Counter(t['asignado_nombre'] for t in tickets); \
       print('Total:', len(tickets)); \
       [print(f'  {n}: {v}') for n,v in c.most_common()]"
```

Debes ver algo como (basado en datos reales de junio 2026):
```
Total: 89
  sin asignar: 37
  Isabel: 18
  Arlette: 16
  Sami: 13
  Skarleth: 5
```

Si los números coinciden con lo que Isabel ve en la UI de LUNA → funciona.

## Avisar a Isabel

Cuando esté listo:

> *"Listo, fixed. open_tickets ahora devuelve asignado_a correctamente.
> Athena debería darte los conteos por agente correctos al toque."*
