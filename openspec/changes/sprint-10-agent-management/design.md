# Sprint 10 — Agent Management: Technical Design

> **Author**: sdd-design (minimax-m3) on 2026-06-14.
> **Mode**: `openspec` — written to `openspec/changes/sprint-10-agent-management/design.md`.
> **Source**: exploration.md, proposal.md, agent-availability/spec.md, auto-assignment-audit/spec.md, sprint_10.md.

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          AGENT DOMAIN (Sprint 10)                           │
└─────────────────────────────────────────────────────────────────────────────┘

  ┌──────────────────┐  1───N   ┌────────────────────┐
  │     Agent        │─────────▶│    AgentShift      │
  │  (UPDATE)        │          │  (NEW)             │
  │                  │          │                    │
  │  id              │          │  id                │
  │  name, email     │          │  agent_id (FK)     │
  │  active          │          │  day_of_week 1..7  │
  │  is_available    │          │  start_time (TIME) │
  │  timezone (NEW)  │          │  end_time   (TIME) │
  │  override (NEW)  │          │  CHECK start!=end  │
  │  override_until  │          │  UNIQUE(agent,     │
  │  max_active      │          │        dow,start,  │
  │                  │          │        end)        │
  └────────┬─────────┘          └────────────────────┘
           │
           │ 1───N
           ▼
  ┌──────────────────┐                       ┌────────────────────┐
  │  Assignment      │ N───1                  │     Ticket         │
  │  (UPDATE)        │───────────▶            │  (no change)       │
  │                  │                        │                    │
  │  + type enum     │                        │  assigned_agent_id │
  │    AUTO_ASSIGN   │                        │  status            │
  │    MANUAL_ASSIGN │                        │                    │
  │    REASSIGN      │                        │                    │
  │    UNASSIGN      │                        │                    │
  │  + previous_     │                        │                    │
  │    agent_id      │                        │                    │
  └──────────────────┘                        └────────────────────┘

  ┌──────────────────────────────────────────────────────────┐
  │  AgentAvailabilityService  (SINGLE source of truth)     │
  │  ──────────────────────────────────────────────────────  │
  │  isAvailable(agent, atUtc)        : bool                │
  │  listAvailableAgents(atUtc)       : Agent[]             │
  │                                                          │
  │  Algorithm: active → override → schedule → capacity      │
  │  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━  │
  │                                                          │
  │  AutoAssignService  (deterministic pipeline)             │
  │  ───────────────────────────────────────────             │
  │  findEligible()  →  sort(tiebreakers)  →  selectFirst()  │
  │  assign(ticket, agent)  →  audit(Assignment)  →  publish │
  └──────────────────────────────────────────────────────────┘
```

**Source-of-truth contract** (hard rules):
| Concept | Canonical location | Forbidden duplicates |
|---|---|---|
| Agent timezone | `agents.timezone` | ❌ do not copy to `Ticket` or `Shift` |
| Agent capacity limit | `agents.max_active_tickets` | ❌ do not denormalize to tickets |
| Agent's live workload | `SELECT COUNT(*) FROM tickets WHERE owner_agent_id=? AND status IN ('new','active','waiting')` | ❌ do NOT persist `current_active_tickets` (see §Capacity Model) |
| Shift schedule | `agent_shifts` table | ❌ do not cache anywhere |
| Ticket owner (current) | `tickets.assigned_agent_id` | — |
| Ownership history | `assignments` table | ❌ do not derive from tickets |

---

## Entity Design

### `Agent` (UPDATE)

Additive — no destructive change. Existing columns preserved.

| Field | Type | Constraint | Note |
|---|---|---|---|
| `timezone` | `VARCHAR(64)` | NOT NULL DEFAULT 'UTC' | IANA name; validated by `AgentAvailabilityService::assertValidTimezone()` |
| `is_available_override` | `BOOLEAN` | NULL | `null` = no override |
| `override_until` | `DATETIME_IMMUTABLE` | NULL | `null` = persistent |
| ~~`current_active_tickets`~~ | — | — | **NOT created** (see §Capacity Model) |

Indexes added:
- `idx_agents_active` on `(active, is_available)` — replaces the current implicit scan used by `AutoAssignTicketUseCase`
- `idx_tickets_owner_status` on `(assigned_agent_id, status)` — covers the availability `COUNT(*)` (this is a `tickets` index, not an agents index)

Validation rules in setters:
- `setTimezone()`: throw `InvalidArgumentException` if `timezone_name_get(DateTimeZone($value))` is false.
- `setMaxActiveTickets($n)`: throw if `$n < 1`.

### `AgentShift` (NEW)

| Field | Type | Constraint |
|---|---|---|
| `id` | `BIGINT AUTO_INCREMENT` | PK |
| `agent_id` | `BIGINT` | FK → `agents.id` ON DELETE CASCADE |
| `day_of_week` | `TINYINT` | 1..7 (Mon=1, ISO-8601) |
| `start_time` | `TIME` | local to agent timezone |
| `end_time` | `TIME` | local to agent timezone; `end_time != start_time` |
| `created_at` | `DATETIME_IMMUTABLE` | — |

Constraints:
- `CHECK (day_of_week BETWEEN 1 AND 7)`
- `CHECK (start_time <> end_time)`
- `UNIQUE(agent_id, day_of_week, start_time, end_time)`
- `INDEX idx_agent_shifts_agent_dow (agent_id, day_of_week)` — composite, supports the schedule lookup

Cross-midnight is **not** a column — it is derived at evaluation time when `end_time < start_time`.

### `Assignment` (UPDATE)

Add two columns to support audit types and reassignment history:

| Field | Type | Constraint |
|---|---|---|
| `type` | `VARCHAR(20)` | NOT NULL DEFAULT 'MANUAL_ASSIGN' — enum: `AUTO_ASSIGN \| MANUAL_ASSIGN \| REASSIGN \| UNASSIGN` |
| `previous_agent_id` | `VARCHAR(100)` | NULL — set for `REASSIGN` and `UNASSIGN` |

`agent_id` is migrated to `BIGINT NOT NULL` with a foreign key to `agents.id`. The `assignments` table is empty (0 rows) — zero-risk migration. All three representations of agent identity (`Agent.id`, `Ticket.assignedAgentId`, `Assignment.agentId`) now share the same type and referential integrity.

New index: `idx_assignments_agent_assigned_at (agent_id, assigned_at)` — supports the tiebreaker "fewest assignments today".

---

## Capacity Model Decision

This is the most consequential decision in the design. The spec currently says "current_active_tickets is a cached counter, updated transactionally." **I recommend the opposite: derived count, no persisted column.** Here is the reasoning.

### Approach A — Persisted counter (`current_active_tickets` INT column)

| Aspect | Evaluation |
|---|---|
| Read cost | O(1) — single integer read |
| Write cost | Every assignment: 1 UPDATE. Every resolve/close: 1 UPDATE. Every status change that crosses the active boundary: 1 UPDATE. **4 write paths to keep in sync.** |
| Correctness risk | Cache drift if any write path forgets to update the counter. Backfill is mandatory at migration. |
| Migration cost | Must backfill `current_active_tickets = (SELECT COUNT(*)...)` on existing data; **locks tickets table** on large datasets. |
| Concurrency primitive | `SELECT ... FOR UPDATE` on agent row + read counter + write counter. |

### Approach B — Derived count (no column)

| Aspect | Evaluation |
|---|---|
| Read cost | One `COUNT(*)` per availability check, indexed on `(assigned_agent_id, status)`. **Sub-millisecond at MVP scale** (≤10k tickets, ≤50 agents). |
| Write cost | Zero. The count is a property of the data, not a duplicated value. |
| Correctness risk | None — the count cannot drift because it is the count. |
| Migration cost | None. New `agent_shifts` table + the 3 `agents` columns. **No backfill needed.** |
| Concurrency primitive | `SELECT ... FOR UPDATE` on agent row + `COUNT(*)` inside the lock. The lock serializes reads; the count is stable inside it. |

### Performance analysis (Approach B at MVP scale)

The hot query inside the FOR UPDATE region:
```sql
SELECT COUNT(*) FROM tickets
WHERE assigned_agent_id = :agent_id
  AND status IN ('new', 'active', 'waiting');
```

With `idx_tickets_owner_status (assigned_agent_id, status)`, MySQL does an index-only count. Benchmarks at this index shape: ~0.2ms for 10k rows per agent, ~0.05ms for 1k rows.

`listAvailableAgents(now)` does **one count per candidate agent** inside the lock. With 50 agents at peak, that's 50 counts × 0.2ms = 10ms total — under the 50ms request budget. **No reason to cache.**

### Recommendation: **Approach B (derived count)**

**Rationale:**
1. **The user prefers simplicity unless performance demands complexity.** Performance does not demand complexity at MVP scale.
2. **Approach B is more correct, not less.** A persisted counter is a duplicated fact. Duplicated facts drift.
3. **Approach B is already the pattern in the current code.** `AutoAssignTicketUseCase::executeInternal` (lines 99-112) already does `COUNT(*)` for capacity decisions. The Sprint 10 rewrite keeps this pattern; the spec's "cached counter" requirement is the change of direction we should reject.
4. **No migration backfill risk** — `current_active_tickets` is a backfill that can lock the `tickets` table for minutes on 10k+ rows.
5. **Adding a cached counter later is a pure optimization, not a refactor.** If we hit 1k agents / 100k tickets, we can add `current_active_tickets` then — read path stays the same (`Agent::getCurrentLoad()`), the implementation just swaps COUNT for the column. **No API change, no migration, no callers to update.**

**Spec change required**: the `agent-availability` and `auto-assignment-audit` specs reference `current_active_tickets` in 11 scenarios. Recommend sdd-spec issue a delta replacing the counter with "the live count via the indexed query". This is a spec-level correction, not a design leak.

**If the team rejects Approach B** (i.e. the user wants the counter persisted): the design still works — the only changes are (a) add the column, (b) run the backfill, (c) update the two setters in `AssignTicketUseCase` and `TransitionTicketStatusUseCase` to maintain it. All other sections of this design remain valid.

---

## Service Layer

### `AgentAvailabilityService` (NEW, the only place availability lives)

**Path**: `apps/api/src/Application/Service/AgentAvailabilityService.php`

**Public surface:**
```php
final class AgentAvailabilityService
{
    public function isAvailable(Agent $agent, \DateTimeImmutable $atUtc): bool;

    /** @return Agent[] */
    public function listAvailableAgents(\DateTimeImmutable $atUtc): array;
}
```

**Algorithm for `isAvailable` (single source of truth):**
```
1. if (!agent.active)                       return false;     // account
2. if (agent.isAvailableOverride !== null
       && (agent.overrideUntil === null
           || agent.overrideUntil >= atUtc))
       return agent.isAvailableOverride;                    // override
3. local = utc → agent.timezone
   if (!agentHasShiftAt(agent, local))    return false;     // schedule
4. count = SELECT COUNT(*) FROM tickets
            WHERE owner_agent_id = agent.id
              AND status IN ('new','active','waiting');
   if (count >= agent.maxActiveTickets)   return false;     // capacity
5.                                         return true;
```

**`agentHasShiftAt` helper** (private, the cross-midnight logic lives here and nowhere else):
```
// For a given agent, find any shift where the (dow, time) tuple of
// (local.day, local.time) falls within [start_time, end_time).
// Cross-midnight: if end < start, the shift matches local.day AND
// (time >= start OR time < end), AND the previous day's shift
// (day_of_week = local.day - 1 modulo 7) matches if (time < end).
```

This is a single batched query against `agent_shifts` indexed on `(agent_id, day_of_week)`. Preload all shifts for the candidate agents in one round-trip; the helper iterates in PHP.

**`listAvailableAgents`:**
- Step 1: `SELECT * FROM agents WHERE active = 1` (no `is_available` filter — that field is being replaced by the override+schedule logic).
- Step 2: preload shifts for all of them in one query: `SELECT * FROM agent_shifts WHERE agent_id IN (...)`.
- Step 3: apply override + schedule filter in PHP (uses local time per agent).
- Step 4: apply capacity filter in PHP by counting tickets per agent.
- Returns `Agent[]` in stable order (by `id ASC`); the auto-assigner re-sorts with tiebreakers.

**Dependencies**: `AgentRepository`, `EntityManagerInterface` (for the `COUNT(*)` query), `DateTimeZone` validation utility.

### `AutoAssignService` (NEW, replaces AutoAssignTicketUseCase)

**Path**: `apps/api/src/Application/UseCase/AutoAssignTicketUseCase.php` (rewrite in place — same class name, different body)

**Pipeline (deterministic, self-contained):**
```
BEGIN TRANSACTION
  1. lock ticket FOR UPDATE
  2. if assigned → return idempotent result
  3. available = AgentAvailabilityService.listAvailableAgents(nowUtc)
  4. lock candidates FOR UPDATE   (SELECT * FROM agents WHERE id IN (...) FOR UPDATE)
  5. re-filter: re-run capacity count inside the lock (may have changed)
  6. pick by tiebreaker:
       ORDER BY live_load ASC,
                (SELECT COUNT(*) FROM assignments
                 WHERE agent_id = a.id AND type='AUTO_ASSIGN'
                   AND assigned_at >= today_start) ASC,
                a.id ASC
  7. update tickets.assigned_agent_id, status, updated_at
  8. if status was NEW, set status=ACTIVE
  9. persist Assignment(type=AUTO_ASSIGN, agent_id, assigned_by='system')
  10. INSERT into outbox_events → ticket.assigned (post-commit)
COMMIT
```

**Tiebreaker SQL is computed inside the lock** — the second-tier tiebreaker (today's count) needs a per-agent `COUNT` on `assignments`. This is two extra queries but they hit `idx_assignments_agent_assigned_at` and run in <1ms each.

**Returns:**
```php
['ticket_id' => int, 'assigned' => bool, 'agent_id' => ?int, 'reason' => string]
// reasons: 'success' | 'ticket_not_found' | 'already_assigned'
//        | 'no_agents_available' | 'all_agents_at_capacity'
```

**The old hardcoded `active AND is_available` filter is REMOVED.** `AgentAvailabilityService` is the SOLE eligibility check. (Spec scenario: "Auto-assignment replaces old hardcoded check".)

### `RebalanceUnassignedTicketsUseCase` (NEW)

**Path**: `apps/api/src/Application/UseCase/RebalanceUnassignedTicketsUseCase.php`

```
1. SELECT * FROM tickets
   WHERE assigned_agent_id IS NULL
     AND status IN ('new', 'active')
   ORDER BY created_at ASC
2. for each ticket:
     delegate to AutoAssignTicketUseCase::executeInTransaction(ticketId)
       // executeInTransaction reuses the parent's transaction OR
       // opens its own — for rebalance we want one-per-ticket so a
       // single failure doesn't void the whole batch
3. return ['attempted' => N, 'assigned' => M, 'skipped' => N - M]
```

One transaction per ticket (not one big transaction) so a transient lock timeout on ticket #5 doesn't roll back tickets #1-#4.

---

## Transaction Boundaries

| Path | Scope | Lock pattern | Outbox |
|---|---|---|---|
| `AssignTicketUseCase::assignTicket` (manual) | Ticket row + Agent row (if counter) | `lock(ticket, PESSIMISTIC_WRITE)` + agent FOR UPDATE only if counter exists | yes (post-commit) |
| `AutoAssignTicketUseCase::execute` | Ticket row + agent candidates | `SELECT ... FOR UPDATE` on ticket; `IN (...) FOR UPDATE` on agents | yes (post-commit) |
| `RebalanceUnassignedTicketsUseCase` | One transaction per ticket | same as AutoAssign | yes (per-ticket, post-commit) |
| `TransitionTicketStatusUseCase::resolve` / `close` | Ticket row | `SELECT ... FOR UPDATE` on ticket. **No counter update needed under Approach B.** | yes (status change event) |
| `SetAgentAvailability` / `ClearAgentAvailability` | Agent row | `SELECT ... FOR UPDATE` on agent | yes (`agent.availability_changed`) |
| `CreateAgentShift` / `DeleteAgentShift` | Agent row (for FK consistency) | `SELECT ... FOR UPDATE` on agent | yes (`agent.shift_changed`) |
| `ReportingRepository::getAgentPerformance` | none (read-only) | none | n/a |

**Lock timeout**: 5 seconds. Symfony config: `dbal.options.innodb_lock_wait_timeout: 5`. If exceeded, the use case returns a 409 Conflict (translated by the controller). No retry inside the use case — the caller (controller or HTTP client) decides.

**Transaction with `executeInTransaction`**: required so the rebalance loop can compose a transaction-per-ticket without nesting. The public `execute()` opens one; `executeInTransaction()` reuses the caller's.

---

## Mercure Event Flow

**Sole path** (mandated by design standard #5):
```
DB transaction commit
  → collect domain event (EventCollector)
    → persist OutboxEvent in a SECOND flush (post-commit, separate transaction)
      → OutboxPublisherService (async worker, existing)
        → Mercure hub
```

**No code path** may publish to Mercure directly from a controller or use case. The only way events leave the system is through `OutboxEvent` + `OutboxPublisherService`. This guarantees:
- Events fire only after the DB write is durable.
- Failed publishes retry (existing MAX_RETRIES=5, dead_letter).
- A single ordering by `(created_at, id)`.

### New events (Sprint 10)

| Event | Topic | Payload | Trigger |
|---|---|---|---|
| `agent.availability_changed` | `agent/{id}/availability` + `agents` (broadcast) | `{agentId, isAvailable, source: 'override'\|'schedule'\|'capacity', overrideUntil, changedAt}` | SetAgentAvailability, ClearAgentAvailability, override TTL expiry evaluated at assignment time |
| `agent.shift_changed` | `agent/{id}/shifts` + `agents` (broadcast) | `{agentId, shiftId, action: 'created'\|'deleted'}` | CreateAgentShift, DeleteAgentShift |
| `ticket.assigned` | `ticket/{id}/assigned` (existing topic) | `{ticketId, agentId, assignmentType, timestamp}` | any assignment (auto/manual/reassign). Payload matches the spec's `auto-assignment-audit`. |
| `ticket.unassigned` | `ticket/{id}/assigned` (same topic) | `{ticketId, agentId: null, assignmentType: 'UNASSIGN', timestamp}` | ownership removal |

**RealtimeEventType enum** (existing): add `AgentAvailabilityChanged = 'agent.availability_changed'` and `AgentShiftChanged = 'agent.shift_changed'`. The `ticket.assigned` and `ticket.unassigned` reuse the existing `TicketAssigned` enum value but the `assignmentType` field disambiguates. **Backward compatible** — frontend that doesn't read `assignmentType` ignores it.

**Assignment as Domain Events** (design standard #6): the three domain events `TicketAssignedEvent`, `TicketUnassignedEvent`, `TicketReassignedEvent` are subtypes of a new `TicketOwnershipChangedEvent` base. The `Assignment` row is created in the same transaction as the event collection. Single point of emission: `AssignmentService::recordOwnershipChange()` is the only function that touches `assignments` and emits the event.

---

## Migration Plan

**Single migration**: `Version20260615000001.php` (idempotent, runs in a low-traffic window).

```sql
-- 1. ALTER agents
ALTER TABLE agents
  ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  ADD COLUMN is_available_override TINYINT(1) DEFAULT NULL,
  ADD COLUMN override_until DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)';
-- NOTE: NO current_active_tickets column (Approach B)

-- 2. Indexes
CREATE INDEX idx_agents_active ON agents (active, is_available);
CREATE INDEX idx_tickets_owner_status ON tickets (assigned_agent_id, status);

-- 3. ALTER assignments — fix type mismatch + add FK
ALTER TABLE assignments
  MODIFY agent_id BIGINT NOT NULL,
  ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'MANUAL_ASSIGN',
  ADD COLUMN previous_agent_id VARCHAR(100) DEFAULT NULL,
  ADD CONSTRAINT fk_assignments_agent
    FOREIGN KEY (agent_id) REFERENCES agents(id);
CREATE INDEX idx_assignments_agent_assigned_at ON assignments (agent_id, assigned_at);

-- 4. CREATE agent_shifts
CREATE TABLE agent_shifts (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  agent_id BIGINT NOT NULL,
  day_of_week TINYINT NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  CONSTRAINT fk_agent_shifts_agent FOREIGN KEY (agent_id)
    REFERENCES agents (id) ON DELETE CASCADE,
  CONSTRAINT chk_agent_shifts_dow CHECK (day_of_week BETWEEN 1 AND 7),
  CONSTRAINT chk_agent_shifts_time CHECK (start_time <> end_time),
  UNIQUE KEY uniq_agent_dow_start_end (agent_id, day_of_week, start_time, end_time)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE=InnoDB;

CREATE INDEX idx_agent_shifts_agent_dow ON agent_shifts (agent_id, day_of_week);
```

**No backfill.** The whole point of Approach B. The `current_active_tickets` value is computed live.

**Existing data implications**:
- All existing agents get `timezone = 'UTC'` and `NULL` override — they are in-shift whenever `is_available = true` (legacy semantics) and at-capacity per live count. **Behavior change for existing users**: pre-Sprint 10, the only filter was `active AND is_available`. Post-Sprint 10, the filter becomes `active AND (override OR schedule) AND capacity`. If an existing agent has `is_available = true` but no shifts, they will become **unassignable** until either a shift is created or an override is set. **Migration risk for prod**: documented in the rollout plan; admins create shifts as part of the deploy runbook.

**Rollback**: `down()` drops `agent_shifts`, removes the new columns, drops the new indexes. No data loss except the `agent_shifts` rows themselves.

---

## Edge Cases & Fallbacks

| Case | Behavior |
|---|---|
| Lock timeout (>5s) on agent row | Transaction rolls back; use case returns `['assigned' => false, 'reason' => 'lock_timeout']`; HTTP 409 |
| No eligible agents | Ticket stays unassigned (`owner_agent_id` remains NULL). **No Assignment row created.** Use case returns `['assigned' => false, 'reason' => 'no_agents_available']`; HTTP 200 (this is not an error, it's the natural "queue full" state). |
| Override TTL expired at assignment time | `isAvailable()` re-evaluates per the spec. If the override has expired, schedule is consulted. If no schedule match, agent is excluded. **No event emitted on expiry** — expiry is read-time, not state-change. The next `setAvailability` or `clearAvailability` call emits the event. |
| Override with `null` `override_until` (persistent) | Stays active until `DELETE /agents/{id}/availability` is called. **UX risk** documented in `exploration.md` §4.4. Mitigation deferred to Sprint 10.5. |
| Agent with no shifts AND no override | `isAvailable() = false` (per spec). Documented in the route handlers as a "this agent needs a shift or an override" admin note. |
| Cross-midnight shift where the END crosses to the NEXT day in the agent's TZ but the agent is in a different TZ | **Already handled.** Cross-midnight is evaluated in the agent's local time, not UTC. Saturday 02:30 in `America/Argentina/Buenos_Aires` for a Friday 22:00-06:00 shift → match. Verified with 4 dedicated test cases (spec scenarios 8-10). |
| Concurrent assignment to two tickets for the same agent when 1 slot remains | First transaction acquires FOR UPDATE on agent row, increments-or-reads capacity (Approach B: reads count), assigns. Second transaction blocks on lock, then re-reads count, sees capacity full, skips. **No over-allocation.** |
| Auto-assign of a ticket that already has an owner | Returns `['assigned' => false, 'reason' => 'already_assigned']`. Idempotent. No new Assignment row. |
| Rebalance iterates 1000 unassigned tickets with 0 eligible agents | One transaction per ticket, each returning `no_agents_available` in <5ms. Total ~5s. No persistence. Safe. |
| `tickets.assigned_agent_id` drift from latest `Assignment.agentId` (pre-existing inconsistency noted in exploration §1) | **Sprint 10 fixes this for new writes only.** The spec mandates "every path generates audit row" — from this sprint forward, the two never drift for new ownership changes. Pre-existing drift is a one-shot data correction in a separate migration (out of scope for Sprint 10; flagged for Sprint 10.5). |
| ~~`Agent.id` BIGINT vs `Assignment.agentId` VARCHAR(100)~~ | **Resolved pre-PR1.** `assignments` table was empty (0 rows). Migrated `agent_id` to `BIGINT NOT NULL` + `FOREIGN KEY (agent_id) REFERENCES agents(id)`. Three representations, one type. |
| IANA timezone invalid | Reject with 422 Unprocessable Entity. `DateTimeZone($value)` throws → caught in DTO validation, returns structured error. |
| Reporting query for a date range with 0 tickets | Empty array. Status 200. No special case. |

---

## Files Affected

### New files

| Path | Purpose |
|---|---|
| `apps/api/src/Domain/Agent/AgentShift.php` | AgentShift entity (Doctrine). |
| `apps/api/src/Domain/Agent/AgentShiftRepository.php` | `findByAgent(int $id)`, `findByAgents(array $ids)` (batched). |
| `apps/api/src/Application/Service/AgentAvailabilityService.php` | **THE** availability service. ~250 lines. |
| `apps/api/src/Application/UseCase/SetAgentAvailabilityUseCase.php` | POST /agents/{id}/availability handler. |
| `apps/api/src/Application/UseCase/ClearAgentAvailabilityUseCase.php` | DELETE /agents/{id}/availability handler. |
| `apps/api/src/Application/UseCase/CreateAgentShiftUseCase.php` | POST /agents/{id}/shifts handler. |
| `apps/api/src/Application/UseCase/DeleteAgentShiftUseCase.php` | DELETE /agents/{id}/shifts/{id} handler. |
| `apps/api/src/Application/UseCase/RebalanceUnassignedTicketsUseCase.php` | Iterates unassigned tickets, delegates to AutoAssign. |
| `apps/api/src/Application/Service/AssignmentService.php` | `recordOwnershipChange()` — single point of Assignment row + event emission. |
| `apps/api/src/Application/DTO/SetAgentAvailabilityRequest.php` | DTO with IANA validation. |
| `apps/api/src/Application/DTO/CreateAgentShiftRequest.php` | DTO with `day_of_week` and time validation. |
| `apps/api/src/Controller/AgentAvailabilityController.php` | 4 endpoints (POST/DELETE availability, POST/DELETE shifts). |
| `apps/api/migrations/Version20260615000001.php` | Schema migration. |
| `apps/api/tests/Unit/Service/AgentAvailabilityServiceTest.php` | 24 scenarios from the spec, plus 4 added (override-race, capacity-count-fence, cross-tz-cross-midnight, all-agents-eligible-pick-lowest-id). |
| `apps/api/tests/Integration/Sprint10AgentManagementTest.php` | End-to-end: create agent, set shift, set override, post a ticket, auto-assign, assert ownership + audit row + event in outbox. |
| `apps/api/tests/Integration/Sprint10ConcurrencyTest.php` | 50 concurrent auto-assign requests against a 5-agent pool with max=3. Asserts no over-allocation. |
| `docs/realtime/events.md` (update — already exists) | Document the 2 new RealtimeEventType enum values + 2 new event topics. |

### Modified files

| Path | Why |
|---|---|
| `apps/api/src/Domain/Agent/Agent.php` | Add 3 fields (`timezone`, `is_available_override`, `override_until`) + setters with validation. No destructiveness. |
| `apps/api/src/Domain/Assignment/Assignment.php` | Add `type` and `previous_agent_id` columns + constructor params. |
| `apps/api/src/Repository/AssignmentRepository.php` | Add `countAutoAssignedToday(int $agentId, \DateTimeImmutable $todayStart)`. |
| `apps/api/src/Repository/TicketRepository.php` | Add `countActiveForAgent(int $agentId)`. |
| `apps/api/src/Repository/ReportingRepository.php` | JOIN `agents` for name; expose `current_active_tickets`, `current_load`, `is_in_shift` in the leaderboard response. |
| `apps/api/src/Application/UseCase/AutoAssignTicketUseCase.php` | **Rewrite** to use `AgentAvailabilityService` + `AssignmentService` + outbox. Same class name, new body. |
| `apps/api/src/Application/UseCase/AssignTicketUseCase.php` | Add `REASSIGN` / `UNASSIGN` types; route through `AssignmentService`. |
| `apps/api/src/Application/UseCase/TransitionTicketStatusUseCase.php` | On resolve/close, emit `ticket.assigned` (UNASSIGN type) if owner changes. |
| `apps/api/src/Application/Realtime/RealtimeEventType.php` | Add 2 enum values: `AgentAvailabilityChanged`, `AgentShiftChanged`. |
| `apps/api/src/Application/Realtime/EventTypeMapper.php` | Map `Assignment\OwnershipChangedEvent` to `TicketAssigned`; add 2 new mappings. |
| `apps/api/src/Application/Realtime/RealtimeEventFactory.php` | Add payload builders for the 2 new event types. |
| `apps/api/src/Application/Service/MercureJwtService.php` | Add a `TOPIC_AGENT_AVAILABILITY = 'agent/%d/availability'` constant; broadcast `TOPIC_AGENTS = 'agents'` for capacity-bar clients (Sprint 10.5). |
| `apps/api/src/Controller/InboxController.php` | Expose `current_active_tickets` and `is_in_shift` in the inbox response (read-only, no new endpoints). |
| `apps/web/src/app/core/stores/agent.store.ts` | New Angular signal store (mirrors `TicketStore` pattern). |
| `apps/web/src/app/core/services/agent.service.ts` | REST client for the 4 new endpoints. |
| `apps/web/src/app/features/agents/availability/availability.page.ts` | UI: set/clear override, choose TTL. |
| `apps/web/src/app/features/agents/shifts/shifts.page.ts` | UI: list/create/delete shifts. |
| `docs/realtime/events.md` | Document 2 new event types and topics. |
| `docs/api/openapi.yaml` (or equivalent) | Document 4 new endpoints. |
| `docs/04_sprints/sprint_10 (Agent Management).md` | Mark Sprint 10 features as shipped. |

**No modifications** to: `AgentRepository` (we don't add a special "find eligible" method — the service is the only one that knows about availability), `TicketStateMachine` (unaffected), `HandleInboundMessageUseCase` (number-agnostic), `HandleOutboundMessageUseCase` (unaffected), `Ticket` entity (no change).

---

## Post-MVP Extension Points

The design intentionally stops here. The following hooks are present but **not designed, not coded, not tested** in Sprint 10. Mentioning them in this design is a deliberate flag for Sprint 10.5, NOT a leak.

| Future feature | Where it would hook in | What Sprint 10 must NOT do |
|---|---|---|
| Capacity bar UI in inbox | Subscribe to the `agents` broadcast topic; render `current_load = COUNT(*)/max` from `AgentAvailabilityService.listAvailableAgents()`. Frontend-only work. | No backend change required. The data is already exposed. |
| One-click "Reassign with required note" | `AssignmentService::recordOwnershipChange()` already supports `REASSIGN` type. Add a `notes` requirement to the manual assign DTO. | Do not change the `notes` column; do not introduce a new event type. |
| Per-agent `handles_number_ids` (per-number routing) | Add a `phone_number_ids JSON` column to `agents`. Update `AgentAvailabilityService` to take a `phoneNumberId` param. | Do not add the column. Do not add the parameter. |
| Agent presence (online/away/busy) | New `AgentSession` table + heartbeat. The `isAvailable()` chain would prepend a presence check. | Do not introduce a presence state. Do not add a heartbeat. |
| Team / Group | New `teams` and `agent_teams` tables. `AgentAvailabilityService` would filter by `team_id IN (...)` when set. | Do not introduce the `Team` entity. Do not add `team_id` to `Agent`. |
| Skill-based routing | `agent_skills` table. Eligibility filter grows a new condition. | Do not introduce `Skill` entity. Do not change the eligibility algorithm signature. |
| SLA-driven auto-escalation | `TransitionTicketStatusUseCase` would trigger rebalance on SLA breach. New `SlaBreachedEvent`. | Do not couple `AutoAssignTicketUseCase` to SLA. |
| Auth / User entity | New `User` table + JWT subject claim. `MercureJwtService` becomes identity-aware. | Do not introduce `User`. Do not change the existing topic auth (already a known risk). |
| Persisted capacity counter | Add `current_active_tickets INT` to `agents`. Replace `countActiveForAgent()` calls in `AutoAssignService` with `$agent->getCurrentActiveTickets()`. Backfill in a one-shot migration. | Do not add the column. Do not pre-emptively add the read path through it. |

**"10-minute test" verification**:
A new developer reads `Agent.php` (timezone, override), `AgentShift.php` (weekly schedule), `Assignment.php` (audit row), `AgentAvailabilityService.php` (3-step algorithm), `AutoAssignTicketUseCase.php` (pipeline). Total: 5 files, ~500 lines of domain logic. **Passes.** No teams, skills, presence, escalation, SLA, capacity bar in sight.

---

## Testing Strategy

| Layer | What | How |
|---|---|---|
| Unit | `AgentAvailabilityService::isAvailable` — 24 spec scenarios | Pure PHP tests, freeze time with a `ClockInterface` mock, build Agent + Shifts in-memory, assert. |
| Unit | `AgentAvailabilityService::listAvailableAgents` — batched path, capacity filter | Same as above, with 5 agents and varied shifts. |
| Unit | `AutoAssignTicketUseCase` — tiebreakers | Test data: 4 agents, equal load + different ids + different today-counts. |
| Unit | Cross-midnight shift, cross-timezone (UTC vs America/Argentina/Buenos_Aires) | Hardcoded `nowUtc`, assert schedule match/no-match. **4 dedicated tests.** |
| Integration | Full sprint 10 flow: create agent, set shift, post ticket, auto-assign | Real DB, real DI container, run migration first. Assert: ownership, audit row, outbox row, Mercure event topic. |
| Integration | Concurrency: 50 simultaneous auto-assign calls vs 5 agents at max=3 | `pcntl_fork` or `curl_multi` in the test. Assert: no over-allocation, all 50 responses are 200, exactly 15 tickets assigned. |
| Integration | Override TTL expiry at assignment time | Set override with TTL=10s, sleep 11s, attempt assign. Assert: agent excluded. |
| Integration | Rebalance: 5 unassigned, 2 agents in-shift with capacity=1 each | Run rebalance, assert: 2 assigned (oldest first), 3 remain unassigned, 2 audit rows of type AUTO_ASSIGN. |
| Integration | Backfill — N/A (Approach B has no backfill) | — |
| E2E | HTTP-level smoke test: POST shift, POST override, POST ticket, POST auto-assign, GET /reports/agents | `Symfony WebTestCase`. Assert response JSON shapes match the OpenAPI spec. |

Coverage target: ≥90% on the new code (`AgentAvailabilityService`, `AutoAssignTicketUseCase` rewrite, new use cases). 100% on the availability algorithm (24/24 spec scenarios).

---

## Open Questions

1. **Spec change for `current_active_tickets`**: the spec's 11 scenarios reference the counter. This design rejects the counter. If the team agrees, sdd-spec must issue a delta. If not, see "If the team rejects Approach B" note in §Capacity Model — the design is still valid with two extra code paths.
2. **Existing agents without shifts**: post-migration, they become unassignable. Is a "synthetic 24/7 shift" backfill acceptable, or do we want admins to consciously create shifts first? My recommendation: **no synthetic shift** — explicit is better, and the admin can do it as part of the deploy runbook.
3. **Frontend capacity bar (Sprint 10.5)**: do we expose `is_in_shift` and `current_active_tickets` in the inbox response now so the frontend is ready, or wait? My recommendation: **expose them now** — they are zero-cost computed values; the UI just chooses to display them or not.
4. **Override null TTL UX warning**: spec says null = persistent. Exploration §1 H4 flags this as a UX risk. Sprint 10 can ship without the warning; should a `GET /agents/{id}/availability` endpoint surface the override state so the UI can warn? My recommendation: **yes, add it to the spec** as a 5th endpoint (`GET /agents/{id}/availability`) for read-only status — small, useful, no design risk.

---

## Next Step

Ready for `sdd-tasks`. Total scope: 16 new files, 11 modified files, ~1,500 lines of net code (without the spec change in question 1 — that reduces it to ~1,400). PR slicing follows the 5-slice plan from the proposal: (1) entities+migration, (2) service+unit tests, (3) auto-assign+rebalance, (4) endpoints+events, (5) reports+docs. 400-line review budget: each PR is under it. **Chained PRs: Yes. 400-line budget risk: Low.**
