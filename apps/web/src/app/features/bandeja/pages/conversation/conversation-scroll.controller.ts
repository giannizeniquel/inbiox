import { Injectable, Signal, computed, inject, signal } from '@angular/core';
import { CdkVirtualScrollViewport } from '@angular/cdk/scrolling';
import { take } from 'rxjs/operators';
import { ConversationStore } from '../../../../data-access/conversacion/conversation.store';
import { TicketStore } from '../../../../data-access/ticket/ticket.store';
import { MessageApiService, GetMessagesResponse } from '../../../../data-access/conversacion/message-api.service';
import { mapApiMessagesToCanonical } from '../../../../data-access/conversacion/message-mapper.util';

/**
 * Scroll state machine for the conversation viewport.
 *
 * - `idle`: no scroll operation in progress; pagination may trigger on user scroll.
 * - `loading-older`: fetching the previous page from the API.
 * - `restoring-anchor`: adjusting scroll to re-anchor the message the user was
 *   looking at after prepending the new page.
 * - `programmatic-scroll`: a scroll triggered by code (scroll-to-bottom, initial
 *   scroll), not by the user. Pagination must not trigger from these.
 */
type ScrollState = 'idle' | 'loading-older' | 'restoring-anchor' | 'programmatic-scroll';

/**
 * A visual anchor for scroll restoration: the message the user is looking at
 * and its offset from the top of the viewport. Restoring by this anchor (rather
 * than by total scrollHeight) keeps the same content in the same visual place
 * regardless of how the prepended messages change the global geometry.
 */
interface ScrollAnchor {
  messageId: string;
  offsetFromViewportTop: number;
}

/** Threshold (px) from the top at which scroll-up pagination triggers. */
const PAGINATION_THRESHOLD = 200;

/**
 * Scroll, pagination and "new messages" state of the conversation viewport.
 *
 * Provided by ConversationComponent, which keeps the effects that call these
 * methods, so their order on a ticket switch stays the component's.
 */
@Injectable()
export class ConversationScrollController {
  private readonly conversationStore = inject(ConversationStore);
  private readonly ticketStore = inject(TicketStore);
  private readonly messageApi = inject(MessageApiService);

  private vps: Signal<CdkVirtualScrollViewport | undefined> = signal(undefined);

  readonly isAtBottom = signal(true);
  readonly newMessagesCount = signal(0);

  /** Auto-scroll to bottom once per ticket (tracked flag reset on ticket switch) */
  private initialLoadDone = false;

  /**
   * How many older messages have been loaded from the API (offset tracking).
   * 0 = initial load returned the most recent messages.
   */
  private readonly oldestLoadedOffset = signal(0);

  /** Scroll state machine (see ScrollState type). */
  private readonly scrollState = signal<ScrollState>('idle');

  /**
   * Hysteresis gate for pagination. Disarmed after restoring the anchor so that
   * the programmatic scroll-back does not immediately re-trigger pagination;
   * re-armed once the user scrolls back down past the threshold.
   */
  private readonly paginationArmed = signal(true);

  /** Spinner is visible during the whole older-messages load + anchor restore. */
  readonly loadingOlder = computed(() =>
    this.scrollState() === 'loading-older' || this.scrollState() === 'restoring-anchor'
  );

  /** Bind the component's viewport query. */
  attach(vps: Signal<CdkVirtualScrollViewport | undefined>): void {
    this.vps = vps;
  }

  /** Back to the initial state for a newly selected ticket. Writes only, so it adds no effect dependency. */
  reset(): void {
    this.oldestLoadedOffset.set(0);
    this.initialLoadDone = false;
    this.scrollState.set('idle');
    this.paginationArmed.set(true);
    this.isAtBottom.set(false);
    this.newMessagesCount.set(0);
  }

  /** Scroll to the bottom once per ticket, after the first load renders the viewport. */
  runInitialScroll(): void {
    const vp = this.vps();
    const isLoading = this.conversationStore.loading();
    if (!vp || isLoading || this.initialLoadDone) return;

    this.initialLoadDone = true;
    if (this.conversationStore.messages().length > 0) {
      requestAnimationFrame(() => {
        this.scrollToBottom();
        // Autosize measures items asynchronously and images load, shifting the
        // true bottom. Re-scroll on progressive delays until heights settle.
        [150, 400, 800].forEach((delay) => {
          setTimeout(() => this.scrollToBottom(), delay);
        });
      });
    } else {
      // Empty conversation — nothing to scroll, already "at bottom"
      this.isAtBottom.set(true);
    }
  }

  /**
   * Handle a scroll event. Only triggers pagination on genuine user scroll in
   * the idle state; programmatic scrolls (restore, scroll-to-bottom) are
   * ignored by the state machine.
   */
  handleScroll(vp: CdkVirtualScrollViewport): void {
    const top = vp.measureScrollOffset('top');

    // Hysteresis: re-arm pagination once the user scrolls back down past the
    // threshold, so a restore that leaves us near the top does not loop.
    if (top >= PAGINATION_THRESHOLD) {
      this.paginationArmed.set(true);
    }

    if (
      this.scrollState() === 'idle'
      && this.paginationArmed()
      && top < PAGINATION_THRESHOLD
      && this.conversationStore.hasMore()
    ) {
      this.loadOlderMessages(vp);
    }

    const bottom = vp.measureScrollOffset('bottom');
    this.isAtBottom.set(bottom === 0);
    if (bottom === 0) {
      this.newMessagesCount.set(0);
    }
  }

  scrollToBottom(): void {
    const vp = this.vps();
    if (!vp) return;
    this.scrollState.set('programmatic-scroll');
    vp.scrollTo({ bottom: 0, behavior: 'auto' });
    this.isAtBottom.set(true);
    this.newMessagesCount.set(0);
    // Re-arm once the programmatic scroll has settled (next frame).
    requestAnimationFrame(() => {
      if (this.scrollState() === 'programmatic-scroll') {
        this.scrollState.set('idle');
      }
    });
  }

  /**
   * Called when new messages arrive (e.g., via Mercure SSE).
   * If user is not at bottom, show the "new messages" indicator.
   */
  notifyNewMessages(): void {
    const vp = this.vps();
    if (!vp) return;
    if (vp.measureScrollOffset('bottom') === 0) {
      vp.scrollTo({ bottom: 0, behavior: 'auto' });
      this.isAtBottom.set(true);
      this.newMessagesCount.set(0);
    } else {
      this.isAtBottom.set(false);
      this.newMessagesCount.update((c) => c + 1);
    }
  }

  /**
   * Load older messages from the API and prepend them to the store.
   * Preserves the user's visual context by capturing a scroll anchor (the first
   * visible message + its offset) and restoring it after the new page renders.
   */
  private loadOlderMessages(vp: CdkVirtualScrollViewport): void {
    const ticket = this.ticketStore.activeTicket();
    if (!ticket) return;

    this.scrollState.set('loading-older');
    this.paginationArmed.set(false);
    const anchor = this.captureAnchor(vp);

    this.messageApi.getMessages(ticket.ticket_id, 20, this.oldestLoadedOffset()).pipe(take(1)).subscribe({
      next: (resp: GetMessagesResponse) => {
        const added = mapApiMessagesToCanonical(resp, ticket.ticket_id);
        this.conversationStore.prependMessages(added);
        this.conversationStore.setHasMore(resp.meta.has_more);
        this.oldestLoadedOffset.set(this.oldestLoadedOffset() + resp.data.length);

        if (anchor) {
          this.scrollState.set('restoring-anchor');
          // Two rAFs: let Angular render the prepended block, then restore the
          // anchor's visual position deterministically.
          requestAnimationFrame(() => {
            requestAnimationFrame(() => {
              this.restoreAnchor(vp, anchor);
              this.scrollState.set('idle');
            });
          });
        } else {
          this.scrollState.set('idle');
        }
      },
      error: () => {
        this.scrollState.set('idle');
      },
    });
  }

  /**
   * Capture the first visible message as a scroll anchor: its persistent id and
   * its offset from the top of the viewport.
   */
  private captureAnchor(vp: CdkVirtualScrollViewport): ScrollAnchor | null {
    const el = vp.elementRef.nativeElement;
    const viewportTop = el.getBoundingClientRect().top;

    const bubbles = el.querySelectorAll<HTMLElement>('app-message-bubble');
    for (const bubble of Array.from(bubbles)) {
      const rect = bubble.getBoundingClientRect();
      // First bubble whose top edge is at or below the viewport top.
      if (rect.top >= viewportTop) {
        const messageId = bubble.getAttribute('data-message-id');
        if (messageId) {
          return { messageId, offsetFromViewportTop: rect.top - viewportTop };
        }
      }
    }
    return null;
  }

  /**
   * Restore the anchor's visual position: locate the same message by id and
   * adjust the native scroll so it sits at the same offset from the viewport top
   * as before the prepend.
   */
  private restoreAnchor(vp: CdkVirtualScrollViewport, anchor: ScrollAnchor): void {
    const el = vp.elementRef.nativeElement;
    const bubble = el.querySelector<HTMLElement>(
      `app-message-bubble[data-message-id="${anchor.messageId}"]`
    );
    if (!bubble) return;

    const viewportTop = el.getBoundingClientRect().top;
    const currentOffset = bubble.getBoundingClientRect().top - viewportTop;
    el.scrollTop += currentOffset - anchor.offsetFromViewportTop;
  }
}
