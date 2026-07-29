import { Injectable, signal, computed, inject, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject, filter } from 'rxjs';
import { Ticket, TicketStatus } from '../models/ticket.model';
import { RealtimeEvent, RealtimeEventType,
  MessageAddedPayload, TicketCreatedPayload, TicketStatusChangedPayload,
  TicketAssignedPayload } from '../models/realtime-event.model';
import { EventBusService } from '../realtime/event-bus.service';

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

  /** Reload trigger Subject — emitted when workspace changes */
  readonly reload$ = new Subject<void>();

  /** Reload callback registered by the InboxPageComponent */
  private reloadCallback: (() => void) | null = null;

  constructor() {
    this.initEventBus();
  }

  /**
   * Register a reload callback that is called when workspace changes.
   * The InboxPageComponent should register its API fetch function here.
   */
  onReload(callback: () => void): void {
    this.reloadCallback = callback;
  }

  /** Trigger reload of tickets from the API */
  reload(): void {
    this.reloadCallback?.();
    this.reload$.next();
  }

  /**
   * Set up EventBus subscription for realtime events.
   * Handles: TicketCreated, TicketStatusChanged, TicketAssigned, MessageAdded.
   */
  private initEventBus(): void {
    try {
      const eventBus = inject(EventBusService);
      const destroyRef = inject(DestroyRef);

      eventBus.events$.pipe(
        filter(e =>
          e.eventType === RealtimeEventType.TicketCreated
          || e.eventType === RealtimeEventType.TicketStatusChanged
          || e.eventType === RealtimeEventType.TicketAssigned
          || e.eventType === RealtimeEventType.MessageAdded
        ),
        takeUntilDestroyed(destroyRef),
      ).subscribe(e => this.onRealtimeEvent(e));
    } catch {
      // Running outside DI context (e.g., unit tests)
    }
  }

  /**
   * Handle realtime events dispatched via EventBus.
   * Logic mirrors MercureService.handleEvent() for ticket-related types.
   */
  private onRealtimeEvent(event: RealtimeEvent): void {
    switch (event.eventType) {
      case RealtimeEventType.MessageAdded: {
        const p = event.payload as MessageAddedPayload;
        // Increment unread if not active ticket
        const activeTicket = this.activeTicket();
        if (!activeTicket || activeTicket.ticket_id !== String(p.ticketId)) {
          const current = this.tickets().find(t => t.ticket_id === String(p.ticketId));
          if (current) {
            this.updateUnreadCount(String(p.ticketId), (current.metrics?.unread_count ?? 0) + 1);
          }
        }
        break;
      }

      case RealtimeEventType.TicketCreated: {
        const p = event.payload as TicketCreatedPayload;
        this.addOrUpdateTicket({
          ticket_id: String(p.ticketId),
          id: String(p.ticketId),
          status: p.status as TicketStatus,
          priority: String(p.priority),
          contact: null,
          last_message: null,
          owner: null,
          metrics: { unread_count: 0 },
          timestamps: { opened_at: p.createdAt, updated_at: p.createdAt },
        });
        break;
      }

      case RealtimeEventType.TicketStatusChanged: {
        const p = event.payload as TicketStatusChangedPayload;
        const existing = this.tickets().find(t => t.ticket_id === String(p.ticketId));
        if (existing) {
          this.addOrUpdateTicket({
            ...existing,
            status: p.newStatus as TicketStatus,
            timestamps: { ...existing.timestamps, updated_at: p.changedAt },
          });
        }
        break;
      }

      case RealtimeEventType.TicketAssigned: {
        const p = event.payload as TicketAssignedPayload;
        const existing = this.tickets().find(t => t.ticket_id === String(p.ticketId));
        if (existing) {
          this.addOrUpdateTicket({
            ...existing,
            owner: { id: p.assigneeId, name: `Agent #${p.assigneeId}` },
            timestamps: { ...existing.timestamps, updated_at: p.assignedAt },
          });
        }
        break;
      }
    }
  }

  /** Filtered tickets based on current filter */
  readonly filteredTickets = computed<Ticket[]>(() => {
    const all = this.tickets();
    const f = this.filter();

    switch (f) {
      case 'mine':
        return all.filter(t => t.owner !== null);
      case 'waiting':
        return all.filter(t => t.owner === null); // unassigned tickets
      case 'internal':
        return all; // all tickets in workspace
      default:
        return all;
    }
  });

  /** Count for each filter tab */
  readonly filterCounts = computed(() => ({
    mine: this.tickets().filter(t => t.owner !== null).length,
    waiting: this.tickets().filter(t => t.owner === null).length, // unassigned count
    internal: this.tickets().length,
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