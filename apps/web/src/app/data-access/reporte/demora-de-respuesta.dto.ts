/**
 * Shared types for the Agent Response Delay Metric & Ticket Participation
 * Report feature.
 * Used by the reports screen and by the inbox's ParticipacionDeTicketComponent.
 */

/** Backend response for GET /api/reportes/demora-de-respuesta */
export interface ReporteDeDemoraDeRespuesta {
  datosDisponiblesDesde: string | null;
  datosParciales: boolean;
  global: DemoraDeRespuestaAggregate;
  porAgente: DemoraDeRespuestaDeAgenteEntry[];
}

/** Aggregate response-delay figures across all agents in range */
export interface DemoraDeRespuestaAggregate {
  demoraPromedioSegundos: number | null;
  cantidadDeMuestras: number;
  cantidadDeEntrantesSinResponder: number;
}

/** Per-agent response-delay entry */
export interface DemoraDeRespuestaDeAgenteEntry {
  agenteId: number;
  agenteNombre: string | null;
  demoraPromedioSegundos: number | null;
  cantidadDeMuestras: number;
}

/** Backend response for GET /api/reportes/tickets/{id}/participacion */
export interface ParticipacionDeTicket {
  ticketId: number;
  datosDisponiblesDesde: string | null;
  datosParciales: boolean;
  participantes: ParticipanteDeParticipacion[];
  cantidadDeMensajesAutomaticos: number;
  cantidadDeEntrantesSinResponder: number;
  pares: ParDeParticipacion[];
}

/** A single agent's participation on a ticket */
export interface ParticipanteDeParticipacion {
  agenteId: number;
  agenteNombre: string | null;
  cantidadDeMensajes: number;
  cantidadDeMuestras: number;
  demoraPromedioSegundos: number | null;
}

/** A single burst -> reply pair on a ticket (null fields = unanswered run) */
export interface ParDeParticipacion {
  inicioDeRachaAt: string;
  respuestaAt: string | null;
  agenteId: number | null;
  demoraSegundos: number | null;
}
