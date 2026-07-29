/**
 * Timeline transport DTOs — stable contract between backend and frontend.
 */

export interface TimelineEventDTO {
  type: 'message.received' | 'message.sent' | 'ticket.created' | 'ticket.status_changed' | 'ticket.assigned' | 'ticket.reassigned';
  occurredAt: string;
  content?: string;
  ticketId?: number;
  ticketStatus?: string;
  assignedTo?: string;
  agentId?: number;
  direction?: 'inbound' | 'outbound';
}

export interface TimelineEventResponse {
  events: TimelineEventDTO[];
  cursor: string | null;
  hasMore: boolean;
}
