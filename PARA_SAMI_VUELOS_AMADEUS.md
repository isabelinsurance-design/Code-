# PARA SAMI — prender la búsqueda de vuelos (Amadeus)

> Athena ya tiene la herramienta `buscar_vuelos` en el código (rama
> claude/sleepy-darwin-P4k2z). Solo le faltan las llaves de Amadeus para
> funcionar. Esto toma ~10 minutos. Es gratis para empezar.

## 1. Crear la cuenta y la app en Amadeus

1. Entra a https://developers.amadeus.com y dale a **Register** (o Sign In si ya hay cuenta).
2. Confirma el correo si lo pide.
3. Ya adentro: **My Self-Service Workspace** → **Create new app** (o "+ Create").
4. Ponle un nombre (ej. "Athena Vuelos") y créala.
5. La app te muestra dos valores:
   - **API Key**  (un texto largo)
   - **API Secret** (otro texto largo)
   Cópialos. (Si los pierdes, en la app puedes regenerarlos.)

## 2. Ponerlos en Railway

Railway → proyecto Athena → pestaña **Variables**. Agrega dos:

- `AMADEUS_API_KEY`  = el API Key que copiaste
- `AMADEUS_API_SECRET` = el API Secret

Guarda y deja que Railway redespliegue.

## 3. Probar

Isabel le pide a Athena algo como: "búscame vuelos de LAX a XPL el 30 de junio para 2 adultos".
Athena debe devolver una lista de vuelos con precios (del más barato al más caro).

Si dice "Amadeus no está configurado" → las variables no quedaron bien puestas.
Si da precios pero con la nota "datos de PRUEBA" → ver el paso 4.

## 4. (Opcional, después) Precios 100% reales — entorno de producción

Amadeus tiene DOS entornos:
- **Test** (lo de arriba): GRATIS, pero los precios son datos de MUESTRA, no en vivo.
  Sirve para confirmar que todo jala.
- **Producción**: precios reales en vivo. Hay que pedir acceso dentro del portal de
  Amadeus (botón para "move to production" en la app) y aprueban en unos días. Tiene
  costo por búsqueda (revisar su pricing).

Cuando tengan las llaves de PRODUCCIÓN:
- Reemplaza `AMADEUS_API_KEY` / `AMADEUS_API_SECRET` con las de producción.
- Agrega la variable `AMADEUS_HOST` = `api.amadeus.com`
- Redeploy. Listo — precios reales.

## Nota importante

`buscar_vuelos` SOLO busca, no reserva. La reserva (que cobra una tarjeta) NO está
construida a propósito — eso se diseña aparte con calma cuando Isabel lo decida.
