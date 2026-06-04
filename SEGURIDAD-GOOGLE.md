# 🔐 Sistema de Seguridad — Cuenta Google / Gmail

Rutina para mantener tu cuenta **segura y bloqueada**.
Sigue esta lista cada día / semana / mes. Marca lo que vayas completando.

---

## 🚨 PRIMERO: Configuración inicial (una sola vez)

Hazlo HOY. Si algo de esto no está hecho, es tu prioridad #1.

- [ ] **Password fuerte y único** (no reutilizado en otros sitios)
  → https://myaccount.google.com/security
- [ ] **2-Step Verification (2FA) activada** — la defensa #1
  → https://myaccount.google.com/signinoptions/twosv
  - [ ] Preferible: **app de autenticación** (Google Authenticator / Authy) o **passkey**, no solo SMS
  - [ ] **Backup codes** guardados en lugar seguro (no en el email)
- [ ] **Email y teléfono de recuperación** correctos y tuyos
  → https://myaccount.google.com/recovery
- [ ] **Revoca apps que no reconozcas** (incluida e-Share si ya no la usas)
  → https://myaccount.google.com/connections
  → En la pantalla de la app: **"Stop using Sign in with Google"**

---

## ☀️ RUTINA DIARIA (2 minutos)

Cada mañana, un vistazo rápido:

- [ ] **Actividad de seguridad reciente** — ¿algún login que no reconozcas?
  → https://myaccount.google.com/notifications
- [ ] **Bandeja de entrada** — ¿algún email de "nuevo inicio de sesión",
  "cambio de contraseña" o "código de verificación" que tú NO pediste?
  - Si ves uno que no fuiste tú → ve directo a la sección 🆘 abajo.

---

## 📅 RUTINA SEMANAL (10 minutos)

Una vez por semana (ej. lunes por la mañana):

- [ ] **Dispositivos conectados** — quita cualquiera que no reconozcas
  → https://myaccount.google.com/device-activity
- [ ] **Apps con acceso a tu cuenta** — revoca lo que no uses
  → https://myaccount.google.com/connections
- [ ] **Reglas de reenvío (forwarding)** — que NO haya direcciones extrañas
  → Gmail → ⚙️ Settings → "Forwarding and POP/IMAP"
  - ⚠️ Truco común de atacantes: reenviar tu correo a otra cuenta sin que lo notes.
- [ ] **Filtros de Gmail** — que no haya filtros que borren/reenvíen/archiven
  correo automáticamente sin tu permiso
  → Gmail → ⚙️ Settings → "Filters and Blocked Addresses"
- [ ] **Confirma que 2FA sigue activa** (un atacante con acceso podría apagarla)
  → https://myaccount.google.com/signinoptions/twosv

---

## 🗓️ RUTINA MENSUAL (15 minutos)

- [ ] **Security Checkup completo** de Google (te guía paso a paso)
  → https://myaccount.google.com/security-checkup
- [ ] Revisa **permisos de terceros** a fondo: cada app, qué datos ve
  → https://myaccount.google.com/connections
- [ ] Revisa **email/teléfono de recuperación** siguen siendo correctos
  → https://myaccount.google.com/recovery
- [ ] Considera cambiar el password **solo si** hay señal de compromiso
  (con 2FA activa, no hace falta cambiarlo cada mes "por si acaso").
- [ ] Si manejas datos de clientes (seguros): revisa que **Less secure apps**
  esté desactivado y que no haya **App Passwords** que no reconozcas
  → https://myaccount.google.com/apppasswords

---

## 🆘 SI ENCUENTRAS ALGO SOSPECHOSO (respuesta a incidente)

Hazlo **en este orden**, rápido:

1. [ ] **Cambia el password AHORA** → https://myaccount.google.com/security
2. [ ] **"Sign out of all devices"** (cierra todas las sesiones)
   → https://myaccount.google.com/device-activity
3. [ ] **Verifica 2FA activa**; si estaba apagada, actívala
4. [ ] **Revisa forwarding y filtros** (sección semanal) — bórralos si son ajenos
5. [ ] **Revisa apps con acceso** y revoca todo lo dudoso
6. [ ] **Confirma email/teléfono de recuperación** — que no los hayan cambiado
7. [ ] Si crees que perdiste el control de la cuenta:
   → https://myaccount.google.com/security-checkup y/o
   → https://accounts.google.com/signin/recovery

---

## 🚩 Señales de alerta (red flags)

- Emails de "nuevo inicio de sesión" desde lugares/dispositivos raros.
- Reglas de **reenvío** que tú no creaste.
- **Filtros** que borran o reenvían correo automáticamente.
- 2FA que aparece **desactivada** sin que tú la apagaras.
- Apps conectadas que **no reconoces**.
- Email/teléfono de recuperación **cambiado**.
- Correos en "Enviados" que tú no mandaste.

---

## 📌 Caso e-Share (lo que comentaste)

El escenario 90% probable: un cliente/broker te mandó un documento encriptado
por e-Share, te autenticaste para verlo, y nunca lo volviste a usar.

- Si solo hay 1-2 documentos viejos y nada raro → **todo OK**.
- Si ya no usas e-Share → **"Stop using Sign in with Google"** para limpiar.
- No te preocupes anticipadamente; **verificar es lo correcto.**

---

### Enlaces rápidos (todos juntos)

| Qué | Enlace |
|---|---|
| Seguridad (home) | https://myaccount.google.com/security |
| Security Checkup | https://myaccount.google.com/security-checkup |
| Activar 2FA | https://myaccount.google.com/signinoptions/twosv |
| Apps conectadas | https://myaccount.google.com/connections |
| Dispositivos | https://myaccount.google.com/device-activity |
| Recuperación | https://myaccount.google.com/recovery |
| App Passwords | https://myaccount.google.com/apppasswords |
| Notificaciones | https://myaccount.google.com/notifications |

> Última revisión: actualiza esta fecha cada vez que completes el Security Checkup mensual.
