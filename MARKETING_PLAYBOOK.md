# Marketing Playbook — Replicar la arquitectura de Athena

Documento de referencia para cuando arranques un sistema separado de marketing (Facebook ads, Instagram, lead gen, contenido). **No es código.** Es la receta arquitectónica.

Si pudieras hacer click en "instalar Athena pero para marketing", esto es lo que pasaría. Cada movimiento corresponde a una fase de la Athena actual.

---

## Por qué esto importa

Athena no es solo "una IA". Es **un patrón arquitectónico** con 10+ fases que resolvieron problemas reales (memoria persistente, captura por defecto, compliance, audit log, voz, llamadas, skills auto-generables, multi-agent). Si vas a construir un sistema de marketing en serio, **úsalo como template** en lugar de reinventar la rueda.

---

## La arquitectura — 22 movimientos en orden de fases

### 🏛 Fundación (Fases 1–2)

**1. Una directora-orquestadora más 5–8 especialistas.**
Para marketing: **Brand Marisol como directora**. Especialistas: Audiencias, Creativos, Copy, Ads Manager, Analytics, Landing Pages. Cada especialista en Sonnet, directora en Opus.

**2. Modelo tiers.**
Opus para Marisol (decide qué hacer). Sonnet para especialistas (ejecuta). Haiku para clasificaciones baratas (clasificar comentarios, taggear leads, scoring de creatives).

**3. Una superficie principal.**
Athena vive en WhatsApp. El sistema de marketing podría vivir en WhatsApp también, o en un panel web propio. **Pick one and commit.** No fragmentes.

**4. Filosofía de marca como constante compartida.**
Igual que `ISABEL_FILOSOFIA` en agents.js — un bloque `MARKETING_FILOSOFIA` que cada especialista hereda. Tu voz, tus no-negociables, tu paleta, tu tono. Sin esto, cada especialista inventa su propia versión y se pierde la marca.

---

### 🛡 Confianza (Fase 2.5)

**5. Cola de borradores antes de publicar.**
Ningún anuncio se publica sin tu "publica". Igual que el `outbound_queue.json` de Athena. Borradores en `marketing_queue.json`. Tú lo flusheas con "publica" o "todos los del lanzamiento X".

**6. Log de auditoría de cada acción.**
Qué cambió, cuándo, en qué campaña, en qué creative. PII redactado. Últimos 500. Mismo patrón que `activity.json`.

**7. Horas de silencio + límite diario.**
Nada se publica de 9pm a 7am (tu zona horaria), máximo X anuncios nuevos por día. Evita la espiral "subí 40 ads en una noche y me banneó Meta".

---

### 🧠 Multimodal + memoria (Fase 3)

**8. Memoria por capas.**
- **Temporada** — qué campaña estás corriendo este mes
- **Wiki** — tu posicionamiento, ICPs, ofertas
- **Tareas** — qué creativos debe redactar, qué A/B falta probar
- **Señales** — CTR bajando, audiencia agotada, frecuencia alta
- **Entidades** — cada lead que entró por el funnel
- **Conversaciones** — últimos 40 turnos contigo

**9. Memoria por persona.**
Cada lead es una **entidad** con alias, notas, salience score. "Maria del comentario del reel azul" se resuelve a la misma persona que "Mariana@gmail.com en la landing".

**10. Reflexión nocturna.**
A las 2am el sistema:
- **Extract** — qué pasó hoy (ads publicados, comments, DMs)
- **Entities** — qué leads nuevos entraron
- **Consolidate** — contradicciones (un creative dijo X, el copy dice Y)
- **Compute signals** — CTR/CPC/CPL deltas, audiencias saturadas, creatives fatigados

---

### 📥 Captura + CRM (Fase 4)

**11. Captura por defecto.**
Si tú dices "el creative del bote azul funcionó bien", el sistema lo guarda solo, sin pedir permiso. La frase es información — capturarla es el job #1. Tú dirás "olvida eso" si está mal.

**12. CRM ligero adentro.**
Aquí el "CRM" es de **campañas + ad sets + creativos + audiencias**, no de clientes. Con compliance fields propios:
- ¿Cumple políticas de Meta? (sin "100% guaranteed", sin claims médicos)
- ¿FTC disclaimer presente?
- ¿Brand voice aprobado?
- ¿UTM tagged?
- ¿Pixel configurado?
- ¿Landing page tested?
- ¿Última optimización (fecha)?
- ¿Próxima rotación de creative (fecha)?

**13. Conector de canal.**
Como Twilio para Athena: aquí **Meta Marketing API + Facebook Graph API + Instagram Graph API**. Empieza read-only.

---

### 🎙 Voz + alcance (Fase 5)

**14. Voz cloning para reels.**
Si vas a hacer videos/reels, ElevenLabs con tu voz para narración rápida. Mismo provider que usa Athena.

**15. Read-only de plataformas primero.**
Como Instagram read-only en Athena: empezar leyendo comentarios, DMs, métricas antes de escribir nada. Construye confianza antes de dar permisos de write.

---

### 🏗 Producción (Fase 5.5)

**16. Validación de firmas + idempotencia + rate limit.**
Igual que ahora con Twilio. Los webhooks de Meta deben ser firmados y deduplicados por `webhook_id`. Rate limit 30 req/min/IP en cualquier endpoint público.

**17. Backups por hora a R2.**
Mismo patrón. Tus campañas, tus audiencias, tus creatives son tu IP. tar.gz a Cloudflare R2 cada hora. Rotación local de 24h. Encrypted at rest.

---

### 📡 Inteligencia (Fase 6)

**18. Señales nocturnas.**
Calculadas todas las noches a las 2am:
- "Tu CPL subió 40% en 3 días"
- "Audiencia Lookalike X agotada (frecuencia 8+)"
- "Creative Y bajó CTR a 0.4% — necesita refresh"
- "30 leads sin asignar a María Medicare"
- "Pixel del landing Z no ha disparado en 6h — ¿roto?"

Las señales aparecen en el briefing matutino, ordenadas por severidad.

---

### 🔍 Compliance + huecos (Fases 8–9)

**19. Known unknowns.**
El sistema te muestra **LO QUE FALTA**, no solo lo que está:
- Anuncios sin UTM
- Campañas sin pixel
- Leads sin nurture en 7+ días
- Creatives sin variación A/B después de 14 días
- Ad sets sin spend cap
- Landing pages sin test móvil

Igual que `gaps_overview` de Athena. **Conocer lo que no sabes es el verdadero leverage.**

**20. Outbound review hooks.**
Cada anuncio que va a publicar pasa por:
- Checks deterministas: Meta policy (palabras prohibidas), FTC compliance (claims), brand voice (vocab fuera de tu lista), longitud
- Check de tono con Haiku: "¿esto suena como Isabel?"

Si falla un check `alto`, **bloquea el envío.** Si es `aviso`, surface al draft para que veas antes de "publica".

---

### 🧰 Y al final (Fase 10)

**21. Skills/playbooks.**
Cuando notas un patrón que repites:
- "Lanzamiento de campaña AEP" → skill
- "Respuesta a lead Medicare frío" → skill
- "Rotación semanal de creatives" → skill
- "Análisis post-campaign con next-step propuesto" → skill

Athena propone el draft, tú apruebas, queda guardado. La próxima vez es **una sola frase**: "rota creatives de la campaña AEP de octubre".

---

### 🆕 El bonus que tendrá Athena en Phase 14

**22. Multi-user web interface.**
Para marketing seguramente quieres a Sami, Skarleth y futuras girls dentro también, con login propio. Cada quien con sus permisos:
- Sami puede ver todo, redactar borradores, pero no publicar
- Skarleth puede ver analytics y comments, pero no tocar ads
- Tú apruebas todo

Mismo patrón que vamos a usar para el CRM team-facing en Phase 14 de Athena.

---

## Stack sugerido (idéntico a Athena, máxima reutilización)

| Componente | Tecnología |
|---|---|
| Brain | Anthropic Claude (Opus + Sonnet + Haiku) |
| Voz out | ElevenLabs con tu voz clonada |
| Vision | Anthropic nativa (analizar creatives en imagen) |
| Web search | Anthropic built-in |
| Canal principal | WhatsApp (Twilio) o panel web |
| Plataformas | Meta Marketing API, Instagram Graph, FB Graph |
| Email | Gmail IMAP + SMTP |
| Storage | JSON files (gitignored) |
| Hosting | Railway Hobby (~$5/mo) |
| Backups | Cloudflare R2 (~$0.50/mo) |
| Costo total esperado | $25–60/mes |

---

## Lo que NO querrías hacer (errores que vale la pena no cometer)

- **No metas todo en un solo agente gigante.** El multi-agent pattern existe por algo.
- **No skipees el confirmation gate.** Publicar ads sin tu approval es la receta para quemarte $500 en 4h.
- **No olvides el log de auditoría.** Cuando algo falla, vas a querer saber QUIÉN/QUÉ lo hizo.
- **No empieces sin la filosofía de marca codificada.** Cada output va a sonar genérico.
- **No deployes sin validación de firmas en los webhooks.** Cualquiera te puede mandar "lead falso" si no.
- **No copies/pegues Athena tal cual.** Adapta los conceptos al dominio. Los clientes Medicare son distintos a los leads de Facebook.

---

## Cómo arrancar el día que decidas hacerlo

1. **Crea un repo nuevo** — no contamines Athena con marketing.
2. **Copia esta playbook** al repo nuevo como `ARCHITECTURE.md`.
3. **Empieza por Fase 1** — directora + 3 especialistas, en WhatsApp. Ni siquiera intentes integrar Meta primero. La pieza más importante es la conversación contigo + memoria persistente.
4. **Agrega fases en orden.** Cada una construye sobre la anterior. Saltar fases = deuda técnica.
5. **Reusa la filosofía.** El bloque `MARKETING_FILOSOFIA` debe estar listo el día 1.
6. **Mete confirmation gate desde el principio.** Es mucho más fácil construir con gate que retrofitear.
7. **Cada fase termina con un commit + ejemplo funcionando.** No avances con cosas a medias.

---

## Cuánto tiempo y costo

Mirando lo que tomó Athena de 0 → producción:

- **Fase 1–2 (fundación):** 2–3 días si reusas Athena como referencia
- **Fase 3–4 (memoria + captura + CRM):** 2–3 días
- **Fase 5–6 (voz + señales):** 2 días
- **Fase 7–9 (hardening + signals + gaps):** 3 días
- **Fase 10+ (skills + dashboard):** 2 días

**Total: ~2 semanas de trabajo con un agente como Claude Code.** Pero solo si la playbook está clara desde el día 1.

---

## Si quieres usar a Athena como template literal

```bash
# El día que decidas arrancar
git clone https://github.com/isabelinsurance-design/Code- marketing-athena
cd marketing-athena
# Rename app, swap MARIA por MARISOL, swap ISABEL_FILOSOFIA por MARKETING_FILOSOFIA,
# swap CRM clientes por CRM campañas, swap webhooks Twilio por webhooks Meta.
```

Pero **ojo**: copiar es la trampa. Adapta los conceptos. No todo lo que sirve para Medicare sirve para Facebook ads (ej. SOA/MBI/TCPA no existen en marketing — pero Meta policy + FTC disclaimers sí).

---

## Quien va a construir esto

Cuando llegue el momento, dile a tu agente:

> "Construye el sistema de marketing siguiendo `MARKETING_PLAYBOOK.md`. Empieza en Fase 1 con Brand Marisol como directora y los 3 especialistas más importantes. Reúsa el patrón arquitectónico de Athena (`Code-` repo) como referencia, no copies código. Para a cada fin de fase para que yo apruebe antes de avanzar."

Ese prompt + esta playbook + Athena como referencia = sistema listo en 2 semanas.
