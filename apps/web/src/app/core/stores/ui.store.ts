import { Injectable, signal, inject, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { filter } from 'rxjs';
import { RealtimeEvent, RealtimeEventType } from '../models/realtime-event.model';
import { EventBusService } from '../realtime/event-bus.service';

/**
 * UiStore — manages UI state for inbox scrolling and notifications.
 */
@Injectable({ providedIn: 'root' })
export class UiStore {
  readonly hasNewMessages = signal<boolean>(false);
  readonly isAtBottom = signal<boolean>(true);

  constructor() {
    this.initEventBus();
  }

  /**
   * Set up EventBus subscription for realtime events.
   * Handles: MessageAdded → notifyNewMessages.
   */
  private initEventBus(): void {
    try {
      const eventBus = inject(EventBusService);
      const destroyRef = inject(DestroyRef);

      eventBus.events$.pipe(
        filter(e => e.eventType === RealtimeEventType.MessageAdded),
        takeUntilDestroyed(destroyRef),
      ).subscribe(() => this.notifyNewMessages());
    } catch {
      // Running outside DI context (e.g., unit tests)
    }
  }

  notifyNewMessages(): void {
    this.hasNewMessages.set(true);
  }

  scrollToBottom(): void {
    this.isAtBottom.set(true);
    this.hasNewMessages.set(false);
  }

  markViewed(): void {
    this.hasNewMessages.set(false);
  }

  setIsAtBottom(value: boolean): void {
    this.isAtBottom.set(value);
    if (value) {
      this.hasNewMessages.set(false);
    }
  }
}