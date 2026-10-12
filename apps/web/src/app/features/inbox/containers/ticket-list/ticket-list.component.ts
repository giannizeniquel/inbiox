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
import { TicketStore } from '../../../../core/stores/ticket.store';
import { ConversationStore } from '../../../../core/stores/conversation.store';
import { AuthStore } from '../../../../core/stores/auth.store';
import { WorkspaceStore } from '../../../../core/stores/workspace.store';
import { TicketApiService } from '../../../../core/api/ticket-api.service';
import { MessageApiService, GetMessagesResponse } from '../../../../core/api/message-api.service';
import { WorkspaceApiService } from '../../../../core/api/workspace-api.service';
import { Ticket } from '../../../../core/models/ticket.model';
import { AgentAvailabilityDTO } from '../../../../core/dto/agent-availability.dto';
import { mapApiMessagesToCanonical } from '../../../../core/utils/message-mapper.util';
import { TicketCardComponent } from '../../components/ticket-card/ticket-card.component';
import { TicketSearchComponent } from '../../components/chat-search/ticket-search.component';
import { TicketFiltersComponent } from '../../components/chat-filters/ticket-filters.component';
import { TeamFilterComponent } from '../../components/team-filter/team-filter.component';
import { VirtualScrollViewportComponent } from '../../../../shared/components/virtual-scroll-viewport/virtual-scroll-viewport.component';
import { VirtualListCoordinatorDirective } from '../../../../shared/directives/virtual-list-coordinator.directive';
import { RealtimeEvent, RealtimeEventType, TicketAssignedPayload } from '../../../../core/models/realtime-event.model';
import { EventBusService } from '../../../../core/realtime/event-bus.service';

@Component({
  selector: 'app-ticket-list',
  standalone: true,
  imports: [
    CommonModule,
    TicketCardComponent,
    TicketSearchComponent,
    TicketFiltersComponent,
    TeamFilterComponent,
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
  private readonly workspaceApi = inject(WorkspaceApiService);
  private readonly authStore = inject(AuthStore);
  private readonly workspaceStore = inject(WorkspaceStore);
  private readonly eventBus = inject(EventBusService);
  private readonly destroyRef = inject(DestroyRef);

  /** Reference to the coordinator directive on the viewport */
  protected readonly coordinator = viewChild.required(VirtualListCoordinatorDirective<Ticket>);

  /** Resolver that maps a ticket to its unique id string */
  protected readonly idOfTicket = (ticket: Ticket): string => ticket.ticket_id;

  /** Available agents for the current workspace */
  readonly availableAgents = signal<AgentAvailabilityDTO[]>([]);

  /** Agent name lookup map for error messages */
  private agentNameMap = signal<Map<number, string>>(new Map());

  // Signals from store
  readonly tickets = computed(() => this.ticketStore.filteredTickets());
  readonly loading = this.ticketStore.loading;
  readonly selectedTicketId = computed(() => this.ticketStore.activeTicket()?.ticket_id ?? null);

  /** Debounce timer for agent refresh */
  private refreshDebounceTimer: ReturnType<typeof setTimeout> | null = null;

  constructor() {
    // Reactive agent loading: watches activeWorkspace signal.
    // effect() must be in injection context (constructor/field initializer),
    // not inside ngOnInit.
    effect(() => {
      const ws = this.workspaceStore.activeWorkspace();
      const wsId = ws?.workspace_id;

      if (wsId) {
        this.loadAvailableAgents(wsId);
      } else {
        // No workspace yet or workspace with null id — clear agents
        this.availableAgents.set([]);
      }
    });
  }

  ngOnInit(): void {
    this.loadTickets();
    this.setupMercureRefresh();
  }

  private loadTickets(): void {
    this.ticketStore.setLoading(true);

    this.ticketApi.getTickets(this.ticketStore.selectedTeamId()).subscribe({
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

  /**
   * Load available agents for a workspace.
   *
   * @param workspaceId Optional workspace ID. If not provided, uses active workspace.
   */
  private loadAvailableAgents(workspaceId?: number): void {
    const wsId = workspaceId ?? this.workspaceStore.activeWorkspace()?.workspace_id;
    if (!wsId) {
      this.availableAgents.set([]);
      return;
    }

    this.workspaceApi.getAvailableAgents(wsId).subscribe({
      next: (agents) => {
        // Filter out the current user — no one can assign to themselves via dropdown
        const currentAgentId = this.authStore.currentUser()?.agent_id;
        const filtered = currentAgentId
          ? agents.filter(a => a.id !== currentAgentId)
          : agents;

        this.availableAgents.set(filtered);
        // Build agent name lookup map
        const nameMap = new Map<number, string>();
        for (const agent of filtered) {
          nameMap.set(agent.id, agent.name);
        }
        this.agentNameMap.set(nameMap);
      },
      error: (err) => {
        console.error('[TicketList] Failed to load available agents:', err);
        this.availableAgents.set([]);
      },
    });
  }

  /**
   * Set up Mercure event listener for debounced agent refresh.
   * When a ticket is assigned, the capacity of agents changes.
   */
  private setupMercureRefresh(): void {
    this.eventBus.events$.pipe(
      filter(e => e.eventType === RealtimeEventType.TicketAssigned),
      takeUntilDestroyed(this.destroyRef),
    ).subscribe(() => {
      this.debouncedRefreshAgents();
    });
  }

  /**
   * Debounced refresh of available agents (10s debounce).
   */
  private debouncedRefreshAgents(): void {
    if (this.refreshDebounceTimer) {
      clearTimeout(this.refreshDebounceTimer);
    }
    this.refreshDebounceTimer = setTimeout(() => {
      this.loadAvailableAgents();
    }, 10000);
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

  /**
   * Handle team filter selection (team-area-entity, Slice 3). Unlike the
   * status tabs above (client-side filter over an already-loaded list),
   * the team filter is server-side — it re-fetches the ticket list scoped
   * to the selected team, composable with any other query-time filter.
   */
  onTeamFilterChange(teamId: number | null): void {
    this.ticketStore.setTeamId(teamId);
    this.loadTickets();
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
  canAssignTicket(ticket: Ticket): boolean {
    const user = this.authStore.currentUser();
    if (!user) return false;

    // Admin or supervisor: always allowed
    const roles = user.roles ?? [];
    if (roles.includes('ROLE_ADMIN') || roles.includes('ROLE_SUPERVISOR')) {
      return true;
    }

    // Agent: must own the ticket
    const agentId = user.agent_id;
    if (agentId && ticket.owner?.id === agentId) {
      return true;
    }

    return false;
  }

  /**
   * Handle assignment from TicketCardComponent.
   * Performs optimistic update, then API call, then rollback on error.
   */
  onAssign(event: { ticketId: string; agentId: number }): void {
    const { ticketId, agentId } = event;

    // Find agent name for display
    const agent = this.availableAgents().find(a => a.id === agentId);
    const agentName = agent?.name ?? `Agent #${agentId}`;

    // Get current user for assignedBy
    const currentUser = this.authStore.currentUser();
    const assignedBy = currentUser?.email ?? currentUser?.agent_name ?? 'Unknown';

    // Optimistic update
    const previousOwner = this.ticketStore.optimisticAssign(ticketId, agentId, agentName);

    // API call
    this.ticketApi.assignTicket(ticketId, agentId, assignedBy).subscribe({
      next: () => {
        // Success: clear pending state (Mercure event will reconcile canonical owner)
        this.ticketStore.clearPendingAssign(ticketId);
      },
      error: (err) => {
        // Error: rollback optimistic update
        this.ticketStore.rollbackAssign(ticketId, previousOwner);
        // Show error badge for 5s
        const errorMessage = err?.error?.error ?? 'Error al asignar ticket';
        this.ticketStore.assignmentError(ticketId, errorMessage);

        // Race condition: the target agent's shift/override may have changed
        // between dropdown-load and assign-click. Reload the roster so the
        // agent re-renders under Inactivo instead of leaving a stale dropdown.
        if (err?.status === 409 && err?.error?.type === 'AGENT_NOT_ACTIVE') {
          this.loadAvailableAgents();
        }
      },
    });
  }
}
