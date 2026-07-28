import { Component, inject, OnInit, DestroyRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { filter, take } from 'rxjs';
import { TicketListComponent } from '../../containers/ticket-list/ticket-list.component';
import { ConversationComponent } from '../../containers/conversation/conversation.component';
import { DebugPanelComponent } from '../../components/debug-panel/debug-panel.component';
import { TicketStore } from '../../../../core/stores/ticket.store';
import { ConversationStore } from '../../../../core/stores/conversation.store';
import { TicketApiService } from '../../../../core/api/ticket-api.service';
import { MessageApiService, GetMessagesResponse } from '../../../../core/api/message-api.service';
import { mapApiMessagesToCanonical } from '../../../../core/utils/message-mapper.util';

@Component({
  selector: 'app-inbox-page',
  standalone: true,
  imports: [CommonModule, TicketListComponent, ConversationComponent, DebugPanelComponent],
  templateUrl: './inbox-page.component.html',
  styleUrl: './inbox-page.component.scss'
})
export class InboxPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly ticketStore = inject(TicketStore);
  private readonly conversationStore = inject(ConversationStore);
  private readonly ticketApi = inject(TicketApiService);
  private readonly messageApi = inject(MessageApiService);
  private readonly destroyRef = inject(DestroyRef);

  ngOnInit(): void {
    this.route.queryParams.pipe(
      filter(params => params['ticketId'] != null),
      take(1),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe(params => {
      const ticketId = String(params['ticketId']);
      this.ticketApi.getTicket(ticketId).subscribe({
        next: (ticket) => {
          this.ticketStore.addOrUpdateTicket(ticket);
          this.ticketStore.setActiveTicket(ticket.ticket_id);
          this.loadMessages(ticket.ticket_id);
        },
        error: () => {},
      });
    });
  }

  private loadMessages(ticketId: string): void {
    this.conversationStore.reset();
    this.conversationStore.setLoading(true);
    this.messageApi.getMessages(ticketId, 20, 0).pipe(take(1)).subscribe({
      next: (resp: GetMessagesResponse) => {
        this.conversationStore.loadMessages(mapApiMessagesToCanonical(resp, ticketId));
        this.conversationStore.setHasMore(resp.meta.has_more);
        this.conversationStore.setLoading(false);
      },
      error: () => {
        this.conversationStore.setLoading(false);
      },
    });
  }
}