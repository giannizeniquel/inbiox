# Verification Report: ticket-assign-dropdown-status

**Mode**: Full artifact verification (proposal + specs + design + tasks + apply-progress), source-inspected against live working tree, with independent runtime evidence (not trusting apply's narrative).

## Completeness

All 22/22 tasks across 6 phases confirmed checked in tasks.md and corroborated by source inspection (not just task-list claims). No unchecked tasks.

## Independent Runtime Evidence

**Backend** (`docker exec inbiox_php php -d memory_limit=512M bin/phpunit`, full suite, this session):
```
Tests: 630, Assertions: 3613, Errors: 17, Failures: 39, Deprecations: 1, Skipped: 2
→ 56 failing (17+39), matches apply-progress claim exactly (baseline 57 → 56, net improvement of 1)
```
- Targeted re-run of all 22 new/renamed tests for this change (`isActiveNow`, `WorkspaceAgentRoster`, `AgentNotActive`, `AssignRejectsInactive*`, `AssignSucceedsForActiveAgent`, `AssignRejectionWritesNoAssignmentRowOrOutboxEvent`, `AgentWithoutActiveShiftReturnedAsInactivo`, `InactiveAgentNeverAppearsInRoster`): **22/22 PASS**.
- Confirmed `WorkspaceAgentControllerTest::testAvailableAgentsReturned` (one of the 56 failures) is a genuine pre-existing failure: reran it against `git stash -u` (fully clean baseline) — identical `ORMInvalidArgumentException` (cascade-persist bug in `createShift()` test helper), unrelated to this change's DTO/service edits.
- Full list of all 56 failing/erroring test names captured and cross-checked: none belong to files touched by this change except the one pre-existing/confirmed-baseline case above.

**Frontend** (`ng build --configuration development`, this session): succeeds, 0 errors, only pre-existing Sass `@import` deprecation warnings. No Chrome/Chromium binary and no `test` script exist in this environment (`package.json` has no `test` script; `CHROME_BIN` unset; no chromium binary found) — Karma cannot run here. This is a pre-existing environment limitation, not introduced by this change; `ng build` is the enforceable gate per this session's established convention. `ticket-card.component.spec.ts` (158 lines, 9 real `it()` cases across `showDropdown`, `activeAgents()/inactiveAgents()` grouping, and `selectAgent()` guard) was read in full and is substantive, not scaffolding.

## Spec Compliance Matrix

| Requirement | Scenario | Evidence | Status |
|---|---|---|---|
| Workspace Agent Roster Endpoint | Full roster returned regardless of cascade result | `listWorkspaceAgentRoster()` never filters by eligibility, only by `active=true` (line 185 `array_filter` on `isActive()`) | PASS |
| | Deactivated agents excluded entirely | Upstream `array_filter($agents, fn($a) => $a->isActive())` in the roster method — `active=false` never reaches `isActiveNow()` in this path; `testInactiveAgentNeverAppearsInRoster` passes | PASS |
| | Activo via unexpired override / shift match / Inactivo despite capacity | 9 `isActiveNow` unit tests, all green, explicit `testIsActiveNowIgnoresCapacityEvenWhenAtLimit` — grep confirms `isActiveNow()` body never calls `countActiveTickets()` | PASS |
| | Route path unchanged | `WorkspaceAgentController::listAvailableAgents` still `#[Route('/{id}/available-agents', methods: ['GET'])]` | PASS |
| Assign-Time Activo Guard | 409 AGENT_NOT_ACTIVE for every role, no bypass | `AssignTicketUseCase::assignTicket()` — guard runs unconditionally after `PESSIMISTIC_WRITE` lock, before `recordOwnershipChange()`; grep for `ROLE_SUPERVISOR`/`ROLE_ADMIN` in the use case and controller returns zero matches — no special-case bypass code exists; 3 new functional tests (`ForRoleAgent`/`ForRoleSupervisor`/`ForRoleAdmin`) all pass | PASS |
| | No audit trail on rejection | `testAssignRejectionWritesNoAssignmentRowOrOutboxEvent` asserts `assignments` COUNT=0 and `outbox_events` COUNT=0 after 409 — passes | PASS |
| | Assignment succeeds for Activo agent | `testAssignSucceedsForActiveAgent` passes | PASS |
| Dropdown Visibility / Two-Tier Rendering | `agents.length > 0` gate removed | `showDropdown` computed in `ticket-card.component.ts` only checks `canAssign() && assignableStatuses.includes(status)` — no roster-length reference anywhere in the file | PASS |
| | Activo-first two-tier render with badges | `.html` template: two separate `@for` blocks (`activeAgents()`/`inactiveAgents()`) each with a `ticket-card__dropdown-badge--active/--inactive` span and group header | PASS |
| | Inactivo row not selectable | Native `[disabled]="true"` + `aria-disabled` on the button (browser blocks click on disabled elements) AND `selectAgent()` has an explicit `if (!agent.is_active_now) return;` early-return — double-enforced, not just CSS dimming | PASS |
| authorization (modified) | Supervisor/Admin reassign blocked by state guard, not role | `specs/authorization/spec.md` footnote + 2 new scenarios present and match implemented 409 behavior exactly | PASS |

## Correctness — Detailed Read-Through (per task's 10 checklist items)

1. **`isActiveNow()` predicate**: read verbatim (lines 84–102 of `AgentAvailabilityService.php`). Signature takes `array $shifts` explicitly — no lazy-load inside the method itself. Override (if set, unexpired) takes precedence over shift match. Capacity is never referenced in the method body (confirmed by full read + the dedicated `IgnoresCapacityEvenWhenAtLimit` test). Account-active exclusion for the roster happens **upstream** via `array_filter` in `listWorkspaceAgentRoster()` (line 185) — the method's own internal Step-1 active check is a defense-in-depth branch that also serves the `AssignTicketUseCase` guard caller, which does not pre-filter by active status before calling `isActiveNow()`. This is intentional layering, not redundant dead logic — both call sites are real (roster: pre-filtered; assign-guard: single-agent, needs the internal check).
2. **Roster method**: `listAvailableAgentsInWorkspace()` confirmed genuinely deleted — repo-wide grep finds zero references to it except one doc-comment mentioning "(removed)". `listWorkspaceAgentRoster()` returns the full `active=true` roster including Inactivo agents (verified via `testAgentWithoutActiveShiftReturnedAsInactivo` and `testInactiveAgentNeverAppearsInRoster`, both passing). `WorkspaceAgentController` route path unchanged (`/api/workspaces/{id}/available-agents`).
3. **DTO rename**: `AgentAvailabilityDTO::toArray()` produces `'is_active_now' => $this->isActiveNow` as the actual JSON key (not just an internal property name). Grep confirms no remaining mapping from `Agent::isAvailable()`'s legacy column into this DTO — `fromEntity()` takes the computed `bool $isActiveNow` as an explicit 3rd constructor argument.
4. **Assign-time guard order**: read `AssignTicketUseCase::assignTicket()` verbatim — statement order is: agent lookup → ticket lookup → `$this->em->lock($ticket, LockMode::PESSIMISTIC_WRITE)` → `isActiveNow()` guard (throws `AgentNotActiveException`) → ownership-type detection → `recordOwnershipChange()` → flush. Guard is strictly after the lock and strictly before the audit-trail write, confirmed both by static read and by the passing `testAssignRejectionWritesNoAssignmentRowOrOutboxEvent` (0 rows on rejection). No role-check code path exists anywhere in this use case or `TicketController::assign()` — grep for `ROLE_SUPERVISOR`/`ROLE_ADMIN` in both files returns nothing — confirming a genuine hard block for all roles, not merely an untested claim.
5. **Exception/controller wiring**: `AgentNotActiveException extends StateConflictException` (verified, plus `StateConflictException extends \RuntimeException`). `TicketController::assign()`'s catch block order read verbatim: `catch (AgentNotActiveException $e)` appears literally before `catch (StateConflictException $e)` — correct PHP first-match semantics. Response body read exactly: `['type' => 'AGENT_NOT_ACTIVE', 'error' => $e->getMessage()]` at HTTP 409 — not just the status code.
6. **Frontend**: `showDropdown` in `ticket-card.component.ts` has no `agents.length` reference at all (full file read). Two-tier Activo/Inactivo render is real: separate `@for` loops keyed off `activeAgents()`/`inactiveAgents()` computed signals, each with its own badge span, not a single flat list with a CSS class swap. Selecting an Inactivo entry is genuinely blocked: native `[disabled]="true"` on the `<button>` (browser-level, not just an opacity class) plus a redundant `selectAgent()` guard clause. The 409-race UX is implemented, not just planned: `ticket-list.component.ts::onAssign()` error handler checks `err?.status === 409 && err?.error?.type === 'AGENT_NOT_ACTIVE'` and calls `loadAvailableAgents()` after the existing rollback — read verbatim, present in the working tree.
7. **Regression check**: `git diff` on `WorkspaceAgentControllerTest.php` shows `testAgentOutsideShiftExcluded` → `testAgentWithoutActiveShiftReturnedAsInactivo` is a genuine behavioral inversion — the new body asserts the agent IS present in the roster (`assertCount(1, $data)`) with `assertFalse($data[0]['is_active_now'])`, not a weakened or deleted assertion. `git diff` on `AgentAvailabilityServiceTest.php` confirms exactly 5 `testListAvailableAgentsInWorkspace*` methods deleted (`-` lines) with 6 new `testListWorkspaceAgentRoster*` methods added (`+` lines) — genuinely deleted, not commented/skipped.
8. **No weekday-dependent new tests**: grepped all new/modified test files (`AgentAvailabilityServiceTest.php`, `WorkspaceAgentControllerTest.php`, `Sprint10AgentManagementTest.php`, `ticket-card.component.spec.ts`) for real-`now()`-plus-real-shift patterns. The only `date('N')`/full-week-shift pattern found (`Sprint10AgentManagementTest.php:389`) is confirmed via `git diff` to be **pre-existing code untouched by this change** (belongs to `testDeterministicTiebreakerLowestId`/`testLoadBasedTiebreaker`, unrelated `AutoAssignTicketUseCase` tests). All 5 new guard tests (`testAssignRejectsInactiveAgentForRole*`, `testAssignSucceedsForActiveAgent`, `testAssignRejectionWritesNoAssignmentRowOrOutboxEvent`) use `setIsAvailableOverride(false)`/deterministic setup exclusively — read verbatim.
9. **Out-of-scope isolation**: `git diff --stat` for `AutoAssignTicketUseCase.php` and `HandleOutboundMessageUseCase.php` returns **empty** — zero diff, confirmed untouched.
10. Both suites independently re-run this session (see Runtime Evidence above); figures match apply's reported 630/56 backend and clean `ng build` exactly. Karma not runnable in this environment (no Chrome binary, no `test` npm script) — noted as a pre-existing environment limitation per established session convention; `ng build` used as the enforceable frontend gate.

## Design Coherence

No deviations found between `design.md` and the implemented code: predicate placement, shift-source parameterization, roster method replacement (not dual-maintained), DTO 3-arg signature, guard position (post-lock, pre-audit-write), and exception subclass-first catch ordering all match the design's Architecture Decisions table exactly.

## Issues

**CRITICAL**: None.

**WARNING**: None. (The one pre-existing `WorkspaceAgentControllerTest::testAvailableAgentsReturned` failure was independently reproduced against a clean `git stash -u` baseline in this session — confirmed unrelated to this change, not merely trusted from apply's narrative.)

**SUGGESTION**:
- `DebugController::assign()` still returns 500 (not 409) for a rejected agent — accepted as a dev-only gap per design's open questions; consider a follow-up catch arm if this endpoint is ever exposed beyond debugging.
- No supervisor/admin force-assign escape hatch exists when zero agents are Activo — explicitly deferred per proposal's open question; worth tracking as a follow-up ticket given the hard-block risk noted in the proposal's risk table (tickets can strand until a shift/override opens).

## Final Verdict

**PASS**
