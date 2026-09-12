# Design: Ticket Assign Dropdown — Full Roster with Activo/Inactivo Status

## Technical Approach

Add a second, narrower eligibility predicate next to the existing 4-step cascade instead of forking it. `isActiveNow()` = account-active AND (unexpired override ?? shift match). Capacity is never consulted. The roster endpoint and the assign guard both call it; `isAvailable()`, `isInShift()` and every auto-assign path stay byte-identical.

## Architecture Decisions

| Decision | Choice | Rejected alternative | Rationale |
|---|---|---|---|
| Predicate placement | New `isActiveNow(Agent $agent, array $shifts, \DateTimeImmutable $atUtc): bool` on `AgentAvailabilityService`, public, next to `isAvailable()` | Flag argument on `isAvailable()` | A boolean flag mutates 51 existing tests' subject; a separate method is additive and self-documenting. |
| Shift source | Explicit `array $shifts` parameter | Always `$agent->getShifts()` | Same method serves the batch roster (preloaded map) and the single-agent guard (`$agent->getShifts()->toArray()`, one lazy query) with no N+1. |
| Roster method | New `listWorkspaceAgentRoster(Workspace, \DateTimeImmutable): AgentAvailabilityDTO[]`; **delete** `listAvailableAgentsInWorkspace()` | Keep both methods | The old method has exactly one consumer (this controller) and would otherwise survive as dead code forcing the DTO to carry both `is_available` and `is_active_now`. |
| DTO | Rename field `isAvailable` → `isActiveNow`; `fromEntity(Agent, int $activeTicketCount, bool $isActiveNow)` takes the computed value | Reuse `Agent::isAvailable()` | That accessor maps to the legacy `agents.is_available` column the cascade never reads (confirmed dead in exploration). |
| Guard position | Inside `assignTicket()`'s transaction, after agent lookup, ticket lookup and `PESSIMISTIC_WRITE` lock, **before** `recordOwnershipChange()` | Before ticket lookup / in the controller | Preserves error precedence: existence (400) → authorization (403, voter) → state conflict (409). Placing it before the audit call means a rejection writes no assignment row and emits no event; the transaction rolls back on throw. |
| Error shape | `AgentNotActiveException extends StateConflictException`, caught **before** the generic `StateConflictException` arm | Reuse the generic 409 | Frontend needs a machine-readable discriminator to trigger a roster refresh on a stale-dropdown race. |

## Data Flow

    GET /workspaces/{id}/available-agents
      findByWorkspace() ─→ filter active===true ─→ findByAgents() (1 query)
        └─→ isActiveNow() per agent ─→ DTO[] (Activo first, then id ASC)

    POST /tickets/{id}/assign
      voter(ASSIGN) ─→ agent? ─→ ticket? ─→ LOCK ─→ isActiveNow() ─┬─ true  → recordOwnershipChange
                                                                   └─ false → AgentNotActiveException → 409

## File Changes

| File | Action | Description |
|---|---|---|
| `apps/api/src/Application/Service/AgentAvailabilityService.php` | Modify | `+isActiveNow()`, `+listWorkspaceAgentRoster()`, `-listAvailableAgentsInWorkspace()` |
| `apps/api/src/Application/DTO/AgentAvailabilityDTO.php` | Modify | `isActiveNow` field, `is_active_now` key, 3-arg `fromEntity()` |
| `apps/api/src/Exception/AgentNotActiveException.php` | Create | `extends StateConflictException`; `::forAgent(int $id, string $name)` |
| `apps/api/src/Controller/WorkspaceAgentController.php` | Modify | Call the roster method (route unchanged) |
| `apps/api/src/Application/UseCase/AssignTicketUseCase.php` | Modify | Inject `AgentAvailabilityService`; add guard |
| `apps/api/src/Controller/TicketController.php` | Modify | `catch (AgentNotActiveException)` → `{"type":"AGENT_NOT_ACTIVE","error":…}`, 409 |
| `apps/web/src/app/core/dto/agent-availability.dto.ts` | Modify | `is_available` → `is_active_now` |
| `apps/web/.../ticket-card.component.ts` | Modify | Drop `agents.length > 0`; `+activeAgents()`/`+inactiveAgents()`; `selectAgent()` early-returns on inactive |
| `apps/web/.../ticket-card.component.html` | Modify | Two `@for` groups with headers, `[disabled]`/`aria-disabled` + badge on Inactivo rows |
| `apps/web/.../ticket-card.component.scss` | Modify | Badge + disabled-row styles beside `status-available`/`status-unavailable` |
| `apps/web/.../ticket-list.component.ts` | Modify | On 409 `AGENT_NOT_ACTIVE`, call `loadAvailableAgents()` after rollback |

## Interfaces / Contracts

```php
// Guard, after $this->em->lock($ticket, LockMode::PESSIMISTIC_WRITE):
if (!$this->availability->isActiveNow($agent, $agent->getShifts()->toArray(), new \DateTimeImmutable('now', new \DateTimeZone('UTC')))) {
    throw AgentNotActiveException::forAgent($agentId, $agent->getName());
}
```

Response `200 GET available-agents`: `[{id,name,is_active_now,active_ticket_count,max_active_tickets}]` — every `active=true` workspace agent, never an `active=false` one.

## Race-Condition UX

The dropdown is a shared snapshot refreshed on a 10s debounce, so a shift can end between load and click. `onAssign`'s existing `error` arm already surfaces `err.error.error`; it gains one branch: on `status===409 && err.error?.type==='AGENT_NOT_ACTIVE'`, rollback as today, show the badge, and immediately reload the roster so the agent re-renders under Inactivo.

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Unit (new) | `isActiveNow` branches: inactive account, override-true, override-false, expired override → in-shift, expired override → out-of-shift, no-override in/out of shift, no shifts | New section in `AgentAvailabilityServiceTest`; explicit `$atUtc`, no capacity mock expectations (asserts capacity is never queried) |
| Unit (new) | `listWorkspaceAgentRoster`: `findByWorkspace` used, `active=false` excluded, at-capacity agent still Activo, Activo-before-Inactivo sort, one `findByAgents` call | Same mocks as the deleted tests |
| Functional | Assign success in-shift; 409 `AGENT_NOT_ACTIVE` out-of-shift for ROLE_AGENT, ROLE_SUPERVISOR, ROLE_ADMIN (no bypass); no assignment row written on rejection | `TicketController` functional test; use `isAvailableOverride=false` for deterministic Inactivo |
| Functional | Roster returns in-shift + out-of-shift agents with correct `is_active_now` | Use override true/false to stay independent of the test run's weekday |
| Build | Frontend | `ng build`; net-new `ticket-card.component.spec.ts` for `showDropdown` with empty roster and grouping |

**Existing-test impact (flagged, not avoidable):** five `testListAvailableAgentsInWorkspace*` unit tests are deleted with their method; `WorkspaceAgentControllerTest::testAvailableAgentsReturned` swaps the `is_available` key assertion for `is_active_now`; `testAgentOutsideShiftExcluded` inverts by design (agent is now returned as Inactivo) and is renamed. The other ~46 `isAvailable`/`listAvailableAgents` tests are untouched. Note both controller tests are already weekday-dependent — new ones must not copy that pattern.

**Out-of-scope confirmed by grep:** `AutoAssignTicketUseCase`, `RebalanceUnassignedTicketsUseCase` and reply-auto-claim never reference `AssignTicketUseCase`, `listAvailableAgentsInWorkspace` or `AgentAvailabilityDTO`; only `TicketController` and `DebugController` reach the guarded use case.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary.

## Migration / Rollout

No migration. The legacy `agents.is_available` column stays untouched (separate cleanup). Single revertible PR.

## Open Questions

- [ ] `DebugController::assign()` returns 500 (not 409) for a rejected agent — accepted as dev-only noise, or add a catch arm?
- [ ] No escape hatch when zero agents are Activo; tickets stay unassignable until a shift or override opens (deferred per proposal).
