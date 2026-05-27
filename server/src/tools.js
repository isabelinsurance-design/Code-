import { SPECIALISTS, specialistList } from './agents.js';
import { askSpecialist } from './claude.js';
import { sendMessage } from './whatsapp.js';
import { sendEmail, checkEmails } from './email.js';
import { remember, buildWikiContext } from './memory.js';

// Definiciones de las herramientas que Athena puede usar.
// Cada una tiene un esquema (qué inputs acepta) que Claude lee.
export const toolDefinitions = [
  {
    name: 'consultar_especialista',
    description: `Consulta a una coach especialista del equipo de Isabel para temas específicos. Especialistas disponibles: ${specialistList()}. Úsala cuando la pregunta es del dominio de una experta (comida=carmen, ejercicio=rivera, sueño/energía=sofia, Medicare/clientes=maria, dinero=elena, estrés/mindset=alma, metas/visión=victoria).`,
    input_schema: {
      type: 'object',
      properties: {
        especialista: { type: 'string', description: 'El id de la especialista (ej. carmen, rivera, maria)' },
        pregunta: { type: 'string', description: 'La pregunta o situación, con contexto suficiente para que responda bien.' },
      },
      required: ['especialista', 'pregunta'],
    },
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
    description: 'Guarda un dato importante en la memoria de largo plazo de Isabel (preferencias, decisiones, contexto que servirá en futuras conversaciones). Todas las coaches pueden leer esta memoria.',
    input_schema: {
      type: 'object',
      properties: {
        nota: { type: 'string', description: 'El dato a recordar, en una frase clara.' },
      },
      required: ['nota'],
    },
  },
];

// Ejecuta una herramienta y devuelve el resultado como texto.
export async function runTool(name, input) {
  switch (name) {
    case 'consultar_especialista': {
      const spec = SPECIALISTS[input.especialista];
      if (!spec) return `No existe la especialista "${input.especialista}". Opciones: ${specialistList()}.`;
      const answer = await askSpecialist(spec, input.pregunta, buildWikiContext());
      return `${spec.name} dice:\n${answer}`;
    }
    case 'mensaje_a_sami': {
      const to = process.env.SAMI_WHATSAPP;
      if (!to) return 'No hay número de Sami configurado (SAMI_WHATSAPP en el .env).';
      await sendMessage(to, `📋 De Athena (Isabel):\n${input.mensaje}`);
      return `Mensaje enviado a Sami: "${input.mensaje}"`;
    }
    case 'enviar_email':
      return await sendEmail(input.para, input.asunto, input.cuerpo);
    case 'revisar_emails':
      return await checkEmails(input.cuantos || 5);
    case 'recordar':
      remember(input.nota);
      return `Guardado en la memoria: "${input.nota}"`;
    default:
      return `Herramienta desconocida: ${name}`;
  }
}
