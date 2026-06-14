import {
  Component,
  inject,
  OnInit,
  OnDestroy,
  signal,
  computed,
  viewChild,
  effect,
  HostListener,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { TicketStore } from '../../../../core/stores/ticket.store';
import { ConversationStore } from '../../../../core/stores/conversation.store';
import { TicketApiService } from '../../../../core/api/ticket-api.service';
import { MessageApiService } from '../../../../core/api/message-api.service';
import { MercureService } from '../../../../core/realtime/mercure.service';
import { Ticket } from '../../../../core/models/ticket.model';
import { take } from 'rxjs';
import { TicketCardComponent } from '../ticket-card/ticket-card.component';
import { TicketSearchComponent } from '../chat-search/ticket-search.component';
import { TicketFiltersComponent } from '../chat-filters/ticket-filters.component';
import { VirtualScrollViewportComponent } from '../../../../shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component';
import { GetMessagesResponse } from '../../../../core/api/message-api.service';

@Component({
  selector: 'app-ticket-list',
  standalone: true,
  imports: [
    CommonModule,
    TicketCardComponent,
    TicketSearchComponent,
    TicketFiltersComponent,
    VirtualScrollViewportComponent,
  ],
  templateUrl: './ticket-list.component.html',
  styleUrl: './ticket-list.component.scss',
})
export class TicketListComponent implements OnInit, OnDestroy {
  protected readonly ticketStore = inject(TicketStore);
  protected readonly conversationStore = inject(ConversationStore);
  private readonly ticketApi = inject(TicketApiService);
  private readonly messageApi = inject(MessageApiService);
  private readonly mercure = inject(MercureService);

  // Signals from store
  readonly tickets = computed(() => this.ticketStore.filteredTickets());
  readonly loading = this.ticketStore.loading;
  readonly selectedTicketId = computed(() => this.ticketStore.activeTicket()?.ticket_id ?? null);

  // Virtual scroll viewport reference
  private readonly vps = viewChild<VirtualScrollViewportComponent<Ticket>>('vps');

  /** Scroll indices from the viewport */
  private scrollIndices = signal<{ startIndex: number; endIndex: number }>({ startIndex: 0, endIndex: -1 });

  /** Visible ticket slice — computed from own items + scroll indices */
  protected readonly visibleTickets = computed(() => {
    const all = this.tickets();
    const { startIndex, endIndex } = this.scrollIndices();
    if (all.length === 0 || startIndex > endIndex) return [];
    return all.slice(startIndex, endIndex + 1);
  });

  /** Saved scroll position for restoration on return */
  private savedScrollTop: number | null = null;

  /** Focused index for keyboard navigation */
  private focusedIndex = signal<number>(-1);

  private readonly unsubscribers: (() => void)[] = [];
  private readonly activeSubscriptions = new Map<string, () => void>();

  // Restore scroll position once tickets and viewport are ready
  private readonly scrollRestoreEffect = effect(() => {
    const items = this.tickets();
    const vps = this.vps();
    if (vps && this.savedScrollTop !== null && items.length > 0) {
      queueMicrotask(() => {
        vps.scrollTop = this.savedScrollTop!;
        this.savedScrollTop = null;
      });
    }
  });

  ngOnInit(): void {
    this.loadTickets();
  }

  private loadTickets(): void {
    this.ticketStore.setLoading(true);

    this.ticketApi.getTickets().subscribe({
      next: (tickets) => {
        this.ticketStore.loadTickets(tickets);
        this.ticketStore.setLoading(false);
        this.subscribeToTickets(tickets);
      },
      error: (err) => {
        console.error('[TicketList] Failed to load tickets:', err);
        this.ticketStore.setLoading(false);
      }
    });
  }

  private subscribeToTickets(tickets: Ticket[]): void {
    // Clean up old subscriptions
    this.activeSubscriptions.forEach((unsub) => unsub());
    this.activeSubscriptions.clear();

    // Subscribe to realtime updates for each ticket
    tickets.forEach((ticket) => {
      const unsub = this.mercure.subscribe(ticket.ticket_id, (event) => {
        this.handleRealtimeEvent(ticket.ticket_id, event);
      });
      this.activeSubscriptions.set(ticket.ticket_id, unsub);
      this.unsubscribers.push(unsub);
    });
  }

  private handleRealtimeEvent(ticketId: string, event: unknown): void {
    console.log('[TicketList] Realtime event:', ticketId, event);
    // Refresh ticket data on any event
    this.ticketApi.getTicket(ticketId).subscribe({
      next: (ticket) => {
        this.ticketStore.addOrUpdateTicket(ticket);
      },
      error: (err) => {
        console.error('[TicketList] Failed to refresh ticket:', ticketId, err);
      }
    });
  }

  selectTicket(ticket: Ticket): void {
    this.ticketStore.setActiveTicket(ticket.ticket_id);
    // Reset conversation state for the new ticket
    this.conversationStore.reset();
    this.conversationStore.setLoading(true);

    this.messageApi.getMessages(ticket.ticket_id, 20, 0).pipe(take(1)).subscribe({
      next: (resp: GetMessagesResponse) => {
        this.conversationStore.loadMessages(
          resp.data.map(m => ({
            id: String(m.message_id),
            message_id: m.message_id,
            ticketId: ticket.ticket_id,
            chatId: ticket.ticket_id,
            content: m.content,
            body: m.content,
            direction: m.direction as 'inbound' | 'outbound' | 'system',
            deliveryStatus: (m.deliveryStatus || 'sent') as 'sent',
            created_at: m.created_at || new Date().toISOString(),
            sender: (m.direction === 'inbound' ? 'customer' : 'agent') as 'agent' | 'customer',
          }))
        );
        this.conversationStore.setHasMore(resp.meta.has_more);
        this.conversationStore.setLoading(false);
      },
      error: (err) => {
        console.error('[TicketList] Failed to load messages:', err);
        this.conversationStore.setLoading(false);
      }
    });
  }

  trackByTicketId(_index: number, ticket: Ticket): string {
    return ticket.ticket_id;
  }

  onFilterChange(filter: 'mine' | 'waiting' | 'internal'): void {
    this.ticketStore.setFilter(filter);
  }

  /** Handle viewport visible range change */
  protected onVisibleRangeChange(range: { startIndex: number; endIndex: number }): void {
    this.scrollIndices.set(range);
  }

  ngOnDestroy(): void {
    // Save scroll position before destroying
    const vps = this.vps();
    if (vps) {
      this.savedScrollTop = vps.scrollTop;
    }
    this.activeSubscriptions.forEach((unsub) => unsub());
    this.unsubscribers.forEach((unsub) => unsub());
  }

  /** Handle keyboard navigation */
  @HostListener('keydown', ['$event'])
  onKeyDown(event: KeyboardEvent): void {
    const items = this.tickets();
    if (items.length === 0) return;

    const currentIndex = this.focusedIndex();

    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        this.focusedIndex.set(Math.min(currentIndex + 1, items.length - 1));
        this.vps()?.scrollToIndex(this.focusedIndex());
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.focusedIndex.set(Math.max(currentIndex - 1, 0));
        this.vps()?.scrollToIndex(this.focusedIndex());
        break;
      case 'Enter':
        if (currentIndex >= 0 && currentIndex < items.length) {
          this.selectTicket(items[currentIndex]);
        }
        break;
    }
  }

  /** Called when a ticket card receives click/focus */
  onCardFocus(index: number): void {
    this.focusedIndex.set(index);
  }

  /** Check if a ticket is currently focused */
  isFocused(ticketId: string): boolean {
    const idx = this.tickets().findIndex(t => t.ticket_id === ticketId);
    return idx === this.focusedIndex();
  }

}