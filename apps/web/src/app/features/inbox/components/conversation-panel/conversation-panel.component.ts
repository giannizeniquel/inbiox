import { Component, inject, signal, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { StateService } from '../../../../core/services/state.service';
import { MessageApiService } from '../../../../core/api/message-api.service';
import { StatusActionsComponent } from '../status-actions/status-actions.component';
import { Message } from '../../../../core/models/message.model';
import { Subscription } from 'rxjs';

@Component({
  selector: 'app-conversation-panel',
  standalone: true,
  imports: [CommonModule, FormsModule, StatusActionsComponent],
  templateUrl: './conversation-panel.component.html',
  styleUrl: './conversation-panel.component.scss'
})
/**
 * @deprecated Use ConversationComponent instead. Will be removed in Sprint 13.5 or 14.
 */
export class ConversationPanelComponent implements OnInit, OnDestroy {
  private readonly state = inject(StateService);
  private readonly messageApi = inject(MessageApiService);

  readonly currentTicket = this.state.currentTicket;
  readonly messages$ = this.state.messages$;

  readonly newMessage = signal('');
  private readonly subscriptions: Subscription[] = [];

  ngOnInit(): void {
    // Load messages when ticket selection changes
    const sub = this.state.selectedTicketId$.subscribe((ticketId) => {
      if (ticketId) {
        this.loadMessages(ticketId);
      } else {
        this.state.setMessages([]);
      }
    });
    this.subscriptions.push(sub);
  }

  private loadMessages(ticketId: string): void {
    this.messageApi.getMessages(ticketId).subscribe({
      next: (resp) => {
        this.state.setMessages(resp.data);
        this.scrollToBottom();
      },
      error: (err) => {
        console.error('[ConversationPanel] Failed to load messages:', err);
      }
    });
  }

  sendMessage(): void {
    const ticketId = this.getSelectedTicketId();
    if (!ticketId) return;

    const content = this.newMessage().trim();
    if (!content) return;

    this.messageApi.sendMessage(ticketId, { content }).subscribe({
      next: (message) => {
        this.state.addMessage(message);
        this.newMessage.set('');
        this.scrollToBottom();
      },
      error: (err) => {
        console.error('[ConversationPanel] Failed to send message:', err);
      }
    });
  }

  onEnterKey(event: Event): void {
    const ke = event as KeyboardEvent;
    if (!ke.shiftKey) {
      ke.preventDefault();
      this.sendMessage();
    }
  }

  private getSelectedTicketId(): string | null {
    // Get the current value from the BehaviorSubject
    let ticketId: string | null = null;
    this.state.selectedTicketId$.subscribe((id) => (ticketId = id)).unsubscribe();
    return ticketId;
  }

  private scrollToBottom(): void {
    setTimeout(() => {
      const container = document.querySelector('.messages-container');
      if (container) {
        container.scrollTop = container.scrollHeight;
      }
    }, 0);
  }

  trackByMessageId(_index: number, message: Message): string {
    return message.id;
  }

  isInbound(message: Message): boolean {
    return message.direction === 'inbound';
  }

  ngOnDestroy(): void {
    this.subscriptions.forEach((sub) => sub.unsubscribe());
  }
}