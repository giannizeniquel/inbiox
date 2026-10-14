import { Injectable, signal, inject, NgZone } from '@angular/core';
import { BehaviorSubject, Observable } from 'rxjs';
import { RealtimeEvent } from './realtime-event.model';
import { environment } from '../../../environments/environment';
import { ConnectionStore } from './connection.store';
import { MetricsService } from './metrics.service';
import { EventBusService } from './event-bus.service';

/** Response shape from the resync endpoint */
interface ResyncResponse {
  events: RealtimeEvent[];
  lastSequence: number;
  hasMore: boolean;
}

/**
 * Per-ticket sequence state with state machine.
 *
 * States: connected → gap_detected → resyncing → connected
 * Each ticket is independent — a gap on T1 does NOT block T2.
 */
interface TicketSeqState {
  lastSeq: number;
  state: 'connected' | 'gap_detected' | 'resyncing';
  inFlightResync?: AbortController;
}

type ConnectionState = 'connected' | 'disconnected' | 'connecting';

@Injectable({ providedIn: 'root' })
export class MercureService {
  private abortController: AbortController | null = null;

  // Connection state tracking
  private reconnectAttempts = 0;
  isConnected = false;

  // Idempotency: track processed event IDs to avoid duplicates
  private readonly processedEventIds = new Set<string>();

  // Seq tracking per ticket — state machine with per-ticket isolation
  private readonly seqState = new Map<string, TicketSeqState>();

  /** Events buffered during resync for a ticket (gap-triggering + subsequent SSE events) */
  private readonly bufferedEvents = new Map<string, RealtimeEvent[]>();

  // Inject stores for incremental updates
  private connectionStore = inject(ConnectionStore);
  private eventBus = inject(EventBusService);
  private metrics = inject(MetricsService);
  private ngZone = inject(NgZone);

  /** Mercure JWT token for authenticated HTTP calls (resync, etc.) */
  private mercureToken: string | null = null;

  /** Max consecutive reconnect failures before circuit breaker trips */
  private readonly maxReconnectAttempts = 10;

  // Public observables
  private readonly connectionStatus = new BehaviorSubject<boolean>(false);

  readonly isConnected$: Observable<boolean> = this.connectionStatus.asObservable();

  // Signals for debug panel
  readonly reconnectCount = signal(0);
  readonly connectionState = signal<ConnectionState>('disconnected');
  readonly lastEventTime = signal<string | null>(null);

  private reconnectTimeoutId: ReturnType<typeof setTimeout> | null = null;

  async connect(): Promise<void> {
    if (this.abortController) return;

    // Only fetch token if not already set (e.g., by AuthStore after login)
    if (!this.mercureToken) {
      await this.fetchMercureToken();
    }

    // If still no token, log warning but proceed (SSE connection may still work)
    if (!this.mercureToken) {
      console.warn('[Mercure] No subscriber token available — resync will be unavailable');
    }

    this.connectionState.set('connecting');
    const hubUrl = `${environment.mercureUrl}/.well-known/mercure?topic=tickets`;
    console.log('[Mercure] Connecting to:', hubUrl);

    this.abortController = new AbortController();

    fetch(hubUrl, {
      signal: this.abortController.signal,
      headers: { 'Accept': 'text/event-stream' },
    })
      .then(async (response) => {
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        if (!response.body) throw new Error('No response body');

        console.log('[Mercure] ✅ Connected to hub (fetch)');
        this.isConnected = true;
        this.reconnectAttempts = 0;
        this.connectionStatus.next(true);
        this.connectionState.set('connected');
        this.connectionStore.setOnline();
        this.reconnectCount.set(0);

        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';

        while (true) {
          const { done, value } = await reader.read();
          if (done) break;

          buffer += decoder.decode(value, { stream: true });

          const lines = buffer.split('\n');
          buffer = lines.pop() || '';

          for (const line of lines) {
            if (line.startsWith('data:')) {
              const eventData = line.slice(5).trim();
              if (!eventData) continue;
              this.ngZone.run(() => this.handleMessage({ data: eventData } as MessageEvent));
            }
          }
        }
      })
      .catch((error) => {
        if (error.name === 'AbortError') {
          console.log('[Mercure] Connection aborted');
          return;
        }
        console.error('[Mercure] Connection error:', error);
        this.ngZone.run(() => this.onError(error));
      });
  }

  /**
   * Transition a ticket's state to a new state.
   * Preserves all other fields in the seq entry.
   */
  private transitionTo(ticketId: string, nextState: TicketSeqState['state']): void {
    const cur = this.seqState.get(ticketId) ?? { lastSeq: -1, state: 'connected' as const };
    this.seqState.set(ticketId, { ...cur, state: nextState });
  }

  /**
   * Apply an event to stores and update sequence tracking.
   * Shared between SSE path and resync path.
   */
  private applyEvent(ticketId: string, data: RealtimeEvent): void {
    // Update seq state
    const state = this.seqState.get(ticketId) ?? { lastSeq: -1, state: 'connected' as const };
    state.lastSeq = data.sequence;
    this.seqState.set(ticketId, state);

    // Track metrics
    this.metrics.increment('messagesReceived');

    // Single dispatch path via EventBus
    this.eventBus.emit(data);
  }

  /**
   * Process buffered events for a ticket after resync completes.
   * Events are sorted by sequence and applied in order.
   * If a new gap is found (seq gap after some events were applied),
   * triggers another resync for the remaining events.
   */
  private processBufferedEvents(ticketId: string): void {
    const buf = this.bufferedEvents.get(ticketId);
    if (!buf || buf.length === 0) return;

    // Sort by sequence (should already be in order from SSE, but be safe)
    buf.sort((a, b) => a.sequence - b.sequence);

    const state = this.seqState.get(ticketId);
    if (!state) {
      this.bufferedEvents.delete(ticketId);
      return;
    }

    const remaining: RealtimeEvent[] = [];

    for (const event of buf) {
      const expectedSeq = state.lastSeq + 1;

      if (event.sequence === expectedSeq) {
        // Exact match — apply
        this.applyEvent(ticketId, event);
      } else if (event.sequence > expectedSeq) {
        // Gap in buffered events — defer remaining for another resync
        remaining.push(event);
      }
      // else: seq < expectedSeq → already applied, drop
    }

    if (remaining.length > 0) {
      // Store remaining events and trigger another resync
      this.bufferedEvents.set(ticketId, remaining);

      // Cancel existing in-flight resync
      if (state.inFlightResync) {
        state.inFlightResync.abort();
      }

      const abortController = new AbortController();
      state.inFlightResync = abortController;
      this.transitionTo(ticketId, 'resyncing');

      this.resyncTicket(ticketId, abortController.signal)
        .then(() => {
          this.processBufferedEvents(ticketId);
          this.transitionTo(ticketId, 'connected');
        })
        .catch((err) => {
          if (err instanceof DOMException && err.name === 'AbortError') return;
          console.warn(`[Mercure] Chained resync failed for ticket ${ticketId}:`, err);
          const is4xx = typeof err?.message === 'string' && /Resync HTTP 4\d\d/.test(err.message);
          this.transitionTo(ticketId, is4xx ? 'connected' : 'gap_detected');
        });
    } else {
      this.bufferedEvents.delete(ticketId);
    }
  }

  /**
   * Handle an incoming SSE message.
   * Per-ticket gap detection state machine:
   *   - seq == expectedSeq: apply normally, stay connected
   *   - seq > expectedSeq: gap detected, trigger resync, buffer event
   *   - seq < expectedSeq: drop (already processed)
   *   - during resync: buffer all events for this ticket
   */
  private handleMessage(event: MessageEvent): void {
    try {
      const data = JSON.parse(event.data) as RealtimeEvent;

      this.lastEventTime.set(new Date().toISOString());
      this.connectionStore.setLastEventTime(Date.now());

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

      // Get ticketId — events without one cannot be processed
      const ticketId = data.ticketId ?? '';
      if (!ticketId) {
        console.warn('[Mercure] Event without ticketId, dropping');
        return;
      }

      // Initialize seq state if this is the first event for this ticket
      if (!this.seqState.has(ticketId)) {
        this.seqState.set(ticketId, { lastSeq: -1, state: 'connected' });
      }

      const seqEntry = this.seqState.get(ticketId)!;

      // ── State: resyncing ──
      // During resync, buffer all SSE events for this ticket.
      // Other tickets continue processing normally (per-ticket isolation).
      if (seqEntry.state === 'resyncing') {
        const buf = this.bufferedEvents.get(ticketId) ?? [];
        buf.push(data);
        this.bufferedEvents.set(ticketId, buf);
        return;
      }

      // ── State: connected ──
      // Apply events directly — sequences are global (not per-ticket), 
      // so per-ticket gap detection with global sequences is unreliable.
      // Idempotency is already handled via eventId dedup above.
      this.applyEvent(ticketId, data);
    } catch (err) {
      console.error('[Mercure] Failed to parse message:', err);
    }
  }

  /**
   * Set the Mercure JWT token for authenticated HTTP requests.
   * Called by the auth system after login or token refresh.
   */
  private async fetchMercureToken(): Promise<void> {
    try {
      // Try to get the access token from the global window (set by AuthStore)
      // This is a fallback for the legacy direct-connect path
      const headers: Record<string, string> = { 'Accept': 'application/json' };
      const response = await fetch(`${environment.apiUrl}/api/mercure/token`, { headers });
      if (response.ok) {
        const data = await response.json();
        this.mercureToken = data.token;
      }
    } catch (err) {
      console.warn('[Mercure] Failed to get subscriber token:', err);
    }
  }

  setMercureToken(token: string): void {
    this.mercureToken = token;
  }

  /**
   * Resync missed events for a specific ticket via the backend resync endpoint.
   * Fetches events after the last known sequence and applies them in order.
   * Handles pagination if hasMore is true.
   *
   * Accepts an optional AbortSignal to cancel in-flight HTTP requests
   * when a newer gap is detected or the connection is closed.
   * Uses fetch() directly (not HttpClient) for AbortSignal compatibility.
   */
  async resyncTicket(ticketId: string, signal?: AbortSignal): Promise<void> {
    const seqEntry = this.seqState.get(ticketId);
    if (!seqEntry) return;

    const afterSequence = seqEntry.lastSeq;

    const headers: Record<string, string> = {
      'Accept': 'application/json',
    };
    if (this.mercureToken) {
      headers['Authorization'] = `Bearer ${this.mercureToken}`;
    }

    const response = await fetch(
      `${environment.apiUrl}/api/tickets/${ticketId}/events?afterSequence=${afterSequence}`,
      { signal, headers }
    );

    if (!response.ok) {
      throw new Error(`Resync HTTP ${response.status}`);
    }

    const data: ResyncResponse = await response.json();

    for (const event of data.events) {
      if (signal?.aborted) return;

      if (event.eventId && this.processedEventIds.has(event.eventId)) continue;
      if (event.eventId) this.processedEventIds.add(event.eventId);

      this.applyEvent(ticketId, event);
    }

    const state = this.seqState.get(ticketId);
    if (state && data.lastSequence > state.lastSeq) {
      state.lastSeq = data.lastSequence;
      this.seqState.set(ticketId, state);
    }

    if (data.hasMore && !signal?.aborted) {
      await this.resyncTicket(ticketId, signal);
    }
  }

  /**
   * Resync all tracked tickets.
   * Called on SSE reconnect (onopen) to recover missed events.
   * Each ticket resyncs independently — failure on one does not block others.
   * Sets each ticket to resyncing state and attaches AbortController.
   */
  resyncAllTickets(): void {
    for (const [ticketId, state] of this.seqState) {
      if (state.state === 'connected' || state.state === 'gap_detected') {
        const abortController = new AbortController();
        state.inFlightResync = abortController;
        this.transitionTo(ticketId, 'resyncing');

        this.resyncTicket(ticketId, abortController.signal)
          .then(() => {
            this.processBufferedEvents(ticketId);
            this.transitionTo(ticketId, 'connected');
          })
          .catch((err) => {
            if (err instanceof DOMException && err.name === 'AbortError') return;
            console.warn(`[Mercure] Resync failed for ticket ${ticketId}:`, err);
            const is4xx = typeof err?.message === 'string' && /Resync HTTP 4\d\d/.test(err.message);
            this.transitionTo(ticketId, is4xx ? 'connected' : 'gap_detected');
          });
      }
    }
  }

  private handleError(): void {
    this.connectionStatus.next(false);
    this.connectionState.set('disconnected');
    this.connectionStore.setOffline();
    this.abortController?.abort();
    this.abortController = null;

    // Circuit breaker: after maxReconnectAttempts consecutive failures, stop auto-retry
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      console.error(
        `[Mercure] Circuit breaker tripped after ${this.reconnectAttempts} consecutive failures. Auto-reconnect stopped.`
      );
      this.connectionState.set('disconnected');
      this.connectionStore.setPermanentFailure();
      return; // Do NOT auto-reconnect
    }

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
    console.warn('[Mercure] disconnect() called — SSE connection terminated. This should only happen during logout.');
    // Cancel all in-flight resyncs
    for (const [, state] of this.seqState) {
      if (state.inFlightResync) {
        state.inFlightResync.abort();
      }
    }

    if (this.reconnectTimeoutId) {
      clearTimeout(this.reconnectTimeoutId);
    }
    this.abortController?.abort();
    this.abortController = null;
    this.seqState.clear();
    this.bufferedEvents.clear();
    this.processedEventIds.clear();
    this.connectionStatus.next(false);
    this.connectionState.set('disconnected');
    this.connectionStore.setOffline();
  }

  getSeqForTicket(ticketId: string): number {
    return this.seqState.get(String(ticketId))?.lastSeq ?? -1;
  }
}