/**
 * Message — represents a single message within a chat.
 * Updated to match the new unified interface spec (sprint-11A).
 */
export interface Message {
  message_id: number;
  direction: 'inbound' | 'outbound' | 'system';
  content: string;
  created_at: string;
  // Unified spec fields
  id: string;
  chatId: string;
  body: string;
  deliveryStatus: 'sending' | 'sent' | 'delivered' | 'read' | 'failed';
  tempId?: string;      // optimistic / pending
  sentAt?: string;
  readAt?: string;
  ticketId: string;
}

export interface SendMessageRequest {
  content: string;
  tempId?: string;
}
