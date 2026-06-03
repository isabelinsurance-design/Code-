// ───────────────────────────────────────────────────────────────────
//  Tools que las coaches usan para escribir su propio plan.
//
//  Cada coach (cuando está en una conversación threaded en la PWA)
//  recibe estas 3 tools. El dispatcher está scoped al coach_id de quien
//  está hablando — Sofía no puede tocar el plan de Carmen, etc.
//
//  El scope se logra en makeCoachPlanDispatcher(coachId) — closure que
//  captura el coach_id y rechaza cualquier intento de manipularlo.
// ───────────────────────────────────────────────────────────────────

import { addPlanItem, updatePlanItem, loadCoachPlan } from './coach_plans.js';

export const coachPlanTools = [
  {
    name: 'coach_plan_agregar',
    description: 'Agrega un item nuevo a TU plan vigente con Isabel. Úsalo cuando le recomiendes algo concreto que quieras que ella recuerde entre sesiones (ej. "tomar D3 5000IU diaria con desayuno", "caminar 30 min después de comer"). Sé específica: dosis, frecuencia, condiciones. Un item por recomendación discreta — no metas varias cosas en uno solo.',
    input_schema: {
      type: 'object',
      properties: {
        text: {
          type: 'string',
          description: 'La recomendación, en una frase clara y accionable. Ejemplo: "D3 5000IU diaria por la mañana con comida grasa".',
        },
      },
      required: ['text'],
    },
  },
  {
    name: 'coach_plan_actualizar',
    description: 'Actualiza un item existente de TU plan: cambia su texto, márcalo como pausado (Isabel decidió descansar), o márcalo como hecho (logro alcanzado, ya no aplica). Usa el item_id que aparece en el plan vigente. Si Isabel te dice que ya no toma D3, ponle status="paused" con una posible explicación al texto. Si completó algo (ej. "ya alcancé mis 168 lbs"), status="done".',
    input_schema: {
      type: 'object',
      properties: {
        item_id: {
          type: 'string',
          description: 'El ID del item a actualizar (formato p<random>). Aparece entre corchetes en el plan vigente.',
        },
        text: {
          type: 'string',
          description: 'Texto nuevo del item (opcional). Solo si necesitas reescribirlo. Si solo cambias status, omite este campo.',
        },
        status: {
          type: 'string',
          enum: ['active', 'paused', 'done'],
          description: 'Nuevo estado: active (vigente), paused (descansando, retomable), done (completado o retirado).',
        },
      },
      required: ['item_id'],
    },
  },
  {
    name: 'coach_plan_ver',
    description: 'Devuelve TU plan vigente con Isabel — útil si necesitas re-leerlo en medio de la conversación. El plan ya viene en tu contexto al inicio, así que normalmente no necesitas llamarlo. Úsalo solo si Isabel te pregunta directamente "qué me recomendaste" o si quieres confirmar el item_id exacto antes de actualizar.',
    input_schema: { type: 'object', properties: {} },
  },
];

export function makeCoachPlanDispatcher(coachId) {
  return async (name, input) => {
    if (name === 'coach_plan_agregar') {
      const plan = addPlanItem(coachId, input.text);
      const newest = plan.items[plan.items.length - 1];
      return `Item agregado a tu plan: [${newest.id}] ${newest.text}`;
    }
    if (name === 'coach_plan_actualizar') {
      const patch = {};
      if (input.text !== undefined) patch.text = input.text;
      if (input.status !== undefined) patch.status = input.status;
      const plan = updatePlanItem(coachId, input.item_id, patch);
      const updated = plan.items.find((i) => i.id === input.item_id);
      return `Item ${input.item_id} actualizado: status=${updated.status}, text="${updated.text}"`;
    }
    if (name === 'coach_plan_ver') {
      const plan = loadCoachPlan(coachId);
      if (!plan.items.length) return 'Tu plan está vacío. Aún no le has dejado nada estructurado a Isabel.';
      return plan.items
        .map((i) => `[${i.id}] (${i.status}) ${i.text}`)
        .join('\n');
    }
    throw new Error(`Tool desconocida: ${name}`);
  };
}
