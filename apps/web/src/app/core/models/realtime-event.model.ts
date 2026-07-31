/**
 * RealtimeEventContract v1 — TypeScript mirror of the PHP backend contract.
 *
 * This enum and interfaces are a 1:1 mapping of the PHP RealtimeEventType
 * (backed string enum) and the wire JSON shape produced by RealtimeEventFactory.
 *
 * No field transformations, no adapters, no `as any` casts — the frontend
 * matches the backend wire format exactly.
 */

// ──────────────────────────────────────────────
// Enum — must match PHP App\Application\Realtime\RealtimeEventType exactly
// ──────────────────────────────────────────────

export enum RealtimeEventType {
  TicketCreated = 'ticket.created',
  TicketStatusChanged = 'ticket.status_changed',
  TicketAssigned = 'ticket.assigned',
  MessageAdded = 'message.added',
  MessageUpdated = 'message.updated',
  ConversationUpdated = 'conversation.updated',
  TicketRead = 'ticket.read',
}

// ──────────────────────────────────────────────
// Generic RealtimeEvent interface — matches wire JSON shape
// ──────────────────────────────────────────────

export interface RealtimeEvent<T = unknown> {
  eventId: string;
  sequence: number;
  timestamp: string;
  eventType: RealtimeEventType;
  ticketId?: string;
  conversationId?: string;
  payload: T;
}

// ──────────────────────────────────────────────
// Typed payload interfaces per event type
// ──────────────────────────────────────────────

/** Payload for `message.added` events */
export interface MessageAddedPayload {
  messageId: number;
  ticketId: number;
  content: string;
  direction: 'inbound' | 'outbound';
  senderName: string | null;
  createdAt: string;
  externalId: string | null;
}

/** Payload for `ticket.created` events */
export interface TicketCreatedPayload {
  ticketId: number;
  contactId: number;
  conversationId: number;
  status: string;
  priority: number;
  createdAt: string;
}

/** Payload for `ticket.status_changed` events */
export interface TicketStatusChangedPayload {
  ticketId: number;
  previousStatus: string | null;
  newStatus: string;
  changedAt: string;
}

/** Payload for `ticket.assigned` events */
export interface TicketAssignedPayload {
  ticketId: number;
  assigneeId: number;
  assignedAt: string;
}

/** Payload for `message.updated` events */
export interface MessageUpdatedPayload {
  messageId: number;
  ticketId: number;
  status: string | null;
  failedReason: string | null;
  updatedAt: string;
}

/** Payload for `conversation.updated` events */
export interface ConversationUpdatedPayload {
  conversationId: number;
  ticketId: number;
  lastMessageAt: string | null;
}

// ──────────────────────────────────────────────
// Utility: compile-time exhaustiveness check
// ──────────────────────────────────────────────

/**
 * Assert never — use as the `default` branch in a strict switch over
 * RealtimeEventType. If a new value is added to the enum but not handled,
 * TypeScript produces a compile-time error.
 *
 * Usage:
 *   switch (event.eventType) {
 *     case RealtimeEventType.MessageAdded: ... break;
 *     default: assertNever(event.eventType);
 *   }
 */
export function assertNever(value: never): never {
  throw new Error(`Unhandled RealtimeEventType case: ${value}`);
}
