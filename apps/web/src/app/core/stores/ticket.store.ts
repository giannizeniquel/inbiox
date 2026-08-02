import { Injectable, signal, computed, inject, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject, filter } from 'rxjs';
import { Ticket, TicketStatus } from '../models/ticket.model';
import { RealtimeEvent, RealtimeEventType,
  MessageAddedPayload, TicketCreatedPayload, TicketStatusChangedPayload,
  TicketAssignedPayload } from '../models/realtime-event.model';
import { EventBusService } from '../realtime/event-bus.service';
import { AuthStore } from './auth.store';
import { WorkspaceStore } from './workspace.store';

/** Owner information for assignment */
interface TicketOwner {
  id: number;
  name: string;
}

/** Assignment error with auto-dismiss timestamp */
interface AssignmentError {
  message: string;
  until: number;
}

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

  /** Tickets currently being assigned (optimistic update in progress) */
  readonly pendingAssignIds = signal<Set<number>>(new Set());

  /** Assignment errors with auto-dismiss timestamps */
  readonly assignmentErrors = signal<Map<number, AssignmentError>>(new Map());

  /** Reload trigger Subject — emitted when workspace changes */
  readonly reload$ = new Subject<void>();

  /** Reload callback registered by the InboxPageComponent */
  private reloadCallback: (() => void) | null = null;

  private readonly authStore = inject(AuthStore);
  private readonly workspaceStore = inject(WorkspaceStore);

  /** Current logged-in agent's ID (null if admin without agent) */
  private readonly currentAgentId = computed(() => this.authStore.currentUser()?.agent_id ?? null);

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
    const eventBus = inject(EventBusService);
    const destroyRef = inject(DestroyRef);

    eventBus.events$.pipe(
      filter(e =>
        e.eventType === RealtimeEventType.TicketCreated
        || e.eventType === RealtimeEventType.TicketStatusChanged
        || e.eventType === RealtimeEventType.TicketAssigned
        || e.eventType === RealtimeEventType.MessageAdded
        || e.eventType === RealtimeEventType.TicketRead
      ),
      takeUntilDestroyed(destroyRef),
    ).subscribe(e => this.onRealtimeEvent(e));
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
        if (!activeTicket || String(activeTicket.ticket_id) !== String(p.ticketId)) {
          const current = this.tickets().find(t => String(t.ticket_id) === String(p.ticketId));
          if (current) {
            this.updateUnreadCount(String(p.ticketId), (current.metrics?.unread_count ?? 0) + 1);
          }
        }
        // Workspace pending indicator: if inbound message from a different workspace
        if (p.direction === 'inbound' && p.phoneNumberId) {
          const activePhone = this.workspaceStore.activePhoneNumberId();
          if (activePhone && activePhone !== p.phoneNumberId) {
            // Find workspace by phone_number_id
            const ws = this.workspaceStore.workspaces().find(
              w => w.phone_number_id === p.phoneNumberId
            );
            if (ws) {
              this.workspaceStore.setPending(ws.id, true);
            }
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
        const existing = this.tickets().find(t => String(t.ticket_id) === String(p.ticketId));
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
        const existing = this.tickets().find(t => String(t.ticket_id) === String(p.ticketId));
        if (existing) {
          // Only overwrite owner name when Mercure provides a real name.
          // Otherwise keep the name from optimistic update (or existing owner).
          const ownerName = p.assigneeName ?? existing.owner?.name ?? `Agent #${p.assigneeId}`;
          // Implicit status transition: unassigned (new) → active on assignment
          const wasUnassigned = existing.status === TicketStatus.New || existing.owner === null;
          this.addOrUpdateTicket({
            ...existing,
            owner: { id: p.assigneeId, name: ownerName },
            status: wasUnassigned ? TicketStatus.Active : existing.status,
          });
        }
        // Clear pending state when Mercure confirms assignment
        this.clearPendingAssign(String(p.ticketId));
        // When a ticket is reassigned, clear the unread badge for the new owner
        if (String(event.ticketId)) {
          this.markAsRead(String(event.ticketId));
        }
        break;
      }

      case RealtimeEventType.TicketRead: {
        // Another user marked this ticket as read — clear our badge
        const ticketId = event.ticketId ? String(event.ticketId) : null;
        if (ticketId) {
          this.markAsRead(ticketId);
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
        return all.filter(t => t.owner?.id === this.currentAgentId());
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
    mine: this.tickets().filter(t => t.owner?.id === this.currentAgentId()).length,
    waiting: this.tickets().filter(t => t.owner === null).length,
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
    const index = current.findIndex(t => String(t.ticket_id) === String(ticket.ticket_id));

    if (index >= 0) {
      const updated = [...current];
      updated[index] = ticket;
      this.tickets.set(updated);
    } else {
      this.tickets.set([ticket, ...current]);
    }

    // Sync activeTicket if this is the currently active ticket
    const active = this.activeTicket();
    if (active && String(active.ticket_id) === String(ticket.ticket_id)) {
      this.activeTicket.set(ticket);
    }
  }

  /** Set the active ticket by ID */
  setActiveTicket(ticketId: string | null): void {
    if (!ticketId) {
      this.activeTicket.set(null);
      return;
    }
    const ticket = this.tickets().find(t => String(t.ticket_id) === String(ticketId)) ?? null;
    this.activeTicket.set(ticket);
  }

  /** Update unread count for a specific ticket */
  updateUnreadCount(ticketId: string, count: number): void {
    this.tickets.update(tickets =>
      tickets.map(t => String(t.ticket_id) === String(ticketId)
        ? { ...t, metrics: { ...t.metrics, unread_count: count } }
        : t
      )
    );
  }

  /** Mark a ticket as read */
  markAsRead(ticketId: string): void {
    this.tickets.update(tickets =>
      tickets.map(t => String(t.ticket_id) === String(ticketId)
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
    this.pendingAssignIds.set(new Set());
    this.assignmentErrors.set(new Map());
  }

  /**
   * Optimistic assignment: immediately update the ticket owner in the store.
   *
   * @param ticketId The ticket ID to assign
   * @param agentId The agent ID to assign to
   * @param agentName The agent name for display
   * @returns The previous owner (for rollback)
   */
  optimisticAssign(ticketId: string, agentId: number, agentName: string): TicketOwner | null {
    const ticketIdNum = parseInt(ticketId, 10);

    // Add to pending set
    this.pendingAssignIds.update(ids => {
      const newSet = new Set(ids);
      newSet.add(ticketIdNum);
      return newSet;
    });

    // Find and update the ticket
    const current = this.tickets();
    const index = current.findIndex(t => String(t.ticket_id) === String(ticketId));
    if (index < 0) {
      return null;
    }

    const ticket = current[index];
    const previousOwner = ticket.owner;

    // Update ticket owner optimistically
    this.addOrUpdateTicket({
      ...ticket,
      owner: { id: agentId, name: agentName },
    });

    return previousOwner;
  }

  /**
   * Rollback optimistic assignment: restore the previous owner.
   *
   * @param ticketId The ticket ID to rollback
   * @param previousOwner The previous owner to restore
   */
  rollbackAssign(ticketId: string, previousOwner: TicketOwner | null): void {
    const ticketIdNum = parseInt(ticketId, 10);

    // Remove from pending set
    this.pendingAssignIds.update(ids => {
      const newSet = new Set(ids);
      newSet.delete(ticketIdNum);
      return newSet;
    });

    // Restore previous owner
    const current = this.tickets();
    const index = current.findIndex(t => String(t.ticket_id) === String(ticketId));
    if (index >= 0) {
      this.addOrUpdateTicket({
        ...current[index],
        owner: previousOwner,
      });
    }
  }

  /**
   * Record an assignment error with auto-dismiss after 5 seconds.
   *
   * @param ticketId The ticket ID that failed
   * @param message The error message
   */
  assignmentError(ticketId: string, message: string): void {
    const ticketIdNum = parseInt(ticketId, 10);
    const until = Date.now() + 5000; // 5 seconds

    this.assignmentErrors.update(errors => {
      const newMap = new Map(errors);
      newMap.set(ticketIdNum, { message, until });
      return newMap;
    });

    // Auto-dismiss after 5 seconds
    setTimeout(() => {
      this.assignmentErrors.update(errors => {
        const newMap = new Map(errors);
        const error = newMap.get(ticketIdNum);
        if (error && error.until <= Date.now()) {
          newMap.delete(ticketIdNum);
        }
        return newMap;
      });
    }, 5000);
  }

  /**
   * Clear pending state for a ticket (called when Mercure event confirms assignment).
   *
   * @param ticketId The ticket ID to clear
   */
  clearPendingAssign(ticketId: string): void {
    const ticketIdNum = parseInt(ticketId, 10);
    this.pendingAssignIds.update(ids => {
      const newSet = new Set(ids);
      newSet.delete(ticketIdNum);
      return newSet;
    });
  }
}