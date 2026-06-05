# Para Sami — LUNA da 403 a Athena (auth bloqueada)

**Síntoma:** Todos los endpoints de LUNA (`/luna_api.php?action=*`) devuelven HTTP 403 Forbidden a las llamadas de Athena, sin importar la acción. Funcionan correctamente cuando entras tú desde browser con login.

**Diagnóstico de Athena (verificado):**
- Llave que Athena manda: `5e6c…7e` (64 caracteres hex, en Railway `LUNA_API_KEY`) — ✓ no se ha cambiado
- Athena manda **tres variantes de header simultáneamente** para cubrir nombres distintos:
  - `X-LUNA-Key: <key>`
  - `X-Athena-Key: <key>`
  - `Authorization: Bearer <key>`
- El servidor PHP responde 403 inmediato, antes de procesar la acción

**Causa raíz más probable:** Falta en `luna_api.php` el bloque de "bypass de sesión" que:
1. Lee el header `X-LUNA-Key` (o equivalente)
2. Lo compara contra una constante PHP
3. Si matchea, brinca el flujo normal de `session_start()` y trata la request como Isabel-admin

Sin este bloque, PHP cae al check de sesión web → no hay cookie → 403.

---

## Paso 1 — Verificación rápida (3 min)

En cPanel File Manager o SSH, dentro del directorio donde vive `luna_api.php`, busca:

```bash
grep -n "X-LUNA-Key\|X-Athena-Key\|HTTP_X_LUNA\|HTTP_X_ATHENA" luna_api.php luna_config.php *.php
grep -n "LUNA_INTERNAL_KEY\|LUNA_ATHENA_KEY\|LUNA_API_AUTH" luna_api.php luna_config.php *.php
```

### Caso A — Encuentras referencias

Si ya hay algo tipo `if ($_SERVER['HTTP_X_LUNA_KEY'] === LUNA_INTERNAL_KEY)`:

- Verifica que el valor de la constante **coincida exactamente** con el `LUNA_API_KEY` en Railway (`5e6c…7e`)
- Si la constante existe pero con OTRO valor → actualízala al valor de Railway, o actualiza Railway al valor de la constante (escoge uno como source-of-truth)
- Si la constante existe y matchea pero igual da 403 → revisa que el bypass este **antes** del `session_start()`, no después

### Caso B — No encuentras ninguna referencia

Falta el patch entero. Aplícalo (siguiente sección).

---

## Paso 2 — Aplicar el patch en `luna_api.php`

**Backup primero:** copia `luna_api.php` a `luna_api.php.backup_YYYYMMDD` antes de tocar.

**Al INICIO de `luna_api.php`**, después de los `require` / `include` pero **ANTES del primer `session_start()`**, pega este bloque:

```php
// === BYPASS DE SESIÓN PARA ATHENA (API) ===
// Athena envía su llave en uno de estos headers. Si matchea, brincamos
// el flujo normal de sesión web y tratamos la request como Isabel-admin.
$athenaKey = $_SERVER['HTTP_X_LUNA_KEY']
          ?? $_SERVER['HTTP_X_ATHENA_KEY']
          ?? null;

// También aceptamos Authorization: Bearer <key>
if (!$athenaKey && !empty($_SERVER['HTTP_AUTHORIZATION'])) {
    if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
        $athenaKey = trim($m[1]);
    }
}

if ($athenaKey) {
    // Llave válida desde luna_config.php — agrega la constante si no existe.
    $validKey = defined('LUNA_INTERNAL_KEY') ? LUNA_INTERNAL_KEY : '';

    if ($validKey && hash_equals($validKey, $athenaKey)) {
        // Match — bypass sesión y monto identidad de Isabel admin
        $_SESSION = [
            'user_id'   => 6,         // id de Isabel en LUNA
            'is_admin'  => true,
            'via'       => 'athena_api',
        ];
        define('ATHENA_API_REQUEST', true);
    } else {
        // Llave presente pero NO coincide — rechazo explícito
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'invalid_athena_key']);
        exit;
    }
}
// === FIN BYPASS ===
```

**En `luna_config.php`**, agrega esta constante (con el valor que está en Railway `LUNA_API_KEY`):

```php
define('LUNA_INTERNAL_KEY', '5e6cXXXXXXXX...XXXXXXX7e');
//                          ^^^^^^^^^^^^^^^^^^^^^^^^^^^^
//                          pega aquí el valor exacto del env var
//                          LUNA_API_KEY de Railway (64 chars hex)
```

> 💡 El valor exacto lo tiene Isabel en su Railway → Variables → `LUNA_API_KEY`. NO lo pegues en este doc por seguridad — pídeselo por canal privado.

> ⚠️ `LUNA_SERVICE_KEY` existente (`LunaAthena2026$!`) **NO se toca** — esa es para otra cosa (probablemente API pública del frontend de LUNA).

---

## Paso 3 — Probar

Después de guardar los cambios, prueba desde tu computadora con `curl`:

```bash
curl -i -H "X-LUNA-Key: <el valor de LUNA_INTERNAL_KEY>" \
     "https://[tu-dominio-luna]/luna_api.php?action=luna_pipeline_summary"
```

**Resultado esperado:**

```
HTTP/2 200
content-type: application/json
{"ok":true,"data":{...}}
```

Si sigue dando 403 → confirma que el bloque PHP nuevo esté **antes** del `session_start()` y revisa el log de errores de Bluehost por si hay un parse error.

---

## Paso 4 — Avisar a Isabel

Una vez verificado con curl que el 200 funciona, mándale mensaje a Isabel:

> *"Lista — bypass aplicado en luna_api.php. Athena debería reconectar en segundos. Confirma en /diagnostico del PWA."*

Ella va a poder ver inmediatamente en el dashboard de Athena (pantalla **Diagnóstico**) que LUNA pasa a verde y los 11 endpoints LUNA marcan ✓.

---

## Gotchas comunes

- **Espacio al copiar la llave** — si pegas con un espacio invisible al final, `hash_equals` falla. Asegúrate `strlen($validKey)` da exactamente 64.
- **session_start() en archivos incluidos** — si tu `luna_api.php` hace `require 'header.php'` y header.php llama `session_start()`, el bypass tiene que estar **antes del require**.
- **mod_security / WAF** — si Bluehost tiene mod_security y bloquea headers custom, puedes terminar dando 403 a nivel Apache antes de que el PHP corra. Revisa logs en `~/logs/error_log`.
- **Cache de OPcache** — después de editar el PHP, si Bluehost tiene OPcache activo, los cambios pueden tardar 1-2 min en surtir efecto. Si necesitas inmediatez, restart de PHP-FPM o un `<? opcache_reset(); ?>` temporal.

---

## Si nada de lo anterior funciona

Mándame a mí (Sami) los siguientes datos y los diagnóstico:

1. Output exacto de `curl -i` con verbose (`-v`)
2. Las primeras 40 líneas de `luna_api.php` (anonimiza credenciales)
3. Las últimas 20 líneas de `~/logs/error_log` después de hacer una request fallida
