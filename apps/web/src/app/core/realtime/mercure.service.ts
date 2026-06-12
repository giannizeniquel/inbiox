import { Injectable, signal, inject } from '@angular/core';
import { BehaviorSubject, Subject, Observable } from 'rxjs';
import { RealtimeEvent, RealtimeEventType, assertNever,
  MessageAddedPayload, TicketCreatedPayload, TicketStatusChangedPayload,
  TicketAssignedPayload, MessageUpdatedPayload } from '../models/realtime-event.model';
import { environment } from '../../../environments/environment';
import { TicketStore } from '../stores/ticket.store';
import { ConversationStore, BridgeMessage } from '../stores/conversation.store';
import { TicketStatus } from '../models/ticket.model';
import { ConnectionStore } from '../stores/connection.store';
import { UiStore } from '../stores/ui.store';
import { MetricsService } from '../metrics/metrics.service';

interface SeqState {
  lastSeq: number;
}

type ConnectionState = 'connected' | 'disconnected' | 'connecting';

@Injectable({ providedIn: 'root' })
export class MercureService {
  private eventSource: EventSource | null = null;

  // Connection state tracking
  private reconnectAttempts = 0;
  isConnected = false;

  // Idempotency: track processed event IDs to avoid duplicates
  private readonly processedEventIds = new Set<string>();

  // Seq tracking per ticket
  private readonly seqState = new Map<string, SeqState>();

  // Inject stores for incremental updates
  private ticketStore = inject(TicketStore);
  private conversationStore = inject(ConversationStore);
  private connectionStore = inject(ConnectionStore);
  private uiStore = inject(UiStore);
  private metrics = inject(MetricsService);

  // Public observables
  private readonly connectionStatus = new BehaviorSubject<boolean>(false);
  private readonly eventsSubject = new Subject<RealtimeEvent>();

  readonly isConnected$: Observable<boolean> = this.connectionStatus.asObservable();
  readonly events$: Observable<RealtimeEvent> = this.eventsSubject.asObservable();

  // Signals for debug panel
  readonly reconnectCount = signal(0);
  readonly connectionState = signal<ConnectionState>('disconnected');
  readonly lastEventTime = signal<string | null>(null);

  private reconnectTimeoutId: ReturnType<typeof setTimeout> | null = null;

  connect(): void {
    if (this.eventSource) return;

    this.connectionState.set('connecting');
    // Subscribe to wildcard to receive all ticket events
    const hubUrl = `${environment.mercureUrl}/.well-known/mercure?topic=ticket/*`;
    console.log('[Mercure] Connecting to:', hubUrl);

    try {
      this.eventSource = new EventSource(hubUrl, {
        withCredentials: true
      });

      this.eventSource.onopen = () => {
        this.isConnected = true;
        this.reconnectAttempts = 0;
        this.connectionStatus.next(true);
        this.connectionState.set('connected');
        this.connectionStore.setOnline();
        console.log('[Mercure] Connected to hub');
        this.reconnectCount.set(0);
      };

      this.eventSource.onmessage = (event: MessageEvent) => {
        this.handleMessage(event);
      };

      this.eventSource.onerror = (err) => {
        this.onError(err);
      };
    } catch (err) {
      console.error('[Mercure] Failed to connect:', err);
      this.handleError();
    }
  }

  /**
   * @deprecated Subscribe is a no-op — superseded by events$. All realtime
   * dispatch goes through handleEvent() (single path). Kept for binary
   * compatibility until InboxService refactor (T-16).
   */
  subscribe(ticketId: string, _callback: (event: unknown) => void): () => void {
    // Initialize seq state for this ticket if not exists
    if (!this.seqState.has(ticketId)) {
      this.seqState.set(ticketId, { lastSeq: 0 });
    }
    return () => { /* no-op */ };
  }

  private handleMessage(event: MessageEvent): void {
    try {
      const data = JSON.parse(event.data) as RealtimeEvent;

      this.lastEventTime.set(new Date().toISOString());

      // Idempotency check using eventId
      if (data.eventId && this.processedEventIds.has(data.eventId)) {
        console.log('[Mercure] Duplicate eventId ignored:', data.eventId);
        return;
      }

      // Track eventId for idempotency before applying
      if (data.eventId) {
        this.processedEventIds.add(data.eventId);
        // Limit memory: keep only last 1000 IDs
        if (this.processedEventIds.size > 1000) {
          const first = this.processedEventIds.values().next().value;
          if (first) this.processedEventIds.delete(first);
        }
      }

      // Seq validation
      const ticketId = data.ticketId ?? '';
      if (!ticketId) {
        console.warn('[Mercure] Event without ticketId, dropping');
        return;
      }

      const currentSeq = this.seqState.get(ticketId)?.lastSeq ?? 0;

      if (data.sequence > currentSeq) {
        // Valid new event - apply
        this.updateSeq(ticketId, data.sequence);

        // Track metrics for valid events
        this.metrics.increment('messagesReceived');

        // Single dispatch path — no applyStorePatch, no subscriber callbacks
        this.handleEvent(data);

        // Emit to global events stream
        this.eventsSubject.next(data);
        console.log('[Mercure] Applied event seq:', data.sequence, 'for ticket:', ticketId);
      } else if (data.sequence === currentSeq) {
        // Duplicate - ignore
        console.log('[Mercure] Duplicate seq ignored:', data.sequence);
      } else {
        // Old event - ignore
        console.log('[Mercure] Old seq ignored:', data.sequence, 'current:', currentSeq);
      }
    } catch (err) {
      console.error('[Mercure] Failed to parse message:', err);
    }
  }

  /**
   * Handle incoming realtime events and dispatch to stores.
   * Single dispatch path — strict switch over RealtimeEventType.
   * No legacy TicketEventType, no nested `event.event` access.
   */
  private handleEvent(event: RealtimeEvent): void {
    switch (event.eventType) {
      case RealtimeEventType.MessageAdded: {
        const p = event.payload as MessageAddedPayload;
        const message: BridgeMessage = {
          id: String(p.messageId),
          tempId: undefined,
          ticketId: String(p.ticketId),
          sender: p.direction === 'outbound' ? 'agent' : 'customer',
          content: p.content,
          createdAt: p.createdAt,
          created_at: p.createdAt,
          deliveryStatus: 'sent',
          externalId: p.externalId ?? undefined,
          message_id: p.messageId,
          direction: p.direction,
          chatId: String(p.ticketId),
          body: p.content,
          sentAt: p.createdAt,
        };
        this.conversationStore.addMessage(message);
        this.uiStore.notifyNewMessages();

        // Increment unread if not active ticket
        const activeTicket = this.ticketStore.activeTicket();
        if (!activeTicket || activeTicket.ticket_id !== String(p.ticketId)) {
          const current = this.ticketStore.tickets().find(t => t.ticket_id === String(p.ticketId));
          if (current) {
            this.ticketStore.updateUnreadCount(String(p.ticketId), (current.metrics?.unread_count ?? 0) + 1);
          }
        }
        break;
      }

      case RealtimeEventType.TicketCreated: {
        const p = event.payload as TicketCreatedPayload;
        this.ticketStore.addOrUpdateTicket({
          ticket_id: String(p.ticketId),
          id: String(p.ticketId),
          status: p.status as TicketStatus,
          priority: String(p.priority),
          contact: null,
          last_message: null,
          owner: null,
          metrics: { unread_count: 0 },
          timestamps: { opened_at: p.createdAt, updated_at: p.createdAt },
        });
        break;
      }

      case RealtimeEventType.TicketStatusChanged: {
        const p = event.payload as TicketStatusChangedPayload;
        const existing = this.ticketStore.tickets().find(t => t.ticket_id === String(p.ticketId));
        if (existing) {
          this.ticketStore.addOrUpdateTicket({
            ...existing,
            status: p.newStatus as TicketStatus,
            timestamps: { ...existing.timestamps, updated_at: p.changedAt },
          });
        }
        break;
      }

      case RealtimeEventType.TicketAssigned: {
        const p = event.payload as TicketAssignedPayload;
        const existing = this.ticketStore.tickets().find(t => t.ticket_id === String(p.ticketId));
        if (existing) {
          this.ticketStore.addOrUpdateTicket({
            ...existing,
            owner: { id: p.assigneeId, name: `Agent #${p.assigneeId}` },
            timestamps: { ...existing.timestamps, updated_at: p.assignedAt },
          });
        }
        break;
      }

      case RealtimeEventType.MessageUpdated: {
        const p = event.payload as MessageUpdatedPayload;
        this.conversationStore.updateMessageStatus(
          String(p.messageId),
          (p.status ?? 'read') as BridgeMessage['deliveryStatus'],
          p.messageId
        );
        break;
      }

      case RealtimeEventType.ConversationUpdated:
        // Metadata only; no-op for now
        break;

      default:
        assertNever(event.eventType);
    }

    // Increment event counter
    this.connectionStore.incrementEventCount();
  }

  private updateSeq(ticketId: string, seq: number): void {
    const state = this.seqState.get(ticketId) || { lastSeq: 0 };
    state.lastSeq = seq;
    this.seqState.set(ticketId, state);
  }

  private handleError(): void {
    this.connectionStatus.next(false);
    this.connectionState.set('disconnected');
    this.connectionStore.setOffline();
    this.eventSource?.close();
    this.eventSource = null;

    // Auto-reconnect with exponential backoff
    const attempt = this.reconnectCount() + 1;
    const delay = Math.min(1000 * Math.pow(2, attempt - 1), 30000);

    console.log(`[Mercure] Reconnecting in ${delay}ms (attempt ${attempt})`);
    this.reconnectCount.set(attempt);
    this.connectionState.set('connecting');
    this.connectionStore.setReconnecting();

    this.reconnectTimeoutId = setTimeout(() => {
      this.connect();
    }, delay);
  }

  /**
   * Handle connection errors and track reconnection attempts.
   */
  private onError(err: any): void {
    this.isConnected = false;
    this.reconnectAttempts++;
    this.metrics.increment('reconnectAttempts');
    console.warn(`[Mercure] Disconnected (attempt ${this.reconnectAttempts})`);
    this.handleError();
  }

  disconnect(): void {
    if (this.reconnectTimeoutId) {
      clearTimeout(this.reconnectTimeoutId);
    }
    this.eventSource?.close();
    this.eventSource = null;
    this.seqState.clear();
    this.processedEventIds.clear();
    this.connectionStatus.next(false);
    this.connectionState.set('disconnected');
    this.connectionStore.setOffline();
  }

  getSeqForTicket(ticketId: string): number {
    return this.seqState.get(String(ticketId))?.lastSeq ?? 0;
  }
}