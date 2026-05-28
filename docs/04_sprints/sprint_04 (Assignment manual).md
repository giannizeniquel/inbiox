You are a senior Symfony 7.4 developer working on a production-grade system called "Inbiox".

Your task is to implement ONLY Sprint 4 (Manual Assignment).

This is a STRICT execution task. Follow all rules exactly.

---

# 🎯 SPRINT 4 GOAL

Enable safe, auditable, concurrent manual assignment of Tickets to Agents.

The system MUST support:
- assigning an unassigned ticket
- reassigning an assigned ticket
- guaranteeing ONLY ONE owner per ticket at any time
- correct timestamping
- minimal audit trail
- idempotent assignment endpoint

---

# 🧠 DOMAIN DEFINITIONS (STRICT)

## Agent
Human operator that can own tickets.

## Ownership
- A Ticket has ZERO or ONE owner (owner_agent_id)
- Ownership changes over time via assignment operations

---

# ⚠️ CRITICAL BUSINESS RULES (NON-NEGOTIABLE)

1. A Ticket can have ONLY ONE owner_agent_id at any time
2. Assigning a Ticket:
   - If no owner → set owner_agent_id and assigned_at = now (UTC)
3. Reassigning a Ticket:
   - Update owner_agent_id
   - DO NOT modify assigned_at (first assignment timestamp is immutable)
4. Cannot assign Tickets with status IN ("resolved","closed")
5. Agent MUST exist and be active
6. Operation MUST be atomic and safe under concurrency
7. Endpoint MUST be idempotent (same ticket_id + agent_id repeated → no duplicate side-effects)
8. Every assignment MUST be recorded in TicketEvent

---

# 🔧 TECHNICAL REQUIREMENTS

- Symfony 7.4
- Doctrine ORM
- MySQL 8+
- DateTimeImmutable (UTC)
- Transactions REQUIRED
- Pessimistic locking REQUIRED (SELECT ... FOR UPDATE)

---

# 📁 PROJECT STRUCTURE (MANDATORY)

src/
  Domain/
    Agent/
    Ticket/
  Application/
    UseCase/
  Infrastructure/
    Persistence/
    Http/

---

# 🧱 ENTITY DEFINITIONS

## Agent

Fields:

- id (BIGINT, PK)
- name (VARCHAR, NOT NULL)
- active (BOOLEAN, NOT NULL DEFAULT true)
- created_at (DATETIME_IMMUTABLE, NOT NULL)

Indexes:
- INDEX(active)

---

## Ticket (UPDATE)

Ensure fields exist:

- owner_agent_id (BIGINT, FK → Agent.id, NULLABLE)
- assigned_at (DATETIME_IMMUTABLE, NULLABLE)

Indexes:
- INDEX(owner_agent_id)

Constraints:
- FK(ticket.owner_agent_id → agent.id)

---

## TicketEvent (MINIMAL AUDIT)

Fields:

- id (BIGINT, PK)
- ticket_id (BIGINT, FK → Ticket.id, NOT NULL)
- type (ENUM: "assigned","reassigned")
- agent_id (BIGINT, FK → Agent.id, NOT NULL)  // new owner
- previous_agent_id (BIGINT, NULLABLE)        // previous owner (if any)
- created_at (DATETIME_IMMUTABLE, NOT NULL)

Indexes:
- INDEX(ticket_id)
- INDEX(agent_id)

---

# 🗄️ DATABASE RULES

- Use FK constraints for referential integrity
- No need for unique constraints beyond PKs
- All writes wrapped in transactions

---

# 🔄 REPOSITORY CONTRACTS (MANDATORY)

## TicketRepository

- findByIdForUpdate(ticket_id): Ticket
  → MUST lock row using SELECT ... FOR UPDATE

## AgentRepository

- findActiveById(agent_id): Agent | null

---

# 🔄 USE CASE

## AssignTicketUseCase

Input:

- ticket_id (int)
- agent_id (int)

Execution steps (STRICT ORDER):

1. Start transaction

2. Retrieve Ticket using findByIdForUpdate(ticket_id)
   - If not found → throw NotFound

3. Validate Ticket status:
   - If status IN ("resolved","closed") → throw DomainException

4. Retrieve Agent using findActiveById(agent_id)
   - If not found → throw DomainException

5. Idempotency check:
   - IF ticket.owner_agent_id == agent_id:
       → RETURN success (no changes, no event)

6. Store previous_owner = ticket.owner_agent_id

7. IF previous_owner IS NULL:
   - ticket.owner_agent_id = agent_id
   - ticket.assigned_at = now (UTC)
   - create TicketEvent:
       type = "assigned"
       agent_id = agent_id
       previous_agent_id = NULL

8. ELSE:
   - ticket.owner_agent_id = agent_id
   - DO NOT change assigned_at
   - create TicketEvent:
       type = "reassigned"
       agent_id = agent_id
       previous_agent_id = previous_owner

9. Update ticket.updated_at = now

10. Persist changes

11. Commit transaction

12. Return:
{
  "ticket_id": ...,
  "agent_id": ...,
  "status": "assigned"
}

---

# ⚠️ CONCURRENCY HANDLING (MANDATORY)

- Use SELECT ... FOR UPDATE on Ticket row
- Last-write-wins strategy (safe overwrite under lock)
- Ensure no partial updates occur

---

# 🌐 CONTROLLER

POST /tickets/{id}/assign

Request:

{
  "agent_id": 3
}

Response:

{
  "ticket_id": 10,
  "agent_id": 3,
  "status": "assigned"
}

HTTP Codes:

- 200 OK
- 400 Bad Request (validation/domain errors)
- 404 Not Found

---

# ✅ VALIDATION

- ticket_id: required (path)
- agent_id: required (body)
- agent must be active
- ticket must be assignable

---

# 🧪 TEST CASES (MANDATORY)

1. Assign unassigned ticket:
   → owner_agent_id set
   → assigned_at set
   → event type "assigned"

2. Reassign ticket:
   → owner updated
   → assigned_at unchanged
   → event type "reassigned"
   → previous_agent_id correct

3. Assign same agent twice:
   → idempotent (no new event)

4. Assign closed/resolved ticket:
   → rejected

5. Assign inactive/non-existent agent:
   → rejected

6. Concurrent assignment (simulate parallel requests):
   → no data corruption
   → final owner consistent
   → events reflect actual changes

7. Audit integrity:
   → events correctly linked to ticket

---

# 🧼 CODE QUALITY RULES

- No business logic in controllers
- Use Application UseCase
- Constructor injection only
- No static methods
- Entities are anemic
- Repositories encapsulate persistence details

---

# 🚫 STRICT PROHIBITIONS

DO NOT implement:

- Automatic assignment
- Agent shifts / schedules
- Skills / routing
- Chatbot
- Notifications / realtime
- CQRS/Event sourcing

---

# 📦 OUTPUT REQUIRED

Generate:

1. Agent Entity
2. TicketEvent Entity
3. Migration(s)
4. TicketRepository (with locking method)
5. AgentRepository
6. AssignTicketUseCase
7. Controller
8. Integration tests (including concurrency & idempotency)

---

# 🧪 FINAL VALIDATION CHECKLIST

Before finishing, ensure:

- Ticket has at most ONE owner at any time
- assigned_at is set ONLY on first assignment
- Reassignment does not alter assigned_at
- Idempotent calls do not create duplicate events
- Closed/resolved tickets cannot be assigned
- Concurrency does not produce inconsistent state
- Audit trail is correct

---

Execute Sprint 4 now.