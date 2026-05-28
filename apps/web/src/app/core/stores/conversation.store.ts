import { Injectable, signal } from '@angular/core';
import { Message } from '../models/message.model';

/**
 * Extended message type that supports both legacy and provider message shapes.
 * This bridge type is used internally to handle the transition.
 */
export interface BridgeMessage {
  // ProviderInterface Message fields
  id: string;
  tempId?: string;
  ticketId: string;
  sender?: 'agent' | 'customer' | 'system';
  content: string;
  createdAt?: string;
  created_at: string;
  deliveryStatus: 'sending' | 'sent' | 'read' | 'failed';
  externalId?: string;
  failedReason?: string;

  // Legacy Message fields
  message_id: number;
  direction: 'inbound' | 'outbound' | 'system';
  chatId: string;
  body: string;
  sentAt?: string;
  readAt?: string;
}

const MAX_MESSAGES = 200;

/**
 * ConversationStore — manages message list state for the active chat.
 *
 * Per spec section 3:
 * - messages: signal<Message[]>
 * - loading: signal<boolean>
 * - hasMore: signal<boolean> (pagination)
 * - Max 200 messages cap enforced (slice oldest)
 *
 * Supports both legacy Message model and ProviderInterface Message through BridgeMessage.
 * Optimistic UI via tempId tracking.
 */
@Injectable({ providedIn: 'root' })
export class ConversationStore {
  /** Messages in the active conversation */
  readonly messages = signal<BridgeMessage[]>([]);

  /** Loading state (for initial load / pagination) */
  readonly loading = signal<boolean>(false);

  /** Whether there are more older messages to load (pagination) */
  readonly hasMore = signal<boolean>(true);

  /**
   * Message cache for retry operations.
   * Stores original payloads by tempId.
   */
  private messageCache = new Map<string, { content: string; ticketId: string }>();

  /** Add a message (optimistic or from server), enforcing 200 cap */
  addMessage(message: BridgeMessage): void {
    this.messages.update(msgs => {
      const next = [...msgs, message];
      return next.length > MAX_MESSAGES ? next.slice(next.length - MAX_MESSAGES) : next;
    });
  }

  /**
   * Add an optimistic (pending) message — identified by tempId.
   * Used for optimistic UI when sending.
   *
   * @param tempId - Temporary ID for the optimistic message
   * @param content - Message content
   * @param chatId - Ticket/chat ID
   * @returns The created optimistic message
   */
  addOptimisticMessage(tempId: string, content: string, chatId: string): BridgeMessage {
    const optimistic: BridgeMessage = {
      message_id: 0,
      id: tempId,
      chatId,
      direction: 'outbound',
      content,
      body: content,
      created_at: new Date().toISOString(),
      deliveryStatus: 'sending',
      tempId,
      sentAt: new Date().toISOString(),
      ticketId: chatId,
    };
    this.addMessage(optimistic);

    // Cache for retry
    this.messageCache.set(tempId, { content, ticketId: chatId });

    return optimistic;
  }

  /**
   * Update delivery status of a message (by id or tempId).
   * Supports both legacy and provider message identification.
   */
  updateMessageStatus(
    idOrTempId: string,
    status: BridgeMessage['deliveryStatus'],
    messageId?: number
  ): void {
    this.messages.update(msgs =>
      msgs.map(m => {
        const matchId = m.id === idOrTempId || (m.tempId ?? m.id) === idOrTempId;
        if (matchId) {
          return { ...m, deliveryStatus: status, message_id: messageId ?? m.message_id };
        }
        return m;
      })
    );
  }

  /**
   * Replace an optimistic message with the server-confirmed one.
   * Reconciles tempId to provider-assigned id.
   */
  confirmMessage(tempId: string, confirmed: BridgeMessage): void {
    this.messages.update(msgs =>
      msgs.map(m => {
        if (m.tempId === tempId) {
          // Reconciliation: keep tempId but merge confirmed data
          return {
            ...confirmed,
            tempId, // Keep tempId for tracking
            message_id: confirmed.message_id || m.message_id,
            deliveryStatus: confirmed.deliveryStatus || m.deliveryStatus,
          };
        }
        return m;
      })
    );
    // Clear from cache on confirmation
    this.messageCache.delete(tempId);
  }

  /**
   * Remove a failed message by id.
   */
  removeMessage(idOrTempId: string): void {
    this.messages.update(msgs =>
      msgs.filter(m => m.id !== idOrTempId && (m.tempId ?? m.id) !== idOrTempId)
    );
    this.messageCache.delete(idOrTempId);
  }

  /**
   * Retry a failed message by resetting its status to 'sending'.
   * Returns the cached payload for retry.
   */
  retryMessage(tempId: string): { content: string; ticketId: string } | null {
    const cached = this.messageCache.get(tempId);
    if (cached) {
      this.updateMessageStatus(tempId, 'sending');
      return cached;
    }
    return null;
  }

  /**
   * Get cached message payload by tempId.
   */
  getCachedMessage(tempId: string): { content: string; ticketId: string } | undefined {
    return this.messageCache.get(tempId);
  }

  /**
   * Clear cached message payload.
   */
  clearCache(tempId: string): void {
    this.messageCache.delete(tempId);
  }

  /**
   * Check if a message exists by tempId.
   */
  hasMessage(tempId: string): boolean {
    return this.messages().some(m => m.tempId === tempId);
  }

  /**
   * Load messages for a chat (initial load or pagination).
   * Merges legacy and provider message formats.
   */
  loadMessages(messages: BridgeMessage[], append = false): void {
    const incoming = append
      ? [...this.messages(), ...messages]
      : messages;
    this.messages.set(incoming.length > MAX_MESSAGES ? incoming.slice(-MAX_MESSAGES) : incoming);
  }

  /** Set loading state */
  setLoading(loading: boolean): void {
    this.loading.set(loading);
  }

  /** Set hasMore for pagination */
  setHasMore(hasMore: boolean): void {
    this.hasMore.set(hasMore);
  }

  /**
   * Find message by tempId or id.
   */
  findById(idOrTempId: string): BridgeMessage | undefined {
    return this.messages().find(m => m.id === idOrTempId || (m.tempId ?? m.id) === idOrTempId);
  }

  /** Reset store (clears messages, loading, hasMore, cache) */
  reset(): void {
    this.messages.set([]);
    this.loading.set(false);
    this.hasMore.set(true);
    this.messageCache.clear();
  }
}