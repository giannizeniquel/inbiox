# Agent Availability Specification

## Purpose

Deterministic, timezone-aware agent availability evaluation. Agents have weekly shifts (by day-of-week), per-agent timezones, manual overrides with TTL, and capacity tracking. The system answers one question: "Is this agent available for assignment RIGHT NOW?" with a reproducible, auditable answer.

---

## Requirements

### Requirement: Agent Entity Extensions

The Agent entity MUST include timezone and override fields. Load is derived from ticket data — no persisted counter.

| Field | Type | Constraint |
|-------|------|------------|
| timezone | VARCHAR (IANA) | NOT NULL, default 'UTC' |
| is_available_override | BOOLEAN | NULLABLE |
| override_until | DATETIME_IMMUTABLE | NULLABLE |

#### Scenario: Agent created with default timezone

- GIVEN a new agent is created
- WHEN timezone is not specified
- THEN timezone MUST default to 'UTC'

#### Scenario: Agent timezone must be valid IANA

- GIVEN agent creation or update
- WHEN timezone is 'Invalid/Timezone'
- THEN the system MUST reject with validation error

---

### Requirement: Agent Shift Management

The system MUST support weekly shifts by day-of-week (1=Mon...7=Sun) with cross-midnight support.

**Cross-midnight rule**: If `end_time < start_time`, the shift crosses midnight and extends into the next day.

#### Scenario: Create shift for Monday 08:00-16:00

- GIVEN agent with timezone 'America/Argentina/Buenos_Aires'
- WHEN POST /agents/{id}/shifts with `{day_of_week: 1, start_time: "08:00", end_time: "16:00"}`
- THEN shift is created and stored

#### Scenario: Create cross-midnight shift

- GIVEN agent with timezone 'America/Argentina/Buenos_Aires'
- WHEN POST /agents/{id}/shifts with `{day_of_week: 5, start_time: "22:00", end_time: "06:00"}`
- THEN shift is created with cross-midnight flag (end_time < start_time)

#### Scenario: Multiple shifts on same day (split shifts)

- GIVEN agent already has shift Monday 08:00-12:00
- WHEN POST /agents/{id}/shifts with `{day_of_week: 1, start_time: "14:00", end_time: "18:00"}`
- THEN both shifts coexist (UNIQUE constraint on agent_id, day_of_week, start_time, end_time)
- AND availability evaluation treats them as union

#### Scenario: Delete shift

- GIVEN agent has shift with id=5
- WHEN DELETE /agents/{id}/shifts/5
- THEN shift is removed

#### Scenario: Shift validation

- GIVEN shift creation request
- WHEN day_of_week < 1 OR day_of_week > 7 OR start_time = end_time
- THEN the system MUST reject with validation error

---

### Requirement: Availability Evaluation Algorithm

The system MUST evaluate availability using a three-step cascade: **override → schedule → capacity**.

**Algorithm** (evaluated in order, short-circuit on first match):

1. **Account active check**: If `agent.active = false` → NOT available (STOP)
2. **Override check**: If `is_available_override IS NOT NULL` AND (`override_until IS NULL` OR `override_until >= now_utc`) → return `is_available_override` (STOP)
3. **Schedule check**: Convert `now_utc` to agent local time. Find shifts where `day_of_week` matches local day AND local time falls within shift window (handle cross-midnight). If no match → NOT available (STOP)
4. **Capacity check**: Compute live load = `COUNT(*) FROM tickets WHERE owner_agent_id = agent.id AND status IN ('new', 'active', 'waiting')`. If `live_load >= max_active_tickets` → NOT available (STOP)
5. **Default**: Available = true

#### Scenario: Agent in shift with capacity

- GIVEN agent with shift Monday 08:00-16:00, timezone 'America/Argentina/Buenos_Aires'
- AND 3 active tickets assigned (status IN 'new', 'active', 'waiting'), max_active_tickets = 5
- WHEN evaluated at Monday 10:00 local time
- THEN available = true

#### Scenario: Agent outside shift hours

- GIVEN agent with shift Monday 08:00-16:00
- WHEN evaluated at Monday 18:00 local time
- THEN available = false (no schedule match)

#### Scenario: Agent inactive always unavailable

- GIVEN agent with active = false
- WHEN evaluated (regardless of shift, override, capacity)
- THEN available = false

#### Scenario: Override true with TTL active

- GIVEN agent with is_available_override = true, override_until = now + 120min
- WHEN evaluated at now + 60min (inside shift or not)
- THEN available = true (override takes precedence)

#### Scenario: Override false blocks assignment

- GIVEN agent with is_available_override = false, override_until = NULL (persistent)
- WHEN evaluated (even if in shift with capacity)
- THEN available = false

#### Scenario: Override TTL expiration

- GIVEN agent with is_available_override = true, override_until = 2026-06-14T12:00:00Z
- WHEN evaluated at 2026-06-14T12:01:00Z (TTL expired)
- THEN override is ignored, schedule evaluation proceeds
- AND if no shift matches → available = false

#### Scenario: Override null means persistent

- GIVEN agent with is_available_override = false, override_until = NULL
- WHEN evaluated at any time
- THEN override is active (no expiration)
- AND available = false until override is cleared via DELETE /agents/{id}/availability

#### Scenario: Cross-midnight shift evaluation

- GIVEN agent with timezone 'America/Argentina/Buenos_Aires', shift Friday 22:00-06:00
- WHEN evaluated at Saturday 02:30 local time
- THEN available = true (shift crosses midnight, 02:30 is within 22:00-06:00 window)

#### Scenario: Cross-midnight shift outside window

- GIVEN agent with shift Friday 22:00-06:00
- WHEN evaluated at Saturday 09:00 local time
- THEN available = false (09:00 is after 06:00 end)

#### Scenario: Multiple shifts same day (union)

- GIVEN agent with shifts Monday 08:00-12:00 AND Monday 14:00-18:00
- WHEN evaluated at Monday 10:00 local time
- THEN available = true (within first shift)

- WHEN evaluated at Monday 13:00 local time
- THEN available = false (gap between shifts)

- WHEN evaluated at Monday 15:00 local time
- THEN available = true (within second shift)

#### Scenario: Capacity reached

- GIVEN agent in shift, 5 active tickets (equals max_active_tickets = 5)
- WHEN evaluated
- THEN available = false (capacity check fails)

#### Scenario: Agent without shifts

- GIVEN agent with no shifts defined
- WHEN evaluated (no override active)
- THEN available = false (no schedule match)

---

### Requirement: Capacity Tracking (Derived Load)

The system MUST derive agent load live via COUNT query — no persisted counter column.

**Capacity definition**: `live_load = COUNT(*) FROM tickets WHERE owner_agent_id = agent.id AND status IN ('new', 'active', 'waiting')`

**Load calculation rules**:
- Load is computed on demand, never stored on the Agent entity
- The COUNT query uses index `idx_tickets_owner_status (assigned_agent_id, status)` for sub-millisecond execution
- No increment/decrement operations — the count changes implicitly when tickets are assigned, resolved, or closed
- Capacity checks inside transactions MUST execute the COUNT within the `FOR UPDATE` lock region to prevent race conditions

#### Scenario: Active ticket count changes on assignment

- GIVEN agent with 3 active tickets assigned
- WHEN ticket is assigned to agent (AutoAssignTicketUseCase or AssignTicketUseCase)
- THEN COUNT(*) for that agent returns 4 (within same transaction)

#### Scenario: Active ticket count decreases on resolution

- GIVEN agent with 5 active tickets, ticket with status 'active'
- WHEN ticket transitions to 'resolved'
- THEN COUNT(*) for that agent returns 4 (within same transaction)

#### Scenario: Active ticket count decreases on closure

- GIVEN agent with 5 active tickets, ticket with status 'active'
- WHEN ticket transitions to 'closed'
- THEN COUNT(*) for that agent returns 4 (within same transaction)

#### Scenario: Capacity check under concurrency

- GIVEN agent with 4 active tickets, max_active_tickets = 5
- WHEN two concurrent assignment requests arrive
- THEN first request acquires FOR UPDATE lock, COUNT returns 4, assigns ticket, commits, releases lock
- AND second request acquires lock, COUNT returns 5, rejects assignment (capacity full)
- AND final COUNT = 5 (no over-allocation)

---

### Requirement: Manual Override Endpoints

The system MUST expose endpoints for agents to set/clear availability overrides with optional TTL.

#### Scenario: Set override with TTL

- GIVEN agent with id=5
- WHEN POST /agents/5/availability with `{is_available: false, ttl_minutes: 120}`
- THEN is_available_override = false, override_until = now + 120min
- AND Mercure event `agent.5.availability_changed` is emitted

#### Scenario: Set persistent override (no TTL)

- GIVEN agent with id=5
- WHEN POST /agents/5/availability with `{is_available: false}` (ttl_minutes omitted)
- THEN is_available_override = false, override_until = NULL (persistent)
- AND override remains active until explicitly cleared

#### Scenario: Clear override

- GIVEN agent with active override
- WHEN DELETE /agents/5/availability
- THEN is_available_override = NULL, override_until = NULL
- AND schedule evaluation resumes
- AND Mercure event `agent.5.availability_changed` is emitted

#### Scenario: Read agent availability state

- GIVEN agent with id=5, in shift, 3 active tickets, max_active_tickets=5, no override
- WHEN GET /agents/5/availability
- THEN response returns:
  ```json
  {
    "agentId": 5,
    "isAvailable": true,
    "isInShift": true,
    "timezone": "America/Argentina/Buenos_Aires",
    "currentLoad": 3,
    "maxCapacity": 5,
    "override": { "active": false, "reason": null, "expiresAt": null }
  }
  ```
- AND endpoint is read-only, reuses AgentAvailabilityService

---

### Requirement: Shift Management Endpoints

The system MUST expose REST endpoints for shift CRUD.

#### Scenario: Create shift

- GIVEN agent with id=5
- WHEN POST /agents/5/shifts with `{day_of_week: 1, start_time: "08:00", end_time: "16:00"}`
- THEN AgentShift is created
- AND Mercure event `agent.5.shift_changed` is emitted

#### Scenario: Delete shift

- GIVEN agent with shift id=10
- WHEN DELETE /agents/5/shifts/10
- THEN shift is removed
- AND Mercure event `agent.5.shift_changed` is emitted

---

### Requirement: Reports Integration (Leaderboard MVP)

The system MUST provide real agent names and real load metrics in reports.

**Leaderboard columns**:
- Agent name (from agents.name, not "Agent {id}")
- Tickets assigned (COUNT where owner_agent_id = agent.id)
- Tickets resolved (COUNT where owner_agent_id = agent.id AND status IN ('resolved', 'closed'))
- Current load (derived: `COUNT(*) WHERE owner_agent_id = agent.id AND status IN ('new', 'active', 'waiting')` / max_active_tickets)

#### Scenario: Leaderboard shows real names

- GIVEN agent with name="María"
- WHEN GET /reports/agents is called
- THEN response includes `{agent_name: "María", ...}` (not "Agent 5")

#### Scenario: Leaderboard shows real load

- GIVEN agent with 3 active tickets (derived count), max_active_tickets = 5
- WHEN GET /reports/agents is called
- THEN response includes `{current_active_tickets: 3, max_active_tickets: 5, current_load: 0.6}`
- AND current_active_tickets is computed via COUNT(*), not read from a column

---

### Requirement: Inbox Integration (Read-Only Exposure)

The inbox response MUST expose agent availability fields for frontend consumption (display optional).

**Inbox agent fields**:
- `is_in_shift` (boolean): whether agent is currently in shift (derived from AgentAvailabilityService)
- `current_load` (integer): derived active ticket count (COUNT(*))
- `max_capacity` (integer): agent's max_active_tickets

#### Scenario: Inbox exposes agent availability fields

- GIVEN agent with id=5, in shift, 3 active tickets, max_active_tickets=5
- WHEN inbox response includes agent data
- THEN response includes `{is_in_shift: true, current_load: 3, max_capacity: 5}`
- AND fields are computed, not persisted

---

### Requirement: Migration and Backfill

The system MUST migrate existing data without data loss. No backfill is needed (derived count approach).

**Migration steps**:
1. ALTER `agents` table: add timezone (default 'UTC'), is_available_override, override_until
2. CREATE `agent_shifts` table with indexes
3. CREATE indexes: `idx_agents_active`, `idx_tickets_owner_status`

**No backfill required**: the whole point of the derived count approach is that `current_active_tickets` does not exist as a column. Load is computed live from the tickets table.

**Existing data implications**:
- All existing agents get `timezone = 'UTC'` and `NULL` override
- Post-migration, agents without shifts become unassignable until a shift is created or override is set
- Admins MUST create shifts as part of the deploy runbook

#### Scenario: Migration on existing dataset

- GIVEN 100 agents, 5000 tickets (1000 active, 4000 resolved/closed)
- WHEN migration runs
- THEN agents table has timezone, override columns (no current_active_tickets column)
- AND agent_shifts table is created
- AND indexes are created
- AND existing tickets remain unchanged

#### Scenario: No backfill on large dataset

- GIVEN 10,000 tickets
- WHEN migration runs
- THEN no backfill query executes (derived count approach eliminates this step)
- AND migration completes without locking the tickets table

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 9 |
| Total Scenarios | 34 |

## Coverage

- **Happy paths**: ✅ Shift CRUD, availability evaluation, override with/without TTL, derived capacity tracking, reports, inbox exposure, read availability endpoint
- **Edge cases**: ✅ Cross-midnight shifts, multiple shifts same day, override expiration, agent without shifts, concurrent assignment with derived COUNT
- **Error states**: ✅ Invalid timezone, invalid shift times, capacity exceeded, concurrent over-allocation prevented by FOR UPDATE + COUNT

## Next Steps

Ready for design (sdd-design). If design already exists, ready for tasks (sdd-tasks).
