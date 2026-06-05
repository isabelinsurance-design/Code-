# 🛠️ RUNBOOK — Poner LUNA en línea (para Sami)

**Objetivo:** que LUNA cargue y que Athena pueda leer los tickets/base de datos.
**Tiempo estimado:** ~10 minutos.
**Qué necesitas:** acceso a Bluehost (cPanel + File Manager) y a Railway (donde vive Athena).

> El código ya está 100% listo y subido. Lo que falta son pasos **del servidor**
> que solo se pueden hacer con acceso a Bluehost. Sigue esto en orden, de arriba
> hacia abajo. Cada paso tiene una verificación. No saltes pasos.

---

## Contexto rápido (qué pasó)
LUNA se acaba de instalar y le faltaban piezas de configuración. Ya se resolvieron
casi todas en el código. Queda **una sola cosa de fondo**: que la *llave de servicio*
sea idéntica en los dos lados (LUNA y Athena). Eso es lo que da el error `403`.

Ruta de LUNA en el servidor:
```
/home1/emzmuumy/public_html/website_5a1c69e7/luna/
```

---

## PASO 1 — Confirmar que los archivos están subidos
En File Manager, entra a `…/website_5a1c69e7/luna/` y confirma que existen:

- [ ] `luna_api.php`
- [ ] `luna_config.php`   ← el de configuración
- [ ] `index.html`, `luna_ai.php`, `luna_radar.php`, `luna_meetings.php`
- [ ] carpeta `cron/`

Si falta alguno, bájalo del repositorio (rama `claude/happy-planck-Dtzud`,
carpeta `luna/`) y súbelo.

---

## PASO 2 — Llenar las credenciales de la base de datos
1. Abre `luna_config.php` con el botón **Edit** del File Manager.
2. Arriba hay un recuadro con 4 valores marcados con ★. Llénalos con los datos
   de **cPanel → MySQL® Databases** (debe ser la MISMA base que usa el CRM actual):
   ```php
   $LUNA_DB_HOST = 'localhost';                 // casi siempre localhost
   $LUNA_DB_USER = 'tu_usuario_mysql';          // ← llenar
   $LUNA_DB_PASS = 'tu_contraseña_mysql';       // ← llenar
   $LUNA_DB_NAME = 'tu_base_de_datos';          // ← llenar
   ```
3. Deja las comillas. Sin espacios extra. **Save Changes.**

**Verificación:** abre en el navegador (cambia TU-DOMINIO por el dominio real):
```
https://TU-DOMINIO/luna/luna_api.php?action=luna_whoami
```
- Si dice `"No autorizado. Inicia sesión..."` → ✅ la base conecta (¡bien!), sigue.
- Si dice `Access denied for user` o `Unknown database` → ❌ revisa usuario/contraseña/nombre.
- Si da error 500 → manda el error log a Isabel.

---

## PASO 3 — ⭐ LA PIEZA CLAVE: sincronizar la llave de servicio
Este es el paso que arregla el `403`. La llave debe ser **idéntica** en los dos lados.

### 3a. Saca la llave de Athena (Railway)
En Railway, variables de entorno de Athena, copia el valor de **`LUNA_API_KEY`**.
(No empieza con `sk-ant-` — eso ya lo confirmamos. Si empezara con `sk-ant-`, AVISA,
porque sería la key de Anthropic, no esta.)

### 3b. ⚠️ Revisa que NO haya una variable de entorno vieja que la pise
Esta es la causa #1 de "edité el archivo y no cambió nada". El código busca la
llave PRIMERO en variables de entorno, y solo si no hay, usa el archivo.

Revisa en estos lugares si existe una `LUNA_SERVICE_KEY` vieja:
- cPanel → **MultiPHP INI Editor** / variables de entorno
- Un archivo `.htaccess` en `website_5a1c69e7/` o en `luna/` con una línea `SetEnv LUNA_SERVICE_KEY ...`
- Un archivo `.env`

Si encuentras una `LUNA_SERVICE_KEY` con un valor distinto → ponle el MISMO valor
de `LUNA_API_KEY` (paso 3a), o bórrala. Si no hay ninguna, perfecto, sigue.

### 3c. Pon la llave en el config de LUNA
En `luna_config.php`, busca:
```php
define('LUNA_SERVICE_KEY', 'PON_AQUI_LA_MISMA_LLAVE_QUE_USA_ATHENA');
```
Reemplaza el texto entre comillas por el valor de `LUNA_API_KEY` (paso 3a).
Copy-paste exacto, sin espacios. **Save Changes.**

**Verificación (la prueba definitiva):** abre en el navegador:
```
https://TU-DOMINIO/luna/luna_api.php?action=luna_whoami&service_key=PEGA_AQUI_LA_LLAVE
```
- Si devuelve algo con `"ok":true` y datos de "Athena" → 🎉 **¡QUEDÓ! El 403 murió.**
- Si dice `"Llave de servicio inválida"` → las llaves aún no coinciden. Revisa 3b
  (alguna variable de entorno la está pisando) y que el valor sea idéntico.
- Si dice `"Acción no permitida"` → la llave SÍ funciona, era otro detalle (avisa).

---

## PASO 4 — La API key de Anthropic (para el chat de IA)
Esto NO afecta a Athena leyendo tickets; solo al chat de IA de LUNA.
En `luna_config.php` busca esta línea (está comentada con `//`):
```php
// define('ANTHROPIC_API_KEY', 'sk-ant-api03-...');
```
Quítale las `//` del inicio y pega la API key de Anthropic (la que empieza con
`sk-ant-`). Si ya está como variable de entorno en Bluehost, déjala comentada.

---

## PASO 5 — Prueba final
1. Pídele a Athena que jale los tickets, o que dé los tickets **por agente**
   (Arlette, Sami, Skarleth). Ya hay un reporte nuevo por agente que antes no existía.
2. Si responde con números → **terminado.** Avísale a Isabel.

---

## 🆘 Tabla de errores (qué hacer según el mensaje)
| Mensaje | Qué significa | Qué hacer |
|---|---|---|
| `Failed opening required ... config` | No encuentra el config | Confirma que `luna_config.php` está en `luna/` (Paso 1) |
| `Undefined constant` | Faltan credenciales | Llena los 4 valores ★ (Paso 2) |
| `Access denied for user` / `Unknown database` | Credenciales MySQL mal | Revisa usuario/contraseña/base (Paso 2) |
| `Llave de servicio inválida` | Las llaves no coinciden | Paso 3 — revisa también la variable de entorno (3b) |
| `Acción no permitida` | La llave sí sirve, acción bloqueada | Avisa a Isabel con el nombre de la acción |
| `No autorizado. Inicia sesión` | No mandó la llave | Athena debe mandar el header `X-LUNA-Key` |
| Error 500 | Fallo del servidor | Manda el error log a Isabel |

---

## 📌 Notas importantes
- La base de datos de LUNA debe ser **la misma del CRM** (miembros, tickets, etc.).
- No hace falta correr ningún SQL: las tablas nuevas de LUNA se crean solas.
- Si te atoras en cualquier paso, **manda a Isabel el texto exacto del error**
  (el de la URL de prueba o el del error log). Con eso se resuelve rápido.
