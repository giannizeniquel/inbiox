import { Injectable, signal, inject, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { RealtimeEvent } from '../models/realtime-event.model';
import { EventBusService } from '../realtime/event-bus.service';

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

  constructor() {
    this.initEventBus();
  }

  /**
   * Set up EventBus subscription for all realtime events.
   * Handles: ALL event types → incrementEventCount + setLastEventTime.
   * Lifecycle setters (setOnline, setOffline, etc.) remain direct calls
   * from MercureService (design decision D4).
   */
  private initEventBus(): void {
    try {
      const eventBus = inject(EventBusService);
      const destroyRef = inject(DestroyRef);

      eventBus.events$.pipe(
        takeUntilDestroyed(destroyRef),
      ).subscribe((event: RealtimeEvent) => {
        this.incrementEventCount();
        this.setLastEventTime(Date.now());
      });
    } catch {
      // Running outside DI context (e.g., unit tests)
    }
  }

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