/**
 * Shared types for the Agent Response Delay Metric & Ticket Participation
 * Report feature.
 * Used by the reports screen and by the inbox's ParticipacionDeTicketComponent.
 */

/** Backend response for GET /api/reports/response-delay */
export interface ReporteDeDemoraDeRespuesta {
  data_available_from: string | null;
  partial_data: boolean;
  aggregate: DemoraDeRespuestaAggregate;
  per_agent: DemoraDeRespuestaDeAgenteEntry[];
}

/** Aggregate response-delay figures across all agents in range */
export interface DemoraDeRespuestaAggregate {
  avg_delay_seconds: number | null;
  sample_count: number;
  unanswered_inbound_count: number;
}

/** Per-agent response-delay entry */
export interface DemoraDeRespuestaDeAgenteEntry {
  agent_id: number;
  agent_name: string | null;
  avg_delay_seconds: number | null;
  sample_count: number;
}

/** Backend response for GET /api/reports/tickets/{id}/participation */
export interface ParticipacionDeTicket {
  ticket_id: number;
  data_available_from: string | null;
  partial_data: boolean;
  participants: ParticipanteDeParticipacion[];
  automatic_message_count: number;
  unanswered_inbound_count: number;
  pairs: ParDeParticipacion[];
}

/** A single agent's participation on a ticket */
export interface ParticipanteDeParticipacion {
  agent_id: number;
  agent_name: string | null;
  message_count: number;
  sample_count: number;
  avg_delay_seconds: number | null;
}

/** A single burst -> reply pair on a ticket (null fields = unanswered run) */
export interface ParDeParticipacion {
  run_start_at: string;
  reply_at: string | null;
  agent_id: number | null;
  delay_seconds: number | null;
}
