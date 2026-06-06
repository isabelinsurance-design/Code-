# Para Sami — Encender LUNA (todo en un solo viaje) · 6 jun 2026

**Tiempo:** ~15-20 min. **Necesitas:** acceso a Bluehost (cPanel/File Manager),
los datos de MySQL, la API key de Anthropic, y la `LUNA_API_KEY` de Railway.

Todo el código está listo en la rama de GitHub **`claude/happy-planck-Dtzud`**.
LUNA vive en: `/home1/emzmuumy/public_html/website_5a1c69e7/luna/`

> Haz los pasos en orden. Cada uno tiene su verificación.

---

## PASO 1 — Subir TODOS los archivos de la carpeta `luna/`

Baja la carpeta `luna/` de la rama `claude/happy-planck-Dtzud` (botón verde
**Code → Download ZIP**, o descarga archivo por archivo) y súbela a
`website_5a1c69e7/luna/`, **reemplazando** lo que pregunte.

Archivos clave que DEBEN quedar arriba:
- `index.html`  ← app de LUNA (agentes, voz, PWA, sección Marketing)
- `luna_api.php`  ← cerebro/API (incluye el arreglo de auth de Athena)
- `luna_config.php`  ← configuración (lo llenas en el Paso 2)
- `marketing.html`  ← el Sistema Maestro de marketing (18 herramientas, ~1.4 MB)
- `manifest.json`, `sw.js`, `icon-192.png`, `icon-512.png`, `apple-touch-icon.png`  ← para instalar como app
- `luna_ai.php`, `luna_meetings.php`, `luna_radar.php`, `recursos.json`
- la subcarpeta `cron/` completa

(Los archivos `.md` son notas; pueden subirse o no, da igual.)

---

## PASO 2 — Llenar `luna_config.php` (lo más importante)

Abre `luna_config.php` con **Edit** y llena:

**a) Base de datos** (cPanel → MySQL® Databases — la MISMA base que usa el CRM):
```php
$LUNA_DB_HOST = 'localhost';
$LUNA_DB_USER = '...';   // usuario MySQL
$LUNA_DB_PASS = '...';   // contraseña
$LUNA_DB_NAME = '...';   // nombre de la base
```

**b) API key de Anthropic** (quita las `//` y pega la key `sk-ant-...`):
```php
define('ANTHROPIC_API_KEY', 'sk-ant-...');
```

**c) ⭐ LA LLAVE DEL PUENTE con Athena (esto cierra el 403):**
```php
define('LUNA_SERVICE_KEY', 'PEGA_AQUI_LA_LUNA_API_KEY_DE_RAILWAY');
```
> Es la **misma** llave que Athena usa en Railway (`LUNA_API_KEY`, empieza con `5e6c…`, 64 caracteres). Pídesela a Isabel por canal privado. **Debe ser idéntica.**

Guarda.

---

## PASO 3 — Quitar el "bypass" viejo (si lo pusiste antes)

Si en un intento anterior pegaste a mano un bloque de "bypass de sesión" en
`luna_api.php` (el que ponía `is_admin = true`): **ya no se necesita y NO debe
quedar.** Al reemplazar `luna_api.php` con el del Paso 1 (versión limpia), el
bypass desaparece solo. Solo confirma que el `luna_api.php` que quedó arriba es
el de la rama (no una versión vieja parchada).

También revisa que **no exista una variable de entorno vieja** `LUNA_SERVICE_KEY`
(en cPanel → MultiPHP INI / variables de entorno, o en un `.htaccess`/`.env`) con
otro valor — si existe, le gana al config. Ponle el mismo valor o bórrala.

---

## PASO 4 — Probar

**a) ¿LUNA carga?** Abre LUNA en el navegador → **Ctrl+Shift+R**. Debe abrir el chat.

**b) ¿El puente con Athena funciona?** Abre en el navegador (cambia el dominio y la llave):
```
https://TU-DOMINIO/luna/luna_api.php?action=luna_whoami&service_key=LA_LLAVE
```
- `{"ok":true,...}` → 🎉 puente conectado.
- `"Llave de servicio inválida"` → la llave no coincide (revisa Paso 2c y Paso 3).

**c) Avísale a Isabel** que ya está vivo, y pásale la **URL final** de LUNA.

---

## 🆘 Si algo falla
| Mensaje | Qué hacer |
|---|---|
| `Failed opening required ... config` | Falta `luna_config.php` en `luna/` (Paso 1) |
| `Undefined constant` / `Access denied` / `Unknown database` | Credenciales MySQL mal (Paso 2a) |
| `Llave de servicio inválida` | La llave no coincide con la de Athena (Paso 2c / Paso 3) |
| `Unexpected token <DOCTYPE` | Falta subir el `index.html` nuevo (Paso 1) |
| Error 500 | Manda el **error log** de Bluehost a Isabel |

---

## Notas
- Bluehost corre **PHP 8.4** (compatible).
- **No hay que correr SQL** — las tablas nuevas se crean solas.
- La base de datos debe ser la **misma del CRM** (miembros, tickets, etc.).
- Athena entra **limitada** (leer + registrar notas/contactos/citas/leads/tickets). NO puede borrar/editar/comisiones. Es a propósito.
