You are a senior Symfony 7.4 developer working on a production-grade system called "Inbiox".

Your task is to implement ONLY Sprint 10 (Advanced Agent Management).

This is a STRICT execution task. Follow all rules exactly.

---

# 🎯 SPRINT 10 GOAL

Implement robust agent management:

- Weekly shifts (by day-of-week)
- Per-agent timezone-aware availability
- Capacity-aware assignment (hard limit)
- Manual availability overrides (with TTL)
- Safe integration with AutoAssignTicketUseCase
- Deterministic behavior under concurrency

---

# 🧠 DESIGN PRINCIPLES (MANDATORY)

1. Availability MUST be deterministic and reproducible
2. Manual overrides MUST take precedence over schedules
3. Assignment MUST respect availability + capacity
4. No duplicated assignment logic (single source of truth)
5. Time evaluation MUST be per-agent timezone, stored UTC
6. All write paths MUST be concurrency-safe (row-level locks)
7. Re-evaluation MUST be explicit (no hidden async)

---

# 🧱 ENTITY DEFINITIONS

----------------------------------------
## Agent (UPDATE)
----------------------------------------

Fields (ensure):

- id (PK)
- active (BOOLEAN, default true)
- timezone (VARCHAR, IANA TZ, NOT NULL)  e.g. "America/Argentina/Buenos_Aires"
- is_available_override (BOOLEAN, nullable)  <-- manual override flag
- override_until (DATETIME_IMMUTABLE, nullable)  <-- TTL for override
- max_active_tickets (INT, NOT NULL)
- current_active_tickets (INT, NOT NULL, cached counter)
- created_at, updated_at

Rules:

- If override_until < now → override expires (treated as NULL)

Index:

- (active, max_active_tickets)
- (current_active_tickets)

---

----------------------------------------
## AgentShift (NEW)
----------------------------------------

Fields:

- id (PK)
- agent_id (FK → Agent.id)
- day_of_week (INT: 1=Mon ... 7=Sun)
- start_time (TIME, local to agent timezone)
- end_time (TIME, local to agent timezone)

Constraints:

- CHECK start_time != end_time
- UNIQUE(agent_id, day_of_week, start_time, end_time)

Notes:

- Shifts MAY overlap (treated as union)
- Shifts MAY cross midnight:
   if end_time < start_time → crosses to next day

Indexes:

- (agent_id, day_of_week)

---

----------------------------------------
## Ticket (UPDATE)
----------------------------------------

Ensure:

- owner_agent_id (nullable)
- status
- updated_at

---

# ⚠️ AVAILABILITY MODEL (FORMAL)

Agent is AVAILABLE at time T (UTC) IF AND ONLY IF:

1. agent.active = true

2. Manual override:
   IF is_available_override IS NOT NULL AND (override_until IS NULL OR override_until >= T):
       → availability = is_available_override
       → STOP evaluation

3. Schedule match:
   Convert T → agent local time (LT)

   Find shifts where:
     - day_of_week matches LT day
     AND
     - time ∈ shift window
       (handle cross-midnight windows)

   IF no matching shift:
       → NOT available

4. Capacity:
   current_active_tickets < max_active_tickets

---

# 🧠 TIMEZONE HANDLING (CRITICAL)

- Store timestamps in UTC
- Convert ONLY for schedule evaluation
- Use PHP DateTimeImmutable with TZ conversion
- NEVER rely on server local timezone

---

# 🔄 AVAILABILITY SERVICE

Create:

## AgentAvailabilityService

Methods:

- isAvailable(agent: Agent, atUtc: DateTimeImmutable): bool
- listAvailableAgents(atUtc: DateTimeImmutable): Agent[]

Implementation:

- Preload shifts per agent (batched)
- Evaluate override first
- Then schedule (with cross-midnight logic)
- Then capacity

---

# 🔄 CAPACITY CONSISTENCY (CRITICAL)

current_active_tickets MUST be accurate.

Rules:

- Increment on assignment
- Decrement when ticket leaves ACTIVE states:
   ("new","active","waiting") → leaving to "resolved" or "closed"

Implementation:

- Always update within SAME transaction as assignment/status change
- Use SELECT ... FOR UPDATE on Agent row

---

# 🔄 AUTO ASSIGNMENT INTEGRATION

Modify:

## AutoAssignTicketUseCase

Flow:

1. Start transaction

2. Get available agents:
   AgentAvailabilityService.listAvailableAgents(now)

3. Lock candidate agents:
   SELECT ... FOR UPDATE

4. Filter again (capacity may have changed)

5. Select agent using strategy:
   - least current_active_tickets
   - tie-breaker: lowest agent_id

6. Assign:
   - ticket.owner_agent_id = agent.id
   - agent.current_active_tickets++

7. Persist + commit

8. Emit realtime events (post-commit)

---

# ⚠️ EDGE CASES

1. Agent without shifts:
   → NEVER available (unless override=true)

2. Overlapping shifts:
   → OK (treated as union)

3. Cross-midnight:
   → handled explicitly

4. No available agents:
   → ticket remains unassigned

---

# 🔄 MANUAL AVAILABILITY CONTROL

----------------------------------------
POST /agents/{id}/availability
----------------------------------------

Request:

{
  "is_available": true,
  "ttl_minutes": 120
}

Rules:

- is_available sets override
- ttl_minutes optional:
   → override_until = now + ttl
- If no ttl:
   → override_until = NULL (persistent override)

---

----------------------------------------
DELETE /agents/{id}/availability
----------------------------------------

- clears override

---

# 🔄 SHIFT MANAGEMENT

----------------------------------------
POST /agents/{id}/shifts
----------------------------------------

{
  "day_of_week": 1,
  "start_time": "08:00",
  "end_time": "16:00"
}

---

----------------------------------------
DELETE /agents/{id}/shifts/{shift_id}
----------------------------------------

---

# 🔄 RE-EVALUATION (IMPORTANT)

Trigger manual re-evaluation:

## RebalanceUnassignedTicketsUseCase

- Find unassigned tickets
- Attempt assignment again

Optional:

## RebalanceOverloadedAgentsUseCase

- Move tickets from overloaded agents to available ones
- MUST respect FSM and audit events

---

# 🔐 VALIDATIONS

- timezone must be valid IANA
- max_active_tickets > 0
- shifts valid time format

---

# 🧪 TEST CASES (MANDATORY)

1. Availability inside shift:
   → true

2. Outside shift:
   → false

3. Override true:
   → always available

4. Override false:
   → always unavailable

5. Override expiration:
   → correctly ignored

6. Cross-midnight shift:
   → works

7. Capacity reached:
   → not assignable

8. Concurrent assignment:
   → no over-allocation

9. Agent without shifts:
   → unavailable

10. Rebalance:
   → assigns correctly

---

# 🧼 CODE QUALITY RULES

- Availability logic centralized
- No duplication
- No business logic in controllers
- All writes transactional

---

# 🚫 STRICT PROHIBITIONS

DO NOT implement:

- cron jobs
- async queues
- ML-based assignment
- external scheduling systems

---

# 📦 OUTPUT REQUIRED

Generate:

1. Agent entity update
2. AgentShift entity
3. AgentAvailabilityService
4. AutoAssignTicketUseCase update
5. Rebalance use cases
6. Controllers
7. Tests

---

# 🧪 FINAL VALIDATION CHECKLIST

- Availability deterministic
- Capacity consistent
- No race conditions
- Timezones correct
- Assignment stable

---

Execute Sprint 10 now.