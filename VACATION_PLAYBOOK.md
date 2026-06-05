# Athena en Vacaciones — Playbook

Para que tu vacación sea de verdad y Athena maneje el negocio sola.

---

## 1. Activar modo vacaciones

**Antes de salir, dile a Athena (WhatsApp o PWA):**

> *"Athena, me voy de vacaciones del 5 al 15 de julio a Madrid. Modo vacaciones."*

O directo:

> *"Activa modo vacaciones hasta el 15 de julio, timezone Europe/Madrid."*

Athena responde algo como:

> *"Modo vacaciones activado hasta 15 julio (TZ: Europe/Madrid). Solo te interrumpo con cosas URGENTES. Todo lo demás lo delego a Sami. Reportes a las 9am y 7pm tuyas."*

**Mientras estés en modo vacaciones:**
- ✅ Solo te molesta con URGENTES (Haiku 4.5 clasifica cada email/mensaje entrante)
- ✅ Todo lo demás → delegado automático a Sami
- ✅ Templates pre-aprobados → mandan sin pedir tu OK
- ✅ Reportes 9am + 7pm **en tu hora local** (no SoCal)
- ✅ Athena sabe que descansas — sus respuestas son más cortas
- ✅ NO te manda los crons normales (briefing 6:30am SoCal, evening 9pm SoCal)

**Al regresar:**

> *"Athena, ya regresé, desactiva modo vacaciones."*

---

## 2. El botón "Athena al toque" en tu iPhone

### Opción A — Llamada directa (la más confiable lejos de WiFi)

Guarda el número Twilio de Athena en tus contactos:
- Nombre: **Athena ⭐**
- Pin a tu Favoritos (arriba en Contactos)

**Cuando manejas / sales del hotel / aeropuerto:**
- Marcas Athena ⭐ → ella contesta con tu voz clonada
- Le hablas natural: *"Sami que llame a Maritza, manda email a Carlos de su AEP, recuérdame llamar a Anthem mañana"*
- Athena ejecuta todo durante la llamada
- Cuelgas → te llega WhatsApp con el resumen

**Ventaja en vacaciones:**
- Funciona con solo señal celular (sin WiFi)
- Cuidado: roaming. Mejor:
  - **iPhone:** Settings → Cellular → eSIM con plan internacional (T-Mobile, Visible, AT&T tienen passes)
  - O **Google Voice / FaceTime audio** sobre WiFi del hotel para llamar al número Twilio gratis

### Opción B — iOS Shortcut "Athena Voice Note"

Una vez configurado, tap → WhatsApp se abre con el chat de Athena listo + mic activo.

**Cómo crearlo (5 minutos):**

1. **Abre la app Shortcuts** (viene con iOS)
2. **+** (arriba derecha) para nuevo Shortcut
3. **Add Action** → busca **"Open URL"**
4. En la URL, pega:
   ```
   whatsapp://send?phone=NUMERO_DE_ATHENA_TWILIO
   ```
   (reemplaza con tu número Twilio en formato internacional sin +, ej. `13105551234`)
5. **Nombre:** "Athena"
6. **Add to Home Screen** → escoge un icono (yo sugiero el corazón o un punto)
7. Confirma — aparece como app en tu home screen
8. **(Opcional) Hey Siri:** "Hey Siri, Athena" lo activa por voz

**Para que el mic se abra automático:** WhatsApp no permite eso directo, pero una vez en el chat solo es **un tap más al icono del mic** y empiezas a grabar voice note.

### Opción C — PWA pinned al home screen

Ya está instalable como app:
1. En Safari abres la URL de la PWA
2. Tap el botón Share (cuadrado con flecha arriba)
3. **Add to Home Screen**
4. Aparece como app

Abres la app → entras a **Athena** en el sidebar → tap mic → hablas.

**Comparación:**

| | A: Llamada | B: Shortcut | C: PWA |
|---|---|---|---|
| Sin WiFi (cellular) | ✅ | ❌ | ❌ |
| Voz a voz natural | ✅ | ⚠ asíncrono | ⚠ asíncrono |
| Rapidez | ⭐⭐⭐ | ⭐⭐ | ⭐ |
| Roaming costs | $$$ | $ data | $ data |

**Mi recomendación para vacaciones:** A para llamadas, B como respaldo cuando tengas WiFi de hotel.

---

## 3. Templates pre-aprobados (antes de viajar)

Crea 4-6 templates que Athena pueda mandar SOLA sin esperar tu OK.

**Ejemplos buenos para crear antes de viajar:**

```
Athena, crea template "fuera_oficina": canal email, asunto "Recibí tu mensaje", cuerpo "Hola {{cliente_nombre}}, gracias por contactarme. Estoy fuera de la oficina hasta {{fecha_regreso}}. Para cosas urgentes Medicare contacta a Sami (sami@... / 310-...). Para lo demás te respondo cuando regrese. Saludos, Isabel."
```

```
Athena, crea template "confirmacion_cita": canal sms, cuerpo "Hola {{cliente_nombre}}, confirmando cita {{fecha_hora}} con Isabel/equipo. Si necesitas cambiar avísame al 310-... Gracias!"
```

```
Athena, crea template "soa_recordatorio": canal email, asunto "Recordatorio SOA", cuerpo "Hola {{cliente_nombre}}, necesito que firmes el SOA antes de seguir hablando de planes (CMS regla). Te mando link: {{link_soa}}. Cualquier duda contactame. Isabel."
```

Athena los guarda y luego puede llamarlos:
```
template_usar(slug="confirmacion_cita", destinatario="555-...", vars={cliente_nombre:"Maritza", fecha_hora:"viernes 3pm"})
```

Cuando esté en vacaciones y un cliente pregunte por una cita, Athena puede usar el template SOLA — sin esperar tu "envía".

---

## 4. Reportes en tu timezone

Sin que hagas nada, una vez activado modo vacaciones:

**9am tu hora local:**
```
🌴 Morning report — 09:00 en Madrid
8 tickets abiertos, 1 ALTA
3 citas hoy (equipo las maneja)

Sigue disfrutando. Solo te interrumpo si es URGENTE.
```

**7pm tu hora local:**
```
🌴 Evening report — 19:00
Hoy: 5 acciones cerradas (Sami / equipo).
⚠ 1 ticket ALTA estancado — ¿le doy nudge al equipo?

Mañana te paso el morning report a las 9am tuyas.
```

Si nada importante pasó, el reporte es de 2 líneas. No te abruma.

---

## 5. Cuando algo SÍ es urgente

Athena te despierta SOLO si:
- Cliente Medicare en crisis (medicamento denial crítico, doctor primario fuera, AEP cierra hoy)
- Carrier rep con deadline <24h
- Audit / CMS letter / complaint formal
- Emergencia familiar
- Decisión estratégica que SOLO tú puedes tomar

Ella decide con un clasificador Haiku ($0.0008 por mensaje — despreciable).

**Si te equivocas y te despierta con algo normal:** dile "*esto no era urgente, no me despiertes con cosas así*" → Athena aprende y lo registra.

---

## 6. Lista rápida de lo que puedes pedirle MANEJANDO / EN LA PLAYA

| Lo que dices | Lo que hace |
|---|---|
| *"Sami que llame a [cliente]"* | LUNA ticket → Sami, tipo LLAMADA |
| *"Manda template confirmación a [cliente]"* | template_usar — sale directo |
| *"Qué tickets están abiertos"* | Reporte de Pilar/LUNA |
| *"Algo urgente?"* | Lista de pendientes URGENT |
| *"Recuérdame [X] cuando regrese"* | Tarea con vence = fecha de regreso |
| *"Cancela modo vacaciones"* | Vuelve a normal |

---

## Setup checklist antes de viajar

- [ ] Probar llamada a número Twilio Athena (desde celular, sin WiFi)
- [ ] Crear iOS Shortcut "Athena" en home screen
- [ ] Crear 4-6 templates pre-aprobados (fuera_oficina, confirmacion_cita, soa_recordatorio, agendar_callback)
- [ ] Avisar a Sami que estarás en modo vacaciones (la carga aumenta para ella)
- [ ] Configurar auto-responder de Gmail (opcional — Athena puede hacerlo via tool)
- [ ] **Disfruta. Athena maneja el negocio.**
