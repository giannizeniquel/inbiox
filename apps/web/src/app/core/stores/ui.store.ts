import { Injectable, signal } from '@angular/core';

/**
 * UiStore — manages UI state for inbox scrolling and notifications.
 */
@Injectable({ providedIn: 'root' })
export class UiStore {
  readonly hasNewMessages = signal<boolean>(false);
  readonly isAtBottom = signal<boolean>(true);

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