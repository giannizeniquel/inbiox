import { MediaAttachment } from './media.model';

/**
 * Message — canonical message model used throughout the frontend.
 * Single source of truth for message shape in stores, components, and services.
 */
export interface Message {
  /** Provider-assigned ID, or tempId for optimistic messages */
  id: string;
  /** Temporary ID for optimistic UI (present until server confirms) */
  tempId?: string;
  /** Ticket this message belongs to */
  ticketId: string;
  /** Message text content */
  content: string;
  /** Message direction relative to the support team */
  direction: 'inbound' | 'outbound' | 'system';
  /** Who sent this message */
  sender: 'agent' | 'customer' | 'system';
  /** Delivery lifecycle status */
  deliveryStatus: 'sending' | 'sent' | 'delivered' | 'read' | 'failed';
  /** ISO 8601 timestamp */
  createdAt: string;
  /** External provider message ID (e.g. WhatsApp message ID) */
  externalId?: string;
  /** Reason for delivery failure (when deliveryStatus === 'failed') */
  failedReason?: string;
  /** Optional media attachment */
  media?: MediaAttachment;
}

/**
 * MessageDto — shape returned by the REST API.
 * Mapped to canonical Message by message-mapper.util.
 */
export interface MessageDto {
  message_id: number;
  direction: 'inbound' | 'outbound' | 'system';
  content: string;
  created_at: string;
  deliveryStatus?: string;
  media?: {
    key: string;
    mimeType: string;
    filename: string;
    size: number;
    kind: 'image' | 'video' | 'audio' | 'document';
    providerId?: string;
    caption?: string;
  };
}

export interface SendMessageRequest {
  content: string;
  tempId?: string;
}
