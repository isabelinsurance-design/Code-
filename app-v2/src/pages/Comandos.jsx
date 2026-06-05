// Cheat sheet — cómo hablarle a Athena.
// Pantalla de referencia rápida para que Isabel sepa qué decir.
// Útil sobre todo cuando está manejando y quiere recordar la "forma"
// de delegar algo (sin tener que pensar).

const SECCIONES = [
  {
    titulo: 'Delegar al equipo',
    descripcion: 'Athena crea ticket en LUNA, asignado a la persona correcta.',
    ejemplos: [
      { dices: 'Que Sami llame a Maritza mañana', hace: 'LUNA ticket → Sami, tipo LLAMADA' },
      { dices: 'Que el equipo le mande el paquete AEP a Carlos', hace: 'LUNA ticket → Sami (default), tipo APLICACION' },
      { dices: 'Que Skarleth confirme la cita con Vega', hace: 'LUNA ticket → Skarleth, tipo CITA' },
      { dices: 'Dile a Arlette que revise el SOA de Beto', hace: 'LUNA ticket → Arlette' },
    ],
  },
  {
    titulo: 'Recordatorios para ti',
    descripcion: 'Athena lo guarda y te pingea a la hora que dijiste.',
    ejemplos: [
      { dices: 'Recuérdame llamar a Carlos esta tarde 3pm', hace: 'Tarea → tú, vence hoy 3pm, push notif' },
      { dices: 'Recuérdame revisar el contrato de Anthem el lunes', hace: 'Tarea → tú, vence lunes' },
      { dices: 'No se me olvide preguntarle a Maritza por su MBI', hace: 'Tarea → tú, sin fecha' },
    ],
  },
  {
    titulo: 'Comunicación a clientes',
    descripcion: 'Athena redacta — tú apruebas con "envía" antes que salga (CMS compliance).',
    ejemplos: [
      { dices: 'Manda email a Maritza confirmando la cita', hace: 'Borrador email + cola de aprobación' },
      { dices: 'Mándale SMS a Carlos preguntando por su Rx', hace: 'Borrador SMS + cola de aprobación' },
      { dices: 'Envía', hace: 'Manda lo que está en cola' },
      { dices: 'No mandes / cancela', hace: 'Borra el borrador' },
    ],
  },
  {
    titulo: 'Llamadas',
    descripcion: 'Athena puede llamarte o llamar por ti.',
    ejemplos: [
      { dices: 'Llámame', hace: 'Te llama al +1 310 270 0626' },
      { dices: 'Llama a Carlos por mí', hace: 'Outbound call vía Twilio + transcribe + resume' },
    ],
  },
  {
    titulo: 'Reportes y estado del equipo',
    descripcion: 'Pilar consulta LUNA y te trae la info.',
    ejemplos: [
      { dices: 'Dame reporte de tickets abiertos', hace: 'Cuántos por persona + prioridad' },
      { dices: 'Qué tiene pendiente el equipo hoy', hace: 'Lista de tickets ALTA + citas' },
      { dices: 'Cómo va Maritza', hace: 'Expediente completo de Maritza' },
      { dices: 'Qué tengo en mi agenda hoy', hace: 'Citas Google Calendar + LUNA' },
    ],
  },
  {
    titulo: 'Promesas que otros te hicieron',
    descripcion: 'Athena las guarda y persigue por ti — no te las pueden zafar.',
    ejemplos: [
      { dices: 'Anthem dijo que mandaba el approval mañana', hace: 'Compromiso registrado + recordatorio si no llega' },
      { dices: 'Carlos prometió mandar su MBI el viernes', hace: 'Compromiso → si no llega, Athena le recuerda' },
    ],
  },
  {
    titulo: 'Calendar',
    descripcion: 'Crea, mueve o cancela citas. Google Calendar real.',
    ejemplos: [
      { dices: 'Agenda con Dra Vega el viernes 2pm', hace: 'Evento Google Calendar' },
      { dices: 'Mueve la cita con Maritza al jueves', hace: 'Reagenda + notifica' },
      { dices: 'Cancela la junta con Carlos', hace: 'Cancela + notifica asistentes' },
    ],
  },
  {
    titulo: 'Multi-acción (lo más útil manejando)',
    descripcion: 'Habla seguido — Athena parsea todo y ejecuta en paralelo.',
    ejemplos: [
      {
        dices: 'Athena tengo cinco cosas: uno Sami que llame a Maritza, dos manda email a Carlos del AEP, tres recuérdame llamar a Anthem el lunes, cuatro cuántos tickets están abiertos, cinco agéndame con Vega el viernes a las dos',
        hace: 'Hace los 5 a la vez y te reporta una sola vez: ✓ Sami ticket #X · ✓ Email Carlos (cola) · ✓ Tarea Anthem lunes · 8 tickets abiertos · ✓ Cita Vega viernes 2pm',
      },
    ],
  },
];

export default function Comandos() {
  return (
    <div className="space-y-8">
      <header>
        <h2 className="font-serif text-3xl text-lino-800">Cómo le hablo a Athena</h2>
        <p className="text-ink-3 text-sm mt-1">
          Referencia rápida. Habla normal — ella entiende. Lo de aquí son patrones que SIEMPRE funcionan.
        </p>
      </header>

      {SECCIONES.map((sec) => (
        <section key={sec.titulo}>
          <h3 className="font-serif text-lg text-lino-800">{sec.titulo}</h3>
          <p className="text-ink-3 text-sm mt-1 mb-3">{sec.descripcion}</p>
          <div className="space-y-2">
            {sec.ejemplos.map((ex, i) => (
              <article key={i} className="card">
                <div className="text-xs uppercase tracking-wider text-ink-3 mb-1">
                  Dices
                </div>
                <p className="text-ink-1 font-serif italic">"{ex.dices}"</p>
                <div className="text-xs uppercase tracking-wider text-ink-3 mt-3 mb-1">
                  Hace
                </div>
                <p className="text-ink-2 text-sm">{ex.hace}</p>
              </article>
            ))}
          </div>
        </section>
      ))}

      <section className="card bg-lino-50">
        <h3 className="font-serif text-lg text-lino-800 mb-2">Regla de oro</h3>
        <p className="text-ink-2 text-sm leading-relaxed">
          <strong>No tienes que estructurar.</strong> Habla como si le contaras a una persona.
          Athena ordena, decide canales, y ejecuta. Si algo necesita aprobación tuya
          (email a cliente, SMS a cliente) te lo muestra primero. Todo lo demás se hace.
        </p>
        <p className="text-ink-2 text-sm leading-relaxed mt-2">
          <strong>Si te equivocas</strong>, di "no eso no" / "cancela" / "borra eso" y ella deshace.
        </p>
      </section>
    </div>
  );
}
