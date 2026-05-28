import { Injectable, signal } from '@angular/core';

/**
 * Metrics keys for tracking application events.
 */
export type MetricKey = 'messagesReceived' | 'reconnectAttempts' | 'failedMessages' | 'apiRetries';

/**
 * Application metrics service for observability.
 * Tracks key performance indicators for realtime and API operations.
 */
@Injectable({ providedIn: 'root' })
export class MetricsService {
  private metrics = signal({
    messagesReceived: 0,
    reconnectAttempts: 0,
    failedMessages: 0,
    apiRetries: 0,
  });

  /**
   * Increment a specific metric counter.
   */
  increment(key: MetricKey): void {
    this.metrics.update((m) => ({
      ...m,
      [key]: m[key] + 1,
    }));
  }

  /**
   * Get all current metrics.
   */
  getAll() {
    return { ...this.metrics() };
  }

  /**
   * Get a specific metric value.
   */
  get(key: MetricKey): number {
    return this.metrics()[key];
  }

  /**
   * Reset all metrics to zero.
   */
  reset(): void {
    this.metrics.set({
      messagesReceived: 0,
      reconnectAttempts: 0,
      failedMessages: 0,
      apiRetries: 0,
    });
  }

  /**
   * Get metrics as readonly signal for template binding.
   */
  readonly metrics$ = this.metrics.asReadonly();
}