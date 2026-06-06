# SAMIA — Project Context

## What this is

**SAMIA es la escuela.** Es la asistente IA / maestra de Medicare del equipo de
**Isabel Fuentes** (`withisabelfuentes.com`). No es un CRM ni una herramienta de
marketing — es de donde el equipo **aprende Medicare** y a donde acuden cuando se
atoran. Dos trabajos:

1. **Entrenar** a los agentes nuevos (cómo llenar aplicaciones, qué es cada plan,
   compliance de CMS, práctica de ventas).
2. **Rescatarlos en vivo** cuando se traban con un ticket o un miembro (redes
   médicas, IPAs, planes, bills, cartas, farmacia…).

Responde en el idioma del usuario (español/inglés), nunca inventa (en Medicare eso
es riesgo regulatorio), y siempre cierra con el **próximo paso**.

## Cómo está organizado el repo

```
Code-/
├─ CLAUDE.md          este archivo — contexto del proyecto
├─ README.md          overview + fases construidas
├─ app/               las dos caras de la escuela (frontend)
│   ├─ samia.html       app de entrenamiento (guías, quizzes, videos)  ← home (/)
│   └─ dashboard.html   panel de operación (briefing, señales, skills…) ← /dashboard
├─ server/            backend Node.js (ESM, sin build step)
│   ├─ index.js         servidor HTTP + ruteo /api + arma el prompt del turno
│   ├─ config.js        env, model tiers, DATA_DIR
│   ├─ anthropic.js     cliente API (key SOLO en el server) + web_search
│   ├─ constitucion.js  la constitución de SAMIA (quién es, sus reglas)
│   ├─ specialists.js   modos/especialistas + router determinista + voz por modo
│   ├─ orchestrator.js  multi-agente: fan-out paralelo + síntesis
│   ├─ static.js        sirve app/ y archive/ (estático)
│   ├─ kb/              base de conocimiento (casos, doctores, grupos, planes)
│   ├─ memory/          memoria: entidades, captura, extracción, wiki
│   ├─ intel/           el cerebro: señales, briefing, salud, reflexión,
│   │                   compromisos, skills, scheduler, growth (Radar)
│   └─ security/        gate de PII + compliance CMS
├─ docs/              DEPLOY.md (Railway), SMOKE-TEST.md (validación con key)
├─ archive/           legacy guardado, NO se borra (sistema-maestro + tools viejos)
└─ data/              estado en runtime (gitignored; volumen en producción)
```

## Arranque

```bash
cp .env.example .env   # poner ANTHROPIC_API_KEY
npm start              # http://localhost:8137  (/ = escuela, /dashboard = panel)
```

Funciona **con o sin** key: sin key, los caminos que necesitan LLM lo dicen con
honestidad (nunca inventan); todo lo determinista (memoria, señales, salud,
compromisos, router, compliance, autoevaluación) corre igual.

## Principios (el playbook que sigue)

- **Nunca inventa.** Si falta un dato, dice "déjame verificar X" y cómo (Connecture,
  llamada, preguntar al miembro).
- **Compliance de CMS no es opcional.** Ante la duda, gana la opción segura.
- **Captura por defecto.** Lo que el equipo menciona se guarda sin pedir permiso.
- **Confirmation gate.** Lo sensible (aprobar skills) siempre lo confirma un humano;
  SAMIA no se auto-aprueba.
- **Autonomía con latido.** Un scheduler corre briefing, reflexión y el Radar a sus
  horas (solo en deploy always-on; ver docs/DEPLOY.md).

Las fases construidas y el detalle de cada módulo están en `README.md`.
