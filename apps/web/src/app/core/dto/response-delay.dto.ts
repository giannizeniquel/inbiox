/**
 * Shared types for the Agent Response Delay Metric & Ticket Participation
 * Report feature.
 *
 * Lives in core/dto/ (not features/reports/models/) because the inbox
 * feature's TicketParticipationComponent also needs these types — putting
 * them in features/reports/ would be the exact core→features layer
 * violation that `core/dto/reports.params.ts` was created to fix.
 */

/** Backend response for GET /api/reports/response-delay */
export interface ResponseDelayReport {
  data_available_from: string | null;
  partial_data: boolean;
  aggregate: ResponseDelayAggregate;
  per_agent: ResponseDelayAgentEntry[];
}

/** Aggregate response-delay figures across all agents in range */
export interface ResponseDelayAggregate {
  avg_delay_seconds: number | null;
  sample_count: number;
  unanswered_inbound_count: number;
}

/** Per-agent response-delay entry */
export interface ResponseDelayAgentEntry {
  agent_id: number;
  agent_name: string | null;
  avg_delay_seconds: number | null;
  sample_count: number;
}

/** Backend response for GET /api/reports/tickets/{id}/participation */
export interface TicketParticipation {
  ticket_id: number;
  data_available_from: string | null;
  partial_data: boolean;
  participants: ParticipationParticipant[];
  automatic_message_count: number;
  unanswered_inbound_count: number;
  pairs: ParticipationPair[];
}

/** A single agent's participation on a ticket */
export interface ParticipationParticipant {
  agent_id: number;
  agent_name: string | null;
  message_count: number;
  sample_count: number;
  avg_delay_seconds: number | null;
}

/** A single burst -> reply pair on a ticket (null fields = unanswered run) */
export interface ParticipationPair {
  run_start_at: string;
  reply_at: string | null;
  agent_id: number | null;
  delay_seconds: number | null;
}
