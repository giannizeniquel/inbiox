import { Injectable, inject, NgZone } from '@angular/core';
import { EventoEnTiempoReal } from './evento-en-tiempo-real.model';
import { environment } from '../../../environments/environment';
import { ConnectionStore } from './connection.store';
import { MetricsService } from './metrics.service';
import { EventBusService } from './event-bus.service';

/** Max eventIds remembered for deduplication. */
const MAX_PROCESSED_EVENT_IDS = 1000;

@Injectable({ providedIn: 'root' })
export class MercureService {
  private abortController: AbortController | null = null;

  /** Consecutive failed connections; reset on a successful connect. */
  private reconnectAttempts = 0;

  // Idempotency: track processed event IDs to avoid duplicates
  private readonly processedEventIds = new Set<string>();

  private connectionStore = inject(ConnectionStore);
  private eventBus = inject(EventBusService);
  private metrics = inject(MetricsService);
  private ngZone = inject(NgZone);

  /** Mercure subscriber JWT, set by AuthStore after login or token refresh. */
  private mercureToken: string | null = null;

  /** Max consecutive reconnect failures before circuit breaker trips */
  private readonly maxReconnectAttempts = 10;

  private reconnectTimeoutId: ReturnType<typeof setTimeout> | null = null;

  async connect(): Promise<void> {
    if (this.abortController) return;

    // Only fetch token if not already set (e.g., by AuthStore after login)
    if (!this.mercureToken) {
      await this.fetchMercureToken();
    }

    if (!this.mercureToken) {
      console.warn('[Mercure] No subscriber token available');
    }

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
        this.reconnectAttempts = 0;
        this.connectionStore.setOnline();

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
              this.ngZone.run(() => this.handleMessage(eventData));
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
        this.ngZone.run(() => this.onError());
      });
  }

  /** Parse one SSE payload, drop duplicates and events without a ticket, and emit the rest on the EventBus. */
  private handleMessage(raw: string): void {
    try {
      const data = JSON.parse(raw) as EventoEnTiempoReal;

      this.connectionStore.setLastEventTime(Date.now());

      if (data.eventId && this.processedEventIds.has(data.eventId)) {
        console.log('[Mercure] Duplicate eventId ignored:', data.eventId);
        return;
      }

      if (data.eventId) {
        this.processedEventIds.add(data.eventId);
        if (this.processedEventIds.size > MAX_PROCESSED_EVENT_IDS) {
          const first = this.processedEventIds.values().next().value;
          if (first) this.processedEventIds.delete(first);
        }
      }

      if (!data.ticketId) {
        console.warn('[Mercure] Event without ticketId, dropping');
        return;
      }

      this.metrics.increment('messagesReceived');
      this.eventBus.emit(data);
    } catch (err) {
      console.error('[Mercure] Failed to parse message:', err);
    }
  }

  /** Fallback when connect() runs before AuthStore set the token. */
  private async fetchMercureToken(): Promise<void> {
    try {
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

  /** Called by AuthStore after login or token refresh. */
  setMercureToken(token: string): void {
    this.mercureToken = token;
  }

  /** Count the failure, then reconnect with exponential backoff until the circuit breaker trips. */
  private onError(): void {
    this.reconnectAttempts++;
    this.metrics.increment('reconnectAttempts');
    console.warn(`[Mercure] Disconnected (attempt ${this.reconnectAttempts})`);

    this.connectionStore.setOffline();
    this.abortController?.abort();
    this.abortController = null;

    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      console.error(
        `[Mercure] Circuit breaker tripped after ${this.reconnectAttempts} consecutive failures. Auto-reconnect stopped.`
      );
      this.connectionStore.setPermanentFailure();
      return; // Do NOT auto-reconnect
    }

    const attempt = this.reconnectAttempts;
    const delay = Math.min(1000 * Math.pow(2, attempt - 1), 30000);

    console.log(`[Mercure] Reconnecting in ${delay}ms (attempt ${attempt})`);
    this.connectionStore.setReconnecting();

    this.reconnectTimeoutId = setTimeout(() => {
      this.connect();
    }, delay);
  }

  disconnect(): void {
    console.warn('[Mercure] disconnect() called — SSE connection terminated. This should only happen during logout.');

    if (this.reconnectTimeoutId) {
      clearTimeout(this.reconnectTimeoutId);
    }
    this.abortController?.abort();
    this.abortController = null;
    this.processedEventIds.clear();
    this.connectionStore.setOffline();
  }
}
