# Post-deploy smoke test — SAMIA

Después de desplegar (ver `DEPLOY.md`) con un `ANTHROPIC_API_KEY` real, corre esto
para validar lo que el sandbox **no** pudo: los caminos que dependen del LLM.

> Todo lo determinista (memoria, señales, compromisos, salud, router, cumplimiento)
> ya quedó verificado en desarrollo. Esta lista se enfoca en lo que **necesita la key**:
> chat, captura Haiku, resumen de reflexión, reescritura compliant, y síntesis del
> fan-out paralelo.

## 0. Preparación

```bash
export SAMIA=https://TU-DOMINIO.up.railway.app    # tu dominio de Railway
```

Un helper para imprimir bonito (opcional, requiere `jq`):

```bash
post(){ curl -s -X POST "$SAMIA$1" -H 'Content-Type: application/json' -d "$2"; }
get(){ curl -s "$SAMIA$1"; }
```

---

## 1. Salud / arranque  (no necesita key)

```bash
get /api/health
```
**Espera:** `{"ok":true,"service":"samia","kb":{...}}`. Si falla, el deploy no está
arriba — revisa Railway antes de seguir.

---

## 2. Chat básico  (✦ necesita key)

```bash
post /api/chat '{"mode":"chat","message":"Un miembro Full Dual quiere cambiar de PCP, que paso sigue?"}'
```
**Espera:** `reply` con HTML, en español, **terminando en "Tu próximo paso:"** + UNA
acción. Si devuelve `503 NO_API_KEY` → la key no está en las Variables de Railway.

✅ Verifica los hábitos de la Fase 8:
- ¿Termina con UNA sola acción concreta?
- ¿No inventó datos (manda a verificar en Connecture si aplica)?

---

## 3. Captura por defecto con Haiku  (✦ necesita key)

```bash
post /api/chat '{"mode":"chat","sessionId":"smoke1","message":"El miembro Roberto Diaz tiene SCAN Classic, su doctor es Garcia en Facey, diabetes. Falta su SOA."}'
sleep 3   # la captura corre async tras responder
get '/api/memory/entities?q=roberto'
```
**Espera:** una entidad `Roberto Diaz` con `attrs` (plan SCAN, doctor Garcia, diabetes)
y un gap del SOA. Esto confirma que el extractor **LLM** (no solo el heurístico) corre.

```bash
get /api/memory/gaps      # debe incluir el SOA de Roberto
```

---

## 4. Reflexión nocturna — resumen con LLM  (✦ necesita key)

```bash
post /api/intel/reflect '{}'
```
**Espera:** `report.summary` con un resumen del día en prosa (no el fallback
"Sin actividad…" si hubo turnos). `merged`, `signalCount`, `proposedSkills` presentes.

---

## 5. Reescritura compliant  (✦ necesita key)

```bash
post /api/security/review '{"text":"Este es el mejor plan, es gratis y la aceptacion esta garantizada, inscribase hoy","rewrite":true}'
```
**Espera:** `status:"block"`, varios `flags` (superlativo, gratis, garantía, presión),
y un campo **`rewrite`** con una versión compliant reescrita por el LLM (sin esas
infracciones). Sin key, `rewrite` sería `null`.

---

## 6. Fan-out paralelo + síntesis  (✦ necesita key — el más importante)

Primero confirma el router (determinista, sin key):
```bash
post /api/orchestrate/route '{"text":"el doctor de Maria salio de la red de su IPA y ademas le llego un bill de 400"}'
```
**Espera:** `{"specialists":["ipa","bill"],"fanout":true,...}`.

Ahora el fan-out real:
```bash
post /api/chat '{"mode":"auto","message":"el doctor de Maria salio de la red de su IPA y ademas le llego un bill de 400 que no entiende"}'
```
**Espera:** UNA respuesta **integrada** (no dos bloques pegados) que resuelve red
médica **y** el bill, terminando en "Tu próximo paso:". El JSON trae
`specialists:["ipa","bill"]` y `parts` (las respuestas individuales antes de
sintetizar). Confirma el hábito "sintetiza, no recites".

---

## 7. Skills end-to-end  (mezcla — el ciclo no necesita key)

```bash
post /api/skills '{"name":"Full Dual onboarding","trigger":["full dual","dual"],"steps":"1) Verifica Medi-Cal activo. 2) Consigue SOA. 3) Revisa SSBCI."}'
post /api/skills/approve '{"id":"sk_full-dual-onboarding"}'
post /api/chat '{"mode":"chat","message":"tengo un full dual nuevo, por donde empiezo?"}'
```
**Espera (✦ con key):** la respuesta sigue los pasos del playbook aprobado (Medi-Cal →
SOA → SSBCI). Luego:
```bash
get '/api/skills?status=approved'   # invocations debe haber subido
```

---

## 8. Briefing + salud del negocio  (no necesita key, pero valida el conjunto)

```bash
post /api/intel/briefing '{}' | jq -r '.briefing.text' 2>/dev/null || post /api/intel/briefing '{}'
```
**Espera:** primera línea con fecha, luego `Salud del negocio: NN/100`, luego las
secciones (prioridad alta, compromisos, señales, gaps, anoche).

```bash
get /api/intel/health     # score y briefing deben coincidir
```

---

## 9. Scheduler vivo  (no necesita key)

```bash
get /api/intel/scheduler
```
**Espera:** `running:true` y, tras ~1 hora, una ejecución de `task-tick` en `recent`.
Para forzar sin esperar: `post /api/intel/run-jobs '{}'`.

> Recuerda: con **volumen persistente** (`DATA_DIR=/data`), todo lo capturado en este
> smoke test sobrevive a redeploys. Sin volumen, se borra al reiniciar.

---

## Resumen de qué prueba cada paso

| Paso | Camino | Necesita key |
|---|---|---|
| 1 | Arranque / healthcheck | no |
| 2 | Chat + voz/hábitos (Fase 8) | ✦ sí |
| 3 | Captura Haiku (#13) | ✦ sí |
| 4 | Reflexión — resumen LLM (#15) | ✦ sí |
| 5 | Reescritura compliant (Fase 7) | ✦ sí |
| 6 | Fan-out + síntesis (Fase 11) | ✦ sí |
| 7 | Skills inyectadas (Fase 12) | ✦ sí (el ciclo, no) |
| 8 | Briefing + salud (Fases 5/9) | no |
| 9 | Scheduler (Fase 5) | no |

Si los 9 pasan, SAMIA está operando completa en producción.
