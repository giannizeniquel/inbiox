import { Observable } from 'rxjs';
import { InjectionToken } from '@angular/core';

/**
 * FSM Status types for ticket workflow.
 */
export type FsmStatus = 'new' | 'active' | 'waiting' | 'resolved' | 'closed';

/**
 * Message delivery status.
 */
export type DeliveryStatus = 'sending' | 'sent' | 'delivered' | 'read' | 'failed';

/**
 * Message sender type.
 */
export type MessageSender = 'agent' | 'customer' | 'system';

/**
 * SLA status for tickets.
 */
export type SlaStatus = 'ok' | 'warn' | 'breach';

/**
 * Message interface for the provider layer.
 */
export interface Message {
  id: string;
  tempId?: string;
  ticketId: string;
  sender: MessageSender;
  content: string;
  createdAt: string;
  deliveryStatus: DeliveryStatus;
  failedReason?: string;
  externalId?: string;
}

/**
 * Ticket interface for the provider layer.
 */
export interface Ticket {
  id: string;
  externalId?: string;
  contactName: string;
  unreadCount: number;
  lastMessage: string;
  lastMessageAt: string;
  isAutomated: boolean;
  slaStatus: SlaStatus;
  fsmStatus: FsmStatus;
}

/**
 * Configuration options for providers.
 */
export interface ProviderConfig {
  responseDelay: number;
  failureRate: number;
}

/**
 * Callback for inbound message simulation (SSE integration).
 */
export type InboundCallback = (message: Message) => void;

/**
 * Provider Interface - defines the contract for all channel providers.
 * This abstraction allows the application to work with any messaging channel
 * (WhatsApp, Telegram, etc.) through a unified interface.
 */
export interface ProviderInterface {
  /**
   * Send a message to a ticket.
   *
   * @param ticketId - The ticket ID to send the message to
   * @param content - The message content
   * @returns Observable that emits the sent message with deliveryStatus
   *
   * @remarks
   * The returned Message should include a delivery callback expectation.
   * Providers should update the message's deliveryStatus to reflect:
   * - 'sending' - message is being sent
   * - 'sent' - message delivered to channel API
   * - 'read' - recipient has read (if supported by channel)
   * - 'failed' - message failed to send (include failedReason)
   *
   * For Meta-ready implementations (WhatsApp Business API):
   * - Use webhooks to update deliveryStatus from 'sending' → 'sent' → 'read'
   * - Map WhatsApp status callbacks to these states
   */
  sendMessage(ticketId: string, content: string): Observable<Message>;

  /**
   * Send a template message to a ticket.
   *
   * Used for out-of-window conversations (outside the 24h customer service window).
   * WhatsApp requires templates for initiating conversations beyond the 24h window.
   *
   * @param ticketId - The ticket ID to send the template to
   * @param templateName - The template name (e.g. "hello_world")
   * @param language - Language code (e.g. "en_US", "es_AR")
   * @param parameters - Optional body text parameters (plain strings)
   * @returns Observable that emits the sent message with deliveryStatus
   */
  sendTemplate(ticketId: string, templateName: string, language: string, parameters: string[]): Observable<Message>;

  /**
   * Get all messages for a specific ticket.
   * @param ticketId - The ticket ID
   * @returns Observable that emits an array of messages
   */
  getMessages(ticketId: string): Observable<Message[]>;

  /**
   * Get a specific ticket by ID.
   * @param id - The ticket ID
   * @returns Observable that emits the ticket
   */
  getTicket(id: string): Observable<Ticket>;

  /**
   * Get all tickets.
   * @returns Observable that emits an array of tickets
   */
  getTickets(): Observable<Ticket[]>;

  /**
   * Update the FSM status of a ticket.
   * @param ticketId - The ticket ID
   * @param status - The new FSM status
   * @returns Observable that emits the updated ticket
   */
  updateTicketStatus(ticketId: string, status: FsmStatus): Observable<Ticket>;

  /**
   * Register a callback for inbound messages (for SSE integration).
   * @param callback - Function to handle incoming messages
   *
   * @remarks
   * Connection expectations:
   * - The provider should maintain a persistent connection to the channel (e.g., WhatsApp webhooks)
   * - On connection failure, implement exponential backoff reconnection (max 5 attempts, 30s max delay)
   * - Emit connection status via the callback or a separate status observable
   */
  onInboundMessage(callback: InboundCallback): void;

  /**
   * Get the delivery status of a specific message.
   *
   * @param messageId - The message ID to check
   * @returns Observable that emits the current delivery status
   *
   * @remarks
   * Required states that providers must support:
   * - 'sending' - Message is in the process of being sent
   * - 'sent' - Message was successfully sent to the channel
   * - 'delivered' - Message was delivered to the recipient (channel dependent)
   * - 'read' - Recipient has read the message (WhatsApp supports this)
   * - 'failed' - Message failed to send (provider should set failedReason)
   *
   * For Meta-ready implementations:
   * - Poll WhatsApp Status Callback webhooks
   * - Map WhatsApp statuses: 'sent' → 'delivered' → 'read'
   * - Handle 'failed' with specific error codes from Meta API
   */
  getMessageStatus(messageId: string): Observable<DeliveryStatus>;

  /**
   * Disconnect from the provider's channel.
   *
   * @remarks
   * Expected behavior:
   * - Close all active connections (webhooks, SSE, etc.)
   * - Clear any pending message queues
   * - Reset connection state to disconnected
   * - Provider should emit final status update before closing
   */
  disconnect(): void;

  /**
   * Reconnect to the provider's channel after a disconnect.
   *
   * @remarks
   * Expected behavior:
   * - Attempt reconnection with exponential backoff
   * - Re-subscribe to any previously active subscriptions
   * - Resume any interrupted message delivery
   * - Emit reconnection status via callbacks
   */
  connect(): void;

  // ──────────────────────────────────────────────
  //  Media Support (CAP-8 — Sprint 13.4)
  // ──────────────────────────────────────────────

  /**
   * Upload a media file to the messaging channel.
   *
   * @param file - The file blob to upload
   * @param mimeType - MIME type of the file (e.g. "image/jpeg")
   * @returns Observable emitting the uploaded media ID
   */
  uploadMedia(file: Blob, mimeType: string): Observable<{mediaId: string}>;

  /**
   * Download a media file by its media ID.
   *
   * @param mediaId - The media ID to download
   * @returns Observable emitting the file as a Blob
   */
  downloadMedia(mediaId: string): Observable<Blob>;

  /**
   * Send a message with a media attachment.
   *
   * @param ticketId - The ticket ID to send the message to
   * @param mediaId - The media ID from uploadMedia
   * @param mimeType - MIME type of the media file
   * @param caption - Optional caption text for the media
   * @param mediaKey - Optional object-storage key (persisted for download/preview)
   * @param filename - Original filename
   * @param size - File size in bytes
   * @returns Observable emitting the sent message
   */
  sendMediaMessage(
    ticketId: string,
    mediaId: string,
    mimeType: string,
    caption?: string,
    mediaKey?: string,
    filename?: string,
    size?: number,
  ): Observable<Message>;
}

/**
 * Provider configuration injection token.
 * Note: This is defined as an alias - the actual token is exported from app.config.ts
 * to ensure it's a proper Angular InjectionToken.
 */
export const PROVIDER_CONFIG = new InjectionToken<ProviderConfig>('PROVIDER_CONFIG');

/**
 * Provider Interface injection token.
 * Allows the application to inject ProviderInterface implementations
 * (e.g., FakeWhatsappProvider, WhatsAppProvider) via the DI container.
 */
export const PROVIDER_INTERFACE = new InjectionToken<ProviderInterface>('PROVIDER_INTERFACE');