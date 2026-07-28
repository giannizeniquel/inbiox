import { Message, MessageDto } from '../models/message.model';
import { GetMessagesResponse } from '../api/message-api.service';

/**
 * Maps a backend MessageDto to the canonical Message format used by ConversationStore.
 *
 * Used by both TicketListComponent (initial load) and ConversationComponent (pagination).
 * Centralized here to avoid duplicated inline mapping.
 */
export function mapApiMessageToCanonical(
  m: MessageDto,
  ticketId: string
): Message {
  const direction = m.direction as 'inbound' | 'outbound' | 'system';
  return {
    id: String(m.message_id),
    ticketId,
    content: m.content,
    direction,
    sender: direction === 'inbound' ? 'customer' : 'agent',
    deliveryStatus: (m.deliveryStatus || 'sent') as Message['deliveryStatus'],
    createdAt: m.created_at || new Date().toISOString(),
  };
}

/**
 * Extracts messages from a GetMessagesResponse and maps them to canonical Message.
 */
export function mapApiMessagesToCanonical(
  resp: GetMessagesResponse,
  ticketId: string
): Message[] {
  return resp.data.map((m) => mapApiMessageToCanonical(m, ticketId));
}
