import { Injectable, signal } from '@angular/core';

/**
 * ConnectionStore — manages realtime connection status.
 */
@Injectable({ providedIn: 'root' })
export class ConnectionStore {
  readonly status = signal<'online' | 'reconnecting' | 'offline'>('offline');

  /** Counter for processed realtime events (T2.3 idempotency verification) */
  readonly eventCount = signal(0);

  setOnline(): void {
    this.status.set('online');
  }

  setReconnecting(): void {
    this.status.set('reconnecting');
  }

  setOffline(): void {
    this.status.set('offline');
  }

  /** Increment event counter on each event processed */
  incrementEventCount(): void {
    this.eventCount.update(c => c + 1);
  }
}