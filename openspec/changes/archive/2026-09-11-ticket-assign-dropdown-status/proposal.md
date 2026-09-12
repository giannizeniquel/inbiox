# Proposal: Ticket Assign Dropdown — Full Roster with Activo/Inactivo Status

## Intent

Supervisors cannot assign tickets when the availability cascade returns zero agents: `TicketCardComponent.showDropdown` requires `agents.length > 0`, so the whole "Asignar" button disappears with no explanation. The endpoint also silently drops every agent that fails the 4-step cascade, so the UI can never show *who* exists and *why* they are not assignable. Make the roster always visible and make manual-assign eligibility explicit and enforced.

## Scope

### In Scope

- New service method returning the FULL `active = true` workspace roster with a computed `isActiveNow` flag per agent.
- **Activo rule** (manual-assign only): `isAvailableOverride` when set and unexpired, otherwise shift match at current time. **Capacity is deliberately ignored.**
- `AgentAvailabilityDTO`: drop the dead `Agent::isAvailable()` legacy-column mapping; expose `is_active_now`.
- Assign-time guard: `POST /tickets/{id}/assign` rejects a non-Activo target agent (409, hard block, all roles).
- Frontend: remove the `agents.length > 0` gate; render Activo group first, Inactivo group after, badge per agent, Inactivo entries non-selectable.

### Out of Scope

- `AutoAssignTicketUseCase`, `RebalanceUnassignedTicketsUseCase`, and reply-auto-claim keep the full capacity-inclusive cascade — untouched.
- No schema/migration; no removal of the legacy `agents.is_available` column (separate cleanup).
- No supervisor force-assign escape hatch (see Risks).
- No Mercure event-contract change: assignment still emits the existing `TicketUpdatedEvent`.

## Capabilities

### New Capabilities

- `agent-assignment-eligibility`: roster composition, Activo/Inactivo rule, sort order, assign-time rejection, dropdown UX.

### Modified Capabilities

- `authorization`: qualify the "Reassign tickets ✅" matrix row — supervisors/admins still cannot assign to an Inactivo agent (state guard, not a role change).

## Approach

Extend in place (exploration Approach 1 — single known consumer, no compat fork):

1. Extract `isActiveNow(Agent, shifts, atUtc)` in `AgentAvailabilityService` = active-account + (override ?? shift). Distinct from `isAvailable()` (capacity-inclusive) and from `isInShift()` (override-blind).
2. `listWorkspaceAgentRoster()` uses `AgentRepository::findByWorkspace()` filtered to `active = true`, batch-loads shifts (no N+1), sorts Activo-first then id ASC. Route `GET /api/workspaces/{id}/available-agents` is kept to avoid client churn.
3. `AssignTicketUseCase::assignTicket()` calls `isActiveNow()` inside the existing transaction and throws `AgentNotActiveException extends StateConflictException` → already mapped to **409** in `TicketController::assign()`, body `{"type":"AGENT_NOT_ACTIVE","error":...}`. Only `TicketController` and `DebugController` reach this use case, so auto-assign paths are unaffected.
4. Frontend groups the roster into two tiers; disabled Inactivo rows mirror the backend block; empty roster shows the (currently dead) empty state.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Application/Service/AgentAvailabilityService.php` | Modified | `isActiveNow()` + `listWorkspaceAgentRoster()` |
| `apps/api/src/Application/DTO/AgentAvailabilityDTO.php` | Modified | `is_available` → `is_active_now` (computed, not legacy column) |
| `apps/api/src/Controller/WorkspaceAgentController.php` | Modified | Returns roster instead of survivors |
| `apps/api/src/Application/UseCase/AssignTicketUseCase.php` | Modified | New eligibility guard |
| `apps/api/src/Exception/AgentNotActiveException.php` | New | 409 subclass of `StateConflictException` |
| `apps/web/.../ticket-card.component.{ts,html}` | Modified | Drop gate, two-tier list, badges, disabled rows |
| `apps/web/.../workspace-api.service.ts`, `agent-availability.dto.ts` | Modified | DTO field sync |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Hard block strands tickets when no agent is Activo (all shifts ended) | Med | Flagged as open question; escape hatch is a follow-up change, not this slice |
| `AgentAvailabilityServiceTest` (51 tests) assertions weakened by DTO change | Med | Add per-branch tests for `isActiveNow`; do not edit existing cascade assertions |
| Zero frontend component tests exist for `TicketCard` | High | Net-new scaffolding; `ng build` fallback per `config.yaml` |
| `DebugController` assign also gets the guard | Low | Accepted — dev endpoint, tightening is correct |
| 800-line budget pressure (backend + frontend + tests) | Med | `sdd-tasks` forecasts; slice frontend if needed |

## Rollback Plan

Single PR, revertible in one commit. Backend and frontend can be reverted independently: restoring `is_available` in the DTO plus the `agents.length > 0` clause returns the exact prior behavior; the guard is one `throw` block removal. No migration, no data change, no event-contract change.

## Dependencies

- Existing `AgentShift` data quality: agents with no configured shift render Inactivo and become unassignable — verify shift coverage before deploy.

## Success Criteria

- [ ] "Asignar" is visible whenever the ticket is assignable per ticket-state/ownership, even with zero Activo agents.
- [ ] Roster contains every `active = true` workspace agent; `active = false` agents never appear.
- [ ] Badge matches the override-or-shift rule; an in-shift agent at capacity still shows Activo.
- [ ] Activo agents sort before Inactivo agents.
- [ ] `POST /tickets/{id}/assign` to an Inactivo agent returns 409 `AGENT_NOT_ACTIVE` for every role.
