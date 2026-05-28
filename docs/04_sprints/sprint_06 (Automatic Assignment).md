You are a senior Symfony 7.4 developer working on a production-grade system called "Inbiox".

Your task is to implement ONLY Sprint 6 (Automatic Assignment).

This is a STRICT execution task. Follow all rules exactly.

---

# 🎯 SPRINT 6 GOAL

Automatically assign a Ticket to an Agent at creation time, ensuring:

- Fair load distribution (least-loaded)
- Respect for agent capacity
- Strong consistency under concurrency
- Deterministic selection
- Zero duplication of assignment logic

---

# 🧠 DOMAIN EXTENSION (MANDATORY)

## Agent (UPDATE)

Fields:

- max_active_tickets (INT, NOT NULL DEFAULT 10)
- is_available (BOOLEAN, NOT NULL DEFAULT true)

Eligibility:

Agent is ELIGIBLE IF:
- active = true
- is_available = true

Capacity rule:
- current_active_tickets < max_active_tickets

---

# ⚠️ CRITICAL BUSINESS RULES (NON-NEGOTIABLE)

1. Auto-assignment triggers ONLY when:
   → a new Ticket is created

2. NEVER override existing assignment:
   → IF ticket.owner_agent_id IS NOT NULL → EXIT

3. Only assign if:
   → owner_agent_id IS NULL

4. Eligible agents must satisfy:
   - active = true
   - is_available = true
   - capacity not exceeded

5. If NO eligible agents:
   → Ticket remains unassigned

6. Assignment MUST be:
   - atomic
   - concurrency-safe
   - deterministic

7. Assignment MUST reuse AssignTicketUseCase

---

# 🔧 ASSIGNMENT STRATEGY (STRICT)

Strategy: **Least Loaded**

Definition:

- Compute active_ticket_count per agent
- Select agent with MIN(active_ticket_count)

Tie-breaker:
- lowest agent.id

---

# 🗄️ ACTIVE TICKET DEFINITION

Active statuses:

- "new"
- "active"
- "waiting"

---

# ⚠️ CONCURRENCY MODEL (CRITICAL)

You MUST ensure no agent exceeds capacity under concurrent assignments.

Use THIS strategy:

1. Lock Ticket row:
   SELECT ticket FOR UPDATE

2. Fetch candidate agents WITH LOCK:
   SELECT * FROM agent
   WHERE active = 1 AND is_available = 1
   FOR UPDATE

3. Compute load INSIDE transaction using fresh counts

4. Select agent

5. Assign using AssignTicketUseCase (same transaction)

---

# ⚠️ IMPORTANT CONSTRAINT

DO NOT:

- precompute counts outside transaction
- cache agent load
- use stale data

---

# 🔄 USE CASE

## AutoAssignTicketUseCase

Input:
- ticket_id (int)

Execution steps (STRICT ORDER):

1. Start transaction

2. Retrieve Ticket FOR UPDATE

3. IF ticket.owner_agent_id IS NOT NULL:
   → Commit and EXIT (idempotent behavior)

4. Fetch eligible agents FOR UPDATE

5. IF no agents:
   → Commit and EXIT

6. For each agent:
   compute active_ticket_count:

   SELECT COUNT(*)
   FROM ticket
   WHERE owner_agent_id = agent.id
   AND status IN ("new","active","waiting")

7. Filter agents:
   WHERE active_ticket_count < max_active_tickets

8. IF no agents after capacity filter:
   → Commit and EXIT

9. Select agent:
   ORDER BY active_ticket_count ASC, agent.id ASC
   LIMIT 1

10. Call AssignTicketUseCase:
   (MUST NOT start a new transaction)
   → reuse current transaction

11. Commit

12. Return:

{
  "ticket_id": X,
  "assigned": true|false,
  "agent_id": Y|null
}

---

# 🔄 INTEGRATION (MANDATORY)

Modify:

## HandleInboundMessageUseCase

After Ticket creation:

IF ticket.owner_agent_id IS NULL:
   → call AutoAssignTicketUseCase

MUST be in SAME request flow.

---

# ⚠️ IDEMPOTENCY (MANDATORY)

Auto assignment MUST be safe if executed twice:

- Ticket lock guarantees consistency
- owner_agent_id check prevents duplication

---

# 🌐 OPTIONAL ENDPOINT (DEBUG / TEST)

POST /tickets/{id}/auto-assign

Response:

{
  "ticket_id": 10,
  "assigned": true,
  "agent_id": 3
}

---

# 📊 PERFORMANCE NOTES (IMPORTANT)

- Avoid N+1 when counting tickets
- You MAY:
   - use grouped query (COUNT GROUP BY owner_agent_id)
   - map results in memory

Example:

SELECT owner_agent_id, COUNT(*) 
FROM ticket
WHERE status IN (...)
GROUP BY owner_agent_id

---

# 🧪 TEST CASES (MANDATORY)

1. Single agent:
   → always assigned

2. Multiple agents:
   → balanced distribution

3. Tie:
   → lowest agent.id

4. Agent at capacity:
   → skipped

5. No available agents:
   → unassigned

6. Concurrent ticket creation:
   → no agent exceeds max_active_tickets

7. Already assigned ticket:
   → skipped (idempotent)

---

# 🧼 CODE QUALITY RULES

- NO duplication of assignment logic
- AssignTicketUseCase is the ONLY writer
- Use transactions properly
- No logic in controllers
- Keep strategy isolated (service class)

---

# 🚫 STRICT PROHIBITIONS

DO NOT implement:

- Queues / async workers
- Skills-based routing
- AI routing
- Time-based routing
- Agent shifts

---

# 📦 OUTPUT REQUIRED

Generate:

1. Agent entity update
2. Migration
3. AutoAssignTicketUseCase
4. Integration in HandleInboundMessageUseCase
5. Optional controller
6. Tests

---

# 🧪 FINAL VALIDATION CHECKLIST

- No agent exceeds max_active_tickets
- Assignment is balanced
- Concurrency safe
- Deterministic results
- No duplicate assignments
- Works end-to-end

---

Execute Sprint 6 now.