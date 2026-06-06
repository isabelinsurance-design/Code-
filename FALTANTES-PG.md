# 📌 Lo que falta migrar de PG al CRM

Isabel ya migró la mayor parte de PG al CRM. Tras una comparación fina, **lo que
todavía NO tiene equivalente en el CRM** es:

## 1. Prime a Select  ❌ falta
Jugada de ventas para **subir miembros de planes "Prime" a "Select"** (upsell de
carrier). En el CRM no existe. Encajaría como un **tipo de campaña** en la pestaña
CAMPAÑAS, o como una lista filtrada de miembros candidatos.

## 2. Estrategia — desglose diario  ⚠️ parcial
El CRM **ya tiene** la meta global "Rumbo 500". Lo que falta de PG es el
**embudo de metas diarias**:
`50 llamadas → 15 efectivas → 5 interesados → 2 citas → 1.5 inscritos`
y las **fases de ejecución**. Encajaría en PLANEACIÓN.

## 3. Wins / Rachas / Mood  ❌ falta
Capa de **motivación/gamificación**:
- **Rachas** de llamadas por agente (`getStreak`).
- **Logros** del día.
- **Estado de ánimo** (mood) del agente.
Encajaría en MI DÍA / DASHBOARD.

---

## Ya cubierto en el CRM (no se toca)
Guiones, Objeciones, Campañas, Retención, Referidos, Reuniones, Roles,
Entrenamiento, Historial, Biblioteca de Secuencias, Compliance, Planes
diario/semana/mes, Reportes. (Prompts IA: descartado — ver decisión de IA.)

## Cómo se haría
Construir las 3 piezas **nativas** dentro de las pestañas que el CRM ya tiene.
**No se importa PG** (evita duplicar y los choques de tablas). Cada pieza se
prueba en local con Docker antes de publicar.
