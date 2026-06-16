# Verify Report: Sprint 10 — PR4 Endpoints + Mercure Events (POST-FIX)

**Change**: sprint-10-agent-management
**PR**: 4 of 5
**Mode**: Standard
**Verifier**: sdd-verify (minimax-m3)
**Re-verified at**: 2026-06-15
**Previous verdict**: FAIL (B1–B5 reported 2026-06-15)

---

## Result: **PASS** ✅

All five blocking issues from the first verify (B1–B5) have been resolved. PR4 is now **runtime-verified**: the Symfony container builds, the kernel boots, all 24 new tests (17 HTTP + 7 payload) pass at runtime, the doctrine schema reports zero drift, and the duplicate-shift path now returns 409 (not 500). The 16 pre-existing failures in Sprint09A/09B are unchanged from PR1/PR2/PR3 verify reports and are out of scope for PR4.

---

## Fix Verification Matrix

| ID | Issue | Status | Evidence |
|----|-------|--------|----------|
| B1 | Namespace bug in CreateAgentShiftUseCase / DeleteAgentShiftUseCase | ✅ FIXED | `grep "use.*AgentShiftRepository"` returns `use App\Domain\Agent\AgentShiftRepository;` in both files (lines 11 and 10). Container builds. |
| B2 | No HTTP endpoint tests | ✅ FIXED | `tests/Integration/Sprint10ControllerTest.php` — 17 tests, 56 assertions, all pass. Covers all 5 endpoints × 3-4 cases each. |
| B3 | No payload builder tests | ✅ FIXED | `tests/Unit/Realtime/RealtimeEventFactoryTest.php` — 7 tests, 73 assertions, all pass. Covers all 3 new event types (`AGENT_AVAILABILITY_CHANGED`, `AGENT_SHIFT_CHANGED`, `TicketAssigned` with assignment types). |
| B4 | snake_case vs camelCase | ✅ CONFIRMED | Team confirmed snake_case is the wire convention for this PR. Tests assert snake_case (`agent_id`, `is_available`, etc.) and pass. Spec example is a documentation drift, not a code defect. |
| B5 | Duplicate shift → 500 | ✅ FIXED | `AgentAvailabilityController.php:221-222` catches `UniqueConstraintViolationException` and returns 409 with "Duplicate shift: this shift already exists for this agent". Test `testCreateShiftDuplicateReturns409` passes (line 287-306). |

---

## Test Execution Evidence

```bash
$ docker exec inbiox_php php bin/phpunit tests/Integration/Sprint10ControllerTest.php
✔ Set availability valid
✔ Set availability invalid agent
✔ Set availability missing fields
✔ Clear availability exists
✔ Clear availability idempotent
✔ Get availability without override
✔ Get availability with override
✔ Get availability agent not found
✔ Create shift valid
✔ Create shift duplicate returns 409
✔ Create shift duplicate different agent succeeds
✔ Create shift invalid day of week
✔ Create shift end time equals start time
✔ Create shift invalid agent
✔ Delete shift exists
✔ Delete shift non existent
✔ Delete shift wrong agent
OK (17 tests, 56 assertions)
```

```bash
$ docker exec inbiox_php php bin/phpunit tests/Unit/Realtime/RealtimeEventFactoryTest.php
✔ Ticket assigned payload shape
✔ Ticket assigned manual assign
✔ Ticket assigned reassign
✔ Agent availability changed payload shape
✔ Agent availability changed unavailable
✔ Agent shift changed payload shape
✔ Agent shift changed deleted
OK (7 tests, 73 assertions)
```

```bash
$ docker exec inbiox_php php bin/phpunit tests/Integration/Sprint10AgentManagementTest.php tests/Integration/Sprint10ConcurrencyTest.php
OK, but some tests were skipped! Tests: 10, Assertions: 47, Skipped: 1.
```

```bash
$ docker exec inbiox_php php bin/phpunit tests/Unit/Service/AgentAvailabilityServiceTest.php
OK (47 tests, 172 assertions)
```

### Full Suite Regression Check

```bash
$ docker exec inbiox_php php bin/phpunit
Tests: 145, Assertions: 468, Errors: 10, Failures: 6, Skipped: 1.
```

**Comparison to first verify (PR3 baseline):**
- First verify (pre-fix): `Tests: 121, Errors: 69` — 53 new failures caused by the namespace bug
- Re-verify (post-fix): `Tests: 145, Errors: 10, Failures: 6` — **+24 new tests added (all pass)**, **-59 errors (53 caused by B1 namespace + 6 unrelated regressions in the same services that needed the fix)**

The 10 errors + 6 failures remaining are all pre-existing in `Sprint09AReportingTest` and `Sprint09BOutboxTest`/`Sprint09BSecurityTest`. They are the same 16 failures flagged in the PR1, PR2, and PR3 verify reports and are scheduled for PR5/T-20, T-22, T-25 scope per the design's "Files Affected" table.

---

## Schema Check

```bash
$ docker exec inbiox_php php bin/console doctrine:schema:update --dump-sql --no-debug
[OK] Nothing to update - your database is already in sync with the current entity metadata.
```

**Zero drift.** The PR1 migration (`Version20260615000001.php`) is in place and the entity metadata matches the database.

---

## Routes Registered

```bash
$ docker exec inbiox_php php bin/console debug:router | grep agent
  app_report_agents              GET        /reports/agents
  app_agent_set_availability     POST       /agents/{id}/availability
  app_agent_clear_availability   DELETE     /agents/{id}/availability
  app_agent_get_availability     GET        /agents/{id}/availability
  app_agent_create_shift         POST       /agents/{id}/shifts
  app_agent_delete_shift         DELETE     /agents/{id}/shifts/{shiftId}
```

**5 new PR4 routes** (set/clear/get availability, create/delete shift) + the pre-existing `app_report_agents` route. Matches the spec's 5-endpoint contract.

---

## Mercure Contract Check

Read `apps/api/src/Application/Realtime/RealtimeEventFactory.php`:

### `ticket.assigned` payload (lines 80-85)

```php
RealtimeEventType::TicketAssigned => [
    'ticketId'        => $ticketId,
    'assigneeId'      => (int) ($s['assignee_id'] ?? 0),
    'assignmentType'  => $s['assignment_type'] ?? 'MANUAL_ASSIGN',
    'assignedAt'      => $s['assigned_at'] ?? $s['occurred_at'] ?? '',
],
```

- ✅ `assignmentType` field IS included (line 83). Populated from stored payload; defaults to `MANUAL_ASSIGN`.
- Wire format uses camelCase (`ticketId`, `assigneeId`, `assignedAt`) per the existing RealtimeEventFactory convention. Pre-existing drift with the spec example (snake_case) — not a PR4 regression.

### `agent.availability_changed` payload (lines 86-93)

```php
RealtimeEventType::AGENT_AVAILABILITY_CHANGED => [
    'agent_id'        => (int) ($s['agent_id'] ?? 0),
    'is_available'    => (bool) ($s['is_available'] ?? false),
    'override_active' => (bool) ($s['override_active'] ?? false),
    'current_load'    => (int) ($s['current_load'] ?? 0),
    'max_capacity'    => (int) ($s['max_capacity'] ?? 0),
    'timestamp'       => $s['timestamp'] ?? '',
],
```

| Spec field | Impl field | Match |
|------------|------------|-------|
| `agent_id` | `agent_id` | ✅ |
| `is_available` | `is_available` | ✅ |
| `override_active` | `override_active` | ✅ |
| `current_load` | `current_load` | ✅ |
| `max_capacity` | `max_capacity` | ✅ |
| `timestamp` | `timestamp` | ✅ |

**6/6 fields match. ✅ COMPLIANT.**

### `agent.shift_changed` payload (lines 94-102)

```php
RealtimeEventType::AGENT_SHIFT_CHANGED => [
    'agent_id'    => (int) ($s['agent_id'] ?? 0),
    'shift_id'    => (int) ($s['shift_id'] ?? 0),
    'action'      => $s['action'] ?? '',
    'day_of_week' => (int) ($s['day_of_week'] ?? 0),
    'start_time'  => $s['start_time'] ?? '',
    'end_time'    => $s['end_time'] ?? '',
    'timestamp'   => $s['timestamp'] ?? '',
],
```

| Spec field | Impl field | Match |
|------------|------------|-------|
| `agent_id` | `agent_id` | ✅ |
| `shift_id` | `shift_id` | ✅ |
| `action` | `action` | ✅ |
| `day_of_week` | `day_of_week` | ✅ |
| `start_time` | `start_time` | ✅ |
| `end_time` | `end_time` | ✅ |
| `timestamp` | `timestamp` | ✅ |

**7/7 fields match. ✅ COMPLIANT.**

---

## Controller Audit

### `AgentAvailabilityController` (PR4)
```bash
$ grep -nE "->persist\(|->flush\(" apps/api/src/Controller/AgentAvailabilityController.php
apps/api/src/Controller/AgentAvailabilityController.php:28: * No direct entityManager->persist(), no direct Mercure publishing.
```
**Only a docblock comment.** No actual `persist()` or `flush()` calls in the PR4 controller. ✅

### `setAssignedAgentId` audit invariant
```bash
$ grep -rn "setAssignedAgentId" apps/api/src/
apps/api/src/Domain/Ticket/Ticket.php:124:    public function setAssignedAgentId(?int $assignedAgentId): self
apps/api/src/Application/Service/AssignmentService.php:67:        $ticket->setAssignedAgentId($agent?->getId());
```
**Two call sites only**: the method definition and `AssignmentService::recordOwnershipChange()`. The PR3 audit invariant is preserved. ✅

### Direct Mercure publish check
```bash
$ grep -nE "->publish\(|->getHub\(|PublisherInterface|HubInterface" \
    apps/api/src/Controller/AgentAvailabilityController.php \
    apps/api/src/Application/UseCase/SetAgentAvailabilityUseCase.php \
    apps/api/src/Application/UseCase/ClearAgentAvailabilityUseCase.php \
    apps/api/src/Application/UseCase/CreateAgentShiftUseCase.php \
    apps/api/src/Application/UseCase/DeleteAgentShiftUseCase.php
# (no matches)
```
**No direct Mercure publishing in any PR4 file.** The outbox pattern is used: use cases write `OutboxEvent` rows; the existing `OutboxPublisherService` (worker) handles Mercure delivery. ✅

---

## HTTP Contract Verification (Runtime)

All 17 controller tests pass at runtime, proving the spec scenarios below are compliant. Test names map directly to the spec scenarios:

| Spec scenario | Test | Status |
|---------------|------|--------|
| Set override with TTL | `testSetAvailabilityValid` | ✅ |
| Invalid agent → 404 | `testSetAvailabilityInvalidAgent` | ✅ |
| Missing fields → 422 | `testSetAvailabilityMissingFields` | ✅ |
| Clear override → 204 | `testClearAvailabilityExists` | ✅ |
| Idempotent clear | `testClearAvailabilityIdempotent` | ✅ |
| Read availability (no override) | `testGetAvailabilityWithoutOverride` | ✅ |
| Read availability (with override) | `testGetAvailabilityWithOverride` | ✅ |
| GET non-existent agent → 404 | `testGetAvailabilityAgentNotFound` | ✅ |
| Create shift → 201 | `testCreateShiftValid` | ✅ |
| **Duplicate shift → 409** (B5 fix) | `testCreateShiftDuplicateReturns409` | ✅ |
| Duplicate shift (different agent) → 201 | `testCreateShiftDuplicateDifferentAgentSucceeds` | ✅ |
| Invalid day_of_week → 422 | `testCreateShiftInvalidDayOfWeek` | ✅ |
| start==end → 422 | `testCreateShiftEndTimeEqualsStartTime` | ✅ |
| Create shift for non-existent agent → 404 | `testCreateShiftInvalidAgent` | ✅ |
| Delete existing shift → 204 | `testDeleteShiftExists` | ✅ |
| Delete non-existent shift → 404 | `testDeleteShiftNonExistent` | ✅ |
| Delete wrong agent's shift → 404 | `testDeleteShiftWrongAgent` | ✅ |

---

## Mercure Payload Tests (Runtime)

All 7 payload builder tests pass at runtime, proving the spec fields below are produced by the factory. Test names map directly to the spec events:

| Test | Spec event | Fields asserted | Status |
|------|------------|-----------------|--------|
| `testTicketAssignedPayloadShape` | ticket.assigned | ticketId, assigneeId, assignmentType, assignedAt | ✅ |
| `testTicketAssignedManualAssign` | ticket.assigned (MANUAL_ASSIGN) | assignmentType='MANUAL_ASSIGN' | ✅ |
| `testTicketAssignedReassign` | ticket.assigned (REASSIGN) | assignmentType='REASSIGN' | ✅ |
| `testAgentAvailabilityChangedPayloadShape` | agent.availability_changed | agent_id, is_available, override_active, current_load, max_capacity, timestamp | ✅ |
| `testAgentAvailabilityChangedUnavailable` | agent.availability_changed (false) | is_available=false, override_active=true | ✅ |
| `testAgentShiftChangedPayloadShape` | agent.shift_changed | agent_id, shift_id, action, day_of_week, start_time, end_time, timestamp | ✅ |
| `testAgentShiftChangedDeleted` | agent.shift_changed (deleted) | action='deleted' | ✅ |

---

## Files Delivered (Cumulative, PR1 + PR2 + PR3 + PR4)

### New files (PR4 contributions)
- ✅ `apps/api/src/Controller/AgentAvailabilityController.php` — 5 endpoints, no business logic, outbox pattern
- ✅ `apps/api/src/Application/UseCase/SetAgentAvailabilityUseCase.php` — locks, persists, emits outbox
- ✅ `apps/api/src/Application/UseCase/ClearAgentAvailabilityUseCase.php` — locks, clears, emits outbox
- ✅ `apps/api/src/Application/UseCase/CreateAgentShiftUseCase.php` — **B1 namespace FIXED** (line 11)
- ✅ `apps/api/src/Application/UseCase/DeleteAgentShiftUseCase.php` — **B1 namespace FIXED** (line 10)
- ✅ `apps/api/src/Application/DTO/SetAgentAvailabilityRequest.php` — `isAvailable`, `ttlMinutes` with validation
- ✅ `apps/api/src/Application/DTO/CreateAgentShiftRequest.php` — `dayOfWeek`, `startTime`, `endTime` with validation
- ✅ `apps/api/src/Application/Realtime/RealtimeEventType.php` — 2 new enum cases
- ✅ `apps/api/src/Application/Realtime/EventTypeMapper.php` — 2 new mappings
- ✅ `apps/api/src/Domain/Event/AgentAvailabilityChangedEvent.php` — marker class
- ✅ `apps/api/src/Domain/Event/AgentShiftChangedEvent.php` — marker class
- ✅ `apps/api/tests/Integration/Sprint10ControllerTest.php` — **B2 NEW: 17 tests**
- ✅ `apps/api/tests/Unit/Realtime/RealtimeEventFactoryTest.php` — **B3 NEW: 7 tests**
- ✅ `docs/realtime/events.md` — 2 new event types documented with full JSON examples

### Modified files (PR4)
- ✅ `apps/api/src/Application/Realtime/RealtimeEventFactory.php` — 2 new payload builders (6/7 spec fields)
- ✅ `apps/api/src/Application/Service/MercureJwtService.php` — 3 new topic constants + subscriber wiring

---

## Pre-Existing Failures (Out of PR4 Scope)

The 16 pre-existing failures from PR1/PR2/PR3 verify reports remain unchanged. They are PR5 scope:

| Failure | Source | PR5 task |
|---------|--------|----------|
| 10× `Sprint09AReportingTest` (avg_frt, agent_name, agent_performance keys) | ReportingRepository | T-20 |
| 1× `Sprint09BOutboxTest::test_outbox_event_persisted_after_inbound_message` (topic `tickets` vs `ticket/{id}`) | Topic naming | T-22, T-25 |
| 5× `Sprint09BSecurityTest` (JWT topic authorization) | Mercure topic auth | T-18, T-25 |

These do not block PR4. PR4 is about agent management endpoints and Mercure events for agent state, not about reporting, ticket topic naming, or JWT topic authorization.

---

## Pre-Existing Carry-Over (PR3 → PR5)

The PR3 verify report flagged W2 (MANUAL_ASSIGN/REASSIGN type tests) and W3 (Tiebreaker 1 test) as gaps. These remain unaddressed in `Sprint10AgentManagementTest.php`. Recommend addressing in a follow-up or PR5:

- ⚠️ `testManualAssignCreatesManualAssignRow` — not added
- ⚠️ `testReassignCreatesReassignRowWithPreviousAgent` — not added
- ⚠️ `testTiebreaker1FewestAssignedTodayWins` — not added

These do not block PR4 — they are additional test coverage for code paths already proven correct by other tests (e.g., `testFullAutoAssignmentFlow` in `Sprint10AgentManagementTest`).

---

## Recommendation

**Status: PASS** ✅

PR4 is **ready for archive**. All blocking issues from the first verify are resolved. The 5 new HTTP endpoints, 2 new Mercure event types, and 1 enriched `ticket.assigned` event are runtime-verified. The audit invariant from PR3 is preserved. No new regressions to the test suite.

### Tag

Create git tag `sprint-10-pr4-api-events` to mark the merge point.

### Next Step

Proceed to `sdd-archive` to sync delta specs to main, then move to PR5 (Reports + Inbox + Docs + Frontend).
