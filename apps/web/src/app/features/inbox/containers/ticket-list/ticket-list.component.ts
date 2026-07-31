import {
  Component,
  inject,
  OnInit,
  signal,
  computed,
  viewChild,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { take } from 'rxjs';
import { TicketStore } from '../../../../core/stores/ticket.store';
import { ConversationStore } from '../../../../core/stores/conversation.store';
import { TicketApiService } from '../../../../core/api/ticket-api.service';
import { MessageApiService, GetMessagesResponse } from '../../../../core/api/message-api.service';
import { Ticket } from '../../../../core/models/ticket.model';
import { mapApiMessagesToCanonical } from '../../../../core/utils/message-mapper.util';
import { TicketCardComponent } from '../../components/ticket-card/ticket-card.component';
import { TicketSearchComponent } from '../../components/chat-search/ticket-search.component';
import { TicketFiltersComponent } from '../../components/chat-filters/ticket-filters.component';
import { VirtualScrollViewportComponent } from '../../../../shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component';
import { VirtualListCoordinatorDirective } from '../../../../shared/directives/virtual-list-coordinator.directive';

@Component({
  selector: 'app-ticket-list',
  standalone: true,
  imports: [
    CommonModule,
    TicketCardComponent,
    TicketSearchComponent,
    TicketFiltersComponent,
    VirtualScrollViewportComponent,
    VirtualListCoordinatorDirective,
  ],
  templateUrl: './ticket-list.component.html',
  styleUrl: './ticket-list.component.scss',
})
export class TicketListComponent implements OnInit {
  protected readonly ticketStore = inject(TicketStore);
  protected readonly conversationStore = inject(ConversationStore);
  private readonly ticketApi = inject(TicketApiService);
  private readonly messageApi = inject(MessageApiService);

  /** Reference to the coordinator directive on the viewport */
  protected readonly coordinator = viewChild.required(VirtualListCoordinatorDirective<Ticket>);

  /** Resolver that maps a ticket to its unique id string */
  protected readonly idOfTicket = (ticket: Ticket): string => ticket.ticket_id;

  // Signals from store
  readonly tickets = computed(() => this.ticketStore.filteredTickets());
  readonly loading = this.ticketStore.loading;
  readonly selectedTicketId = computed(() => this.ticketStore.activeTicket()?.ticket_id ?? null);

  ngOnInit(): void {
    this.loadTickets();
  }

  private loadTickets(): void {
    this.ticketStore.setLoading(true);

    this.ticketApi.getTickets().subscribe({
      next: (tickets) => {
        this.ticketStore.loadTickets(tickets);
        this.ticketStore.setLoading(false);
      },
      error: (err) => {
        console.error('[TicketList] Failed to load tickets:', err);
        this.ticketStore.setLoading(false);
      },
    });
  }

  selectTicket(ticket: Ticket): void {
    this.ticketStore.setActiveTicket(ticket.ticket_id);
    this.conversationStore.reset();
    this.conversationStore.setLoading(true);

    this.messageApi.getMessages(ticket.ticket_id, 20, 0).pipe(take(1)).subscribe({
      next: (resp: GetMessagesResponse) => {
        this.conversationStore.loadMessages(mapApiMessagesToCanonical(resp, ticket.ticket_id));
        this.conversationStore.setHasMore(resp.meta.has_more);
        this.conversationStore.setLoading(false);
      },
      error: (err) => {
        console.error('[TicketList] Failed to load messages:', err);
        this.conversationStore.setLoading(false);
      },
    });
  }

  onFilterChange(filter: 'mine' | 'waiting' | 'internal'): void {
    this.ticketStore.setFilter(filter);
  }

  /** Handle ticket search query from TicketSearchComponent */
  onSearch(query: string): void {
    // TODO: implement server-side search via TicketApiService
    console.log('[TicketList] Search:', query);
  }
}
