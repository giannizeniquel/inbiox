import { Component, inject, OnDestroy, signal, ViewChild, ChangeDetectionStrategy, ChangeDetectorRef, input, output, effect } from '@angular/core';
import { Subscription } from 'rxjs';
import { ConversationStore, BridgeMessage } from '../../../../core/stores/conversation.store';
import { TicketStore } from '../../../../core/stores/ticket.store';
import { UiStore } from '../../../../core/stores/ui.store';
import { VirtualScrollViewportComponent } from '../../../../shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component';
import { MessageBubbleComponent } from '../../components/message-bubble/message-bubble.component';
import { ConversationHeaderComponent } from '../../components/conversation-header/conversation-header.component';
import { ComposerComponent } from '../../components/composer/composer.component';
import { ConversationEmptyComponent } from '../../components/conversation-empty/conversation-empty.component';
import { ConversationLoadingComponent } from '../../components/conversation-loading/conversation-loading.component';
import { NewMessagesIndicatorComponent } from '../../components/new-messages-indicator/new-messages-indicator.component';
import { BannerWarningComponent } from '../../../../shared/components/banner-warning/banner-warning.component';
import { MessageSenderService } from '../../services/message-sender.service';

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
  @ViewChild('vps') viewport!: VirtualScrollViewportComponent<BridgeMessage>;

  protected readonly conversationStore = inject(ConversationStore);
  protected readonly ticketStore = inject(TicketStore);
  protected readonly uiStore = inject(UiStore);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly messageSender = inject(MessageSenderService);

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
    if (msgs.length === 0) return;

    if (this.isAtBottom()) {
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          this.scrollToBottom();
        });
      });
    }
  });

  private scrollTimeout?: ReturnType<typeof setTimeout>;
  private subscription?: Subscription;

  ngOnDestroy(): void {
    clearTimeout(this.scrollTimeout);
    this.subscription?.unsubscribe();
  }

  onResolve(): void {
    const ticket = this.activeTicket();
    if (!ticket) return;
    // Handled by parent or workflow service
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
    const el = this.viewport?.scrollContainer;
    if (!el) return;

    const threshold = 50;
    const atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < threshold;
    this.isAtBottom.set(atBottom);

    if (atBottom) {
      this.showNewMessagesIndicator.set(false);
    }
  }

  scrollToBottom(): void {
    if (!this.viewport) return;
    this.viewport.scrollToBottom();
    this.showNewMessagesIndicator.set(false);
    this.isAtBottom.set(true);
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
