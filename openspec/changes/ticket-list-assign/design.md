# Design: Ticket List Assign

## Technical Approach

Reuse the existing `AssignTicketUseCase` for writes; add a read-only workspace endpoint that returns available agents using the already-cascaded `AgentAvailabilityService`. Frontend: centralize the agents list at `TicketListComponent`, push it into each `TicketCardComponent` via input, route assignment through the existing `TicketStore` so optimistic update and Mercure event converge on the same model.

## Architecture Decisions

### Decision: Backend endpoint at `/api/workspaces/{id}/available-agents`

**Choice**: New `WorkspaceAgentController::listAvailableAgents(int $id)`. Read-only.
**Rejected**: extend `MeController` (`/me/*` is current-user scope), add to `AgentAvailabilityController` (gated by per-agent ownership), `GET /workspaces/{id}/agents?available=true` (mixed shape).
**Rationale**: Mirrors REST resource hierarchy. Symmetrical with `POST /tickets/{id}/assign`. Read-only — zero rollback risk.

### Decision: Voter delta on `POST /tickets/{id}/assign`

**Choice**: Add method-level `#[IsGranted('ASSIGN', subject: 'ticket')]` on `TicketController::assign()`; the existing `TicketVoter` already encodes the owner/role check.
**Rejected**: new `TicketOwnerVoter` (`TicketVoter` already correct), move check into use case (breaks "voter at boundary" pattern).
**Rationale**: Voter is the contract; controller just invokes it. Existing exception handling preserved.

### Decision: Availability combines workspace filter + cascade

**Choice**: New `AgentAvailabilityService::listAvailableAgentsInWorkspace(Workspace, DateTimeImmutable)`: 1 query active-in-workspace agents (`findByWorkspace` + `active=true`), 1 batch query shifts, then apply the existing 4-step cascade per agent. Return DTOs.
**Rejected**: post-filter `listAvailableAgents()` (fetches extra rows), separate endpoint per filter.
**Rationale**: Same cascade semantics as auto-assign (single source of truth).

### Decision: Frontend agents cache at `TicketListComponent` level

**Choice**: `WorkspaceApiService.getAvailableAgents(workspaceId)` returns `Observable<AgentAvailabilityDTO[]>`. `TicketListComponent` calls it once per workspace via `WorkspaceStore.onWorkspaceChange`; result is passed as `[availableAgents]` input to every `TicketCardComponent`. Debounced 10s refresh on `TicketAssigned` Mercure events (capacity changed).
**Rejected**: per-card fetch (N+1), cache in `TicketStore` (per-ticket, not per-workspace), `HttpInterceptor` cache (staleness).
**Rationale**: Workspace-scoped shared cache; invalidation on workspace switch + capacity change.

### Decision: Dropdown uses CDK Overlay (portal)

**Choice**: Render via Angular CDK `Overlay` attached to body using trigger's `getBoundingClientRect()`. Closes on outside click / Escape / agent select.
**Rejected**: `position: absolute` inside card (virtual scroll `overflow: hidden` clips), `position: fixed` with manual coords (no outside-click/Escape/aria).
**Rationale**: `@angular/cdk/overlay` is a transitive dep; portal escapes virtual-scroll clipping.

### Decision: Optimistic update + 5s inline error badge

**Choice**: `TicketStore.optimisticAssign(ticketId, agentId, name)` mutates `owner` field immediately, sets `pendingAssignIds` Set. Card shows subtle spinner. On HTTP error: rollback `owner` and raise `assignmentError(ticketId, message)`; `TicketListComponent` renders inline badge that auto-dismisses after 5s. Mercure `TicketAssigned` reconciles to canonical owner.
**Rejected**: no optimistic (spec scenario 7 requires it), global toast (spec says "inline error badge").
**Rationale**: Aligns with existing `addOrUpdateTicket` reconciliation pattern.

## Data Flow

```
TicketListComponent.ngOnInit
  └─→ WorkspaceStore.onWorkspaceChange → fetch getAvailableAgents(workspaceId)
        └─→ [availableAgents] → each TicketCardComponent
User selects agent
  └─→ (assign) → TicketListComponent.onAssign → TicketStore.optimisticAssign
        └─→ TicketApiService.assignTicket
              ├─ success → Mercure TicketAssigned → store reconciles
              └─ error   → rollback → inline error badge 5s
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/api/src/Controller/WorkspaceAgentController.php` | Create | `GET /api/workspaces/{id}/available-agents` |
| `apps/api/src/Application/DTO/AgentAvailabilityDTO.php` | Create | `{id, name, is_available, active_ticket_count, max_active_tickets}` |
| `apps/api/src/Application/Service/AgentAvailabilityService.php` | Modify | Add `listAvailableAgentsInWorkspace(Workspace, DateTimeImmutable)` |
| `apps/api/src/Controller/TicketController.php` | Modify | Add `#[IsGranted('ASSIGN', subject: 'ticket')]`; load ticket for voter |
| `apps/api/config/packages/security.yaml` | Modify | Add access rule for `/api/workspaces/{id}/available-agents` |
| `apps/web/src/app/core/dto/agent-availability.dto.ts` | Create | `AgentAvailabilityDTO` interface |
| `apps/web/src/app/core/api/workspace-api.service.ts` | Modify | Add `getAvailableAgents(workspaceId)` |
| `apps/web/src/app/core/api/ticket-api.service.ts` | Modify | Add `assignTicket(ticketId, agentId, assignedBy)` |
| `apps/web/src/app/core/stores/ticket.store.ts` | Modify | `optimisticAssign`, `rollbackAssign`, `assignmentError`, `pendingAssignIds` |
| `apps/web/.../ticket-card.component.{ts,html,scss}` | Modify | `[availableAgents]` input, `(assign)` output, CDK Overlay dropdown, error badge |
| `apps/web/.../ticket-list.component.{ts,html}` | Modify | Fetch + pass agents; `onAssign` handler; error badge container |

## Interfaces / Contracts

```ts
export interface AgentAvailabilityDTO {
  id: number;
  name: string;
  is_available: boolean;
  active_ticket_count: number;
  max_active_tickets: number;
}
```

`POST /api/tickets/{id}/assign` body unchanged: `{ agentId, assignedBy, notes? }`. `assignedBy` resolution: admin/supervisor → `user.email`; agent self → `agent.name`.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `listAvailableAgentsInWorkspace` cascade (override wins, capacity=0, off-shift) | PHPUnit, in-memory shifts |
| Integration | `WorkspaceAgentController` 200/401/404; voter (owner OK, non-owner 403, admin OK, resolved 409) | WebTestCase |
| Frontend unit | `TicketStore.optimisticAssign` + rollback; card visibility (status + permission gate) | Jasmine + signals |
| E2E | Agent self-reassign ≤2 clicks; supervisor reassigns; resolved hidden; 409 badge auto-dismiss | Playwright |

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

No migration. Deploy backend first (read endpoint safe alone), then frontend. Revert = disable dropdown trigger in `TicketCardComponent` template (one line); backend endpoint stays as read-only.

## Open Questions

- Debounced 10s refresh on `TicketAssigned` vs. per-event fetch — chosen debounce; revisit if capacity pressure is high.
- Single-workspace assumption: if no active workspace, dropdown hidden (matches existing list behavior).
