# Tasks: Ticket List Assign

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~600 (4 new + 7 modified files) |
| 400-line budget risk | Medium |
| Chained PRs recommended | No |
| Suggested split | single PR (under 800-line review budget) |
| Delivery strategy | single-pr |
| Chain strategy | N/A |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: size-exception
400-line budget risk: Medium

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Backend: available-agents endpoint + voter wiring | PR 1 (single) | `php bin/phpunit tests/Controller/WorkspaceAgentControllerTest.php tests/Controller/TicketControllerAssignTest.php` | `curl GET /api/workspaces/{id}/available-agents` returns 200 with DTOs | Remove `WorkspaceAgentController`, revert `security.yaml` rule, revert `TicketController` attribute — list UI unaffected |

## Phase 1: Backend Foundation

- [x] 1.1 Create `apps/api/src/Application/DTO/AgentAvailabilityDTO.php` with public readonly properties: `id`, `name`, `is_available`, `active_ticket_count`, `max_active_tickets`.
- [x] 1.2 Add `AgentRepository::findActiveByWorkspace(Workspace): array` — returns active agents in workspace.
- [x] 1.3 Add `AgentAvailabilityService::listAvailableAgentsInWorkspace(Workspace, DateTimeImmutable): array` — fetches active agents, batch-loads shifts, applies existing 4-step cascade, returns `AgentAvailabilityDTO[]`.
- [x] 1.4 Write PHPUnit unit test for `listAvailableAgentsInWorkspace` covering: on-shift + under capacity → included; off-shift → excluded; at capacity → excluded; empty workspace → `[]`.
- [x] 1.5 Create `apps/api/src/Controller/WorkspaceAgentController.php` with `listAvailableAgents(int $id)` returning JSON; inject `AgentRepository` + `AgentAvailabilityService`.
- [x] 1.6 Add route `GET /api/workspaces/{id}/available-agents` (attribute routing).
- [x] 1.7 Update `apps/api/config/packages/security.yaml` — allow `ROLE_AGENT`, `ROLE_SUPERVISOR`, `ROLE_ADMIN` on `/api/workspaces/\d+/available-agents`.
- [x] 1.8 Write WebTestCase: 200 for authenticated agent, 401 unauthenticated, 404 unknown workspace, empty array when no agents.

## Phase 2: Backend Authorization

- [x] 2.1 Add `#[IsGranted('ASSIGN', subject: 'ticket')]` to `TicketController::assign()`; ensure ticket is loaded before voter invocation.
- [x] 2.2 Extend `TicketVoter::ASSIGN` to reject `resolved`/`closed` states with 409 (throw `StateConflictException`).
- [x] 2.3 Write WebTestCase for assign: owner self-assign 200; admin assigns 200; non-owner 403; resolved ticket 409; waiting→active transition.

## Phase 3: Frontend Foundation

- [x] 3.1 Create `apps/web/src/app/core/dto/agent-availability.dto.ts` with `AgentAvailabilityDTO` interface matching backend.
- [x] 3.2 Add `WorkspaceApiService.getAvailableAgents(workspaceId: number): Observable<AgentAvailabilityDTO[]>`.
- [x] 3.3 Add `TicketApiService.assignTicket(ticketId, agentId, assignedBy, notes?): Observable<Ticket>`.
- [x] 3.4 Extend `TicketStore` with signals: `pendingAssignIds: Set<number>`, `assignmentErrors: Map<number, {message, until}>`. Add methods `optimisticAssign(ticketId, agentId, agentName)`, `rollbackAssign(ticketId, previousOwner)`, `assignmentError(ticketId, message)`. Hook into Mercure `TicketAssigned` to clear pending.
- [x] 3.5 Write Jasmine tests for `optimisticAssign` (owner mutated, pending set), `rollbackAssign` (owner restored, pending cleared), `assignmentError` (map entry with 5s TTL).

## Phase 4: Frontend UI

- [x] 4.1 Add `@Input() availableAgents: AgentAvailabilityDTO[]` and `@Output() assign = EventEmitter<{ticketId, agentId}>` to `TicketCardComponent`. Gate dropdown visibility: only when ticket status ∈ {`new`, `active`, `waiting`} AND agents list non-empty.
- [x] 4.2 Implement CDK Overlay dropdown in `TicketCardComponent`: trigger button, portal with agent list, close on outside click / Escape / select. Show `active_ticket_count / max_active_tickets` per row.
- [x] 4.3 Render inline error badge in `TicketCardComponent` when `assignmentErrors` has entry for this ticket; auto-dismiss via `setTimeout` 5s.
- [x] 4.4 In `TicketListComponent`: fetch agents on workspace change (`WorkspaceStore.onWorkspaceChange`), pass as `[availableAgents]`, debounce 10s refresh on `TicketAssigned` Mercure events.
- [x] 4.5 Wire `TicketListComponent.onAssign({ticketId, agentId})` → `TicketStore.optimisticAssign` → `TicketApiService.assignTicket` → on error `rollbackAssign` + `assignmentError`.
- [ ] 4.6 Add component tests: dropdown opens/closes; agent selection emits `assign`; error badge auto-dismisses after 5s.

## Phase 5: Integration

- [ ] 5.1 E2E (Playwright): agent self-assign from list ≤2 clicks; supervisor reassigns; resolved ticket hides dropdown; 409 error shows badge that auto-dismisses.
- [ ] 5.2 Verify Mercure reconciliation: after successful assign, `TicketAssigned` event updates canonical owner and clears pending state.
