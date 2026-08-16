import { Component, inject, signal, viewChild, computed, ChangeDetectionStrategy, input, output, effect } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { take } from 'rxjs/operators';
import { environment } from '../../../../../environments/environment';
import { ConversationStore } from '../../../../core/stores/conversation.store';
import { TicketStore } from '../../../../core/stores/ticket.store';
import { UiStore } from '../../../../core/stores/ui.store';
import { MessageApiService, GetMessagesResponse } from '../../../../core/api/message-api.service';
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

  /** Contextual warning message (e.g. SLA breach, internal note) */
  protected readonly warningMessage = input<string | null>(null);

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
        vp.scrollTo({ bottom: 0, behavior: 'auto' });
        this.isAtBottom.set(true);
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
    this.loadingOlder.set(false);
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
        const top = vp.measureScrollOffset('top');
        if (top < 200 && this.conversationStore.hasMore() && !this.loadingOlder()) {
          this.loadOlderMessages(vp);
        }
        const bottom = vp.measureScrollOffset('bottom');
        this.isAtBottom.set(bottom === 0);
        if (bottom === 0) {
          this.newMessagesCount.set(0);
        }
      });
    });
    onCleanup(() => sub.unsubscribe());
  });

  /**
   * How many older messages have been loaded from the API (offset tracking).
   * 0 = initial load returned the most recent messages.
   */
  private readonly oldestLoadedOffset = signal(0);

  /** Guard flag to prevent concurrent older-message loads */
  protected readonly loadingOlder = signal(false);

  onRetry(tempId: string): void {
    const message = this.messages().find((m: Message) => m.tempId === tempId);
    if (message) {
      this.conversationStore.retryMessage(tempId);
    }
  }

  onSendMessage(event: { content: string; tempId: string }): void {
    const ticket = this.activeTicket();
    if (!ticket) return;

    this.messageSender.send(ticket.ticket_id, event.content, event.tempId);
    this.scrollToBottom();
  }

  scrollToBottom(): void {
    const vp = this.vps();
    if (!vp) return;
    vp.scrollTo({ bottom: 0, behavior: 'auto' });
    this.isAtBottom.set(true);
    this.newMessagesCount.set(0);
  }

  /**
   * Load older messages from the API and prepend them to the store.
   * Preserves scroll position by measuring the actual height of prepended items.
   */
  private loadOlderMessages(vp: CdkVirtualScrollViewport): void {
    const ticket = this.activeTicket();
    if (!ticket) return;

    this.loadingOlder.set(true);
    const el = vp.elementRef.nativeElement;
    const scrollHeightBefore = el.scrollHeight;

    this.messageApi.getMessages(ticket.ticket_id, 20, this.oldestLoadedOffset()).pipe(take(1)).subscribe({
      next: (resp: GetMessagesResponse) => {
        const added = mapApiMessagesToCanonical(resp, ticket.ticket_id);
        this.conversationStore.prependMessages(added);
        this.conversationStore.setHasMore(resp.meta.has_more);
        this.oldestLoadedOffset.set(this.oldestLoadedOffset() + resp.data.length);

        // setTimeout(fn, 0) fires after layout + paint + ResizeObserver corrections.
        // By then CDK autosize spacers reflect actual measured heights, so the
        // scrollHeight delta is accurate.
        setTimeout(() => {
          const delta = el.scrollHeight - scrollHeightBefore;
          vp.scrollToOffset(delta);
          this.loadingOlder.set(false);
        }, 0);
      },
      error: () => {
        this.loadingOlder.set(false);
      },
    });
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
