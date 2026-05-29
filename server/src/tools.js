import { SPECIALISTS, specialistList } from './agents.js';
import { askSpecialist } from './claude.js';
import { sendMessage } from './whatsapp.js';
import { sendEmail, checkEmails } from './email.js';
import { remember, forget, listMemories, setSeason, buildWikiContext } from './memory.js';

// Definiciones de las herramientas que Athena puede usar.
// Cada una tiene un esquema (qué inputs acepta) que Claude lee.
export const toolDefinitions = [
  {
    name: 'consultar_especialistas',
    description: `Consulta a UNA O VARIAS coachs especialistas del equipo de Isabel EN PARALELO. Pasa un array \`consultas\` con una entrada por coach que quieras consultar. Si una pregunta toca varios dominios (ej. salud + dinero + mindset), incluye las TRES en una sola llamada — es ~3x más rápido y te permite sintetizar entre vistas. Especialistas disponibles: ${specialistList()}. Routing: comida=carmen, ejercicio=rivera, sueño/energía/suplementos=sofia, Medicare/clientes=maria, dinero=elena, estrés/mindset=alma, metas/visión=victoria.`,
    input_schema: {
      type: 'object',
      properties: {
        consultas: {
          type: 'array',
          minItems: 1,
          items: {
            type: 'object',
            properties: {
              especialista: { type: 'string', description: 'El id de la coach (ej. carmen, rivera, maria).' },
              tarea: { type: 'string', description: 'Lo que necesitas de ella, con contexto suficiente. Sé específica.' },
              formato_salida: { type: 'string', description: 'Opcional. Formato esperado, ej. "3 bullets máx", "1 acción concreta", "plan de 4 días".' },
              presupuesto_palabras: { type: 'integer', description: 'Opcional. Máximo de palabras de la respuesta (default 150).' },
            },
            required: ['especialista', 'tarea'],
          },
        },
      },
      required: ['consultas'],
    },
  },
  // Web search server-side de Anthropic — los resultados llegan al modelo
  // automáticamente, no pasan por runTool. max_uses limita su uso por turno.
  {
    type: 'web_search_20250305',
    name: 'web_search',
    max_uses: 3,
  },
  {
    name: 'mensaje_a_sami',
    description: 'Manda un mensaje por WhatsApp/SMS a Sami, el asistente humano de Isabel, para delegarle una tarea o seguimiento que requiere que un humano lo haga (llamadas, recados, papeleo, agendar, seguimiento a clientes).',
    input_schema: {
      type: 'object',
      properties: {
        mensaje: { type: 'string', description: 'La tarea o instrucción clara para Sami.' },
      },
      required: ['mensaje'],
    },
  },
  {
    name: 'enviar_sms',
    description: 'Manda un SMS (mensaje de texto estándar, NO WhatsApp) a un número de teléfono. Úsalo principalmente para contactar clientes de Medicare que no usan WhatsApp: recordatorios de cita, confirmaciones, avisos de AEP/OEP, follow-ups cortos. Mantén el mensaje breve — SMS cobra por segmento.',
    input_schema: {
      type: 'object',
      properties: {
        para: { type: 'string', description: 'Número de teléfono en formato internacional con + (ej. +13105551234).' },
        mensaje: { type: 'string', description: 'El texto del SMS. Sin formato. Corto y claro.' },
      },
      required: ['para', 'mensaje'],
    },
  },
  {
    name: 'enviar_email',
    description: 'Manda un correo electrónico desde la cuenta de Isabel. Úsalo para responder clientes, mandar info, o seguimiento por escrito.',
    input_schema: {
      type: 'object',
      properties: {
        para: { type: 'string', description: 'Email del destinatario.' },
        asunto: { type: 'string', description: 'Asunto del correo.' },
        cuerpo: { type: 'string', description: 'El texto del correo (sin la firma, se agrega sola).' },
      },
      required: ['para', 'asunto', 'cuerpo'],
    },
  },
  {
    name: 'revisar_emails',
    description: 'Revisa los correos más recientes en la bandeja de entrada de Isabel y devuelve un resumen (los no leídos marcados con 🔵).',
    input_schema: {
      type: 'object',
      properties: {
        cuantos: { type: 'integer', description: 'Cuántos correos recientes revisar (por defecto 5).' },
      },
      required: [],
    },
  },
  {
    name: 'recordar',
    description: 'Guarda un dato importante en la memoria de largo plazo de Isabel (preferencias, decisiones, contexto que servirá en futuras conversaciones). Todas las coaches pueden leer esta memoria. Si Isabel dice "recuerda que..." o "anota que..." usa esta herramienta.',
    input_schema: {
      type: 'object',
      properties: {
        nota: { type: 'string', description: 'El dato a recordar, en una frase clara y completa.' },
      },
      required: ['nota'],
    },
  },
  {
    name: 'olvidar',
    description: 'Borra de la memoria todas las notas que contengan el texto dado (búsqueda por substring, case-insensitive). Si Isabel dice "olvida X" o "ya no es cierto que X" usa esta herramienta.',
    input_schema: {
      type: 'object',
      properties: {
        que: { type: 'string', description: 'Texto a buscar y borrar. Sé específica para no borrar más de la cuenta.' },
      },
      required: ['que'],
    },
  },
  {
    name: 'que_recuerdas',
    description: 'Devuelve un listado de lo que Athena tiene guardado en la memoria de largo plazo. Útil cuando Isabel pregunta "¿qué recuerdas de mí?" o "¿qué sabes?".',
    input_schema: {
      type: 'object',
      properties: {
        cuantas: { type: 'integer', description: 'Cuántas notas devolver (default 20, máximo 50).' },
      },
      required: [],
    },
  },
  {
    name: 'actualizar_temporada',
    description: 'Actualiza el resumen de "temporada actual" — 1 o 2 frases que describen en qué está enfocada Isabel ahora mismo. Esto se inyecta en el contexto de TODAS las coaches para que sepan dónde está su cabeza. Úsalo cuando Isabel diga "ahora estoy enfocada en X", cambie de fase, o notes un giro claro en sus prioridades.',
    input_schema: {
      type: 'object',
      properties: {
        texto: { type: 'string', description: '1-2 frases describiendo el foco actual. Ej: "Post-launch de la app, reconstruyendo rutina de mañana y bajando de peso."' },
      },
      required: ['texto'],
    },
  },
];

// Ejecuta una herramienta y devuelve el resultado como texto.
export async function runTool(name, input) {
  switch (name) {
    case 'consultar_especialistas': {
      const consultas = Array.isArray(input.consultas) ? input.consultas : [];
      if (!consultas.length) {
        return 'Pasa al menos una entrada en `consultas` con {especialista, tarea}.';
      }
      const wiki = buildWikiContext();
      const results = await Promise.all(
        consultas.map(async (c) => {
          const spec = SPECIALISTS[c.especialista];
          if (!spec) {
            return `[${c.especialista} — no existe esa coach. Opciones: ${specialistList()}]`;
          }
          try {
            const answer = await askSpecialist(spec, c.tarea, wiki, {
              formato: c.formato_salida,
              presupuesto: c.presupuesto_palabras,
            });
            return `${spec.name} dice:\n${answer}`;
          } catch (err) {
            return `[${spec.name} — error: ${err.message}]`;
          }
        })
      );
      return results.join('\n\n---\n\n');
    }
    case 'mensaje_a_sami': {
      const to = process.env.SAMI_WHATSAPP;
      if (!to) return 'No hay número de Sami configurado (SAMI_WHATSAPP en el .env).';
      await sendMessage(to, `De Athena (Isabel):\n${input.mensaje}`);
      return `Mensaje enviado a Sami: "${input.mensaje}"`;
    }
    case 'enviar_sms': {
      let to = String(input.para || '').trim();
      if (!to) return 'Falta el número de teléfono.';
      if (!to.startsWith('+')) to = '+' + to.replace(/^[^\d]*/, '');
      await sendMessage(to, input.mensaje);
      return `SMS enviado a ${to}.`;
    }
    case 'enviar_email':
      return await sendEmail(input.para, input.asunto, input.cuerpo);
    case 'revisar_emails':
      return await checkEmails(input.cuantos || 5);
    case 'recordar':
      remember(input.nota);
      return `Guardado en la memoria: "${input.nota}"`;
    case 'olvidar': {
      const { borradas, restantes } = forget(input.que);
      if (!borradas) return `No encontré nada en la memoria que matchee "${input.que}".`;
      return `Borré ${borradas} nota(s) que mencionaban "${input.que}". Quedan ${restantes} en total.`;
    }
    case 'que_recuerdas': {
      const cuantas = Math.min(Math.max(parseInt(input.cuantas, 10) || 20, 1), 50);
      const notas = listMemories(cuantas);
      if (!notas.length) return 'Tu wiki está vacía — todavía no he guardado nada.';
      return notas.map((n, i) => `${i + 1}. ${n.nota}`).join('\n');
    }
    case 'actualizar_temporada': {
      const s = setSeason(input.texto);
      return s.texto ? `Temporada actualizada: "${s.texto}"` : 'Temporada vacía.';
    }
    default:
      return `Herramienta desconocida: ${name}`;
  }
}
