# 📋 Evaluación del CRM — Medicare with Isabel

> Análisis de los 13 archivos del sistema. Responde a tus puntos **1** (evaluación
> de la estructura) y **2** (separación front end / back end).

---

## 1. ¿Qué es tu sistema, en realidad?

Es una **aplicación web completa en PHP + MySQL** — un CRM serio y bien construido,
no una simple página. Maneja:

- **Miembros** (clientes Medicare): ficha completa, pólizas, doctores, SOA, salud.
- **Pipeline de ventas**: prospectos → activos, con pasos automáticos.
- **Tickets** y *next steps* con SLA.
- **Citas** y seguimiento post-cita.
- **Asistencia** del equipo (check-in/out, almuerzo, break) y **nómina** por quincena.
- **Comisiones** y **bonos** (portal financiero con candado extra).
- **Retención**: llamadas a 30/60/90 días + cuestionario.
- **Leads de Facebook** que entran solos y se reparten entre agentes.
- **Isabel AI**: un asistente con IA conectado a tu base de datos en tiempo real.
- **Chat de equipo**, mensajes directos y notificaciones.

**Veredicto general:** 👏 Es un sistema potente y con mucha lógica de negocio real.
El problema **no es la calidad del código**, sino **cómo está guardado y desplegado**
(sin control de versiones, con secretos a la vista, editado directo en Bluehost).

---

## 2. Mapa de archivos (la estructura)

| Archivo | Rol | Front/Back |
|---|---|---|
| `config.php` | Conexión a BD, sesión, claves, funciones base | 🟥 **Back** (núcleo) |
| `index.php` | Panel principal / pipeline / pestañas (≈1.700 líneas) | 🟪 Mixto |
| `login.php` · `logout.php` | Entrada/salida con sesiones PHP | 🟪 Mixto |
| `api.php` | **Cerebro del back**: ~70 acciones (guardar miembro, tickets, citas, chat, finanzas…) | 🟥 **Back** |
| `api_ai.php` | Isabel AI — habla con Claude y consulta la BD con "herramientas" | 🟥 **Back** |
| `fb_leads_webhook.php` | Recibe leads de Facebook, asigna agente, crea ticket | 🟥 **Back** |
| `profile.php` | Ficha del miembro (10 pestañas) | 🟦 **Front** (lee BD) |
| `member_form.php` | Formulario alta/edición de miembro | 🟦 **Front** |
| `finance_data.php` | Tablas de comisiones | 🟦 **Front** (admin) |
| `reporte_nomina.php` | Nómina por quincena | 🟦 **Front** (admin) |
| `reporte_export.php` | Exportar a CSV/TXT | 🟥 Back (genera archivo) |
| `usuarios_setup.php` | Crea el equipo (ejecutar 1 vez y **borrar**) | ⚙️ Script |

**Cómo se conectan:** `index.php` es el centro. Los formularios (`member_form.php`)
y las fichas (`profile.php`) se cargan dentro y mandan datos por `fetch()` a
`api.php`, que escribe en MySQL. `config.php` es el cimiento que **todos** incluyen.

---

## 3. Lo que está BIEN ✅

- **Consultas seguras (PDO con `prepare`)** en casi todo `api.php` → buena defensa
  contra inyección SQL.
- **Sesiones + roles** (`admin` / `agent`) con verificación de permisos por acción.
- **Contraseñas con `password_hash` / `password_verify`** (no en texto plano en la BD).
- **`session_regenerate_id`** en login (previene robo de sesión).
- Arquitectura modular y coherente; un *router* de API central muy ordenado.
- Detalles de cumplimiento **CMS** (SOA, alertas) bien pensados para Medicare.

## 4. Lo que hay que ARREGLAR 🔴

| # | Problema | Riesgo | Solución (ya incluida o en la guía) |
|---|---|---|---|
| 1 | **Secretos en `config.php`** (clave MySQL, API Key de Anthropic, clave financiera) en texto plano | 🔴 Crítico | `config.php` fuera de Git + plantilla `config.example.php` ✅ |
| 2 | **Contraseñas del equipo escritas en `login.php`** (visibles en el código fuente) | 🔴 Crítico | **Ya las quité** del archivo ✅ |
| 3 | **Secreto del webhook escrito en `fb_leads_webhook.php`** | 🟠 Alto | **Movido a config / variable de entorno** ✅ |
| 4 | **Sin control de versiones** (se edita directo en Bluehost) | 🟠 Alto | GitHub como fuente de la verdad (guía) ✅ |
| 5 | **`usuarios_setup.php` con contraseñas** sigue en el servidor | 🟠 Alto | Borrarlo del servidor tras usarlo (la guía lo recuerda) |
| 6 | `display_errors = 1` en producción | 🟡 Medio | En `config.example.php` queda en `0` ✅ |
| 7 | Datos sensibles (SSN, MBI, salud) sin cifrado en reposo | 🟡 Medio | Recomendación a futuro (ver sugerencias) |
| 8 | `reporte_export.php` mezcla variables directas en una consulta | 🟡 Medio | Revisar y pasar a `prepare` (lo puedo hacer) |

---

## 5. Sobre la separación FRONT END / BACK END (tu punto 2)

**La verdad técnica, sin rodeos:** en PHP el front y el back viven *mezclados dentro
del mismo archivo* (un `.php` consulta la BD **y** pinta el HTML). Separarlos al 100%
—un front independiente (ej. React) hablando con una API pura— sería **reescribir el
sistema**, semanas de trabajo y riesgo de romper lo que ya funciona.

Lo **sensato y realista** es una separación **por responsabilidad**, que es la que
dejé aplicada en el repositorio:

```
crm/
├── config.example.php   🟥 BACK — cimiento (BD, sesión, secretos)
│
├── api.php              🟥 BACK — guarda/lee datos (el "motor")
├── api_ai.php           🟥 BACK — IA
├── fb_leads_webhook.php 🟥 BACK — entrada de leads
│
├── index.php           🟦 FRONT — panel principal
├── login.php           🟦 FRONT — entrada
├── profile.php         🟦 FRONT — ficha del miembro
├── member_form.php     🟦 FRONT — formularios
├── finance_data.php    🟦 FRONT — vista financiera
├── reporte_*.php       🟦 FRONT — reportes
└── usuarios_setup.php  ⚙️  script de instalación
```

> Mantuve todos los archivos en una sola carpeta `crm/` **a propósito**: tu código usa
> rutas relativas (`require 'config.php'`, `fetch('api.php')`). Si los repartiera en
> carpetas distintas, **se romperían todas esas rutas**. La separación aquí es
> *lógica* (sabes qué es cada cosa), no física, para no romper nada.

**Si más adelante quieres la separación física de verdad** (API pura + front aparte),
es un proyecto que puedo planificar contigo paso a paso. Pero no es lo que necesitas
hoy para resolver la pérdida de archivos y la seguridad.

---

## 6. Lo que todavía falta para que corra 100%

Estos archivos los menciona el código pero no estaban entre los 13:
- **`prompts__1_.php` / `prompts.php`** → lo incluye `config.php` (funciones de IA como
  `generarSMS`). Lo dejé **opcional** para que la app no se caiga si falta.
- **El esquema de la base de datos** (`.sql`) → necesario para correr local. Cómo
  obtenerlo está en `database/LEEME.md`.
- Si tienes **`google_apps_script.js`**, súbelo también (conecta Facebook → webhook).
