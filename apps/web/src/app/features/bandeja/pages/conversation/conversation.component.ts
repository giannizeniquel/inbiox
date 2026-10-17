import { Component, inject, signal, viewChild, computed, ChangeDetectionStrategy, output, effect } from '@angular/core';
import { HttpErrorResponse } from '@angular/common/http';
import { take } from 'rxjs/operators';
import { ConversationStore } from '../../../../data-access/conversacion/conversation.store';
import { TicketStore } from '../../../../data-access/ticket/ticket.store';
import { TicketApiService } from '../../../../data-access/ticket/ticket-api.service';
import { UiStore } from '../../ui.store';
import { WorkflowApiService } from '../../../../data-access/ticket/workflow-api.service';
import { CdkVirtualScrollViewport, ScrollingModule } from '@angular/cdk/scrolling';
import { CdkAutoSizeVirtualScroll } from '@angular/cdk-experimental/scrolling';
import { MessageBubbleComponent } from '../../ui/message-bubble/message-bubble.component';
import { ConversationHeaderComponent } from '../../ui/conversation-header/conversation-header.component';
import { TicketParticipationComponent } from '../../ui/ticket-participation/ticket-participation.component';
import { ComposerComponent } from '../../ui/composer/composer.component';
import { ConversationEmptyComponent } from '../../ui/conversation-empty/conversation-empty.component';
import { ConversationLoadingComponent } from '../../ui/conversation-loading/conversation-loading.component';
import { NewMessagesIndicatorComponent } from '../../ui/new-messages-indicator/new-messages-indicator.component';
import { BannerWarningComponent } from '../../../../shared/components/banner-warning/banner-warning.component';
import { LoadingSpinnerComponent } from '../../../../shared/components/loading-spinner/loading-spinner.component';
import { MessageSenderService } from '../../../../data-access/conversacion/message-sender.service';
import { Message } from '../../../../data-access/conversacion/message.model';
import { ConversationScrollController } from './conversation-scroll.controller';

/**
 * ConversationComponent — main conversation panel container.
 *
 * Per spec section 17:
 * - flex-grow, overflow-y: auto, min-height: 0
 * - Connects to ConversationStore (messages, loading) + TicketStore (activeTicket)
 * - Auto-scroll detection (per spec section 6), delegated to ConversationScrollController
 * - Renders: header + scrollable message list + composer + new-msg indicator
 * - BannerWarning for workflow action feedback (resolve)
 */
@Component({
  selector: 'app-conversation-container',
  standalone: true,
  imports: [
    ScrollingModule,
    CdkAutoSizeVirtualScroll,
    ConversationHeaderComponent,
    TicketParticipationComponent,
    MessageBubbleComponent,
    ComposerComponent,
    ConversationEmptyComponent,
    ConversationLoadingComponent,
    NewMessagesIndicatorComponent,
    BannerWarningComponent,
    LoadingSpinnerComponent,
  ],
  templateUrl: './conversation.component.html',
  styleUrl: './conversation.component.scss',
  providers: [ConversationScrollController],
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class ConversationComponent {
  readonly vps = viewChild<CdkVirtualScrollViewport>('vps');

  protected readonly conversationStore = inject(ConversationStore);
  protected readonly ticketStore = inject(TicketStore);
  protected readonly uiStore = inject(UiStore);
  protected readonly scroll = inject(ConversationScrollController);
  private readonly ticketApi = inject(TicketApiService);
  private readonly messageSender = inject(MessageSenderService);
  private readonly workflowApi = inject(WorkflowApiService);

  constructor() {
    this.scroll.attach(this.vps);
  }

  /**
   * Feedback for a workflow action the agent just triggered (resolve).
   * Null while idle; set to a user-facing message on failure.
   */
  protected readonly actionMessage = signal<string | null>(null);

  /** True while a resolve request is in flight — keeps the action idempotent. */
  protected readonly resolving = signal(false);

  /** True when the "Participación" panel is expanded (lazy-fetches on open). */
  protected readonly participationOpen = signal(false);

  /** Emitted when user taps back button on mobile — closes conversation panel */
  protected readonly back = output<void>();

  protected readonly messages = this.conversationStore.messages;
  protected readonly loading = this.conversationStore.loading;
  protected readonly activeTicket = this.ticketStore.activeTicket;

  /**
   * WhatsApp 24h conversation-window compliance (Sprint 16 Fase 3, design.md D7).
   *
   * Derived, read-only signal — never written back to any store. Takes the
   * more recent of the REST baseline (`activeTicket().timestamps.
   * last_inbound_message_at`, loaded once) and the latest inbound message
   * already present in `ConversationStore.messages()` (kept live by the
   * existing `MessageAdded` Mercure handler). This is what lets the
   * composer's window-expired state flip back automatically on a new
   * inbound message with no polling and no new Mercure payload.
   */
  protected readonly lastInboundMessageAt = computed<string | null>(() => {
    const restBaseline = this.activeTicket()?.timestamps.last_inbound_message_at ?? null;

    let latestFromMessages: string | null = null;
    const messages = this.messages();
    for (let i = messages.length - 1; i >= 0; i--) {
      if (messages[i].direction === 'inbound') {
        latestFromMessages = messages[i].createdAt;
        break;
      }
    }

    if (!restBaseline) return latestFromMessages;
    if (!latestFromMessages) return restBaseline;

    return new Date(latestFromMessages) > new Date(restBaseline) ? latestFromMessages : restBaseline;
  });

  trackById = (_: number, m: Message): string => m.tempId ?? m.id;
  // React to UiStore notifications from SSE events
  private readonly newMessagesEffect = effect(() => {
    if (this.uiStore.hasNewMessages()) {
      this.scroll.notifyNewMessages();
      this.uiStore.markViewed();
    }
  });

  private readonly initialScrollEffect = effect(() => this.scroll.runInitialScroll());

  /** Reset scroll, pagination and the participation panel when switching to a different ticket */
  private readonly ticketResetEffect = effect(() => {
    void this.activeTicket();
    this.scroll.reset();
    this.participationOpen.set(false);
  });

  /** Mark ticket as read on the backend when the active ticket changes */
  private readonly markReadEffect = effect(() => {
    const ticket = this.activeTicket();
    if (ticket) {
      const ticketId = Number(ticket.ticket_id);
      if (!isNaN(ticketId)) {
        this.ticketApi.markRead(ticketId)
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
        this.scroll.handleScroll(vp);
      });
    });
    onCleanup(() => sub.unsubscribe());
  });

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

  /** Toggle the "Participación" panel — fetch is lazy (gated by `@if` in the template). */
  onToggleParticipation(): void {
    this.participationOpen.update((open) => !open);
  }

  onSendMessage(event: { content: string; tempId: string }): void {
    const ticket = this.activeTicket();
    if (!ticket) return;

    this.messageSender.send(ticket.ticket_id, event.content, event.tempId);
    this.scroll.scrollToBottom();
  }

  onSendFile(event: { content: string; tempId: string; file: File }): void {
    const ticket = this.activeTicket();
    if (!ticket) return;

    this.messageSender.sendWithMedia(ticket.ticket_id, event.content, event.file, event.tempId);
    this.scroll.scrollToBottom();
  }

  onBack(): void {
    this.ticketStore.setActiveTicket(null);
    this.back.emit();
  }
}
