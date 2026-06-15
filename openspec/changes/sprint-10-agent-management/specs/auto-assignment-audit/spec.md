# Auto-Assignment & Audit Specification

## Purpose

Deterministic auto-assignment with full audit trail. Every ticket ownership change — whether by system, supervisor, or removal — creates an Assignment record. The auto-assignment algorithm selects the least-loaded available agent with deterministic tiebreakers, protected by row-level locks against concurrent over-allocation.

---

## Requirements

### Requirement: Auto-Assignment Algorithm

The system MUST assign tickets to the least-loaded eligible agent using deterministic selection.

**Eligibility criteria** (ALL must be true):
1. `agent.active = true`
2. Agent is in-shift OR has active non-expired override = true (evaluated via `AgentAvailabilityService`)
3. No active override = false (override blocks schedule-based availability)
4. Agent has capacity: live active ticket count < max_active_tickets (derived via COUNT(*))

**Tiebreaker order** (applied sequentially until a single agent remains):
1. Lowest active ticket count (derived via COUNT(*))
2. Fewest tickets assigned today (COUNT from Assignment table WHERE agent_id = X AND type = 'AUTO_ASSIGN' AND created_at >= today_start)
3. Lowest `agent.id`

**Guarantee**: Given the same set of eligible agents and their state, the algorithm MUST always select the same agent.

#### Scenario: Single eligible agent

- GIVEN one agent with active=true, in-shift, 2 active tickets (derived count), max=5
- WHEN auto-assignment runs for a new ticket
- THEN that agent is selected
- AND ticket.owner_agent_id = agent.id
- AND active ticket count for agent becomes 3 (COUNT returns 3)

#### Scenario: Multiple agents — lowest load wins

- GIVEN Agent A (2 active tickets, max=5) and Agent B (4 active tickets, max=5), both eligible
- WHEN auto-assignment runs
- THEN Agent A is selected (lower active ticket count)

#### Scenario: Tiebreaker 1 — equal load, fewest assigned today wins

- GIVEN Agent A (3 active tickets, assigned_today=2) and Agent B (3 active tickets, assigned_today=5), both eligible
- WHEN auto-assignment runs
- THEN Agent A is selected (fewer assignments today)

#### Scenario: Tiebreaker 2 — equal load and equal today, lowest ID wins

- GIVEN Agent A (id=3, 3 active tickets, assigned_today=2) and Agent B (id=7, 3 active tickets, assigned_today=2)
- WHEN auto-assignment runs
- THEN Agent A is selected (lower agent.id)

#### Scenario: No eligible agents

- GIVEN all agents at max capacity OR no agents in shift
- WHEN auto-assignment runs
- THEN ticket remains unassigned (owner_agent_id = NULL)
- AND no Assignment row is created

#### Scenario: Capacity-full agent is skipped

- GIVEN Agent A (5 active tickets, max=5) and Agent B (3 active tickets, max=5)
- WHEN auto-assignment runs
- THEN Agent B is selected (Agent A fails capacity check)

#### Scenario: Override false agent is ineligible

- GIVEN Agent A with is_available_override=false (active override), in-shift
- WHEN auto-assignment runs
- THEN Agent A is NOT eligible (override blocks)

---

### Requirement: Assignment Audit Trail

The system MUST create an Assignment row for EVERY ticket ownership change. No assignment path may bypass the audit.

**Assignment types**:

| Type | Trigger |
|------|---------|
| `AUTO_ASSIGN` | System assigns via AutoAssignTicketUseCase |
| `MANUAL_ASSIGN` | Supervisor assigns via AssignTicketUseCase (ticket had no owner) |
| `REASSIGN` | Transfer from one agent to another (ticket already had owner) |
| `UNASSIGN` | Ticket owner removed (owner_agent_id set to NULL) |

**Assignment record fields**: ticket_id, agent_id (nullable for UNASSIGN), previous_agent_id (nullable, for REASSIGN/UNASSIGN), type, assigned_by (identifier of actor or 'system'), created_at.

#### Scenario: Auto-assignment creates audit row

- GIVEN auto-assignment selects Agent 5 for Ticket 100
- WHEN assignment completes
- THEN Assignment row is created: `{ticket_id: 100, agent_id: 5, type: 'AUTO_ASSIGN', assigned_by: 'system'}`
- AND row is persisted within the same transaction as the ticket update

#### Scenario: Manual assignment creates audit row

- GIVEN Ticket 100 is unassigned
- WHEN supervisor assigns to Agent 5 via POST /tickets/100/assign
- THEN Assignment row: `{ticket_id: 100, agent_id: 5, type: 'MANUAL_ASSIGN', assigned_by: supervisor_id}`

#### Scenario: Reassignment creates audit row with previous agent

- GIVEN Ticket 100 owned by Agent 5
- WHEN supervisor reassigns to Agent 8
- THEN Assignment row: `{ticket_id: 100, agent_id: 8, previous_agent_id: 5, type: 'REASSIGN', assigned_by: supervisor_id}`
- AND Agent 5 active ticket count decreases by 1 (COUNT returns N-1)
- AND Agent 8 active ticket count increases by 1 (COUNT returns N+1)

#### Scenario: Unassignment creates audit row

- GIVEN Ticket 100 owned by Agent 5
- WHEN ticket owner is removed
- THEN Assignment row: `{ticket_id: 100, agent_id: NULL, previous_agent_id: 5, type: 'UNASSIGN', assigned_by: actor_id}`
- AND Agent 5 active ticket count decreases by 1 (COUNT returns N-1)

#### Scenario: Every path generates audit row

- GIVEN any code path that modifies ticket.owner_agent_id
- WHEN the modification occurs
- THEN exactly one Assignment row MUST be created in the same transaction
- AND no path may update owner_agent_id without creating an Assignment row

---

### Requirement: Referential Integrity

The system MUST enforce that every `Assignment.agent_id` references a valid, existing `Agent.id`.

**Constraint**: `Assignment.agent_id` SHALL be `BIGINT NOT NULL` with a foreign key `FOREIGN KEY (agent_id) REFERENCES agents(id)`.

#### Scenario: Assignment agent_id must reference existing agent

- GIVEN an agent with id=5 exists
- WHEN an Assignment row is created with agent_id=5
- THEN the database accepts the row
- AND the assignment is valid

#### Scenario: Assignment agent_id cannot reference non-existent agent

- GIVEN no agent with id=99 exists
- WHEN an attempt is made to insert an Assignment row with agent_id=99
- THEN the database SHALL reject the row with a foreign key constraint violation
- AND the transaction SHALL roll back

#### Scenario: Three representations, one type

- GIVEN `agents.id` is `BIGINT`
- AND `tickets.assigned_agent_id` is `BIGINT`
- AND `assignments.agent_id` is `BIGINT`
- THEN all three representations of agent identity share the same column type
- AND referential integrity is enforceable via foreign keys

---

### Requirement: Concurrency Safety

The system MUST prevent over-allocation under concurrent assignment requests using row-level locking and derived COUNT.

**Pattern**: `SELECT ... FOR UPDATE` on Agent row within a transaction. The active ticket count is derived via `COUNT(*)` inside the lock region, ensuring serialized reads. No persisted counter to update.

#### Scenario: Two concurrent assignments, one slot remaining

- GIVEN Agent A (4 active tickets, max=5), two tickets pending auto-assignment
- WHEN two assignment requests execute concurrently
- THEN Request 1 acquires FOR UPDATE lock on Agent A row
- AND Request 1 runs COUNT(*) = 4, assigns ticket (COUNT now returns 5), commits, releases lock
- AND Request 2 acquires lock, runs COUNT(*) = 5, sees capacity full, does NOT assign
- AND final state: exactly one ticket assigned, COUNT(*) = 5

#### Scenario: Lock re-validation after acquisition

- GIVEN AgentAvailabilityService returns Agent A as available (pre-lock)
- WHEN FOR UPDATE lock is acquired on Agent A row
- THEN the system MUST re-check `COUNT(*) < max_active_tickets` inside the lock (value may have changed between pre-lock read and lock acquisition)
- AND if capacity is now full, skip this agent

#### Scenario: Transaction boundaries

- GIVEN an auto-assignment in progress
- WHEN any step fails (lock timeout, capacity changed, DB error)
- THEN the entire transaction rolls back (ticket update + Assignment row)
- AND no partial state is persisted
- AND no counter update is needed (count is derived)

---

### Requirement: Rebalance Unassigned Tickets

The system MUST provide a use case to retry assignment of unassigned tickets.

**RebalanceUnassignedTicketsUseCase**:
1. Query all tickets WHERE `owner_agent_id IS NULL` AND `status IN ('new', 'active')`
2. For each ticket (ordered by created_at ASC — oldest first):
   - Run auto-assignment flow
   - If no eligible agent found → ticket remains unassigned (skip, continue to next)
3. Return count of tickets assigned during rebalance

#### Scenario: Rebalance assigns pending tickets

- GIVEN 3 unassigned tickets, 2 agents become available (were outside shift, now in shift)
- WHEN RebalanceUnassignedTicketsUseCase executes
- THEN tickets are assigned to available agents using standard auto-assignment algorithm
- AND each assignment creates an Assignment row with type=AUTO_ASSIGN

#### Scenario: Rebalance with no available agents

- GIVEN 5 unassigned tickets, no agents available (all at capacity or outside shift)
- WHEN RebalanceUnassignedTicketsUseCase executes
- THEN 0 tickets are assigned
- AND all tickets remain unassigned
- AND use case returns count=0

#### Scenario: Rebalance partial assignment

- GIVEN 5 unassigned tickets, 2 agents each with capacity for 1 ticket
- WHEN RebalanceUnassignedTicketsUseCase executes
- THEN 2 tickets are assigned (oldest first)
- AND 3 tickets remain unassigned

---

### Requirement: Mercure Event Contracts

The system MUST emit the following events via Mercure when assignment state changes. Events MUST be emitted AFTER the database transaction commits (post-commit, via outbox pattern).

#### Scenario: ticket.assigned event

- GIVEN a ticket is assigned to an agent (any type: AUTO_ASSIGN, MANUAL_ASSIGN, REASSIGN)
- WHEN the transaction commits
- THEN Mercure event is published to topic `ticket.{ticket_id}.assigned`
- AND payload:
  ```json
  {
    "ticket_id": 100,
    "agent_id": 5,
    "assignment_type": "AUTO_ASSIGN",
    "timestamp": "2026-06-14T15:30:00Z"
  }
  ```

#### Scenario: ticket.unassigned event

- GIVEN a ticket's owner is removed
- WHEN the transaction commits
- THEN Mercure event is published to topic `ticket.{ticket_id}.assigned`
- AND payload:
  ```json
  {
    "ticket_id": 100,
    "agent_id": null,
    "assignment_type": "UNASSIGN",
    "timestamp": "2026-06-14T15:30:00Z"
  }
  ```

#### Scenario: Rebalance emits events per assignment

- GIVEN RebalanceUnassignedTicketsUseCase assigns 3 tickets
- WHEN rebalance completes
- THEN 3 separate `ticket.{id}.assigned` events are emitted (one per ticket)
- AND each event has assignment_type = "AUTO_ASSIGN"

---

### Requirement: Auto-Assignment Use Case Integration

The AutoAssignTicketUseCase MUST be rewritten to use AgentAvailabilityService as the single source of truth for eligibility.

**Flow**:
1. Begin transaction
2. Call `AgentAvailabilityService.listAvailableAgents(now_utc)` → candidate list
3. `SELECT ... FOR UPDATE` on candidate agent rows
4. Re-filter: remove agents where `COUNT(*) >= max_active_tickets` (post-lock revalidation via derived count)
5. Select agent using tiebreaker algorithm
6. Update `ticket.owner_agent_id = agent.id`
7. Ticket assignment changes the active count implicitly (ticket now has owner_agent_id with active status — COUNT returns N+1 on next read)
8. Create Assignment row (type=AUTO_ASSIGN)
9. Commit transaction
10. Emit `ticket.{id}.assigned` Mercure event (post-commit)

#### Scenario: Full auto-assignment flow

- GIVEN a new unassigned ticket, 3 eligible agents
- WHEN AutoAssignTicketUseCase executes
- THEN agent is selected via tiebreaker algorithm
- AND ticket.owner_agent_id is set
- AND active ticket count for agent increases by 1 (derived from COUNT(*))
- AND Assignment row is created
- AND Mercure event is emitted (post-commit)

#### Scenario: Auto-assignment replaces old hardcoded check

- GIVEN the existing AutoAssignTicketUseCase currently filters by `active AND is_available`
- WHEN the rewrite is deployed
- THEN the old hardcoded filter is REMOVED
- AND AgentAvailabilityService.isAvailable() is the SOLE eligibility check
- AND no duplicate eligibility logic exists in the codebase

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 7 |
| Total Scenarios | 26 |

## Coverage

- **Happy paths**: ✅ Single/multiple agent selection, all tiebreaker levels, all assignment types, rebalance
- **Edge cases**: ✅ No eligible agents, concurrent over-allocation, lock re-validation, partial rebalance, override blocks eligibility
- **Error states**: ✅ Transaction rollback on failure, capacity full skip, lock timeout handling

## Next Steps

Ready for design (sdd-design). If design already exists, ready for tasks (sdd-tasks).
