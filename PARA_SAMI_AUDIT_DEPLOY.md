# PARA SAMI — cerrar el deploy del audit (10 jun 2026)

> Rama: claude/sleepy-darwin-P4k2z · último commit: be5f678
> Esto es lo que falta para que los arreglos de hoy queden VIVOS en producción.
> Hay 4 cosas. Tres son de Sami; una es de Isabel (la marco).

## 0. ⚠️ LO MÁS IMPORTANTE — el volumen (por qué Athena no guarda las cosas)

Athena guarda TODO (wiki, tareas, memoria, entidades) en `/app/server/data`. En Railway
el disco del contenedor es EFÍMERO: si NO hay un volumen persistente montado en esa ruta,
**cada deploy borra toda la memoria de Athena**. Esa es la causa más probable de que "no
haya guardado mucho".

Verificar en Railway → proyecto Athena → pestaña **Volumes**:
- Debe existir un volumen montado EXACTAMENTE en `/app/server/data` (y otro en
  `/app/server/backups`). Ver DEPLOY.md, paso de Volumes.
- Si NO existe, créalo (1 GB) y redeploy. Sin esto, todo lo demás es en vano.

Cómo confirmar que quedó: después del deploy, en los Logs de Railway debe aparecer
`[persistencia] OK — data/ sobrevivió un reinicio` en el SEGUNDO arranque. Si en cada
deploy sale `[persistencia] ⚠️ data/ parece EFÍMERO`, el volumen NO está montado bien.

(Buena noticia: ahora Athena tiene RESTORE automático. Si arranca con la memoria vacía
y hay un backup en R2 (o local), se recupera sola antes de empezar — verás
`[restore] ✅ memoria recuperada` en los logs. El candado: solo restaura si data/ está
vacío, nunca encima de datos vivos. Aun así, el volumen sigue siendo el paso 0: el
restore es la red de seguridad, el volumen es lo que evita perderla de entrada.)

## 1. Desplegar el código nuevo (Sami)

Railway corre desde la rama `claude/sleepy-darwin-P4k2z`. Asegúrate de que
Railway esté en el último commit:

- Entra a Railway → proyecto Athena → Deployments.
- Confirma que el deploy más reciente sea el commit `be5f678` (o más nuevo).
- Si no, dispara un redeploy (Deploy / Redeploy) desde esa rama.
- Cuando termine, revisa los Logs: debe arrancar limpio, sin "crash" ni
  "SyntaxError". Si ves un warning que dice "APP_SECRET no configurado", es
  esperado — lo arreglas en el paso 2.

## 2. Poner dos variables en Railway (Sami)

Railway → proyecto Athena → Variables. Agrega / verifica estas dos:

a) `APP_SECRET`
   - Valor: un texto largo al azar. En una terminal: `openssl rand -hex 32`
     y pega el resultado.
   - Para qué: firma las sesiones del PWA. Sin esto, cada vez que Railway
     re-despliega, a Isabel y al equipo se les cierra la sesión del PWA.

b) `LUNA_API_KEY` — ROTAR (la vieja se expuso, hay que cambiarla)
   - Genera una nueva: `openssl rand -hex 32` (64 caracteres hex).
   - Pega la NUEVA en Railway → `LUNA_API_KEY`.
   - Pega la MISMA, idéntica, en Bluehost: cPanel → File Manager →
     public_html/website_5a1c69e7/luna/luna_config.php →
     `define('LUNA_SERVICE_KEY', 'PEGA_AQUI');` (sin espacios ni comillas extra).
   - Verifica: abre https://withisabelfuentes.com/luna/luna_diag.php → debe
     decir llave definida, longitud 64, base de datos conecta: true.
   - Después de cambiar la llave, redeploy en Railway para que tome la nueva.

## 3. Verificar que los arreglos jalan (Sami)

- Email de equipo: el correo de las 6am (va al inbox de preview
  isabel.medicareadvantage@gmail.com) ya NO debe hablar de "tickets". Ahora
  arma "cómo se ve tu día" con citas, seguimientos y SOAs. Al final del email
  de Isabel hay una sección DIAG — mándasela a Isabel (o pégala aquí) para
  confirmar qué está devolviendo LUNA.
- Bridge: cuando la llave nueva esté en los dos lados, el email de equipo y
  los reportes deben traer datos reales en vez de vacío.

## 4. (ISABEL, no Sami) Recargar Anthropic

- Athena no contesta nada conversacional si no hay saldo de Anthropic.
- Isabel: console.anthropic.com → Billing → recargar + activar auto-recharge.

---

## Lo que NO se tocó a propósito (no es pendiente de Sami)

Dos cosas del audit se dejaron en paz porque cambiarlas a ciegas puede
tumbar producción; se coordinan después con la sesión de LUNA:

- Forzar la firma de Twilio en los webhooks de voz (hoy solo loggea a
  propósito, para no romper llamadas entrantes).
- Reducir la llave de LUNA a un solo header (los 3 headers existen porque no
  sabemos cuál checa el PHP de LUNA — quitar dos sin verificar puede tumbar
  el bridge).

## Resumen de lo que se arregló hoy (contexto)

Escritura atómica de los archivos de datos (ya no se truncan en un crash),
secreto de sesión seguro, el briefing ya no llama una tool que no existía,
el bridge ya distingue "error de formato" de "vacío", el gate de compliance
(SOA) volvió a funcionar consultando LUNA, los crons ya no mueren callados, y
los archivos corruptos ahora avisan en el log. Detalle completo en AUDIT.md.

---

## ✅ ACTUALIZACIÓN FINAL (13 jun 2026) — checklist limpio para Sami

Todo el código está listo y pusheado a `claude/sleepy-darwin-P4k2z` (54 pruebas en verde + CI).
Sami: estos pasos, EN ESTE ORDEN.

1. **Desplegar** — Railway → Athena → Deployments. Confirma que el último deploy sea de hoy;
   si no, Redeploy desde `claude/sleepy-darwin-P4k2z`. Revisa Logs: arranca sin crash.

2. **EL VOLUMEN (lo más importante — por esto Athena olvidaba todo)** — Railway → Volumes.
   Debe haber un volumen montado EXACTAMENTE en `/app/server/data` (y otro en `/app/server/backups`),
   1 GB c/u. Si no están, créalos y redeploy. En el 2º arranque, los Logs deben decir
   `[persistencia] OK`. Si dicen `[persistencia] ⚠️ EFÍMERO`, el volumen quedó mal.

3. **Variables (Railway → Variables):**
   - `APP_SECRET` = texto largo al azar (`openssl rand -hex 32`).
   - `LUNA_API_KEY` = ROTAR: nueva de 64 hex, misma en Railway y en Bluehost `luna_config.php`
     (`LUNA_SERVICE_KEY`). Verifica en `withisabelfuentes.com/luna/luna_diag.php` → conecta: true.
   - `SAMI_ON_LEAVE_UNTIL` = fecha de regreso de Sami (ej. `2026-07-13`). Pausa sus emails/tareas
     mientras se recupera; se reactiva sola.

4. **Verificar en Logs:** `[config]` (qué está configurado), `[persistencia] OK`, y el email de
   equipo de las 6am ya NO dice "tickets" (dice citas/seguimientos/SOAs).

5. **(LADO LUNA, no Athena — Bluehost/MySQL):** Skarleth (agente id 7) salió del equipo. Hay que
   reasignar sus clientes/trabajo en LUNA a otro agente, o quedan huérfanos (riesgo de retención).

**(ISABEL, no Sami):** recargar Anthropic en console.anthropic.com → Billing. Sin saldo Athena
no contesta nada conversacional.
