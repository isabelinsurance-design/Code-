# Plan de mañana — Deploy LUNA + verificación

## ✅ Antes de empezar — checkpoint final del 6 jun

Athena (mi lado, Railway) está **100% verificado y desplegado**:
- 20 coaches registrados (incluyendo LUNA y Aurora)
- 18 tools LUNA todos bien formados con dispatchers
- 142 tools de Athena (directora)
- Migración pilar→luna idempotente (puede correr 3+ veces sin problema)
- Boot limpio, build limpio
- Cero referencias huérfanas a "pilar" en código activo

Lo único que falta es el deploy de Sami en Bluehost.

---

## 📋 Pasos de Sami mañana — 4 pasos sencillos

### 1. Pull de la rama LUNA

```bash
# Si LUNA está en su propio repo
git clone [url-luna-repo]
cd luna
git checkout claude/happy-planck-Dtzud
```

### 2. Sube los archivos PHP a Bluehost

cPanel → File Manager → navega a `public_html/website_5a1c69e7/luna/`

**Archivos a reemplazar / subir:**
- `luna_api.php` ← versión nueva con auth limpia
- `luna_config.php` ← debe tener la `LUNA_SERVICE_KEY` correcta
- Cualquier otro PHP nuevo de la rama

### 3. Verifica que `luna_config.php` tenga:

```php
define('LUNA_SERVICE_KEY', '5e6c916e1328c10c2200f6ed6bb0929b1129f64f449df194cb1a00231f191b7e');
```

(64 caracteres hex, debe matchear EXACTAMENTE la `LUNA_API_KEY` que está en Railway)

### 4. Test con curl

```bash
curl -i -H "X-LUNA-Key: 5e6c916e1328c10c2200f6ed6bb0929b1129f64f449df194cb1a00231f191b7e" \
     "https://[tu-dominio-luna]/luna_api.php?action=luna_pipeline_summary"
```

**Resultado esperado:** `HTTP/2 200` + JSON `{"ok":true, "data": {...}}`

---

## 🚦 Si algo no jala — diagnóstico paso a paso

### Síntoma 1: `HTTP 403 Forbidden`

**Causa probable:** Llaves no matchean.

**Acción:**
1. Verifica que en `luna_config.php` la llave esté SIN espacios, SIN comillas dobles extras, SIN salto de línea al final
2. Compara con Railway: PWA → Sistema → Diagnóstico → `/api/luna/debug-auth` debe mostrar `length: 64` y `masked: "5e6c…7e"`
3. Si todavía 403, mándame screenshot de `luna_config.php` (oculta el resto) y del debug-auth para comparar

### Síntoma 2: `HTTP 500 Internal Server Error`

**Causa probable:** PHP error en `luna_api.php` (typo, función faltante, columna que no existe).

**Acción:**
1. Bluehost → cPanel → **Error Log** → revisa últimas líneas
2. Mándame las últimas 20 líneas del error log
3. Si menciona "Unknown column" → es discrepancia entre el SQL del PHP nuevo y el schema de tu DB. Probablemente menor, lo arreglo

### Síntoma 3: HTML en vez de JSON (warning de PHP)

**Acción:**
1. Bluehost a veces tiene `display_errors=on` en shared hosting
2. En el top de `luna_api.php` agregar:
   ```php
   error_reporting(E_ERROR | E_PARSE);
   ini_set('display_errors', '0');
   ```

### Síntoma 4: Diagnóstico marca LUNA verde pero el reporte sale con números raros

**Causa probable:** Cambio en shape de respuesta entre la versión vieja y la nueva.

**Acción:**
1. Mándame el resultado de `/api/luna/raw?action=open_tickets` (los primeros 500 caracteres)
2. Yo ajusto el cliente Athena para matchear el nuevo shape

---

## 🔄 Plan de rollback si todo falla

Si Sami sube los archivos nuevos y se rompe TODO (LUNA admin web se cae para Skarleth/Arlette/Sami que la usan), el rollback es:

1. cPanel → File Manager → directorio luna/
2. Si tienes backup automático de Bluehost → restaurar
3. Si no, Sami debe tener una copia de los archivos viejos antes de subir los nuevos (paso 0: hacer backup local)

**Importante:** Antes de subir los archivos nuevos, Sami debe **bajar copia de los actuales** a su computadora. Así si algo se rompe, los re-sube y todo vuelve a como estaba.

---

## 📞 Lo que necesito que me mandes (en orden)

Cuando Sami termine deploy, mándame **uno por uno**:

1. ✅ Foto del curl test pasando
2. ✅ Screenshot de PWA → Diagnóstico (LUNA debe estar verde)
3. ✅ Screenshot de chat con Athena: pregúntale "dame reporte de tickets abiertos" — manda screenshot de su respuesta

Con esos 3 confirmo que todo el chain está vivo: Athena → bridge → LUNA → MySQL → respuesta.

---

## 🎯 Lo que vamos a probar después de confirmación

Una vez todo verde, vale la pena probar estas 4 cosas concretas:

1. **Reporte de tickets por agente** — Athena debe darte 89 totales con desglose correcto (Isabel 18, Arlette 16, Sami 13, Skarleth 5, sin asignar 37 — o lo que sea la realidad de mañana)
2. **Búsqueda de cliente** — PWA → Equipo → Clientes → escribe nombre real → ver expediente
3. **Operación Medicare** — PWA → Equipo → LUNA · CRM → tap "Generar reporte" → LUNA hace análisis estratégico de tu CRM
4. **Smart insights en tickets** — pregunta a Athena "qué tickets ALTA están estancados" — debe darte una lista con días y razones

Si todos los 4 jalan, **todo el sistema está vivo y funcional al 100%.**

---

## ⏰ Si me necesitas hoy

Estoy aquí. Si Sami quiere probar antes de mañana, o si tienes alguna duda de último minuto, me avisas y resolvemos esta noche.

---

## En una frase

Sami tiene **un solo trabajo mañana**: subir los archivos PHP nuevos de la rama `claude/happy-planck-Dtzud` a Bluehost, verificar que la llave en `luna_config.php` matchee Railway, y probar con curl. Si jala, ya estamos. Si no jala, tengo plan B documentado arriba para cada caso.
