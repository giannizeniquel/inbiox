import { Component, inject, signal, viewChild, computed, ChangeDetectionStrategy, input, output, effect } from '@angular/core';
import { HttpClient, HttpErrorResponse } from '@angular/common/http';
import { take } from 'rxjs/operators';
import { environment } from '../../../../../environments/environment';
import { ConversationStore } from '../../../../core/stores/conversation.store';
import { TicketStore } from '../../../../core/stores/ticket.store';
import { UiStore } from '../../../../core/stores/ui.store';
import { MessageApiService, GetMessagesResponse } from '../../../../core/api/message-api.service';
import { WorkflowApiService } from '../../../../core/api/workflow-api.service';
import { CdkVirtualScrollViewport, ScrollingModule } from '@angular/cdk/scrolling';
import { CdkAutoSizeVirtualScroll } from '@angular/cdk-experimental/scrolling';
import { MessageBubbleComponent } from '../../components/message-bubble/message-bubble.component';
import { ConversationHeaderComponent } from '../../components/conversation-header/conversation-header.component';
import { ComposerComponent } from '../../components/composer/composer.component';
import { ConversationEmptyComponent } from '../../components/conversation-empty/conversation-empty.component';
import { ConversationLoadingComponent } from '../../components/conversation-loading/conversation-loading.component';
import { NewMessagesIndicatorComponent } from '../../components/new-messages-indicator/new-messages-indicator.component';
import { BannerWarningComponent } from '../../../../shared/components/banner-warning/banner-warning.component';
import { LoadingSpinnerComponent } from '../../../../shared/components/loading-spinner/loading-spinner.component';
import { ConversationSkeletonComponent } from '../../components/conversation-skeleton/conversation-skeleton.component';
import { MessageSenderService } from '../../services/message-sender.service';
import { Message } from '../../../../core/models/message.model';
import { mapApiMessagesToCanonical } from '../../../../core/utils/message-mapper.util';

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
 * ConversationComponent — main conversation panel container.
 *
 * Per spec section 17:
 * - flex-grow, overflow-y: auto, min-height: 0
 * - Connects to ConversationStore (messages, loading) + TicketStore (activeTicket)
 * - Auto-scroll detection (per spec section 6)
 * - Renders: header + scrollable message list + composer + new-msg indicator
 * - BannerWarning for contextual notices (per spec section 17[D])
 */
@Component({
  selector: 'app-conversation-container',
  standalone: true,
  imports: [
    ScrollingModule,
    CdkAutoSizeVirtualScroll,
    ConversationHeaderComponent,
    MessageBubbleComponent,
    ComposerComponent,
    ConversationEmptyComponent,
    ConversationLoadingComponent,
    ConversationSkeletonComponent,
    NewMessagesIndicatorComponent,
    BannerWarningComponent,
    LoadingSpinnerComponent,
  ],
  templateUrl: './conversation.component.html',
  styleUrl: './conversation.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversationComponent {
  readonly vps = viewChild<CdkVirtualScrollViewport>('vps');

  protected readonly conversationStore = inject(ConversationStore);
  protected readonly ticketStore = inject(TicketStore);
  protected readonly uiStore = inject(UiStore);
  private readonly http = inject(HttpClient);
  private readonly messageSender = inject(MessageSenderService);
  private readonly messageApi = inject(MessageApiService);
  private readonly workflowApi = inject(WorkflowApiService);

  /** Contextual warning message (e.g. SLA breach, internal note) */
  protected readonly warningMessage = input<string | null>(null);

  /**
   * Feedback for a workflow action the agent just triggered (resolve).
   * Null while idle; set to a user-facing message on failure.
   */
  protected readonly actionMessage = signal<string | null>(null);

  /** True while a resolve request is in flight — keeps the action idempotent. */
  protected readonly resolving = signal(false);

  /** Emitted when user taps back button on mobile — closes conversation panel */
  protected readonly back = output<void>();

  protected readonly messages = this.conversationStore.messages;
  protected readonly loading = this.conversationStore.loading;
  protected readonly activeTicket = this.ticketStore.activeTicket;

  protected readonly isAtBottom = signal(true);
  protected readonly newMessagesCount = signal(0);

  trackById = (_: number, m: Message): string => m.tempId ?? m.id;

  // React to UiStore notifications from SSE events
  private readonly newMessagesEffect = effect(() => {
    if (this.uiStore.hasNewMessages()) {
      this.notifyNewMessages();
      this.uiStore.markViewed();
    }
  });

  /** Auto-scroll to bottom once per ticket (tracked flag reset on ticket switch) */
  private initialLoadDone = false;

  private readonly initialScrollEffect = effect(() => {
    const vp = this.vps();
    const isLoading = this.loading();
    if (!vp || isLoading || this.initialLoadDone) return;

    this.initialLoadDone = true;
    if (this.messages().length > 0) {
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
  });

  /** Reset pagination offset when switching to a different ticket */
  private readonly ticketResetEffect = effect(() => {
    void this.activeTicket();
    this.oldestLoadedOffset.set(0);
    this.initialLoadDone = false;
    this.scrollState.set('idle');
    this.paginationArmed.set(true);
    this.isAtBottom.set(false);
    this.newMessagesCount.set(0);
  });

  /** Mark ticket as read on the backend when the active ticket changes */
  private readonly markReadEffect = effect(() => {
    const ticket = this.activeTicket();
    if (ticket) {
      const ticketId = Number(ticket.ticket_id);
      if (!isNaN(ticketId)) {
        this.http.put(`${environment.apiUrl}/api/tickets/${ticketId}/mark-read`, {})
          .subscribe({
            error: () => { /* fire-and-forget */ },
          });
        // Note: no local markAsRead — badge clears via Mercure ticket.read event
        // which only fires when the assigned agent reads the conversation.
      }
    }
  });

  /**
   * Scroll listener. Uses effect cleanup to re-subscribe whenever the CDK
   * viewport is recreated (e.g. ticket switch) — no manual flag needed.
   */
  private readonly scrollSetupEffect = effect((onCleanup) => {
    const vp = this.vps();
    if (!vp) return;

    let rafPending = false;
    const sub = vp.elementScrolled().subscribe(() => {
      if (rafPending) return;
      rafPending = true;
      requestAnimationFrame(() => {
        rafPending = false;
        this.handleScroll(vp);
      });
    });
    onCleanup(() => sub.unsubscribe());
  });

  /**
   * Handle a scroll event. Only triggers pagination on genuine user scroll in
   * the idle state; programmatic scrolls (restore, scroll-to-bottom) are
   * ignored by the state machine.
   */
  private handleScroll(vp: CdkVirtualScrollViewport): void {
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
  protected readonly loadingOlder = computed(() =>
    this.scrollState() === 'loading-older' || this.scrollState() === 'restoring-anchor'
  );

  onRetry(tempId: string): void {
    const message = this.messages().find((m: Message) => m.tempId === tempId);
    if (message) {
      this.conversationStore.retryMessage(tempId);
    }
  }

  /**
   * Resolve the active ticket.
   *
   * Authorization lives on the backend (admin, supervisor, or current owner).
   * A 403 is an expected outcome here, not a bug, so it gets its own message
   * instead of a generic failure.
   */
  onResolveTicket(): void {
    const ticket = this.activeTicket();
    if (!ticket || this.resolving()) return;

    this.resolving.set(true);
    this.actionMessage.set(null);

    this.workflowApi
      .resolve(ticket.ticket_id)
      .pipe(take(1))
      .subscribe({
        next: () => {
          this.resolving.set(false);
          // No local store write on purpose: the backend emits
          // `ticket.status_changed`, which TicketStore already applies via the
          // Mercure pipeline. Patching here too would duplicate the update path
          // and break the project's event-driven realtime convention.
        },
        error: (err: HttpErrorResponse) => {
          this.resolving.set(false);
          this.actionMessage.set(
            err.status === 403
              ? 'No tenés permiso para resolver esta conversación. Solo puede hacerlo un administrador, un supervisor o el agente asignado.'
              : 'No se pudo resolver la conversación. Reintentá en unos segundos.'
          );
        },
      });
  }

  /** Dismiss the workflow action message. */
  onDismissActionMessage(): void {
    this.actionMessage.set(null);
  }

  onSendMessage(event: { content: string; tempId: string }): void {
    const ticket = this.activeTicket();
    if (!ticket) return;

    this.messageSender.send(ticket.ticket_id, event.content, event.tempId);
    this.scrollToBottom();
  }

  onSendFile(event: { content: string; tempId: string; file: File }): void {
    const ticket = this.activeTicket();
    if (!ticket) return;

    this.messageSender.sendWithMedia(ticket.ticket_id, event.content, event.file, event.tempId);
    this.scrollToBottom();
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
   * Load older messages from the API and prepend them to the store.
   * Preserves the user's visual context by capturing a scroll anchor (the first
   * visible message + its offset) and restoring it after the new page renders.
   */
  private loadOlderMessages(vp: CdkVirtualScrollViewport): void {
    const ticket = this.activeTicket();
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

  /**
   * Called externally when new messages arrive (e.g., via Mercure SSE).
   * If user is not at bottom, show the "new messages" indicator.
   */
  onBack(): void {
    this.ticketStore.setActiveTicket(null);
    this.back.emit();
  }

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
}
