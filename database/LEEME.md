# Carpeta `database/`

Aquí va el **esquema de tu base de datos** para poder trabajar localmente.

## Cómo obtenerlo (una sola vez)
1. Entra a **Bluehost → cPanel → phpMyAdmin**.
2. Selecciona tu base de datos `emzmuumy_CRM_MEDICAREWITHISABEL`.
3. Pestaña **Exportar → Método rápido → Formato SQL → Continuar**.
4. Guarda el archivo que descarga aquí dentro, con el nombre `schema.sql`.

## Importante sobre seguridad
- Un export **con datos reales** (nombres, Social Security, MBI de tus
  miembros) **NO debe subirse a GitHub.** Por eso `.gitignore` ignora
  `database/*.sql`.
- Para compartir solo la *estructura* (sin datos sensibles), en phpMyAdmin
  elige **Exportar → Personalizado → Estructura solamente** y guárdalo como
  `schema-ejemplo.sql` (ese sí se puede versionar).

## Qué hace este archivo localmente
Cuando corres `docker compose up`, todo `.sql` que esté en esta carpeta se
importa automáticamente a la base de datos local la primera vez. Así tu CRM
local arranca con las mismas tablas que en Bluehost.
