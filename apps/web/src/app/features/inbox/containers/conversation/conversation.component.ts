import { Component, inject, OnDestroy, signal, viewChild, computed, ChangeDetectionStrategy, ChangeDetectorRef, input, output, effect } from '@angular/core';
import { Subscription } from 'rxjs';
import { take } from 'rxjs/operators';
import { ConversationStore, BridgeMessage } from '../../../../core/stores/conversation.store';
import { TicketStore } from '../../../../core/stores/ticket.store';
import { UiStore } from '../../../../core/stores/ui.store';
import { MessageApiService, GetMessagesResponse } from '../../../../core/api/message-api.service';
import { VirtualScrollViewportComponent } from '../../../../shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component';
import { MessageBubbleComponent } from '../../components/message-bubble/message-bubble.component';
import { ConversationHeaderComponent } from '../../components/conversation-header/conversation-header.component';
import { ComposerComponent } from '../../components/composer/composer.component';
import { ConversationEmptyComponent } from '../../components/conversation-empty/conversation-empty.component';
import { ConversationLoadingComponent } from '../../components/conversation-loading/conversation-loading.component';
import { NewMessagesIndicatorComponent } from '../../components/new-messages-indicator/new-messages-indicator.component';
import { BannerWarningComponent } from '../../../../shared/components/banner-warning/banner-warning.component';
import { MessageSenderService } from '../../services/message-sender.service';
import { mapApiMessagesToBridge } from '../../../../core/utils/message-mapper.util';

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
    VirtualScrollViewportComponent,
    ConversationHeaderComponent,
    MessageBubbleComponent,
    ComposerComponent,
    ConversationEmptyComponent,
    ConversationLoadingComponent,
    NewMessagesIndicatorComponent,
    BannerWarningComponent,
  ],
  templateUrl: './conversation.component.html',
  styleUrl: './conversation.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversationComponent implements OnDestroy {
  private readonly vps = viewChild<VirtualScrollViewportComponent<BridgeMessage>>('vps');

  /** Scroll indices from the viewport */
  private scrollIndices = signal<{ startIndex: number; endIndex: number }>({ startIndex: 0, endIndex: -1 });

  /** Visible message slice — computed from own messages + scroll indices */
  protected readonly visibleMessages = computed(() => {
    const all = this.messages();
    const { startIndex, endIndex } = this.scrollIndices();
    if (all.length === 0 || startIndex > endIndex) return [];
    return all.slice(startIndex, endIndex + 1);
  });

  protected readonly conversationStore = inject(ConversationStore);
  protected readonly ticketStore = inject(TicketStore);
  protected readonly uiStore = inject(UiStore);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly messageSender = inject(MessageSenderService);
  private readonly messageApi = inject(MessageApiService);

  /** Contextual warning message (e.g. SLA breach, internal note) */
  protected readonly warningMessage = input<string | null>(null);

  /** Emitted when user taps back button on mobile — closes conversation panel */
  protected readonly back = output<void>();

  protected readonly messages = this.conversationStore.messages;
  protected readonly loading = this.conversationStore.loading;
  protected readonly activeTicket = this.ticketStore.activeTicket;

  protected readonly showNewMessagesIndicator = signal(false);
  protected readonly isAtBottom = signal(true);

  // React to UiStore notifications from SSE events
  private readonly newMessagesEffect = effect(() => {
    if (this.uiStore.hasNewMessages()) {
      this.notifyNewMessages();
      this.uiStore.markViewed();
      this.cdr.markForCheck();
    }
  });

  private readonly messagesEffect = effect(() => {
    const msgs = this.messages();
    const vps = this.vps(); // track vps for when viewport becomes available
    if (msgs.length === 0) return;

    if (this.isAtBottom()) {
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          this.scrollToBottom();
        });
      });
    }
  });

  /** Reset pagination offset when switching to a different ticket */
  private readonly ticketResetEffect = effect(() => {
    void this.activeTicket();
    this.oldestLoadedOffset.set(0);
  });

  private scrollTimeout?: ReturnType<typeof setTimeout>;
  private subscription?: Subscription;

  ngOnDestroy(): void {
    clearTimeout(this.scrollTimeout);
    this.subscription?.unsubscribe();
  }

  /**
   * How many older messages have been loaded from the API (offset tracking).
   * 0 = initial load returned the most recent messages.
   */
  private readonly oldestLoadedOffset = signal(0);

  /** Guard flag to prevent concurrent older-message loads */
  private loadingOlder = false;

  /** Handle viewport visible range change */
  protected onVisibleRangeChange(range: { startIndex: number; endIndex: number }): void {
    this.scrollIndices.set(range);
    this.cdr.markForCheck();
  }

  onResolve(): void {
    const ticket = this.activeTicket();
    if (!ticket) return;
  }

  onRetry(tempId: string): void {
    const message = this.messages().find((m: BridgeMessage) => m.tempId === tempId);
    if (message) {
      this.conversationStore.retryMessage(tempId);
      this.cdr.markForCheck();
    }
  }

  onSendMessage(event: { content: string; tempId: string }): void {
    const ticket = this.activeTicket();
    if (!ticket) return;

    this.messageSender.send(ticket.ticket_id, event.content, event.tempId);
    this.scrollToBottom();
  }

  onScroll(): void {
    const el = this.vps()?.scrollContainer;
    if (!el) return;

    const threshold = 50;
    const atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < threshold;
    this.isAtBottom.set(atBottom);

    if (atBottom) {
      this.showNewMessagesIndicator.set(false);
    }

    // Scroll-up pagination: when near top, load older messages
    if (el.scrollTop < 200 && this.conversationStore.hasMore() && !this.loadingOlder) {
      this.loadOlderMessages(el);
    }
  }

  scrollToBottom(): void {
    const v = this.vps();
    if (!v) return;
    v.scrollToBottom();
    this.showNewMessagesIndicator.set(false);
    this.isAtBottom.set(true);
  }

  /**
   * Load older messages from the API and prepend them to the store.
   * Preserves scroll position by compensating for the added height.
   */
  private loadOlderMessages(el: HTMLElement): void {
    const ticket = this.activeTicket();
    if (!ticket) return;

    this.loadingOlder = true;
    const saveScrollTop = el.scrollTop;

    this.messageApi.getMessages(ticket.ticket_id, 20, this.oldestLoadedOffset()).pipe(take(1)).subscribe({
      next: (resp: GetMessagesResponse) => {
        this.conversationStore.prependMessages(mapApiMessagesToBridge(resp, ticket.ticket_id));
        this.conversationStore.setHasMore(resp.meta.has_more);
        this.oldestLoadedOffset.set(this.oldestLoadedOffset() + resp.data.length);

        // Preserve scroll position: after prepend, the same visual content
        // is shifted down by the approximate height of the new items.
        requestAnimationFrame(() => {
          el.scrollTop = saveScrollTop + resp.data.length * 80;
        });

        this.loadingOlder = false;
      },
      error: () => {
        this.loadingOlder = false;
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
    if (!this.isAtBottom()) {
      this.showNewMessagesIndicator.set(true);
    } else {
      this.scrollToBottom();
    }
  }
}
