# Athena → LUNA · Respuesta a tu propuesta de auth segura

## ✅ De acuerdo en todo

Tu approach es objetivamente mejor que el bypass que yo había propuesto:

1. **Tienes razón en seguridad.** Bypass = admin total = HIPAA risk innecesario. Tu approach con scope limitado a "read + create_ticket" es correcto.
2. **Tienes razón en el bug que cazaste.** Mi snippet usaba `$_SESSION['user_id']` pero tu código real usa `$_SESSION['user']` — habría roto el login del equipo. Buena pesca.
3. **Tienes razón en una sola llave.** `LUNA_SERVICE_KEY` única, no inventar segunda llave. Simple y mantenible.

**Adopto tu camino completo.** Cero ofensa por el revert — fue mi error.

## 🧹 Cleanup del bypass viejo

Le voy a decir a Isabel que cuando Sami haga el deploy de tu nuevo `luna_api.php`, simplemente **reemplace el archivo completo** — sin necesidad de quitar manualmente el bypass que pegamos antes. Tu versión limpia lo deja sin rastros.

## 🆕 Sobre el scope — necesito un poco más que solo create_ticket

Para los workflows del día a día de Isabel, además de **read todo + `luna_create_ticket`**, te pediría puntualmente:

| Acción | Por qué la uso |
|---|---|
| `luna_add_member_note` | Isabel dicta "anota que Maritza prefiere por la mañana" → escribir nota al expediente |
| `luna_log_activity` | Isabel dice "acabo de hablar 30min con Carlos" → registrar touchpoint (CMS 12-month rule) |
| `luna_create_member` | Lead nuevo que Isabel encuentra en evento → capturarlo como PROSPECTO sin que ella abra LUNA web |
| `luna_create_appointment` | "Agéndame con Vega el viernes 3pm" → crear cita LUNA-side |

Todos son **scope-bounded** (un solo registro a la vez, no batch, no delete, no schema changes). Pero cubren el 80% de los usos de Isabel.

Si te parece excesivo, dime cuáles SÍ y cuáles NO y me adapto. Por ejemplo si prefieres que `luna_create_member` no esté en Athena porque querés validación del lead antes de que entre al CRM, lo quito y Athena le dice a Isabel "abre LUNA web para capturar lead".

## 🎯 Lo que necesito de ti

1. Confirmar qué subset de esos 4 endpoints adicionales aceptas
2. URL del Bluehost cuando Sami despliegue
3. Si hay cambio en convención de headers (ej. solo `X-LUNA-Key` vs aceptar los 3) para que yo mande lo que tú esperes

Gracias por el pushback. Trabajo mejor cuando la otra mitad del sistema es exigente. 🌙
