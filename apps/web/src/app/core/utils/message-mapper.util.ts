import { BridgeMessage } from '../stores/conversation.store';
import { GetMessagesResponse } from '../api/message-api.service';
import { Message } from '../models/message.model';

/**
 * Maps a backend Message DTO to the BridgeMessage format used by ConversationStore.
 *
 * Used by both TicketListComponent (initial load) and ConversationComponent (pagination).
 * Centralized here to avoid duplicated inline mapping.
 */
export function mapApiMessageToBridge(
  m: Message,
  ticketId: string
): BridgeMessage {
  return {
    id: String(m.message_id),
    message_id: m.message_id,
    ticketId,
    chatId: ticketId,
    content: m.content,
    body: m.content,
    direction: m.direction as 'inbound' | 'outbound' | 'system',
    deliveryStatus: (m.deliveryStatus || 'sent') as 'sent',
    created_at: m.created_at || new Date().toISOString(),
    sender: (m.direction === 'inbound' ? 'customer' : 'agent') as 'agent' | 'customer',
  };
}

/**
 * Extracts messages from a GetMessagesResponse and maps them.
 */
export function mapApiMessagesToBridge(
  resp: GetMessagesResponse,
  ticketId: string
): BridgeMessage[] {
  return resp.data.map((m) => mapApiMessageToBridge(m, ticketId));
}
