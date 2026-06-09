# Estado del bridge Athena ↔ LUNA — 6 junio 2026

> Doc de estado durable. Se escribe en el repo para que NINGÚN dato dependa de
> que una sesión de chat siga abierta. Cualquier sesión nueva arranca leyendo
> esto + `CLAUDE.md`.

## Ramas (importante — no confundir)

- **Athena (este repo, Node.js/Railway):** `claude/sleepy-darwin-P4k2z` ← casa oficial. Sami despliega de aquí.
- **LUNA (Bluehost, PHP/MySQL):** `claude/happy-planck-Dtzud` ← repo SEPARADO, otra sesión Claude. El lado PHP del puente.
- `claude/busy-noether-1i93Q` fue un error — ignorar. (Estaba en el mismo commit que sleepy-darwin, no se perdió nada.)

## Qué está HECHO y verificado

**Lado LUNA (Bluehost) — listo:**
- `luna_config.php` arreglado en `/home1/emzmuumy/public_html/website_5a1c69e7/luna/luna_config.php`
  - Bug raíz: `LUNA_SERVICE_KEY` estaba sin `define( ... )` correcto → PHP leía el hex `5e6c…` como notación científica (5×10^6). Corregido a `define('LUNA_SERVICE_KEY', '5e6c…7e');`
  - Credenciales MySQL tenían placeholders literales (`PON_AQUI_EL_USUARIO`). Llenadas con valores reales:
    - DB_HOST: `localhost`
    - DB_USER: `emzmuumy_ISABEL_MEDICARE`
    - DB_NAME: `emzmuumy_CRM_MEDICAREWITHISABEL` (~219 MB, datos reales)
    - DB_PASS: reseteado en cPanel MySQL Databases, valor real en `luna_config.php`
- Endpoint diagnóstico público `https://withisabelfuentes.com/luna/luna_diag.php` devuelve:
  - `llave_servicio`: definida=true, longitud=64, tiene_espacios=false, es_placeholder=false, enmascarada `5e6c…1b7e`
  - `base_datos`: conecta=true
- `luna_api.php` acepta dual-key (`LUNA_SERVICE_KEY` o `LUNA_INTERNAL_KEY`); `db()` tolerante a fallos.

**Lado Athena (este repo) — pusheado a origin/sleepy-darwin:**
- `eaca599` fix(luna): Athena ya no inventa causas falsas de 403, repite hechos reales del bridge
- `e50b848` fix(briefing): fecha PT no UTC para comparación de "hoy"
- `30f48ed` fix(briefing): empty briefings — conflicto de prompt + sobras de Pilar
- `e9e6b8e` audit fixes: coach_cadence ids + trends.js drift
- `723d8ec` audit fixes: enmascarar llave en docs + limpiar refs a Pilar

`server/src/luna_client.js` manda 3 headers (`X-LUNA-Key`, `X-Athena-Key`, `Authorization: Bearer`). Código del puente correcto, no es el problema.

## Qué FALTA (poco)

### 1. Prueba final del bridge (sin necesidad de WhatsApp para el primer check)
En el PWA → Sistema → Diagnóstico:
- **`/api/luna/debug-auth`** confirma que Railway tiene la llave. Esperado: `length: 64`, `has_dollar: false`, `has_exclaim: false`, `base_url: configurado`, `masked: 5e6c…7e`.
  - Nota: Railway enmascara con los últimos 2 (`5e6c…7e`); Bluehost con los últimos 4 (`5e6c…1b7e`). Mismo valor, cortan distinto. No cunda el pánico.
- **`/api/luna/health`** pinguea las 11 acciones de LUNA una por una y marca cuál responde ok. Si "Tickets abiertos" sale verde → el puente ya está vivo. Este es el momento de la verdad.
- Vuelta de la victoria: WhatsApp a Athena → "dame el reporte de tickets abiertos" → debe contestar con números reales.

### 2. Rotar la llave (seguridad — hacer DESPUÉS de que pase el test)
La `LUNA_SERVICE_KEY` se expuso en chat. Rotar:
1. `openssl rand -hex 32` (64 hex nuevos)
2. Railway → Variables → `LUNA_API_KEY` → pegar nueva
3. cPanel → `luna_config.php` → reemplazar valor de `LUNA_SERVICE_KEY` (idéntico, sin comillas/espacios extra)
4. Verificar con `luna_diag.php` que conecta
(El valor viejo NO se guarda en este doc a propósito — está quemado.)

### 3. Avisar a Sami
El deploy estaba agendado para el 7 jun. Ya se hizo hoy (6 jun). Decirle "ya quedó" + resumen.

## Aprendizajes del día (para no repetir el dolor)
- cPanel Git deploy va a la carpeta `crm/`, NO a `luna/`. Los archivos del puente llegan a `luna/` por otro mecanismo de Bluehost que sí funciona.
- El `error_log` de PHP vive dentro de la carpeta `luna/` — ábrelo en File Manager. El "Errors" de Metrics en cPanel solo muestra Apache, NO fatals de PHP.
- El sitio web de LUNA nunca se cayó para el equipo (Skarleth, Arlette, Sami). Lo único roto era el puente a Athena.
</content>
