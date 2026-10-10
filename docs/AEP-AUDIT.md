# Auditoría de conocimiento — AEP 2027 (plan year 2027)

> Hecha el 10 oct 2026, 5 días antes de que abra AEP (15 oct). SAMIA es la escuela:
> tiene que enseñar hechos **al día**. Esto separa lo que ya está correcto de lo que
> falta verificar (y cómo).

## ✅ Ya al día (deterministico, sin necesidad de key)

- **Calendario Medicare automático** (`server/memory/wiki.js` → `medicareSeason`):
  SAMIA ahora sabe sola en qué ventana estamos y de qué plan year se habla —
  pre-AEP / AEP (15 oct–7 dic) / post-AEP / OEP (1 ene–31 mar) / lock-in. Se inyecta
  en cada respuesta y en el briefing. No depende de que nadie lo configure.
- **Fechas de inscripción** (AEP 15 oct–7 dic, OEP 1 ene–31 mar): fijas y correctas.
- **Grupos médicos aceptados por plan** (`plans.json`): la estructura (qué IPA acepta
  qué plan) suele ser estable año a año; se mantiene como guía, con Connecture como
  fuente oficial para confirmar.

## 🔑 Pendiente de verificar para 2027 (necesita la key / Connecture)

Marcado en el código con `needsVerify2027`. Son datos que **cambian cada plan year**
y que NO se deben inventar:

| Dato | Dónde | Cómo verificar |
|---|---|---|
| Montos give-back / tarjeta ($130 SCAN, $129 Alignment…) | `kb/plans.json` notes | Connecture (cotización oficial 2027) |
| Números de plan (Alignment 039/054, Humana Gold Plus 021) | `kb/plans.json` | Connecture — los IDs de plan se renumeran por año |
| Beneficios específicos (dental, Ozempic/Mounjaro, implantes) | `kb/plans.json` notes | Resumen de beneficios 2027 del carrier |
| Reglas CMS/TPMO vigentes, retención de grabaciones, SEP de Full Dual | `kb/knowledge.es.md` | CMS / FMO — confirmar lo vigente para 2027 |

**Flujo recomendado** cuando la key esté puesta: correr el Radar de currículo
(`POST /api/growth/research {"topic":"planes-beneficios"}` y `{"topic":"cms-reglas"}`)
que usa búsqueda web real y trae los cambios 2027 con fuente, luego actualizar
`plans.json` / `knowledge.es.md` y mover `planYearData` a `"2027"`.

## Nota de honestidad

Mientras los montos no estén verificados para 2027, SAMIA ya hace lo correcto:
manda a **Connecture** para cotizar (así lo dice `plans.json._meta` y la constitución
"nunca inventa"). Así que no da números falsos — remite a la fuente oficial.
