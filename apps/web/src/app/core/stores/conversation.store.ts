import { Injectable, signal, inject, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { filter } from 'rxjs';
import { Message } from '../models/message.model';
import { RealtimeEvent, RealtimeEventType,
  MessageAddedPayload, MessageUpdatedPayload } from '../models/realtime-event.model';
import { EventBusService } from '../realtime/event-bus.service';

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
 * Optimistic UI via tempId tracking.
 */
@Injectable({ providedIn: 'root' })
export class ConversationStore {
  /** Messages in the active conversation */
  readonly messages = signal<Message[]>([]);

  /** Loading state (for initial load / pagination) */
  readonly loading = signal<boolean>(false);

  /** Whether there are more older messages to load (pagination) */
  readonly hasMore = signal<boolean>(true);

  constructor() {
    this.initEventBus();
  }

  /**
   * Set up EventBus subscription for realtime events.
   * Handles: MessageAdded, MessageUpdated.
   */
  private initEventBus(): void {
    try {
      const eventBus = inject(EventBusService);
      const destroyRef = inject(DestroyRef);

      eventBus.events$.pipe(
        filter(e =>
          e.eventType === RealtimeEventType.MessageAdded
          || e.eventType === RealtimeEventType.MessageUpdated
        ),
        takeUntilDestroyed(destroyRef),
      ).subscribe(e => this.onRealtimeEvent(e));
    } catch {
      // Running outside DI context (e.g., unit tests)
    }
  }

  /**
   * Handle realtime events dispatched via EventBus.
   * Logic mirrors MercureService.handleEvent() for conversation-related types.
   */
  private onRealtimeEvent(event: RealtimeEvent): void {
    switch (event.eventType) {
      case RealtimeEventType.MessageAdded: {
        const p = event.payload as MessageAddedPayload;
        const message: Message = {
          id: String(p.messageId),
          ticketId: String(p.ticketId),
          content: p.content,
          direction: p.direction,
          sender: p.direction === 'outbound' ? 'agent' : 'customer',
          deliveryStatus: 'sent',
          createdAt: p.createdAt,
          externalId: p.externalId ?? undefined,
        };
        this.addMessage(message);
        break;
      }

      case RealtimeEventType.MessageUpdated: {
        const p = event.payload as MessageUpdatedPayload;
        this.updateMessageStatus(
          String(p.messageId),
          (p.status ?? 'read') as Message['deliveryStatus'],
        );
        break;
      }
    }
  }

  /**
   * Message cache for retry operations.
   * Stores original payloads by tempId.
   */
  private messageCache = new Map<string, { content: string; ticketId: string }>();

  /** Add a message (optimistic or from server), enforcing 200 cap */
  addMessage(message: Message): void {
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
   * @param ticketId - Ticket ID
   * @returns The created optimistic message
   */
  addOptimisticMessage(tempId: string, content: string, ticketId: string): Message {
    const optimistic: Message = {
      id: tempId,
      tempId,
      ticketId,
      content,
      direction: 'outbound',
      sender: 'agent',
      deliveryStatus: 'sending',
      createdAt: new Date().toISOString(),
    };
    this.addMessage(optimistic);

    // Cache for retry
    this.messageCache.set(tempId, { content, ticketId });

    return optimistic;
  }

  /**
   * Update delivery status of a message (by id or tempId).
   */
  updateMessageStatus(
    idOrTempId: string,
    status: Message['deliveryStatus'],
  ): void {
    this.messages.update(msgs =>
      msgs.map(m => {
        const matchId = m.id === idOrTempId || (m.tempId ?? m.id) === idOrTempId;
        if (matchId) {
          return { ...m, deliveryStatus: status };
        }
        return m;
      })
    );
  }

  /**
   * Replace an optimistic message with the server-confirmed one.
   * Reconciles tempId to provider-assigned id.
   */
  confirmMessage(tempId: string, confirmed: Message): void {
    this.messages.update(msgs =>
      msgs.map(m => {
        if (m.tempId === tempId) {
          // Reconciliation: keep tempId but merge confirmed data
          return {
            ...confirmed,
            tempId, // Keep tempId for tracking
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
   */
  loadMessages(messages: Message[], append = false): void {
    if (append) {
      const existingIds = new Set(this.messages().map(m => m.id));
      const newMessages = messages.filter(m => !existingIds.has(m.id));
      this.messages.update(msgs => [...msgs, ...newMessages]);
    } else {
      this.messages.set(messages.length > MAX_MESSAGES ? messages.slice(-MAX_MESSAGES) : messages);
    }
  }

  /**
   * Prepend older messages at the start of the array.
   * Used for scroll-up pagination — adds older entries before current ones.
   * Caps at MAX_MESSAGES, trimming from the newest (end).
   */
  prependMessages(messages: Message[]): void {
    this.messages.update(msgs => {
      const existingIds = new Set(msgs.map(m => m.id));
      const newMessages = messages.filter(m => !existingIds.has(m.id));
      const next = [...newMessages, ...msgs];
      return next.length > MAX_MESSAGES ? next.slice(0, MAX_MESSAGES) : next;
    });
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
  findById(idOrTempId: string): Message | undefined {
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
