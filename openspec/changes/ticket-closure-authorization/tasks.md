# Tasks: Ticket Closure Authorization and Audit Trail

> **Scope**: 28 tasks across 7 phases (Phase 0–6). Strict TDD baselined against the PHPUnit failure count observed at `sdd-apply`'s own start — **not** the stale `58` in `openspec/config.yaml`, and not zero (the sibling change's apply already shifted the count once this session). Every task touching production logic is preceded by its own failing-test task. Frontend N/A — no frontend file is in scope.

## Phase 0 Gate — BLOCKING, do not start Phase 1 until satisfied

- [ ] 0.1 **Confirm `whatsapp-24h-window-compliance` is committed on `sprint-16-production-readiness`** before any file in this change is touched. That sibling's code is implemented but, as of this writing, still uncommitted — the user is doing a manual review before commit. This design's every insertion point (`TicketEventType.php`'s existing constants, `GetContactTimelineQuery.php`'s `ticket_events` `UNION ALL` branch and its `idx_ticket_events_ticket_created` index) assumes the sibling's code already exists in the working tree as committed history, not as a pending diff. Check: `git log` shows the sibling's commits on this branch. If not yet committed, STOP and wait — do not edit `TicketEventType.php` or `GetContactTimelineQuery.php` against an uncommitted, still-changing base.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | **~800–950** (production ~250: 7 modified files + 1 new `ClosureAuditClassifier.php`; tests ~600, all net-new per exploration's zero-existing-coverage finding) |
| 800-line budget risk | **Medium** — borderline, likely at or modestly over budget, not a dramatic overage |
| Chained PRs recommended | No — 7 files, no migration, no frontend; the four seams (classifier, authorization, actor threading, timeline) are small and tightly coupled, splitting would fragment an already-small change |
| Suggested split | Single PR, with the 4 work units below as internal commit boundaries |
| Delivery strategy (preflight) | single-pr |
| Chain strategy | size-exception (recommended, pending explicit user confirmation) |

Decision needed before apply: **Resolved — 2026-08-29**
Chained PRs recommended: No (declined by user, single PR chosen)
Chain strategy: **`size:exception` approved — single PR**
800-line budget risk: Medium

**Decision**: this change touches noticeably fewer files than the sibling (7 modified + 1 new vs. the sibling's ~19 backend + 5 frontend + 1 migration), has zero migration and zero frontend work, so it does not read as a dramatic overage the way the sibling's ~1,050–1,100 lines did — but the estimate still lands at or slightly above 800, driven entirely by the test surface (exploration confirmed zero existing coverage for audit-writing, no-prior-owner, and controller-authorization paths). Brought back to the user per this project's guard rule before `sdd-apply` started. **User approved `size:exception` — one single PR**, the same call already made twice this session (`file-attachments`, and this branch's sibling `whatsapp-24h-window-compliance`). The 4 work units below remain internal commit boundaries, not separate PRs.

### Suggested Work Units (internal commit boundaries if `size:exception` is confirmed; PR boundaries if the user instead chooses to chain)

| Unit | Goal | Focused test command | Runtime harness | Rollback boundary |
|------|------|----------------------|-----------------|-------------------|
| 1 | Phase 0–1 — gate + `TicketEventType` constants + `ClosureAuditClassifier` | `bin/phpunit --filter ClosureAuditClassifierTest` | `docker compose exec php bin/phpunit` (requires `make up`) | Revert the 6 new constants + delete the new classifier file; nothing else depends on this yet |
| 2 | Phase 2 — `TicketVoter` docblock + controller authorization gating | `bin/phpunit --filter VoterTest\|TicketWorkflowController` | same as above | Revert the docblock and the 3 gate call sites; controller falls back to today's ungated behavior |
| 3 | Phase 3–4 — actor threading + audit-event writing | `bin/phpunit --filter TransitionTicketStatusUseCaseTest\|TicketWorkflowController` | same as above | Revert the `$actor` params (defaults preserve every automatic caller) and the classify+persist block; depends on Unit 1's classifier |
| 4 | Phase 5–6 — timeline read-path + final regression pass | `bin/phpunit --filter GetContactTimelineQuery` | same as above | Revert the allow-list/`CASE`/joins; independently revertible, no data cleanup (orphaned `manual_*` rows simply stop being selected) |

---

## Phase 1 — Foundation: Classification Primitives (pure, dependency-free)

*Must land before anything that calls them. No Doctrine, no HTTP — runs without Docker.*

- [ ] 1.1 RED: `ClosureAuditClassifierTest` (new, `apps/api/tests/Unit/Domain/Event/ClosureAuditClassifierTest.php`) — full truth table: `(null, any)` → `*_UNOWNED`; `(7,7)` → `*_OWNER`; `(7,3|null)` → `*_OVERRIDE`, for both `resolved` and `closed`; `actorReference(User#9)` → `'u:9'`; every constant's string length `<= 50` (guards the `VARCHAR(50)` contract).
- [ ] 1.2 GREEN: add six constants to `App\Domain\Event\TicketEventType` per design D2 — `MANUAL_RESOLVED_OWNER`, `MANUAL_RESOLVED_OVERRIDE`, `MANUAL_RESOLVED_UNOWNED`, `MANUAL_CLOSED_OWNER`, `MANUAL_CLOSED_OVERRIDE`, `MANUAL_CLOSED_UNOWNED`. File `apps/api/src/Domain/Event/TicketEventType.php`. Existing `WINDOW_EXPIRED_CLOSED`/`WINDOW_REOPENED` untouched.
- [ ] 1.3 GREEN: create `App\Domain\Event\ClosureAuditClassifier` (new file) — `classify(string $newStatus, ?int $previousOwnerId, ?int $actingAgentId): string` per D2's truth table, and `actorReference(User $user): string` returning `sprintf('u:%d', $user->getId())` per D5. Final class, no constructor dependencies.
- [ ] 1.4 Verify: run 1.1 against 1.2/1.3, confirm green with zero Doctrine bootstrap required.

## Phase 2 — Authorization: Voter Docblock + Controller Gating

*Independent of Phase 1's classifier — proceeds in parallel or before it.*

- [ ] 2.1 RED: extend `tests/Security/VoterTest.php` — add `AGENT CLOSE unassigned ticket` → DENIED (R2, this is the D3 case, pin it explicitly, it is load-bearing); `AGENT (no linked Agent) CLOSE own ticket` → DENIED (R1); `SUPERVISOR CLOSE resolved ticket` → **GRANTED, no exception thrown** (the regression proof for D1 — the identical case under `ASSIGN` must still throw `StateConflictException`).
- [ ] 2.2 GREEN: update `TicketVoter`'s class docblock ONLY (D1) — it currently states `CLOSE` is "reserved/dead code … a no-op if ever called"; this becomes actively misleading the moment it is wired up. **Zero logic change** — `supports()` already accepts `CLOSE`, `voteOnAttribute()`'s generic branch already implements the required rule verbatim. File `apps/api/src/Application/Security/Voter/TicketVoter.php`.
- [ ] 2.3 RED: **safety-critical** — new integration test matrix (new file, e.g. `apps/api/tests/Integration/TicketClosureAuthorizationTest.php`) covering, for each of `POST /resolve`, `/close`, `/transition{resolved}`, `/transition{closed}`: owning agent → 200; non-owning agent → 403 `reason:not_ticket_owner`; agent with no linked `Agent` → 403 `reason:no_linked_agent`; any agent on an unowned ticket → 403 `reason:unowned_ticket`; supervisor → 200; admin → 200; `/transition{active}` and `{waiting}` on another agent's ticket → 200 (over-blocking regression); no-`status` `/transition` → 400 unchanged; wrong-case status → 422; nonexistent ticket → 400 (not 404); every denied request writes zero `tickets`/`ticket_events`/`assignments` rows. **This is the single most safety-critical assertion in the whole change** — per design D3, `POST /tickets/{id}/close` returns 403 for **every** `ROLE_AGENT` including the ticket's own resolver (it is structurally always unowned by the time `/close` is reachable), and 200 only for supervisor/admin.
- [ ] 2.4 GREEN: add `#[CurrentUser] ?User $user`, `TicketRepository`, and `Security` to `TicketWorkflowController`; gate `close()`/`resolve()` unconditionally and `transition()` conditionally (only when target status is `resolved`/`closed`, evaluated after the existing 400-on-missing-status check) via `isGranted(TicketVoter::CLOSE, $ticket)`; custom 403 body (`error`, `code:TICKET_CLOSURE_FORBIDDEN`, `reason` derived from `no_linked_agent`/`unowned_ticket`/`not_ticket_owner`, `action`, `ticket_id`, `message`); null ticket falls through to the use case's existing 400 (no new 404). File `apps/api/src/Controller/TicketWorkflowController.php`.
- [ ] 2.5 Verify: run 2.1 and 2.3 together, confirm the full matrix passes and zero mutation occurs on every denied path.

## Phase 3 — Actor Threading: Real Identity Into the Audit Chain

*Depends on nothing from Phase 1/2 to compile, but Phase 4 needs this landed first.*

- [ ] 3.1 RED: extend `TransitionTicketStatusUseCaseTest` — when a prior owner existed, the resulting `assignments` row has `assigned_by = 'u:{userId}'`, never the literal `'system'`; every automatic caller (`AutoCloseRule`, `AutoReopenRule`, `HandleOutboundMessageUseCase`, `HandleInboundMessageUseCase`, `closeDueToWindowExpiry()`) still defaults to `'system'` unchanged when no actor is passed.
- [ ] 3.2 GREEN: add an optional trailing `string $actor = 'system'` parameter to `execute()`, `resolve()`, `close()`, and `recordUnassignIfOwner()` in `TransitionTicketStatusUseCase`; use it at the `assignedBy: $actor` call site (was hardcoded `'system'` at line 59); forward `$actor` through `ResolveTicketUseCase::execute()`. Every parameter is optional and trailing — no existing caller needs to change. Files `TransitionTicketStatusUseCase.php`, `ResolveTicketUseCase.php`.
- [ ] 3.3 RED: extend the same test — `execute()`/`close()`'s returned array carries `previous_assigned_agent_id` matching the value captured under the use case's own `SELECT ... FOR UPDATE`, not a fresh unlocked read (D4 — no second query, no new lock, no TOCTOU on the audit label).
- [ ] 3.4 GREEN: add the `previous_assigned_agent_id` key to the result array in `execute()` (propagates to `resolve()` for free) and `close()`, per D4's exact snippet.
- [ ] 3.5 **Explicit decision (D7), included, not dropped**: `execute()` never sets `closed_at` when landing on `closed` — a pre-existing gap from the sibling's FSM widening, now load-bearing because D3 routes the agent's own-ticket close primarily through `POST /transition{closed}`. Add `if ($newStatus === TicketStatus::CLOSED->value && empty($ticket['closed_at'])) { $updates[] = 'closed_at = NOW()'; }` alongside the existing `resolved_at` conditional. Verified safe for every caller (design D7): the automatic callers never target `closed` through this branch, and `close()`/`closeDueToWindowExpiry()` already set it themselves.
- [ ] 3.6 Verify (regression): `AutoCloseRule` still writes exactly one `auto_closed` event, `source='system'`, resulting `assignments.assigned_by='system'` when it unassigns an owned resolved ticket; `AutoReopenRule`, `closeDueToWindowExpiry()`, `HandleOutbound`/`HandleInboundMessageUseCase` all compile and behave unchanged with the new optional parameter.

## Phase 4 — Classification + Event Writing (controller ties Phase 1 + Phase 3 together)

*Depends on Phase 1 (classifier) and Phase 3 (`previous_assigned_agent_id`, actor threading) both landing first.*

- [ ] 4.1 RED: new integration test — audit classification matrix: owner closes own ticket via `/transition{closed}` → exactly 1 row, `manual_closed_owner`, `source='u:{ownerUserId}'`; supervisor overrides another agent's ticket → `manual_closed_override`; supervisor closes via `/close` (D3: always unowned) → `manual_closed_unowned`; the three `resolved` equivalents via `/resolve`, including a supervisor resolving an `AutoReopenRule`-reopened (therefore unowned) ticket → `manual_resolved_unowned` (pins D2's reachability claim); every successful action writes exactly **one** `ticket_events` row, never two; prior-owner case also produces exactly one `assignments` row with the real actor; no-prior-owner case produces **zero** new `assignments` rows (constraint 1 unchanged).
- [ ] 4.2 GREEN: wire `EntityManagerInterface` and `ClosureAuditClassifier` into `TicketWorkflowController`; after a successful transition, classify using `previous_assigned_agent_id` + `$user->getAgent()?->getId()`, persist `TicketEvent(type, $classifier->actorReference($user))`, flush.
- [ ] 4.3 GREEN: **`unset($result['previous_assigned_agent_id'])` before `new JsonResponse($result)`** (D4 reminder) — this key is an internal classification input only; the REST contract must stay byte-identical, no field added to any API response, no frontend file touched.
- [ ] 4.4 RED: test that a `TicketEvent` persist/flush failure (simulated throw) still returns the successful transition response, and logs at `error` with `ticket_id`, `type`, `source` (D8).
- [ ] 4.5 GREEN: wrap the `TicketEvent` persist+flush in a `try/catch (\Throwable)`; on failure, `logger->error()` with the classification payload; **do not** rethrow or fail the request — the transition already committed, a 500 here would be strictly worse (D8's full rationale: retries would hit a phantom 400).
- [ ] 4.6 Verify: exactly one `ticket_events` row per successful manual action, zero for every denied request (ties back to 2.3's zero-mutation assertion).

## Phase 5 — Timeline Read-Path Extension

*Depends on Phase 1's six constants existing as real, reachable values (Phase 4 makes them meaningful).*

- [ ] 5.1 RED: extend the sibling's `GetContactTimelineQueryWindowEventsTest` (or a new sibling test) — all six `manual_*` types appear as `ticket.manual_*` with `assigned_to = "Admin|Sup|Agente {name}"`; an admin who also holds `ROLE_SUPERVISOR` in stored roles is labeled `"Admin …"` never `"Sup …"` (pins the check-order); an actor with no linked `Agent` falls back to `"Admin {email}"`, `agent_id` NULL; a deleted `Agent` row still returns the event via `LEFT JOIN`, `agent_id` NULL; `window_expired_closed`/`window_reopened` rows are unaffected — `assigned_to`/`agent_id` still NULL (zero regression from the added joins); `auto_closed`/`tag_added`/etc. remain excluded by the allow-list; pagination (`before`/`limit`/`phone_number_id`) behaves identically.
- [ ] 5.2 GREEN: extend `GetContactTimelineQuery`'s existing third `UNION ALL` branch — allow-list (L75) gains the six constants, `CASE` (L136-139) gains six arms, add `LEFT JOIN \`user\` u ON te.source = CONCAT('u:', u.id)` and `LEFT JOIN agents ag ON ag.user_id = u.id`, and the `assigned_to`/`agent_id` expressions per D5/D6 (ordered `ROLE_ADMIN` → `ROLE_SUPERVISOR` → `ROLE_AGENT` via `JSON_CONTAINS(u.roles, ...)`). **No fourth branch, no new bound params** — reuses `:contact_id`/`:before_date`/`:phone_number_id`. `TimelineEventDTO` unchanged. File `apps/api/src/Application/Query/GetContactTimelineQuery.php`.
- [ ] 5.3 Verify: full timeline matrix (5.1) passes; confirm `'system'`-sourced rows (window/auto events) still resolve to NULL actor — byte-identical to pre-change output.

## Phase 6 — Final Regression + Verification

- [ ] 6.1 Run the full PHPUnit suite; confirm the failure count is at or below the baseline observed at `sdd-apply`'s own start (not the stale `58`).
- [ ] 6.2 Confirm zero migration files added (`git diff --stat apps/api/migrations/` empty) and zero `apps/web/` changes — both are hard success criteria from `proposal.md`.
- [ ] 6.3 Confirm no Mercure payload, topic, publisher, or outbox emission was added or changed — `AssignmentService::recordOwnershipChange()`'s existing `TicketUpdatedEvent`/`InboxUpdatedEvent` emission is untouched, only the `assignedBy` string passed into it changed.

---

## Parallelization Notes

- Phase 0 is a hard sequential gate — nothing else starts until the sibling's commits land.
- Phase 1 (classifier + constants, pure) and Phase 2 (voter docblock + controller gating) are independent of each other and may be done in parallel or in either order — Phase 2's gating does not need the classifier.
- Phase 3 (actor threading) is independent of Phase 1 and Phase 2 to compile, but Phase 4 needs Phase 1's classifier and Phase 3's `previous_assigned_agent_id`/actor both landed first.
- Phase 4 depends on Phase 1 and Phase 3.
- Phase 5 depends on Phase 1's constants being real, reachable values (Phase 4 having landed makes the test data meaningful, though the SQL edit itself only needs the constants to exist).
- Phase 6 is last, after everything else.

## Design Items Explicitly Resolved Here (not left to `sdd-apply` to improvise)

- **D7 (`closed_at` on `/transition{closed}`)**: INCLUDED (task 3.5), not dropped — D3 makes this the agent's primary self-close path, so the pre-existing gap becomes load-bearing rather than cosmetic. One-liner, additive, verified safe for every other caller.
- **D8 (audit-write failure)**: log and still return success (task 4.5) — never fail an already-committed transition over an audit-row INSERT failure.
- **D4 (`previous_assigned_agent_id` leak)**: `unset()` before the JSON response is its own explicit task (4.3), not folded silently into 4.2, because it is the one thing standing between this change and a REST contract break.
- **D1 (`TicketVoter::CLOSE`)**: docblock-only (task 2.2) — confirmed zero logic change needed; the generic rule already matches, per design's verified reasoning.
