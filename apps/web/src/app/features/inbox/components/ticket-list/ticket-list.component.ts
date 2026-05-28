import { Component, inject, OnInit, OnDestroy, signal, computed } from '@angular/core';
import { CommonModule } from '@angular/common';
import { TicketStore } from '../../../../core/stores/ticket.store';
import { TicketApiService } from '../../../../core/api/ticket-api.service';
import { MercureService } from '../../../../core/realtime/mercure.service';
import { Ticket } from '../../../../core/models/ticket.model';
import { TicketCardComponent } from '../ticket-card/ticket-card.component';
import { TicketSearchComponent } from '../chat-search/ticket-search.component';
import { TicketFiltersComponent } from '../chat-filters/ticket-filters.component';

@Component({
  selector: 'app-ticket-list',
  standalone: true,
  imports: [CommonModule, TicketCardComponent, TicketSearchComponent, TicketFiltersComponent],
  templateUrl: './ticket-list.component.html',
  styleUrl: './ticket-list.component.scss'
})
export class TicketListComponent implements OnInit, OnDestroy {
  protected readonly ticketStore = inject(TicketStore);
  private readonly ticketApi = inject(TicketApiService);
  private readonly mercure = inject(MercureService);

  // Signals from store
  readonly tickets = computed(() => this.ticketStore.filteredTickets());
  readonly loading = this.ticketStore.loading;
  readonly selectedTicketId = computed(() => this.ticketStore.activeTicket()?.ticket_id ?? null);

  private readonly unsubscribers: (() => void)[] = [];
  private readonly activeSubscriptions = new Map<string, () => void>();

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
  }

  trackByTicketId(_index: number, ticket: Ticket): string {
    return ticket.ticket_id;
  }

  onFilterChange(filter: 'mine' | 'waiting' | 'internal'): void {
    this.ticketStore.setFilter(filter);
  }

  ngOnDestroy(): void {
    this.activeSubscriptions.forEach((unsub) => unsub());
    this.unsubscribers.forEach((unsub) => unsub());
  }
}