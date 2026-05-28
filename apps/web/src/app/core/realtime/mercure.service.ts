import { Injectable, signal, inject } from '@angular/core';
import { BehaviorSubject, Subject, Observable } from 'rxjs';
import { RealtimeEvent, TicketEvent, TicketEventType } from '../models/ticket-event.model';
import { environment } from '../../../environments/environment';
import { TicketStore } from '../stores/ticket.store';
import { ConversationStore } from '../stores/conversation.store';
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
  private readonly subscriptions = new Map<string, (event: TicketEvent) => void>();

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

  subscribe(ticketId: string, callback: (event: TicketEvent) => void): () => void {
    this.subscriptions.set(ticketId, callback);

    // Initialize seq state for this ticket if not exists
    if (!this.seqState.has(ticketId)) {
      this.seqState.set(ticketId, { lastSeq: 0 });
    }

    // Return unsubscribe function
    return () => {
      this.subscriptions.delete(ticketId);
    };
  }

  private handleMessage(event: MessageEvent): void {
    try {
      const data = JSON.parse(event.data) as RealtimeEvent;

      this.lastEventTime.set(new Date().toISOString());

      // Idempotency check using eventId (if provided by backend)
      const eventId = (data as any).eventId;
      if (eventId && this.processedEventIds.has(eventId)) {
        console.log('[Mercure] Duplicate eventId ignored:', eventId);
        return;
      }

      // Seq validation
      const ticketId = String(data.ticketId);
      const currentSeq = this.seqState.get(ticketId)?.lastSeq ?? 0;

      if (data.seq > currentSeq) {
        // Valid new event - apply
        this.updateSeq(ticketId, data.seq);

        // Track metrics for valid events
        this.metrics.increment('messagesReceived');

        // Track eventId for idempotency
        if (eventId) {
          this.processedEventIds.add(eventId);
          // Limit memory: keep only last 1000 IDs
          if (this.processedEventIds.size > 1000) {
            const first = this.processedEventIds.values().next().value;
            if (first) this.processedEventIds.delete(first);
          }
        }

        // Apply incremental store patches
        this.applyStorePatch(ticketId, data.event);

        // Handle event dispatch to stores (T2.2)
        this.handleEvent(data);

        // Emit to ticket-specific subscribers
        const callback = this.subscriptions.get(ticketId);
        if (callback) {
          callback(data.event);
        }

        // Also emit to global events stream
        this.eventsSubject.next(data);
        console.log('[Mercure] Applied event seq:', data.seq, 'for ticket:', ticketId);
      } else if (data.seq === currentSeq) {
        // Duplicate - ignore
        console.log('[Mercure] Duplicate seq ignored:', data.seq);
      } else {
        // Old event - ignore
        console.log('[Mercure] Old seq ignored:', data.seq, 'current:', currentSeq);
      }
    } catch (err) {
      console.error('[Mercure] Failed to parse message:', err);
    }
  }

  /**
   * Apply incremental patches to stores based on incoming events.
   * This enables real-time UI updates without full refetch.
   */
  private applyStorePatch(ticketId: string, event: TicketEvent): void {
    switch (event.type) {
      case TicketEventType.MessageAdded:
        // Handle new message event -> ConversationStore.appendMessage()
        if (event.payload?.message) {
          this.conversationStore.addMessage(event.payload.message as any);
          // Notify UiStore for auto-scroll / new messages indicator
          this.uiStore.notifyNewMessages();
        }
        // Increment unread if not active ticket
        const activeTicket = this.ticketStore.activeTicket();
        if (!activeTicket || activeTicket.ticket_id !== ticketId) {
          const current = this.ticketStore.tickets().find(t => t.ticket_id === ticketId);
          if (current) {
            const currentUnread = current.metrics?.unread_count ?? 0;
            this.ticketStore.updateUnreadCount(ticketId, currentUnread + 1);
          }
        }
        break;

      case TicketEventType.Assigned:
        // Handle ticket assignment event -> TicketStore.addOrUpdateTicket()
        if (event.payload?.ticket) {
          this.ticketStore.addOrUpdateTicket(event.payload.ticket as any);
        }
        break;

      case TicketEventType.StatusChanged:
        // Handle status update -> update ticket
        if (event.payload?.ticket) {
          this.ticketStore.addOrUpdateTicket(event.payload.ticket as any);
        }
        break;

      default:
        break;
    }
  }

  /**
   * Handle incoming realtime events and dispatch to stores.
   * This is the main entry point for event-driven store updates.
   */
  private handleEvent(event: RealtimeEvent): void {
    const ticketId = String(event.ticketId);

    switch (event.event?.type) {
      case TicketEventType.MessageAdded:
        // New message event -> ConversationStore.addMessage()
        if (event.event?.payload?.message) {
          this.conversationStore.addMessage(event.event.payload.message as any);
        }
        break;

      case TicketEventType.StatusChanged:
        // Status update -> update ticket
        if (event.event?.payload?.ticket) {
          this.ticketStore.addOrUpdateTicket(event.event.payload.ticket as any);
        }
        break;

      case TicketEventType.Assigned:
        // Assignment event -> update ticket
        if (event.event?.payload?.ticket) {
          this.ticketStore.addOrUpdateTicket(event.event.payload.ticket as any);
        }
        break;

      default:
        console.log('[Mercure] Unknown event type:', event.event?.type);
        break;
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
    this.subscriptions.clear();
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