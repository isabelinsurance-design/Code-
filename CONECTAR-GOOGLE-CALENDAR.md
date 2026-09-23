# 📅 Conectar Citas con Google Calendar

Esto hace que cada cita que se guarde/edite/cancele en el CRM se refleje
sola en **un solo Google Calendar** (el tuyo) — vas a ver ahí las citas
de todos los agentes, juntas.

Se configura **una sola vez**. Después, es solo darle un clic a
"CONECTAR CON GOOGLE" dentro del CRM.

---

## PARTE A — Crear las credenciales en Google Cloud (una sola vez)

1. Entra a **https://console.cloud.google.com/** con la cuenta de Google
   que quieres usar como tu calendario de citas.
2. Arriba, donde dice **"Seleccionar proyecto"**, crea uno nuevo — el
   nombre no importa, por ejemplo "CRM Isabel".
3. En el buscador de arriba escribe **"Google Calendar API"**, ábrela y
   dale **"Habilitar"**.
4. En el menú de la izquierda ve a **"APIs y servicios" → "Pantalla de
   consentimiento OAuth"**:
   - Tipo de usuario: **Externo** → Crear.
   - Nombre de la app: lo que quieras (ej. "CRM Isabel"). Correo de
     soporte: el tuyo.
   - En "Usuarios de prueba" agrega tu propio correo de Gmail.
   - Guarda y continúa en las demás pantallas (puedes dejar todo lo
     demás en blanco).
5. Ve a **"APIs y servicios" → "Credenciales" → "Crear credenciales" →
   "ID de cliente de OAuth"**:
   - Tipo de aplicación: **Aplicación web**.
   - Nombre: lo que quieras.
   - En **"URI de redirección autorizados"** pega EXACTAMENTE (cambia el
     dominio si el tuyo es distinto):
     ```
     https://withisabelfuentes.com/crm/google_calendar_callback.php
     ```
   - Dale **Crear**. Te va a mostrar un **ID de cliente** y un **Secreto
     de cliente** — cópialos, los necesitas en la Parte B.

---

## PARTE B — Poner las credenciales en el CRM

1. Entra a tu `config.php` en el servidor (por cPanel → File Manager, o
   como ya edites ese archivo).
2. Agrega estas líneas (con tus valores reales de la Parte A):
   ```php
   define('GOOGLE_CLIENT_ID',     'PON_AQUÍ_TU_ID_DE_CLIENTE');
   define('GOOGLE_CLIENT_SECRET', 'PON_AQUÍ_TU_SECRETO');
   ```
3. Guarda. No hace falta tocar nada más — el CRM ya sabe usarlas.

---

## PARTE C — Conectar tu cuenta desde el CRM

1. Publica estos cambios en Bluehost (cPanel → Git Version Control →
   Update from Remote → Deploy HEAD Commit), igual que siempre.
2. Entra al CRM → pestaña **CITAS** → arriba vas a ver una tarjeta
   **"📅 GOOGLE CALENDAR: NO CONECTADO"**.
3. Dale clic a **"CONECTAR CON GOOGLE →"**, inicia sesión con la cuenta
   que quieres usar, y dale **"Permitir"** cuando Google te pregunte.
4. Te regresa al CRM y ahora dice **"✓ CONECTADO — tu correo"**.

¡Listo! De aquí en adelante, cada cita que guardes/edites/canceles en el
CRM aparece sola en ese Google Calendar.

---

## Notas

- Si algún día quieres cambiar de cuenta, dale **"DESCONECTAR"** en esa
  misma tarjeta y vuelve a conectar con la cuenta que quieras.
- Si Google Calendar no está configurado (o Google está lento/caído en
  algún momento), Citas sigue funcionando exactamente igual — la
  sincronización nunca puede impedir que guardes una cita.
- Cada evento dura 30 minutos por default (el CRM no guarda una duración
  para las citas todavía) — si quieres que sea otro tiempo, dime y lo
  ajustamos.
