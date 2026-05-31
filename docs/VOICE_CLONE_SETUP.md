# Voice clone con tu voz — setup paso a paso

Athena hoy te contesta voice notes con la voz de OpenAI (TTS-1). Genérica.
Esta guía te lleva a que te conteste con **tu propia voz**, clonada con
ElevenLabs. ~25 minutos en total: 15 grabándote, 10 entre Sami y yo.

---

## Lo que vas a lograr

- Athena pone llamadas en tu voz a clientes (siempre con disclaimer
  "AI assistant for Isabel").
- Tus voice notes de respuesta en WhatsApp suenan como tú, en Spanglish.
- Los briefings 6:30am que llegan como audio suenan más íntimos.

---

## ANTES de empezar — qué necesitas

1. **iPhone o teléfono con micrófono decente** (el iPhone normal está bien;
   no necesitas mic de podcast).
2. **Cuarto silencioso** — apaga aire acondicionado, cierra ventana, lejos
   del tráfico. La aspiradora del vecino arruina la grabación.
3. **15 minutos sin interrupciones**.
4. **App de grabación** — Voice Memos del iPhone funciona. Configúrala en
   los Ajustes para **calidad sin comprimir** (Audio Quality → Lossless).

---

## PASO 1 — Crea cuenta ElevenLabs (3 min)

1. Ve a [elevenlabs.io](https://elevenlabs.io) → Sign up.
2. Plan recomendado: **Creator ($22/mes)** — incluye 5 voice clones, 100K
   caracteres/mes (≈30 horas de TTS). Más que suficiente para Athena.
3. Si prefieres probar gratis primero: el plan free permite Instant Voice
   Clone con 1 min de audio — calidad menor pero funcional para test.

---

## PASO 2 — Graba el material (10-15 min)

ElevenLabs **Professional Voice Clone** pide 30 min de audio de calidad.
Para Instant Voice Clone basta con 3-5 min.

**Recomendación: hazlo en 3 sesiones cortas, no una larga.** Tu voz se cansa
y se nota.

### Sesión 1 — Lectura técnica (5 min)
Lee este texto en voz natural, sin "voz de podcast":

> Hola, soy Isabel Fuentes, agente de Medicare licenciada en el Sur de
> California. Trabajo con SCAN, Anthem, Humana, Alignment, LA Care, Health
> Net, Molina y UHC. Si estás cerca de los sesenta y cinco años, o ya cumpliste,
> y todavía no tienes plan Medicare, plática conmigo. Yo te explico las
> opciones sin presión. ¿Tienes preguntas sobre el período de inscripción
> anual? Estamos en octubre, queda poquito tiempo. Llámame o mándame
> mensaje al número que sale en mi sitio web.

### Sesión 2 — Spanglish natural (5 min)
Cuenta como si le hablaras a una amiga:

> Mira, te voy a ser honesta — Medicare es un overwhelming cuando empiezas.
> Yo lo sé porque me lo preguntan every single day. Pero you don't have to
> figure it out alone. Hay una thing que se llama Special Enrollment Period
> si te acabas de jubilar, si te quitaron el plan del trabajo, o si te mudaste
> de estado. Hay que verlo case-by-case. Cada persona es diferente. Pero
> tranquila, paso por paso vamos.

### Sesión 3 — Tono cálido (5 min)
Como si dejaras un voice note a tu hija:

> Mami, ¿cómo amaneciste? Yo bien, gracias a Dios. Hoy tengo dos citas con
> clientes nuevos, una a las diez y otra a las dos. Después voy al
> super al Sprouts porque ya casi no queda nada. Si quieres que te traiga
> algo, mándame mensaje. Te quiero, hablamos al ratito.

---

## PASO 3 — Sube a ElevenLabs (3 min)

1. ElevenLabs dashboard → **Voices** → **Add New Voice** → **Instant Voice
   Clone** (o **Professional Voice Clone** si grabaste 30+ min).
2. Nombre: "Isabel Fuentes Spanglish".
3. Sube los 3 archivos.
4. Labels (opcional): `accent: latina mexican`, `age: middle aged`, `tone:
   warm professional`.
5. Save → ElevenLabs procesa 30-60 segundos.
6. Click la voz nueva → arriba a la derecha hay un botón **⋯** → **Copy
   Voice ID**. Guarda ese ID (parecido a `21m00Tcm4TlvDq8ikWAM`).

---

## PASO 4 — Saca tu API key (1 min)

1. ElevenLabs → tu avatar arriba a la derecha → **Profile + API key**.
2. Copia el **API key**.

---

## PASO 5 — Mándale esto a Sami (1 min)

Mándale a Sami exactamente este mensaje (cambia los valores):

```
Sami — actualicé mi voz. Pon estas 3 variables en Railway:

TTS_PROVIDER=elevenlabs
ELEVENLABS_API_KEY=sk_<el key que te di>
ELEVENLABS_VOICE_ID=<el voice id que te di>
ELEVENLABS_MODEL=eleven_flash_v2_5

Cuando termines, redeploy. Después mándame por WhatsApp "voz".
Cuando Athena me conteste el siguiente voice note debería sonar
como yo.
```

---

## PASO 6 — Prueba (2 min)

1. Cuando Sami te confirme el redeploy, mándale a Athena por WhatsApp:
   > "Mándame un voice note diciendo cómo va mi día."
2. Athena debe contestar con audio en tu voz.
3. Si no suena como tú: la grabación pudo estar muy ruidosa, o el modelo
   `eleven_flash_v2_5` no captó suficiente. Pasa a `eleven_multilingual_v2`
   (más calidad, más lento) en Railway y redeploy.

---

## Si quieres ajustar después

ElevenLabs te deja **fine-tunear** la voz desde su dashboard. Estos 3
sliders importan:

- **Stability** (0.5 default): más alto = más consistente pero monótono.
  Para Athena recomiendo **0.45** — algo de variación natural.
- **Similarity** (0.75): más alto = más fiel a tu voz original. **0.85**
  para Spanglish funciona bien.
- **Style** (0.0): expresividad emocional. **0.2** para llamadas, **0.4**
  para voice notes íntimos.

Cualquier cambio en sliders aplica inmediatamente — no hay que redeploy.

---

## Costo real

- **ElevenLabs Creator**: $22/mes (100K chars ≈ 2.5h de audio).
- **Anthropic + OpenAI + Twilio + Railway**: ~$30/mes.
- **Total Athena con voz clonada**: ~$52/mes.

Un día de Apex te cuesta más.

---

## Lo que NO va a sonar como tú (aún)

- **Llantos / risas exagerados / gritos** — los modelos voice clone son
  buenos para conversación normal, no extremos emocionales.
- **Acentos regionales que tú haces a propósito** (chilango fingido, etc.)
  — no captados.
- **Susurros muy bajos** — pierden definición.

Para todo lo demás: te va a engañar a ti misma cuando lo escuches.

---

## Privacidad

Tu voz clonada vive en la cuenta tuya de ElevenLabs. Athena solo tiene el
`ELEVENLABS_VOICE_ID` para invocarla. Si algún día cancelas, tu voice clone
queda solo en tu cuenta — no hay copia residual en Athena.

Para usar tu voz para llamadas a clientes Athena pega automáticamente
"This is an AI assistant calling on behalf of Isabel Fuentes" al inicio
de cada llamada — requisito ético + reduce confusión.
