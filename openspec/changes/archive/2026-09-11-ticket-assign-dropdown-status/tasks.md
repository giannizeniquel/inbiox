# Tasks: Ticket Assign Dropdown — Full Roster with Activo/Inactivo Status

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1180 gross (additions+deletions) — backend ~862, frontend ~319 |
| 400-line budget risk | High |
| 800-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | Unit 1 (backend roster) → Unit 2 (backend guard) → Unit 3 (frontend) |
| Delivery strategy | single-pr (session default) |
| Chain strategy | pending — user must choose |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High
800-line budget risk: High

**Estimate breakdown** (verified against live file line counts, not guesses):
- Backend core logic (isActiveNow + roster method + DTO rename + exception + guard + controller wiring): ~215 lines
- Backend tests: DELETE 5 `testListAvailableAgentsInWorkspace*` (~190 lines removed) + ADD ~13 new unit tests (isActiveNow branches + roster) (~279) + rewrite 2 `WorkspaceAgentControllerTest` cases (~43) + 5 new functional 409/success tests (~135) = **net +467, gross ~647**
- Frontend: component/html/scss/list/dto changes (~139) + net-new `ticket-card.component.spec.ts` (~180) = **~319**
- Backend subtotal ~862 alone exceeds the 800 budget before any frontend line is counted.

Single-PR under `single-pr` strategy requires `size:exception`. Given estimate is ~1.5x the 800-line budget, **ask the user to pick a chain strategy** (stacked-to-main / feature-branch-chain / size-exception) before `sdd-apply`. Do not default silently.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | `isActiveNow` predicate + `listWorkspaceAgentRoster` + DTO rename + controller wiring (Roster Endpoint req) | PR 1 | `docker compose exec php bin/phpunit --filter AgentAvailabilityServiceTest` + `--filter WorkspaceAgentControllerTest` | `GET /api/workspaces/{id}/available-agents` via existing functional test client | Revert `AgentAvailabilityService.php`/`AgentAvailabilityDTO.php`/`WorkspaceAgentController.php`; no schema/migration touched |
| 2 | Assign-time Activo guard: `AgentNotActiveException`, `AssignTicketUseCase` guard, `TicketController` catch arm (Assign-Time Guard req) — depends on Unit 1 | PR 2 | `docker compose exec php bin/phpunit --filter Sprint10AgentManagementTest` | `POST /tickets/{id}/assign` functional test, 3 roles | Remove guard block + catch arm + exception class; independently revertible |
| 3 | Frontend two-tier dropdown + race-condition 409 handling (Dropdown Visibility req) — depends on Units 1+2 | PR 3 | `ng test --include='**/ticket-card.component.spec.ts'` | `ng build` (no e2e harness yet — N/A, net-new component tests substitute) | Revert `ticket-card.component.*`, `ticket-list.component.ts`, `agent-availability.dto.ts` |

## Phase 1: `isActiveNow` predicate (Roster Endpoint req)

- [x] 1.1 RED: add `isActiveNow` branch tests to `AgentAvailabilityServiceTest.php` (inactive account, override unexpired true/false, override expired→in/out shift, no-override in/out shift, no shifts, capacity-ignored)
- [x] 1.2 GREEN: implement `isActiveNow(Agent, array $shifts, \DateTimeImmutable $atUtc): bool` in `AgentAvailabilityService.php`
- [x] 1.3 REFACTOR: confirm `isAvailable()`/`isInShift()` byte-identical (no capacity call in new predicate)

## Phase 2: Roster + DTO + Controller (Roster Endpoint req)

- [x] 2.1 RED: add `listWorkspaceAgentRoster` tests (active=false excluded, at-capacity agent still Activo, Activo-before-Inactivo sort, sort-by-id within tier, DTO shape, one `findByAgents` call) to `AgentAvailabilityServiceTest.php`
- [x] 2.2 GREEN: implement `listWorkspaceAgentRoster()` in `AgentAvailabilityService.php`; delete `listAvailableAgentsInWorkspace()` + its 5 tests
- [x] 2.3 GREEN: rename `AgentAvailabilityDTO::$isAvailable` → `$isActiveNow`; `fromEntity()` takes 3rd `bool $isActiveNow` arg; `toArray()` key → `is_active_now`
- [x] 2.4 GREEN: `WorkspaceAgentController.php` calls `listWorkspaceAgentRoster()` (route path unchanged)
- [x] 2.5 Update `WorkspaceAgentControllerTest.php`: `testAvailableAgentsReturned` asserts `is_active_now`; rename+invert `testAgentOutsideShiftExcluded` → `testAgentWithoutActiveShiftReturnedAsInactivo`, now asserts agent returned as Inactivo (uses `isAvailableOverride` for determinism, not weekday-dependent); added `testInactiveAgentNeverAppearsInRoster`

## Phase 3: Assign-time Activo guard (Assign-Time Guard req, depends on Phase 1)

- [x] 3.1 RED: functional tests in `Sprint10AgentManagementTest.php` (converted to `WebTestCase` for HTTP-level role testing) — 409 `AGENT_NOT_ACTIVE` for ROLE_AGENT/SUPERVISOR/ADMIN assigning Inactivo agent; success for Activo; no assignment row/outbox event written on reject
- [x] 3.2 GREEN: create `AgentNotActiveException extends StateConflictException` with `::forAgent(int $id, string $name)`
- [x] 3.3 GREEN: inject `AgentAvailabilityService` into `AssignTicketUseCase`; guard after `PESSIMISTIC_WRITE` lock, before `recordOwnershipChange()`
- [x] 3.4 GREEN: `TicketController::assign()` — subclass-first `catch (AgentNotActiveException)` BEFORE generic `StateConflictException` arm → 409 `{"type":"AGENT_NOT_ACTIVE","error":...}`

## Phase 4: Frontend two-tier dropdown (Dropdown Visibility req, depends on Phase 2)

- [x] 4.1 RED: new `ticket-card.component.spec.ts` — `showDropdown` true with zero Activo agents (incl. fully empty roster); Activo group before Inactivo; Inactivo row disabled/non-selectable
- [x] 4.2 GREEN: `agent-availability.dto.ts` field `is_available` → `is_active_now`
- [x] 4.3 GREEN: `ticket-card.component.ts` — drop `agents.length > 0` gate; add `activeAgents()`/`inactiveAgents()` computed; `selectAgent()` early-returns on Inactivo
- [x] 4.4 GREEN: `ticket-card.component.html` — two `@for` groups with headers + badge; `[disabled]`/`aria-disabled` on Inactivo rows
- [x] 4.5 GREEN: `ticket-card.component.scss` — badge + disabled-row styles

## Phase 5: Race UX (depends on Phases 3+4)

- [x] 5.1 `ticket-list.component.ts` — on 409 `AGENT_NOT_ACTIVE`, rollback (existing) then reload roster so agent re-renders Inactivo
- [x] 5.2 Sync `workspace-api.service.ts` typings if any reference `is_available` (none found — no change needed)

## Phase 6: Verification / Cleanup

- [x] 6.1 Confirm `authorization/spec.md` footnote matches implemented 409 behavior (doc-only, no code — verified match, no edit needed)
- [x] 6.2 Run full `bin/phpunit`; confirm failing-test baseline did not increase net of the 5 deleted + 2 rewritten tests (57→56 failing, net improvement; see Work Unit Evidence)
- [x] 6.3 `ng build` + run `ticket-card.component.spec.ts` (both green; full `ng test` also confirmed no new frontend regressions: 58 failed baseline unchanged, +9 new passing)

## TDD Cycle Evidence

| Task | RED | GREEN | REFACTOR |
|---|---|---|---|
| 1.1–1.2 `isActiveNow` | 9 new tests added, confirmed failing with `Call to undefined method isActiveNow()` | Implemented predicate; `--filter AgentAvailabilityServiceTest` → 61/61 green | No change to `isAvailable()`/`isInShift()`; predicate factored to share `utcToLocal()`/`hasShiftAtTime()` |
| 2.1–2.2 `listWorkspaceAgentRoster` | 6 new tests added (5 old `listAvailableAgentsInWorkspace*` tests deleted first), confirmed failing with `Call to undefined method listWorkspaceAgentRoster()` | Implemented roster method + DTO 3-arg `fromEntity()`; `--filter AgentAvailabilityServiceTest` → 62/62 green | Roster reuses `isActiveNow()`/`countActiveTickets()`; no duplication |
| 2.4–2.5 Controller wiring | N/A (wiring change, not new predicate logic) | `WorkspaceAgentControllerTest` → 7 tests, only pre-existing weekday-flaky `testAvailableAgentsReturned` errors (confirmed identical on original code via git-stash diff) | — |
| 3.1–3.4 Assign guard | 5 new functional tests added (class converted `KernelTestCase`→`WebTestCase`), confirmed failing (404 route-prefix fix, then 200 instead of 409) | Implemented `AgentNotActiveException`, use-case guard, controller catch arm; `--filter Sprint10AgentManagementTest` → 11/11 green | Subclass-first catch arm ordering verified (must precede generic `StateConflictException`) |
| 4.1 Frontend two-tier | 9 new component tests added, confirmed failing with TS2339 (`activeAgents`/`inactiveAgents` undefined) | Implemented computed signals, template groups, guard in `selectAgent()`; `ng test --include='**/ticket-card.component.spec.ts'` → 9/9 green | Reused existing badge/status SCSS pattern |

## Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and result | `docker compose exec php bin/phpunit --filter "AgentAvailabilityServiceTest\|WorkspaceAgentControllerTest\|Sprint10AgentManagementTest"` → all green except the one pre-existing, unrelated `testAvailableAgentsReturned` error (confirmed present on unmodified code via `git stash` diff); `ng test --include='**/ticket-card.component.spec.ts'` → 9/9 SUCCESS |
| Runtime harness command/scenario and result | Full suite: `docker compose exec php php -d memory_limit=1G bin/phpunit` → 630 tests (614 baseline +16), 17 errors + 39 failures = 56 total (baseline was 18+39=57); diff of failing-test names before/after shows exactly one expected change (`testAgentOutsideShiftExcluded`→renamed) plus one unrelated flaky `UserControllerTest` test not present in either run consistently. Frontend: `ng build` → success (0 errors, pre-existing budget warnings only); full `ng test --watch=false` → 58 FAILED/182 SUCCESS vs baseline (git-stash -u) 58 FAILED/173 SUCCESS — identical failure count, +9 new passing (the new spec file) |
| Rollback boundary | Backend: revert `AgentAvailabilityService.php`, `AgentAvailabilityDTO.php`, `WorkspaceAgentController.php`, `AssignTicketUseCase.php`, `TicketController.php`, delete `AgentNotActiveException.php`, revert the 3 test files — no migration, no schema change. Frontend: revert `agent-availability.dto.ts`, `ticket-card.component.{ts,html,scss}`, `ticket-list.component.ts`, delete `ticket-card.component.spec.ts` — independently revertible from backend |
