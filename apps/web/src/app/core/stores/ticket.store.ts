import { Injectable, signal, computed } from '@angular/core';
import { Ticket, TicketStatus } from '../models/ticket.model';

const MAX_TICKETS = 1000;

export type TicketFilter = 'mine' | 'waiting' | 'internal';

/**
 * TicketStore — manages ticket list state via Angular signals.
 */
@Injectable({ providedIn: 'root' })
export class TicketStore {
  /** All loaded tickets */
  readonly tickets = signal<Ticket[]>([]);

  /** Currently selected ticket */
  readonly activeTicket = signal<Ticket | null>(null);

  /** Active filter tab */
  readonly filter = signal<TicketFilter>('mine');

  /** Loading state */
  readonly loading = signal<boolean>(false);

  /** Filtered tickets based on current filter */
  readonly filteredTickets = computed<Ticket[]>(() => {
    const all = this.tickets();
    const f = this.filter();

    switch (f) {
      case 'mine':
        return all.filter(t => t.owner !== null);
      case 'waiting':
        return all.filter(t => t.status === TicketStatus.Waiting);
      case 'internal':
        return all.filter(t => t.status === TicketStatus.New || t.status === TicketStatus.Active);
      default:
        return all;
    }
  });

  /** Count for each filter tab */
  readonly filterCounts = computed(() => ({
    mine: this.tickets().filter(t => t.owner !== null).length,
    waiting: this.tickets().filter(t => t.status === TicketStatus.Waiting).length,
    internal: this.tickets().filter(t => t.status === TicketStatus.New || t.status === TicketStatus.Active).length,
  }));

  /** Total unread count across all tickets */
  readonly totalUnread = computed(() =>
    this.tickets().reduce((sum, t) => sum + (t.metrics?.unread_count ?? 0), 0)
  );

  /** Set loading state */
  setLoading(loading: boolean): void {
    this.loading.set(loading);
  }

  /** Set the active filter */
  setFilter(filter: TicketFilter): void {
    this.filter.set(filter);
  }

  /** Load tickets, enforcing a max cap of 1000 */
  loadTickets(tickets: Ticket[]): void {
    const capped = tickets.slice(0, MAX_TICKETS);
    this.tickets.set(capped);
  }

  /** Add or update a ticket in the list */
  addOrUpdateTicket(ticket: Ticket): void {
    const current = this.tickets();
    const index = current.findIndex(t => t.ticket_id === ticket.ticket_id);

    if (index >= 0) {
      const updated = [...current];
      updated[index] = ticket;
      this.tickets.set(updated);
    } else {
      this.tickets.set([ticket, ...current]);
    }
  }

  /** Set the active ticket by ID */
  setActiveTicket(ticketId: string | null): void {
    if (!ticketId) {
      this.activeTicket.set(null);
      return;
    }
    const ticket = this.tickets().find(t => t.ticket_id === ticketId) ?? null;
    this.activeTicket.set(ticket);
  }

  /** Update unread count for a specific ticket */
  updateUnreadCount(ticketId: string, count: number): void {
    this.tickets.update(tickets =>
      tickets.map(t => t.ticket_id === ticketId
        ? { ...t, metrics: { ...t.metrics, unread_count: count } }
        : t
      )
    );
  }

  /** Mark a ticket as read */
  markAsRead(ticketId: string): void {
    this.tickets.update(tickets =>
      tickets.map(t => t.ticket_id === ticketId
        ? { ...t, metrics: { ...t.metrics, unread_count: 0 } }
        : t
      )
    );
  }

  /** Reset store state */
  reset(): void {
    this.tickets.set([]);
    this.activeTicket.set(null);
    this.filter.set('mine');
    this.loading.set(false);
  }
}