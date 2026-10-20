import { Injectable, signal, computed, inject, DestroyRef } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { filter } from 'rxjs';
import { Ticket } from './ticket.model';
import { applyTicketEvent } from './ticket-realtime.reducer';
import { TipoDeEventoEnTiempoReal } from '../../core/tiempo-real/evento-en-tiempo-real.model';
import { EventBusService } from '../../core/tiempo-real/event-bus.service';
import { AuthStore } from '../../core/auth/auth.store';
import { EspacioDeTrabajoStore } from '../administracion/espacio-de-trabajo.store';

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

export type FiltroDeTicket = 'mine' | 'waiting' | 'internal';

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
  readonly filtro = signal<FiltroDeTicket>('mine');

  /**
   * Single-select team filter (team-area-entity, Slice 3). Server-side
   * filter — independent of and composable with `filter` above — forwarded
   * as a query param to `GET /api/tickets`. `null` = no team scope.
   */
  readonly equipoSeleccionadoId = signal<number | null>(null);

  /** Loading state */
  readonly loading = signal<boolean>(false);

  /** Assignment errors with auto-dismiss timestamps */
  readonly erroresDeAsignacion = signal<Map<number, AssignmentError>>(new Map());

  private readonly authStore = inject(AuthStore);
  private readonly espacioDeTrabajoStore = inject(EspacioDeTrabajoStore);

  /** Current logged-in agent's ID (null if admin without agent) */
  private readonly agenteActualId = computed(() => this.authStore.usuarioActual()?.agent_id ?? null);

  constructor() {
    this.initEventBus();
  }

  /**
   * Set up EventBus subscription for realtime events.
   * Handles: TicketCreated, TicketStatusChanged, TicketAssigned, MessageAdded, TicketRead
   * (see `applyTicketEvent`).
   */
  private initEventBus(): void {
    const eventBus = inject(EventBusService);
    const destroyRef = inject(DestroyRef);

    eventBus.events$.pipe(
      filter(e =>
        e.eventType === TipoDeEventoEnTiempoReal.TicketCreated
        || e.eventType === TipoDeEventoEnTiempoReal.TicketStatusChanged
        || e.eventType === TipoDeEventoEnTiempoReal.TicketAssigned
        || e.eventType === TipoDeEventoEnTiempoReal.MessageAdded
        || e.eventType === TipoDeEventoEnTiempoReal.TicketRead
      ),
      takeUntilDestroyed(destroyRef),
    ).subscribe(e => applyTicketEvent(e, this, this.espacioDeTrabajoStore));
  }

  /** Filtered tickets based on current filter */
  readonly filteredTickets = computed<Ticket[]>(() => {
    const all = this.tickets();
    const f = this.filtro();

    switch (f) {
      case 'mine':
        return all.filter(t => t.owner?.id === this.agenteActualId());
      case 'waiting':
        return all.filter(t => t.owner === null); // unassigned tickets
      case 'internal':
        return all; // all tickets in workspace
      default:
        return all;
    }
  });

  /** Count for each filter tab */
  readonly conteosDeFiltro = computed(() => ({
    mine: this.tickets().filter(t => t.owner?.id === this.agenteActualId()).length,
    waiting: this.tickets().filter(t => t.owner === null).length,
    internal: this.tickets().length,
  }));

  /** Total unread count across all tickets */
  readonly totalNoLeidos = computed(() =>
    this.tickets().reduce((sum, t) => sum + (t.metrics?.unread_count ?? 0), 0)
  );

  /** Set loading state */
  setLoading(loading: boolean): void {
    this.loading.set(loading);
  }

  /** Set the active filter */
  setFiltro(filtro: FiltroDeTicket): void {
    this.filtro.set(filtro);
  }

  /** Set the active team filter (null clears it) */
  setEquipoId(equipoId: number | null): void {
    this.equipoSeleccionadoId.set(equipoId);
  }

  /** Load tickets, enforcing a max cap of 1000 */
  cargarTickets(tickets: Ticket[]): void {
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
  updateConteoDeNoLeidos(ticketId: string, count: number): void {
    this.tickets.update(tickets =>
      tickets.map(t => String(t.ticket_id) === String(ticketId)
        ? { ...t, metrics: { ...t.metrics, unread_count: count } }
        : t
      )
    );
  }

  /** Mark a ticket as read */
  marcarComoLeido(ticketId: string): void {
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
    this.filtro.set('mine');
    this.equipoSeleccionadoId.set(null);
    this.loading.set(false);
    this.erroresDeAsignacion.set(new Map());
  }

  /**
   * Optimistic assignment: immediately update the ticket owner in the store.
   *
   * @param ticketId The ticket ID to assign
   * @param agenteId The agent ID to assign to
   * @param nombreDeAgente The agent name for display
   * @returns The previous owner (for rollback)
   */
  asignarOptimista(ticketId: string, agenteId: number, nombreDeAgente: string): TicketOwner | null {
    // Find and update the ticket
    const current = this.tickets();
    const index = current.findIndex(t => String(t.ticket_id) === String(ticketId));
    if (index < 0) {
      return null;
    }

    const ticket = current[index];
    const responsableAnterior = ticket.owner;

    // Update ticket owner optimistically
    this.addOrUpdateTicket({
      ...ticket,
      owner: { id: agenteId, name: nombreDeAgente },
    });

    return responsableAnterior;
  }

  /**
   * Rollback optimistic assignment: restore the previous owner.
   *
   * @param ticketId The ticket ID to rollback
   * @param responsableAnterior The previous owner to restore
   */
  revertirAsignacion(ticketId: string, responsableAnterior: TicketOwner | null): void {
    // Restore previous owner
    const current = this.tickets();
    const index = current.findIndex(t => String(t.ticket_id) === String(ticketId));
    if (index >= 0) {
      this.addOrUpdateTicket({
        ...current[index],
        owner: responsableAnterior,
      });
    }
  }

  /**
   * Record an assignment error with auto-dismiss after 5 seconds.
   *
   * @param ticketId The ticket ID that failed
   * @param message The error message
   */
  errorDeAsignacion(ticketId: string, message: string): void {
    const ticketIdNum = parseInt(ticketId, 10);
    const until = Date.now() + 5000; // 5 seconds

    this.erroresDeAsignacion.update(errors => {
      const newMap = new Map(errors);
      newMap.set(ticketIdNum, { message, until });
      return newMap;
    });

    // Auto-dismiss after 5 seconds
    setTimeout(() => {
      this.erroresDeAsignacion.update(errors => {
        const newMap = new Map(errors);
        const error = newMap.get(ticketIdNum);
        if (error && error.until <= Date.now()) {
          newMap.delete(ticketIdNum);
        }
        return newMap;
      });
    }, 5000);
  }
}
