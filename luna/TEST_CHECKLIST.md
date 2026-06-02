# LUNA — Checklist de despliegue y verificación

Sigue esto al subir LUNA a Bluehost. Marca cada paso.

## 1. Subir archivos
- [ ] Sube `luna/` a `public_html/luna/` (index.html, luna_api.php, luna_telegram_webhook.php).
- [ ] Sube los crons. **Ojo con el path de config.php** (ver DEPLOY_LUNA.md):
      si los dejas en `luna/cron/`, edita `require '/../config.php'` → `'/../../config.php'`.

## 2. Configurar secretos en `config.php` (raíz, NUNCA en git)
- [ ] `define('ANTHROPIC_API_KEY', 'sk-ant-...');`  ← sin esto el chat da 500
- [ ] **Rota la API key vieja** en console.anthropic.com (estuvo expuesta en el HTML).
- [ ] (Opcional) `BACKUP_DIR`, `BACKUP_OFFSITE_CMD`, `TG_TOKEN`, `TG_ISABEL_CHAT`.

## 3. Self-test (1 clic) — inicia sesión como Isabel y abre:
```
https://withisabelfuentes.com/luna/luna_api.php?action=luna_selftest
```
- [ ] `all_ok: true`
- [ ] `anthropic_api_key: OK (configurada)`
- [ ] Cada tabla (audit_log, outbound_queue, plan_contenido, entidades, senales, skills) = OK
- [ ] crm_miembros / crm_soa / compute_signals / gaps = OK

Si algo dice FALLÓ, el mensaje indica la causa (tabla/columna/permiso).

## 4. Probar en la interfaz (`/luna/`)
- [ ] **Chat**: abre LUNA, haz una pregunta → responde en streaming (proxy funciona).
- [ ] **Estudio Creativo**: clic en cada uno de los 9 chips → genera contenido,
      aparece la tira de Compliance y el botón **Copiar** (deshabilitado si hay flag ALTO).
- [ ] **Ads & Métricas**: visible para Isabel. Inicia sesión como agente (Skarleth/
      Samia) → el tile **NO** debe aparecer.
- [ ] **Señales/Gaps**: pídele a LUNA "¿qué necesita atención hoy?" y "¿qué datos faltan?".
- [ ] **Auditoría estructural**: pídele "busca duplicados y errores en la base".
- [ ] **Búsqueda web**: pregúntale a Compliance algo de reglas CMS 2025 actuales.

## 4b. Probar el MOTOR AGÉNTICO nuevo (tool-calling nativo + loop)
> Cambió el cerebro del chat: del truco de texto `[[TOOL:]]` (1 sola consulta)
> a tool-calling nativo de Anthropic con loop real (encadena hasta 6 consultas).
> La lógica del parser ya pasó 12/12 pruebas simuladas; esto valida en vivo.

- [ ] **Una herramienta** — al **Analista**: *"¿Cómo va mi pipeline?"*
      → aparece "_Consultando CRM: pipeline..._" y luego datos **reales** (no inventados).
- [ ] **Encadenar (lo nuevo)** — a **LUNA principal**: *"Busca a [cliente real] y dime
      sus tickets abiertos"* → hace **2 consultas seguidas** (buscar → tickets) y
      responde con ambos. Esto antes no se podía.
- [ ] **Candado de seguridad** — al **Centro de Comando**: *"Crea tickets de retención
      para hoy"* → muestra un **PLAN con botón de aprobar**, NO ejecuta solo.

**Señales de falla:**
- 🔴 "Error HTTP" / sin respuesta → revisa `ANTHROPIC_API_KEY` en `config.php`.
- 🔴 Inventa números sin decir "consultando CRM" → las tools no están llegando.
- 🔴 El Centro de Comando ejecuta sin pedir aprobación → reportar (no debe pasar).

**Revertir** (si algo falla): `git revert` del commit del motor agéntico → vuelve
al motor anterior en segundos. Solo afecta `luna_api.php` + `index.html`.

## 4c. Conexión de servicio Athena/Pilar → LUNA (cuando aplique)
> Solo si ya configuraste la llave de servicio (ver DEPLOY_LUNA.md).
- [ ] En `config.php`: `LUNA_SERVICE_KEY` + `LUNA_SERVICE_AGENT_ID` definidos.
- [ ] Prueba un CREATE con la llave (crear ticket) → responde `{"ok":true,...}`.
- [ ] El ticket **aparece en el CRM normal** donde trabaja el equipo.
- [ ] Una acción fuera de la allowlist (ej. `luna_update_member_status`) → `403`.
- [ ] En `luna_audit_log` la acción aparece con prefijo `ATHENA:`.

## 5. Crons (cuando 1-4 pasen)
- [ ] Programa los 7 crons (ver DEPLOY_LUNA.md). Corre cada uno manualmente 1 vez
      (`php .../cron/xxx.php`) y revisa su `*_log.txt`.
- [ ] Verifica que `luna_backup_cron.php` deja un `.sql.gz` en el directorio de respaldos.

## 6. Recién entonces
- [ ] Retira el viejo `Sistema Maestro IA` HTML (ya migrado a Estudio + Ads).
- [ ] Guarda el HTML viejo como fallback hasta confirmar 1 semana sin problemas.

---
**Pendiente (necesita cuentas externas):** #18 Google Calendar (OAuth de Google
Cloud) y #19 multimodal (voz/visión). Avísame cuando tengas esas credenciales.
