import { Injectable, signal } from '@angular/core';

/**
 * ConnectionStore — manages realtime connection status.
 *
 * Status transitions:
 *   offline ↔ connecting → online → offline → reconnecting → ...
 *   reconnecting → online (success)
 *   reconnecting → permanent_failure (circuit breaker tripped)
 *   permanent_failure → online (manual retry via forceReconnect)
 */
@Injectable({ providedIn: 'root' })
export class ConnectionStore {
  readonly status = signal<'online' | 'reconnecting' | 'offline' | 'permanent_failure'>('offline');

  /** Counter for processed realtime events (T2.3 idempotency verification) */
  readonly eventCount = signal(0);

  /**
   * Timestamp of the last received event (ms since epoch).
   * Set by MercureService.handleMessage(). null if no event received yet.
   */
  readonly lastEventTime = signal<number | null>(null);

  setOnline(): void {
    this.status.set('online');
  }

  setReconnecting(): void {
    this.status.set('reconnecting');
  }

  setOffline(): void {
    this.status.set('offline');
  }

  /** Set permanent failure state — circuit breaker tripped, auto-retry stopped */
  setPermanentFailure(): void {
    this.status.set('permanent_failure');
  }

  /** Record the timestamp of the last received event */
  setLastEventTime(ts: number): void {
    this.lastEventTime.set(ts);
  }

  /** Increment event counter on each event processed */
  incrementEventCount(): void {
    this.eventCount.update(c => c + 1);
  }
}