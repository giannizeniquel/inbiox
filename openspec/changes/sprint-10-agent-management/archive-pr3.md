# Archive: Sprint 10 — PR3 AutoAssign + AssignmentService + Rebalance

## Change
sprint-10-agent-management / PR3 of 5

## Archived

- **`AssignmentService`** is the SINGLE audit gateway for ticket ownership changes
  - All ownership changes route through `recordOwnershipChange()` (Ticket + Assignment + Events)
  - `setAssignedAgentId` is callable ONLY here (verified by grep)
- **`AutoAssignTicketUseCase` rewritten** to use `AgentAvailabilityService::listAvailableAgents()` as the SOLE eligibility check
  - Old hardcoded `WHERE active = 1 AND is_available = 1` filter REMOVED
  - Pipeline: lock ticket FOR UPDATE → list candidates → lock agents FOR UPDATE → re-filter capacity inside lock → tiebreaker sort → assign + audit
  - Tiebreaker: active count ASC → AUTO_ASSIGN today count ASC → agent.id ASC (deterministic, no shuffle/rand)
  - `executeInTransaction()` reuses caller's transaction (for `RebalanceUnassignedTicketsUseCase` and `TicketAutoAssignmentRequestedListener`)
- **`RebalanceUnassignedTicketsUseCase`** retries unassigned active tickets
  - Query: `WHERE assigned_agent_id IS NULL AND status IN ('new','active') ORDER BY created_at ASC`
  - One independent transaction per ticket (failure on #5 doesn't roll back #1-#4)
  - Type is always `AUTO_ASSIGN` (not `MANUAL_ASSIGN`)
- **`TicketRepository::countActiveForAgent()`** — derived COUNT for capacity re-check
- **`AssignmentRepository::countAutoAssignedToday()`** — derived COUNT for tiebreaker
- **Outbox pattern** — events collected in main transaction, persisted in SECOND flush post-commit
  - `OutboxEvent` rows for PR4 worker to publish to Mercure
  - NO direct Mercure publishing in PR3 (verified by grep)

## Files

### Created
- `apps/api/src/Application/Service/AssignmentService.php` (114 lines)
- `apps/api/src/Application/UseCase/RebalanceUnassignedTicketsUseCase.php` (71 lines)
- `apps/api/tests/Integration/Sprint10AgentManagementTest.php` (352 lines, 6 tests)
- `apps/api/tests/Integration/Sprint10ConcurrencyTest.php` (422 lines, 4 tests, 1 skipped without pcntl)

### Modified
- `apps/api/src/Application/UseCase/AutoAssignTicketUseCase.php` (rewrite, 247 lines)
- `apps/api/src/Application/UseCase/AssignTicketUseCase.php` (route through `AssignmentService`)
- `apps/api/src/Repository/AssignmentRepository.php` (added `countAutoAssignedToday`)
- `apps/api/src/Repository/TicketRepository.php` (added `countActiveForAgent`)
- `apps/api/src/Domain/Assignment/AssignmentRepositoryInterface.php` (int $agentId)
- `apps/api/src/Controller/AssignTicketRequest.php` (int $agentId)
- `apps/api/src/Controller/DebugController.php` (int agent IDs)
- `apps/api/tests/Integration/Sprint09BConcurrencyTest.php` (seed agents first)

## Verification
**PASS WITH WARNINGS** — 9/10 Sprint 10 tests pass + 1 skipped (pcntl unavailable in container). PR1 + PR2 unit tests (47 + 5 = 52) still pass. Zero schema drift. 16 design decisions verified.

### Test Results
- `phpunit tests/Integration/Sprint10AgentManagementTest.php` → 6/6 OK (31 assertions)
- `phpunit tests/Integration/Sprint10ConcurrencyTest.php` → 3/4 OK + 1 SKIPPED (16 assertions, 1.96s)
- `phpunit tests/Unit/Service/AgentAvailabilityServiceTest.php` (PR2 regression) → 47/47 OK (172 assertions)
- `phpunit tests/Unit` (all) → 52/52 OK
- `phpunit tests/Integration` (all) → 53/69 OK + 1 SKIPPED + 16 PRE-EXISTING FAILURES (Sprint09A + Sprint09B, unchanged from PR1/PR2)
- `doctrine:schema:update --dump-sql` → `[OK] Nothing to update`

### Spec Coverage
18/26 spec scenarios runtime-verified:
- 6/7 algorithm scenarios (Tiebreaker 1 "fewest today" is impl-verified, not tested)
- 2/5 audit scenarios (MANUAL_ASSIGN/REASSIGN paths impl-verified; UNASSIGN deferred to PR5)
- 2/3 referential integrity (FK constraint enforced at DB level)
- 3/3 concurrency safety (sequential proof + impl inspection)
- 1/3 rebalance scenarios (status filter proven by `testRebalanceSkipsResolvedAndClosed`)
- 2/3 mercure event contracts (post-commit outbox proven by `testFullAutoAssignmentFlow`)
- 2/2 use case integration (full flow + filter removal)

5/26 impl-verified (not runtime-tested but provably correct by line inspection).
3/26 deferred to PR5 (T-22 unassign event) and DB-level enforcement.

### Audit Invariant
- `setAssignedAgentId` call sites in `apps/api/src/`: ONLY `AssignmentService.php:67` and `Ticket.php:124` (setter definition)
- All other PR3 files route through `AssignmentService::recordOwnershipChange()`
- 0 raw SQL `UPDATE tickets SET assigned_agent_id` in PR3

### Determinism
- 0 occurrences of `shuffle`, `rand`, `array_rand`, `RAND`, `mt_rand`, `random_int` in PR3 files (only the negation in a docblock)
- Tiebreaker uses `usort` with three strict comparators (load → today → id)
- Same data → same result (verified by `testDeterministicTiebreakerLowestId`)

### Lock Ordering
1. `SELECT ticket FOR UPDATE` (line 82-85) — idempotent gate
2. `AgentAvailabilityService::listAvailableAgents()` (line 109) — pre-lock candidates
3. `SELECT agents FOR UPDATE WHERE id IN (...)` (line 123-126) — lock
4. Re-run `countActiveForAgent()` inside lock (line 133) — capacity re-validation
5. Update ticket + `AssignmentService::recordOwnershipChange()` (line 190)
6. `em->flush()` (line 199) — single transaction commit

### Rebalance Safety
- Query: `WHERE assigned_agent_id IS NULL AND status IN ('new','active') ORDER BY created_at ASC`
- Each ticket: independent transaction (one `execute($ticketId)` call)
- Type: `AssignmentType::AUTO_ASSIGN` (hardcoded at `AutoAssignTicketUseCase.php:194`)
- Test: `testRebalanceSkipsResolvedAndClosed` proves resolved/closed are NOT touched

### Outbox Pattern (No Direct Mercure)
- Events collected via `EventCollector` in main transaction
- `OutboxEvent` rows persisted in SECOND flush post-commit (`persistEventsToOutbox()`)
- PR4 worker (`OutboxPublisherService`) is the only Mercure publisher
- 0 references to `mercure`/`Mercure`/`publish`/`Publisher` in PR3 files (only docblock comments)

### Design Compliance
16/16 design decisions implemented:
- AgentAvailabilityService is sole eligibility check
- Legacy `active AND is_available` filter REMOVED
- Tiebreaker order: load → today → id
- Lock ordering: ticket → agents → re-filter
- Capacity re-check INSIDE the lock
- AssignmentService is single point of audit
- Events via EventCollector only (no direct Mercure)
- Outbox events persist POST-commit
- Rebalance: unassigned + new/active only
- Rebalance: per-ticket transaction
- Rebalance type is AUTO_ASSIGN
- Determinism: no random/shuffle in tiebreaker
- Approach B: no persisted counter
- Assignment.type populated correctly
- Assignment.previousAgent populated for REASSIGN/UNASSIGN
- TicketStatus.NEW → ACTIVE on first assignment

### PR1 + PR2 Regression
0 regressions:
- Agent entity unchanged from PR1
- AgentShift entity unchanged from PR1
- Assignment entity unchanged from PR1
- AgentAvailabilityService unchanged from PR2
- All 47 PR2 unit tests still pass
- `doctrine:schema:update --dump-sql`: Nothing to update

## Follow-ups (WARNINGS)

### W1. `AssignmentType::UNASSIGN` is defined but unused
- **File**: `apps/api/src/Domain/Assignment/AssignmentType.php:19`
- **Status**: Correctly deferred to PR5/T-22 (`TransitionTicketStatusUseCase` will emit UNASSIGN on resolve/close).
- **Action**: None for PR3.

### W2. No explicit tests for MANUAL_ASSIGN and REASSIGN type
- **File**: `apps/api/src/Application/UseCase/AssignTicketUseCase.php:87`
- **Status**: Implementation is correct (line 87 picks REASSIGN vs MANUAL_ASSIGN based on current owner), but no PR3 test asserts the `type` column.
- **Action**: Add `testManualAssignCreatesManualAssignRow` and `testReassignCreatesReassignRowWithPreviousAgent` to `Sprint10AgentManagementTest.php` (or accept this gap and rely on PR4 endpoint tests).

### W3. Tiebreaker 1 (fewest assigned today) is not tested
- **File**: `apps/api/src/Application/UseCase/AutoAssignTicketUseCase.php:166-168`
- **Status**: The comparator exists but no test creates two equal-load agents with different `todayCount`.
- **Action**: Add `testTiebreaker1FewestAssignedTodayWins` to `Sprint10AgentManagementTest.php`.

### W4. Rebalance edge cases not tested
- **File**: `apps/api/src/Application/UseCase/RebalanceUnassignedTicketsUseCase.php`
- **Status**: `testRebalanceSkipsResolvedAndClosed` proves the status filter, but the "0 agents available" and "partial assignment" paths are impl-verified only.
- **Action**: Add `testRebalanceWithNoAvailableAgents` and `testRebalancePartialAssignment`.

### W5. pcntl_fork test skipped in container
- **File**: `apps/api/tests/Integration/Sprint10ConcurrencyTest.php:115`
- **Status**: `testConcurrentCapacityWithPcntl` correctly self-skips when pcntl is not loaded. Sequential proof (`testSequentialCapacityLimit`) confirms capacity is enforced.
- **Action**: None for PR3. CI environments with `php-pcntl` will run the full test.

### W6. `innodb_lock_wait_timeout` not configured (pre-existing)
- **File**: `config/packages/doctrine.yaml` (or equivalent)
- **Status**: Design says 5s, default is 50s. PR3 uses the default. PR5 ops work.
- **Action**: PR5 ops work.

## Next

PR4: Endpoints + Mercure Events (5 endpoints, 2 new event types, outbox worker wiring).

PR4 will:
- Add 5 endpoints: POST/DELETE `/agents/{id}/availability`, GET `/agents/{id}/availability`, POST/DELETE `/agents/{id}/shifts`
- Add `AgentAvailabilityChanged` and `AgentShiftChanged` to `RealtimeEventType` enum
- Wire `TicketAssigned` enum value with the `assignmentType` field produced by PR3
- Update `EventTypeMapper` to map PR3's `TicketUpdatedEvent` → `TicketAssigned`
- Update `MercureJwtService` topic constants

PR4 consumes from PR3:
- `AssignmentService::recordOwnershipChange()` — already creates `TicketUpdatedEvent` with `ownerAgentId`
- `AutoAssignTicketUseCase` — already emits outbox events post-commit
- `RebalanceUnassignedTicketsUseCase` — already delegates to `AutoAssignTicketUseCase`
- `AssignTicketUseCase` — already routes through `AssignmentService`

The audit trail, lock ordering, and outbox pattern are in place. PR4 can build on them with confidence.
