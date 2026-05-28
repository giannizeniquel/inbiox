You are a senior Symfony 7.4 developer working on a production-grade system called "Inbiox".

Your task is to implement ONLY Sprint 3 (Ticket).

This is a STRICT execution task. Follow all rules exactly.

---

# 🎯 SPRINT 3 GOAL

Introduce Ticket as the operational unit and guarantee that:

- Every inbound Message is linked to exactly ONE Ticket
- At most ONE ACTIVE Ticket exists per Conversation (MVP invariant)
- Concurrency and webhook idempotency are handled correctly

You MUST integrate with existing:
- Contact
- Conversation
- Message
- HandleInboundMessageUseCase

---

# 🧠 DOMAIN DEFINITIONS (STRICT)

## Ticket
Unit of work for a customer interaction.

## Relationships

- Conversation (1) → (N) Ticket over time
- Ticket (1) → (N) Message
- Conversation → MAX ONE ACTIVE Ticket at any time (MVP invariant)

## ACTIVE TICKET DEFINITION

Active statuses:
- "new"
- "active"
- "waiting"

Non-active:
- "resolved"
- "closed"

---

# ⚠️ CRITICAL BUSINESS RULES (NON-NEGOTIABLE)

1. A Ticket MUST belong to exactly ONE Conversation
2. A Message MUST belong to exactly ONE Ticket (after this sprint)
3. For a given Conversation, there MUST NOT be more than ONE ACTIVE Ticket
4. On inbound message:
   - If ACTIVE Ticket exists → reuse it
   - Else → create a new Ticket with status "new"
5. Ticket MUST set lifecycle timestamps correctly:
   - opened_at on creation
6. System MUST be safe under concurrent inbound requests
7. System MUST be idempotent when external_id is provided (no duplicate Message nor side-effects)

---

# 🔧 TECHNICAL REQUIREMENTS

- Symfony 7.4
- Doctrine ORM
- MySQL 8+
- DateTimeImmutable in UTC
- Transactions REQUIRED
- Use proper locking to avoid race conditions

---

# 📁 PROJECT STRUCTURE (MANDATORY)

src/
  Domain/
    Ticket/
  Application/
    UseCase/
  Infrastructure/
    Persistence/

Re-use existing modules.

---

# 🧱 ENTITY DEFINITION

## Ticket

Fields:

- id (BIGINT, PK)
- conversation_id (FK → Conversation, NOT NULL)
- status (ENUM: "new","active","waiting","resolved","closed") NOT NULL
- priority (ENUM: "low","medium","high") DEFAULT "medium"
- category (VARCHAR, NULLABLE)

- owner_agent_id (BIGINT, NULLABLE) // NOT USED in this sprint

- opened_at (DATETIME_IMMUTABLE, NOT NULL)
- assigned_at (DATETIME_IMMUTABLE, NULLABLE)
- first_response_at (DATETIME_IMMUTABLE, NULLABLE)
- resolved_at (DATETIME_IMMUTABLE, NULLABLE)
- closed_at (DATETIME_IMMUTABLE, NULLABLE)

- created_at (DATETIME_IMMUTABLE, NOT NULL)
- updated_at (DATETIME_IMMUTABLE, NOT NULL)

Indexes:

- INDEX(conversation_id)
- INDEX(status)
- INDEX(conversation_id, status)

---

# 🔄 MESSAGE UPDATE (MANDATORY, BACKWARD-SAFE)

You MUST update Message entity:

Add field:
- ticket_id (FK → Ticket)

Migration strategy (STRICT):

1. Add nullable column ticket_id
2. Backfill existing messages:
   - For each Conversation:
     - Create ONE Ticket (status="new", opened_at = earliest message.created_at or now)
     - Assign all its Messages to that Ticket
3. After backfill:
   - ALTER ticket_id to NOT NULL

Constraints:
- FK(message.ticket_id → ticket.id)

---

# 🗄️ DATABASE RULES (CONCURRENCY & INVARIANTS)

- Enforce uniqueness of ACTIVE Ticket at DB level using a generated column or partial uniqueness strategy:

Option A (preferred, MySQL 8+):
- Add generated column: is_active (TINYINT) computed from status IN ("new","active","waiting")
- Create UNIQUE INDEX on (conversation_id, is_active) WHERE is_active = 1
  (If partial index not available, use functional index or enforce strictly at app level with locks)

- Always use transactions
- Use SELECT ... FOR UPDATE when checking/creating active ticket

---

# 🔄 USE CASE (MANDATORY UPDATE)

## HandleInboundMessageUseCase

Input:
- phone (string)
- message (string)
- external_id (optional string)

Execution steps (STRICT ORDER):

1. Normalize phone
2. Start transaction

3. Retrieve or create Contact (reuse Sprint 1)
4. Retrieve or create Conversation (reuse Sprint 1)

5. Idempotency check (EARLY):
   IF external_id provided:
     - try find Message by external_id
     - IF exists:
         RETURN existing {contact_id, conversation_id, ticket_id, message_id}

6. Acquire lock:
   - SELECT Conversation FOR UPDATE (by id)

7. Find ACTIVE Ticket:
   WHERE conversation_id = X
   AND status IN ("new","active","waiting")
   (inside the same transaction/lock)

8. IF ACTIVE Ticket exists:
     ticket = existing
   ELSE:
     create Ticket:
       - conversation_id = X
       - status = "new"
       - priority = "medium"
       - opened_at = now (UTC)
       - created_at = now
       - updated_at = now
     persist & flush

9. Create Message:
   - conversation_id = X
   - ticket_id = ticket.id
   - direction = "inbound"
   - external_id (if provided)
   - content = message
   - created_at = now (UTC)
   persist

10. Update Conversation.last_message_at = message.created_at

11. Commit transaction

12. Return:
{
  "contact_id": ...,
  "conversation_id": ...,
  "ticket_id": ...,
  "message_id": ...,
  "status": "ok"
}

---

# ⚠️ IDEMPOTENCY (STRICT)

- If external_id is provided:
  - UNIQUE INDEX(external_id) WHERE NOT NULL
  - On duplicate key:
      - catch exception
      - fetch existing message
      - return existing result WITHOUT creating ticket or side-effects

- If external_id is NOT provided:
  - allow multiple messages (non-idempotent)

---

# 🌐 CONTROLLER

POST /messages/inbound

Request:

{
  "phone": "+5437XXXXXXX",
  "message": "Hola",
  "external_id": "wamid-123" // optional
}

Response:

{
  "contact_id": 1,
  "conversation_id": 10,
  "ticket_id": 5,
  "message_id": 55,
  "status": "ok"
}

HTTP Codes:
- 200 OK
- 400 Bad Request

---

# ✅ VALIDATION

- phone: required, normalized
- message: required, non-empty

---

# 🧪 TEST CASES (MANDATORY)

1. First inbound:
   → creates Contact + Conversation + Ticket + Message

2. Subsequent inbound:
   → reuses same ACTIVE Ticket

3. After closing ticket (manually set to "closed" in test):
   → next inbound creates NEW Ticket

4. Concurrency test:
   - simulate parallel inbound (same phone)
   → only ONE ACTIVE Ticket created

5. Idempotency with external_id:
   → duplicate request does NOT create new Message nor Ticket

6. Data integrity:
   → all Messages have ticket_id NOT NULL

7. Backfill migration:
   → old Messages linked to a Ticket

---

# 🧼 CODE QUALITY RULES

- No business logic in controllers
- Use Application UseCase
- Repositories for persistence queries
- Constructor injection only
- No static methods
- Entities are anemic (no business logic)

---

# 🚫 STRICT PROHIBITIONS

DO NOT implement:

- Agent / Assignment
- Automatic assignment
- Chatbot
- Events / queues
- Complex state transitions beyond creation
- SLA logic

---

# 📦 OUTPUT REQUIRED

Generate:

1. Ticket Entity (Doctrine)
2. Migration(s):
   - Ticket table
   - Message.ticket_id + backfill + NOT NULL
   - Indexes/constraints for active uniqueness (or app-level lock if DB cannot enforce)
3. TicketRepository (with method: findActiveByConversationForUpdate)
4. Updated Message entity
5. Updated HandleInboundMessageUseCase
6. Controller update
7. Integration tests (covering concurrency & idempotency)

---

# 🧪 FINAL VALIDATION CHECKLIST

Before finishing, ensure:

- At most ONE ACTIVE Ticket per Conversation (even under concurrency)
- All Messages have ticket_id NOT NULL
- Idempotency with external_id prevents duplicates and side-effects
- Backfill migration leaves no orphan messages
- Endpoint works end-to-end

---

Execute Sprint 3 now.