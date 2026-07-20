# CRM WS-Relay

Servidor pequeño (un solo archivo) que avisa **al instante** a todos los
navegadores con el CRM abierto cuando alguien guarda algo — para que no
tengan que esperar el refresco automático ni recargar la página.

**No toca datos reales del CRM.** Solo reenvía una señal tipo "cambió algo
en TICKETS"; el navegador va a buscar los datos reales al CRM de siempre
(Bluehost, autenticado). Si este servidor se cae, el CRM sigue funcionando
normal — el refresco automático periódico sigue de respaldo.

## Desplegar en Railway (una sola vez)

1. Entra a [railway.app](https://railway.app) → **New Project** → **Deploy from GitHub repo**.
2. Elige este repositorio y, cuando pregunte la carpeta ("root directory"),
   pon **`ws-relay`** (no la raíz del repo — ahí está el CRM en PHP, que no
   se toca).
3. Railway detecta que es Node.js automáticamente e instala/arranca solo.
4. Ve a la pestaña **Variables** de ese servicio en Railway y agrega:
   ```
   RELAY_SECRET = (una clave larga y al azar — ejemplo: genera una con
                   https://www.uuidgenerator.net/ o similar)
   ```
   Guarda esa MISMA clave — la vas a necesitar también en el `config.php`
   del CRM (paso siguiente, en Bluehost).
5. Railway te da una URL pública tipo `https://tu-proyecto.up.railway.app`.
   Cópiala.

## Conectarlo con el CRM (en Bluehost)

Abre el `config.php` **real** del servidor (el de Bluehost — este archivo
nunca se sube a Git porque tiene tus claves) y agrega, junto a los demás
`define(...)`:

```php
define('RELAY_URL',    'https://tu-proyecto.up.railway.app'); // la URL que te dio Railway
define('RELAY_SECRET', 'la-misma-clave-que-pusiste-en-railway');
```

Eso es todo — el CRM detecta automáticamente que están configuradas y
empieza a usar avisos instantáneos. Si las dejas vacías o no las agregas,
el CRM sigue funcionando igual que antes (con el refresco automático
periódico), sin errores.

## Verificar que está vivo

Abre en el navegador: `https://tu-proyecto.up.railway.app/health`
Debe responder algo como `{"ok":true,"clients":0}`. El número de `clients`
sube cuando hay gente con el CRM abierto.

## Costo

Es un proceso muy liviano (solo mantiene conexiones abiertas y reenvía
mensajes de texto cortos — no hay base de datos ni procesamiento pesado).
Para un equipo de 4-5 personas, el uso debería caer dentro del plan
**Hobby** de Railway (~$5/mes).
