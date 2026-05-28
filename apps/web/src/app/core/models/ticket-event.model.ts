export enum TicketEventType {
  StatusChanged = 'status_changed',
  MessageAdded = 'message_added',
  Assigned = 'assigned',
  TagAdded = 'tag_added'
}

export interface TicketEventPayload {
  message?: unknown;
  ticket?: unknown;
}

export interface TicketEvent {
  type: TicketEventType;
  ticketId: string;
  payload?: TicketEventPayload;
  data?: unknown;
  seq: number;
  occurredAt: string;
}

export interface RealtimeEvent {
  ticketId: string;
  event: TicketEvent;
  seq: number;
}