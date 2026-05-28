/**
 * Domain Contracts — Core business rules and interfaces.
 *
 * Per the minimal sprint, this contains:
 * - Provider boundary contracts
 * - FSM status validation
 * - Message delivery status FSM
 */

/**
 * Valid status transitions for message delivery.
 * FSM enforcement: prevents invalid transitions like read → delivered
 */
export const MESSAGE_STATUS_TRANSITIONS: Record<string, string[]> = {
  sending: ['sent', 'failed'],
  sent: ['delivered', 'failed'],
  delivered: ['read', 'failed'],
  read: [], // Terminal state
  failed: ['sending'], // Retry allowed
};

/**
 * Check if a status transition is valid.
 */
export function isValidStatusTransition(
  from: string,
  to: string
): boolean {
  const allowed = MESSAGE_STATUS_TRANSITIONS[from];
  return allowed?.includes(to) ?? false;
}

/**
 * Domain Message interface — provider-agnostic.
 * This is the canonical shape used by stores and components.
 */
export interface DomainMessage {
  id: string;
  tempId?: string;
  conversationId: string;
  content: string;
  direction: 'inbound' | 'outbound';
  status: 'pending' | 'sending' | 'sent' | 'delivered' | 'read' | 'failed';
  createdAt: Date;
  metadata?: Record<string, unknown>;
}

/**
 * Domain Events emitted by Application Layer.
 */
export type DomainEvent =
  | { type: 'MessageSent'; payload: { message: DomainMessage } }
  | { type: 'MessageStatusUpdated'; payload: { messageId: string; status: string } }
  | { type: 'InboundMessageReceived'; payload: { message: DomainMessage } }
  | { type: 'ConversationMarkedRead'; payload: { conversationId: string } };