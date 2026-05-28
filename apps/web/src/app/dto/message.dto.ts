/**
 * Message DTO — Data Transfer Objects for message operations.
 *
 * Per spec: separates domain concerns from transport/API concerns.
 */

/**
 * Message creation input DTO.
 */
export interface CreateMessageDto {
  ticketId: string;
  content: string;
  sender?: 'agent' | 'customer' | 'system';
  tempId?: string; // Client-generated for optimistic UI
}

/**
 * Message output DTO (from provider/reconciliation).
 */
export interface MessageDto {
  id: string;
  tempId?: string;
  ticketId: string;
  sender: 'agent' | 'customer' | 'system';
  content: string;
  createdAt: string;
  deliveryStatus: 'sending' | 'sent' | 'delivered' | 'read' | 'failed';
  failedReason?: string;
  externalId?: string;
}

/**
 * Status update DTO.
 */
export interface StatusUpdateDto {
  providerId: string;
  status: 'sent' | 'delivered' | 'read' | 'failed';
  timestamp?: string;
  reason?: string;
}

/**
 * Inbound event DTO (from SSE/provider).
 */
export interface InboundEventDto {
  providerId: string;
  ticketId: string;
  sender: 'customer' | 'system';
  content: string;
  timestamp: string;
}

/**
 * Map ProviderInterface Message to MessageDto.
 */
export function toMessageDto(msg: {
  id: string;
  tempId?: string;
  ticketId: string;
  sender: 'agent' | 'customer' | 'system';
  content: string;
  createdAt: string;
  deliveryStatus: 'sending' | 'sent' | 'delivered' | 'read' | 'failed';
  failedReason?: string;
  externalId?: string;
}): MessageDto {
  return {
    id: msg.id,
    tempId: msg.tempId,
    ticketId: msg.ticketId,
    sender: msg.sender,
    content: msg.content,
    createdAt: msg.createdAt,
    deliveryStatus: msg.deliveryStatus,
    failedReason: msg.failedReason,
    externalId: msg.externalId,
  };
}