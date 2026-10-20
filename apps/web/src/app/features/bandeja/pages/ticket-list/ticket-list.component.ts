import {
  Component,
  inject,
  OnInit,
  signal,
  computed,
  viewChild,
  DestroyRef,
  effect,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { filter, take } from 'rxjs/operators';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { TicketStore } from '../../../../data-access/ticket/ticket.store';
import { ConversacionStore } from '../../../../data-access/conversacion/conversacion.store';
import { AuthStore } from '../../../../core/auth/auth.store';
import { EspacioDeTrabajoStore } from '../../../../data-access/administracion/espacio-de-trabajo.store';
import { TicketApiService } from '../../../../data-access/ticket/ticket-api.service';
import { MensajeApiService, GetMensajesResponse } from '../../../../data-access/conversacion/mensaje-api.service';
import { DisponibilidadApiService } from '../../../../data-access/disponibilidad/disponibilidad-api.service';
import { AsignacionApiService } from '../../../../data-access/asignacion/asignacion-api.service';
import { Ticket } from '../../../../data-access/ticket/ticket.model';
import { DisponibilidadDeAgenteDTO } from '../../../../data-access/disponibilidad/disponibilidad-de-agente.dto';
import { mapearMensajesDeApiACanonicos } from '../../../../data-access/conversacion/mensaje-mapper.util';
import { TicketCardComponent } from '../../ui/ticket-card/ticket-card.component';
import { TicketSearchComponent } from '../../ui/ticket-search/ticket-search.component';
import { FiltrosDeTicketComponent } from '../../ui/filtros-de-ticket/filtros-de-ticket.component';
import { FiltroDeEquipoComponent } from '../../ui/filtro-de-equipo/filtro-de-equipo.component';
import { VirtualScrollViewportComponent } from '../../../../shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component';
import { VirtualListCoordinatorDirective } from '../../../../shared/directives/virtual-list-coordinator.directive';
import { EventoEnTiempoReal, TipoDeEventoEnTiempoReal, TicketAsignadoPayload } from '../../../../core/tiempo-real/evento-en-tiempo-real.model';
import { EventBusService } from '../../../../core/tiempo-real/event-bus.service';

@Component({
  selector: 'app-ticket-list',
  standalone: true,
  imports: [
    CommonModule,
    TicketCardComponent,
    TicketSearchComponent,
    FiltrosDeTicketComponent,
    FiltroDeEquipoComponent,
    VirtualScrollViewportComponent,
    VirtualListCoordinatorDirective,
  ],
  templateUrl: './ticket-list.component.html',
  styleUrl: './ticket-list.component.scss',
})
export class TicketListComponent implements OnInit {
  protected readonly ticketStore = inject(TicketStore);
  protected readonly conversacionStore = inject(ConversacionStore);
  private readonly ticketApi = inject(TicketApiService);
  private readonly mensajeApi = inject(MensajeApiService);
  private readonly disponibilidadApi = inject(DisponibilidadApiService);
  private readonly asignacionApi = inject(AsignacionApiService);
  private readonly authStore = inject(AuthStore);
  private readonly espacioDeTrabajoStore = inject(EspacioDeTrabajoStore);
  private readonly eventBus = inject(EventBusService);
  private readonly destroyRef = inject(DestroyRef);

  /** Reference to the coordinator directive on the viewport */
  protected readonly coordinator = viewChild.required(VirtualListCoordinatorDirective<Ticket>);

  /** Resolver that maps a ticket to its unique id string */
  protected readonly idOfTicket = (ticket: Ticket): string => ticket.ticketId;

  /** Available agents for the current workspace */
  readonly agentesDisponibles = signal<DisponibilidadDeAgenteDTO[]>([]);

  /** Agent name lookup map for error messages */
  private nombreDeAgenteMap = signal<Map<number, string>>(new Map());

  // Signals from store
  readonly tickets = computed(() => this.ticketStore.filteredTickets());
  readonly loading = this.ticketStore.loading;
  readonly selectedTicketId = computed(() => this.ticketStore.activeTicket()?.ticketId ?? null);

  /** Debounce timer for agent refresh */
  private refreshDebounceTimer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    // Reactive agent loading: watches activeWorkspace signal.
    // effect() must be in injection context (constructor/field initializer),
    // not inside ngOnInit.
    effect(() => {
      const ws = this.espacioDeTrabajoStore.espacioDeTrabajoActivo();
      const wsId = ws?.workspace_id;

      if (wsId) {
        this.cargarAgentesDisponibles(wsId);
      } else {
        // No workspace yet or workspace with null id — clear agents
        this.agentesDisponibles.set([]);
      }
    });
  }

  ngOnInit(): void {
    this.cargarTickets();
    this.setupMercureRefresh();
  }

  private cargarTickets(): void {
    this.ticketStore.setLoading(true);

    this.ticketApi.getTickets(this.ticketStore.equipoSeleccionadoId()).subscribe({
      next: (tickets) => {
        this.ticketStore.cargarTickets(tickets);
        this.ticketStore.setLoading(false);
      },
      error: (err) => {
        console.error('[TicketList] Failed to load tickets:', err);
        this.ticketStore.setLoading(false);
      },
    });
  }

  /**
   * Load available agents for a workspace.
   *
   * @param espacioDeTrabajoId Optional workspace ID. If not provided, uses active workspace.
   */
  private cargarAgentesDisponibles(espacioDeTrabajoId?: number): void {
    const wsId = espacioDeTrabajoId ?? this.espacioDeTrabajoStore.espacioDeTrabajoActivo()?.workspace_id;
    if (!wsId) {
      this.agentesDisponibles.set([]);
      return;
    }

    this.disponibilidadApi.getAgentesDisponibles(wsId).subscribe({
      next: (agentes) => {
        // Filter out the current user — no one can assign to themselves via dropdown
        const agenteActualId = this.authStore.usuarioActual()?.agent_id;
        const filtered = agenteActualId
          ? agentes.filter(a => a.id !== agenteActualId)
          : agentes;

        this.agentesDisponibles.set(filtered);
        // Build agent name lookup map
        const nameMap = new Map<number, string>();
        for (const agente of filtered) {
          nameMap.set(agente.id, agente.name);
        }
        this.nombreDeAgenteMap.set(nameMap);
      },
      error: (err) => {
        console.error('[TicketList] Failed to load available agents:', err);
        this.agentesDisponibles.set([]);
      },
    });
  }

  /**
   * Set up Mercure event listener for debounced agent refresh.
   * When a ticket is assigned, the capacity of agents changes.
   */
  private setupMercureRefresh(): void {
    this.eventBus.events$.pipe(
      filter(e => e.tipoDeEvento === TipoDeEventoEnTiempoReal.TicketAsignado),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe(() => {
      this.debouncedRefrescarAgentes();
    });
  }

  /**
   * Debounced refresh of available agents (10s debounce).
   */
  private debouncedRefrescarAgentes(): void {
    if (this.refreshDebounceTimer) {
      clearTimeout(this.refreshDebounceTimer);
    }
    this.refreshDebounceTimer = setTimeout(() => {
      this.cargarAgentesDisponibles();
    }, 10000);
  }

  selectTicket(ticket: Ticket): void {
    this.ticketStore.setActiveTicket(ticket.ticketId);
    this.conversacionStore.reset();
    this.conversacionStore.setLoading(true);

    this.mensajeApi.getMensajes(ticket.ticketId, 20, 0).pipe(take(1)).subscribe({
      next: (resp: GetMensajesResponse) => {
        this.conversacionStore.cargarMensajes(mapearMensajesDeApiACanonicos(resp, ticket.ticketId));
        this.conversacionStore.setHasMore(resp.meta.hayMas);
        this.conversacionStore.setLoading(false);
      },
      error: (err) => {
        console.error('[TicketList] Failed to load messages:', err);
        this.conversacionStore.setLoading(false);
      },
    });
  }

  onFiltroChange(filtro: 'mine' | 'waiting' | 'internal'): void {
    this.ticketStore.setFiltro(filtro);
  }

  /**
   * Handle team filter selection (team-area-entity, Slice 3). Unlike the
   * status tabs above (client-side filter over an already-loaded list),
   * the team filter is server-side — it re-fetches the ticket list scoped
   * to the selected team, composable with any other query-time filter.
   */
  onFiltroDeEquipoChange(equipoId: number | null): void {
    this.ticketStore.setEquipoId(equipoId);
    this.cargarTickets();
  }

  /** Handle ticket search query from TicketSearchComponent */
  onSearch(query: string): void {
    // TODO: implement server-side search via TicketApiService
    console.log('[TicketList] Search:', query);
  }

  /**
   * Determine whether the current user can assign this ticket.
   * Admin/supervisor can always assign. Agent can only assign own tickets.
   */
  puedeAsignarTicket(ticket: Ticket): boolean {
    const usuario = this.authStore.usuarioActual();
    if (!usuario) return false;

    // Admin or supervisor: always allowed
    const roles = usuario.roles ?? [];
    if (roles.includes('ROLE_ADMIN') || roles.includes('ROLE_SUPERVISOR')) {
      return true;
    }

    // Agent: must own the ticket
    const agenteId = usuario.agent_id;
    if (agenteId && ticket.responsable?.id === agenteId) {
      return true;
    }

    return false;
  }

  /**
   * Handle assignment from TicketCardComponent.
   * Performs optimistic update, then API call, then rollback on error.
   */
  onAsignar(event: { ticketId: string; agentId: number }): void {
    const { ticketId, agentId } = event;

    // Find agent name for display
    const agente = this.agentesDisponibles().find(a => a.id === agentId);
    const nombreDeAgente = agente?.name ?? `Agent #${agentId}`;

    // Get current user for assignedBy
    const usuarioActual = this.authStore.usuarioActual();
    const asignadoPor = usuarioActual?.email ?? usuarioActual?.agent_name ?? 'Unknown';

    // Optimistic update
    const responsableAnterior = this.ticketStore.asignarOptimista(ticketId, agentId, nombreDeAgente);

    // API call
    // On success the TicketAssigned event reconciles the canonical owner.
    this.asignacionApi.asignarTicket(ticketId, agentId, asignadoPor).subscribe({
      error: (err) => {
        // Error: rollback optimistic update
        this.ticketStore.revertirAsignacion(ticketId, responsableAnterior);
        // Show error badge for 5s
        const errorMessage = err?.error?.error ?? 'Error al asignar ticket';
        this.ticketStore.errorDeAsignacion(ticketId, errorMessage);

        // Race condition: the target agent's shift/override may have changed
        // between dropdown-load and assign-click. Reload the roster so the
        // agent re-renders under Inactivo instead of leaving a stale dropdown.
        if (err?.status === 409 && err?.error?.type === 'AGENT_NOT_ACTIVE') {
          this.cargarAgentesDisponibles();
        }
      },
    });
  }
}
