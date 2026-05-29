# Athena — Manual de uso diario

Cómo usar a Athena día a día. Para Isabel.

> *Si quieres saber CÓMO FUNCIONA Athena por dentro, lee `CLAUDE.md`.*
> *Si quieres DEPLOYARLA, lee `DEPLOY.md`.*
> *Esta guía es para USARLA.*

---

## Las tres superficies

Athena vive en tres lugares. Usa cada una para distintas cosas:

| Superficie | Cuándo usar | Cómo |
|---|---|---|
| **WhatsApp** | Todo el día. La principal. | Mándale mensaje normal a su número Twilio. |
| **El app HTML** (`app/todoisabel.html`) | Cuando quieras hablarle directo a UNA coach específica | Ábrelo en el browser o iPhone |
| **Dashboard** (`/dashboard`) | Cuando quieras VER el estado del día | Browser, login con `DASHBOARD_PASSWORD` |

LUNA es aparte — eso es para Skarleth, Arlette, Samia y tú cuando estás en la computadora.

---

## Cómo le hablas a Athena (WhatsApp)

### Texto normal
"Hola Athena, ¿cómo va mi día?"
"Recuérdame llamar a Carlos antes de las 3pm."
"Platícame de la última conversación con María Hernández."

### Voz (mejor opción para manejar)
- Manda un voice note normal de WhatsApp
- Athena lo transcribe con Whisper, contesta como si fuera texto
- Si tu voice note empieza con "voz" o terminas pidiéndole "contéstame en voz", Athena te contesta con un audio (su voz, no la tuya)

### Foto
- Manda una foto, Athena la analiza
- Útil para: foto de Medicare card → Athena extrae MBI / DOB / nombre
- Útil para: foto de un recibo → "agrega esto a mis gastos del mes"
- Útil para: foto de pantalla → "léeme qué dice esto"

### PDF
- Manda un PDF directo de WhatsApp
- Útil para: SOA firmada → Athena la lee y confirma compliance
- Útil para: cotización de carrier → Athena la resume

---

## Las 14 cosas que Athena hace mejor

### 1. Captura
> "Recuerda que Carlos prefiere llamar después de las 3pm."

Athena guarda eso AUTOMÁTICAMENTE en LUNA (si es sobre un cliente Medicare) o en su wiki (si es sobre ti). Nunca te pide permiso. Si está mal después, dices "olvídalo".

### 2. Resumen de cliente Medicare
> "Platícame de María Hernández antes de mi llamada de las 11."

Athena consulta a **Maria Medicare** (su especialista). Maria es la única coach con acceso a LUNA — ella lee el expediente en vivo y te lo resume: estado actual + último touchpoint + carrier + notas + tickets abiertos. Tú no tienes que saber dónde vive el dato; Athena delega y te trae la respuesta.

### 3. Cuestionario de intake (lead nuevo)
> "Agarré un lead nuevo en el evento, se llama Lupita Vargas, su teléfono es 818-555-9999."

Athena le pasa a Maria, Maria lo registra en LUNA como PROSPECTO. Después puedes invocar la skill "Intake cliente Medicare" para que te guíe por las 12 preguntas.

### 4. Delegar al equipo
> "Que Sami llame a María Hernández mañana sobre la SOA."

Athena consulta a Maria, Maria crea el ticket en LUNA asignado a Samia (user_id 10). Samia lo ve en su workspace.

### 5. Agendar (Google Calendar)
> "Búscame huecos esta semana para una review de 30 min."
> "Agenda con Carlos el viernes a las 2pm."

Athena consulta tu agenda real, ofrece huecos sin conflicto, crea el evento.

### 6. Mandar emails / SMS (con tu aprobación)
> "Manda email a Carlos invitándolo a review pre-AEP."

Athena REDACTA el email. Queda en draft. Cuando estés lista, escribes `envía` y se manda. Nunca manda algo sin tu OK.

### 7. Llamadas telefónicas (Athena habla por ti)
> "Llama a Carlos y dile que ya tengo respuesta del carrier."

Athena hace la llamada con tu voz clonada (ElevenLabs). Después te resume qué dijo Carlos y registra touchpoint en LUNA.

### 8. Consultar especialistas en paralelo
> "Quiero bajar 2 kilos y tengo el AEP a 4 meses, ayúdame a pensarlo todo junto."

Athena consulta a Carmen (nutrición), Rivera (fitness), Sofía (hormonas), María (Medicare), Victoria (planeación) EN PARALELO y te sintetiza una respuesta integrada.

### 9. Triage de email matutino (automático)
Cada día a las 5am Athena revisa tu Gmail y te manda por WhatsApp: 3-5 emails que requieren tu atención + drafts pre-redactados de los que respondería ella.

### 10. Briefing matutino 6:30am
Sin pedirlo: te llega un mensaje con:
- Señales activas (X clientes en riesgo de cancelación, X T65 esta semana, etc.)
- Compliance gaps del CRM (SOAs faltantes, retención del día)
- Tareas pendientes
- Top 3 prioridades del día
- Una pregunta para enfocarte

### 11. Evening check-in 9pm
Sin pedirlo: "¿Cuáles fueron tus 3 wins de hoy? ¿Una cosa para mañana?"

### 12. Review semanal domingo 6pm
Sin pedirlo: resumen de la semana + qué quedó + prioridades para la próxima.

### 13. Reflexión nocturna 2am (silenciosa)
Athena procesa todo el día mientras duermes: extrae personas mencionadas, consolida contradicciones en sus notas, computa señales para el briefing del día siguiente. No te molesta.

### 14. Skills/playbooks (workflows aprobados)
> "Hazle el AEP outreach a María Hernández."

Athena ejecuta el playbook completo: lee LUNA, verifica SOA, redacta email, redacta SMS, crea tarea de follow-up, registra touchpoint. Todo en una sola instrucción tuya.

---

## Las palabras mágicas (slash commands)

Escribe cualquiera de estas en WhatsApp:

| Comando | Qué hace |
|---|---|
| `/help` | Lista todos los comandos |
| `/luna` | (Sami: test de conectividad) Briefing en vivo del CRM real |
| `/luna ping` | (Sami: test rápido) ¿está LUNA respondiendo? |
| `/agenda` | Próximos eventos de tu Google Calendar |
| `/huecos 7` | Huecos libres próximos 7 días (cambia 7 por N días) |
| `/pendientes` | Borradores esperando que digas `envía` |
| `/historial` | Últimas 20 acciones que Athena tomó |
| `/tareas` | Tareas activas por owner (athena/isabel/sami) |
| `/compromisos` | Promesas que terceros te hicieron y están pendientes |
| `/skills` | Playbooks aprobados que puedes invocar |
| `/signals` | Señales calculadas anoche |
| `/briefing` | Forzar el briefing matutino ahora |
| `/envía` | Mandar TODOS los borradores en cola |
| `/envía 3` | Mandar solo el borrador #3 |
| `/descartar` | Tirar todos los borradores sin mandar |
| `/triage` | Forzar triage de Gmail ahora (no esperar 5am) |
| `/reflect` | Forzar reflexión nocturna ahora |
| `/evening` | Forzar evening check-in ahora |
| `/weekly` | Forzar weekly review ahora |
| `/backup` | Snapshot inmediato a R2 |
| `/seed-medicare-pack` | Instala los 6 drafts del workflow Medicare |

**Sami puede usar:** /help, /gaps, /signals, /briefing, /agenda, /clientes, /pendientes, /historial, /compromisos, /skills, /tareas, /huecos, /luna.
Lo demás solo tú (Isabel).

---

## Cómo aprobar / rechazar lo que Athena propone

### Borradores de email/SMS
Athena los redacta y los mete a una **cola**. Tú decides:
- `envía` — manda todos los pendientes
- `envía 2` — manda solo el #2
- `descartar` — tira todos
- `quita el primero` — descarta uno específico
- También: cuando dices "cambia esto a más cálido" Athena edita el draft y te lo muestra de nuevo

### Skills nuevas (Athena propone playbooks)
Cuando Athena nota un patrón que repites, te propone un draft de skill. Tú dices:
- `aprueba la skill X` — la activa
- `retira la skill X` — la desactiva
- `borra la skill X` — la elimina

### Tareas
Cuando Athena propone una tarea, no hay que aprobar. Ella la crea sola. Tú dices después:
- `ya hice X` — la marca lista
- `cancela X` — la cancela
- `mueve X a mañana` — la reagenda

---

## Lo que Athena NO hace (rieles)

- **NO manda emails ni SMS a clientes sin tu "envía".** Cero excepciones.
- **NO te interrumpe de 9pm a 7am** (horas de silencio). Excepto algo crítico.
- **NO pasa de 1 briefing + 3 mensajes proactivos por día.**
- **NO da consejo médico, financiero, ni detalles de plan a un cliente sin SOA firmada.** Se bloquea automáticamente — esto es protección de tu licencia.
- **NO modifica el CRM de LUNA sin que tú se lo digas explícitamente.**
- **NO cambia su propio código.** Las skills solo orquestan tools que ya existen.

---

## Las 17 coaches — cuándo cada una

Athena coordina. Las otras 16 son especialistas. Cuando hables con Athena, ella decide a quién consultar. Si quieres hablar directo a una, abre el HTML.

| Coach | Para qué |
|---|---|
| **Athena** | Chief of Staff — la jefa que coordina todo |
| **Chef Carmen** | Comida, proteína, plan de comidas, suplementos nutricionales |
| **Coach Rivera** | Tonal, fitness, fuerza, recovery |
| **Dra. Sofía** | Hormonas, sueño, energía, perimenopausia |
| **Beauty Luna** | Piel, skincare, rutinas, productos |
| **Estilo Valentina** | Outfits, look del día, eventos |
| **María Medicare** | Clientes Medicare, AEP, compliance CMS, leads |
| **CFO Elena** | Finanzas, Profit First, comisiones, ahorros, taxes |
| **Mente Alma** | Mindset, manejo de estrés, ansiedad, días duros |
| **Casa Rosa** | Organización del hogar, limpieza, sistemas en casa |
| **Decor Camila** | Diseño de interiores, decoración |
| **Brand Marisol** | Marca personal, marketing, contenido |
| **Voz Lucía** | Charlas, presentaciones, voz en cámara |
| **Viajes Catalina** | Trips, escapadas, lifestyle |
| **Network Beatriz** | Networking, PR, relaciones profesionales |
| **Guía Esperanza** | Fe, espiritualidad, propósito |
| **Visión Victoria** | Visión a largo plazo, metas, planeación estratégica |

---

## Casos de uso reales (cómo Isabel realmente la usa)

### Lunes en la mañana
1. Despertás → Athena ya te mandó briefing 6:30am
2. Lees: "3 SOAs faltantes, 2 hot leads sin contacto, T65 de María Hdz esta semana"
3. Le dices: "Hazle AEP outreach a Carlos primero, después al T65"
4. Athena ejecuta la skill, te da resumen

### En el carro entre cliente y cliente
1. Voice note: "Acabo de salir con María, me dijo que quiere cambiar a SCAN Classic, llámame mañana para discutirlo"
2. Athena: registra nota en LUNA (Skarleth la ve), crea tarea para mañana, propone email de follow-up

### Antes de una llamada de 11am
1. "Athena, en 10 min hablo con Lupita Vargas, dame el contexto"
2. Athena lee LUNA, te resume: "Lead de hace 3 días, fuente walk-in, no tiene plan, cumple 65 en 2 meses. Recomiendo: ICEP window, ofrece consulta gratis. Skarleth ya hizo intake parcial."

### Cliente difícil quiere cambiar plan
1. "Compárame SCAN Classic vs Anthem MediBlue para Carlos Ramírez"
2. Athena invoca la skill "brief_comparar_planes": web search de cambios 2026, tabla side-by-side de premium/deductible/MOOP/cobertura de sus medicamentos, recomendación con razón
3. Encola email al cliente con la tabla
4. Tú revisas, dices `envía`

### Domingo en la noche
1. Athena: "Weekly review — 12 touchpoints esta semana, 2 SOAs firmadas, 1 nuevo lead. Faltaron 3 callbacks. Lo grande para el lunes: pre-AEP outreach a los 8 clientes activos sin contacto en 11+ meses."

---

## Si algo se rompe

| Síntoma | Solución |
|---|---|
| Athena no responde en WhatsApp | Railway dashboard → revisa logs del servidor |
| Athena dice "LUNA no está configurado" | Tu `LUNA_API_KEY` o `LUNA_BASE_URL` están mal en Railway |
| Athena dice "LUNA inalcanzable" | El secret no coincide entre Railway y Bluehost, o el PHP patch falta |
| Las llamadas telefónicas no se conectan | Twilio webhook de `/voice/incoming` no apunta a Railway |
| Los emails no llegan | Verifica `GMAIL_APP_PASSWORD` (no es tu password normal — es app password de 16 chars) |
| Athena suena rara/genérica | Probablemente `ELEVENLABS_VOICE_ID` no está cargado — está usando OpenAI default |
| Los borradores no se mandan al decir "envía" | Revisa `/pendientes` — quizás están en cola pero hay error en el outbound review hook |

---

## Recordatorio final

**Athena es tu jefa de operaciones, no tu empleada.** Confía en su capture-by-default. Confía en sus drafts. Confía cuando te diga "esto roza compliance" o "no te conviene". Tú aún decides, pero ella ya hizo el research.

**No vives para Athena. Athena vive para que tú vivas más.**
