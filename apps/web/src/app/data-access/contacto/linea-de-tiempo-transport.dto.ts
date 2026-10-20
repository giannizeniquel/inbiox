/**
 * Timeline transport DTOs — stable contract between backend and frontend.
 */

export interface EventoDeLineaDeTiempoDTO {
  type: 'message.received' | 'message.sent' | 'ticket.created' | 'ticket.status_changed' | 'ticket.assigned' | 'ticket.reassigned';
  occurredAt: string;
  content?: string;
  ticketId?: number;
  ticketStatus?: string;
  assignedTo?: string;
  agentId?: number;
  direction?: 'entrante' | 'saliente';
}

export interface EventoDeLineaDeTiempoResponse {
  events: EventoDeLineaDeTiempoDTO[];
  cursor: string | null;
  hasMore: boolean;
}
