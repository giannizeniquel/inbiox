You are a senior Symfony 7.4 developer working on a production-grade system called "Inbiox".

Your task is to implement ONLY Sprint 9B (Realtime updates using Mercure / SSE).

This is a STRICT execution task. Follow all rules exactly.

---

# 🎯 GOAL

Implement a reliable, deterministic, event-driven realtime layer using Mercure (SSE).

Realtime MUST:

- Reflect DB state (never lead it)
- Be eventually consistent but never incorrect
- Be safe under retries, reconnects, and concurrency

REST API remains the SINGLE SOURCE OF TRUTH.

---

# 🧠 CORE PRINCIPLES (NON-NEGOTIABLE)

1. Events are SIDE EFFECTS of committed transactions
2. Outbox pattern is MANDATORY (no direct publish)
3. Ordering is REQUIRED per ticket
4. Events MUST be idempotent
5. Payloads MUST be minimal
6. Authorization MUST be strict (least privilege)
7. System MUST tolerate:
   - retries
   - duplicates
   - out-of-order delivery

---

# 🧱 ARCHITECTURE (STRICT)

FLOW:

UseCase
→ DB Transaction
→ Domain Events (in-memory)
→ Outbox INSERT (same transaction)
→ COMMIT
→ OutboxPublisher (async-safe)
→ Mercure

NO EXCEPTIONS.

---

# 🧱 OUTBOX ENTITY (MANDATORY)

Table: outbox_event

Fields:

- id (ULID, PK)
- aggregate_type ("ticket")
- aggregate_id (ticket_id)
- topic (string)
- event_type (string)
- payload (JSON)
- created_at (UTC)
- published_at (nullable UTC)
- retry_count (int, default 0)

Indexes:

- (published_at)
- (aggregate_id)

---

# 🧠 EVENT CONTRACT (STRICT)

Every event MUST include:

- event_id (ULID)
- type (string)
- ticket_id (int)
- occurred_at (ISO8601 UTC)
- seq (int, monotonic per ticket)
- version (int, start at 1)

Example:

{
  "event_id": "01H...",
  "type": "ticket.updated",
  "ticket_id": 10,
  "occurred_at": "2026-01-01T10:00:00Z",
  "seq": 46,
  "version": 1,
  "data": { ... }
}

---

# ⚠️ SEQUENCE GUARANTEE (CRITICAL)

Per-ticket strict ordering REQUIRED.

Implementation:

- Add to Ticket:
   last_event_seq (INT NOT NULL DEFAULT 0)

Inside SAME transaction:

1. SELECT ticket FOR UPDATE
2. seq = last_event_seq + 1
3. UPDATE ticket.last_event_seq = seq
4. use seq in event payload

This MUST be atomic.

---

# 📡 TOPICS (STRICT)

- ticket/{ticket_id}
- agent/{agent_id}/inbox

NO global topics.

Events may be published to MULTIPLE topics.

---

# 🔐 SECURITY (MANDATORY)

Use Mercure JWT.

Rules:

- Agent subscribes ONLY to:
   - assigned tickets
   - their inbox topic

JWT MUST include:

{
  "mercure": {
    "subscribe": [...]
  }
}

NO wildcard access.

---

# 📦 EVENT TYPES

----------------------------------------
message.created
----------------------------------------

data:
- message_id
- direction (inbound|outbound)

Topic:
- ticket/{ticket_id}

---

----------------------------------------
ticket.updated
----------------------------------------

data:
- status
- owner_agent_id

Topics:
- ticket/{ticket_id}
- agent/{agent_id}/inbox

---

----------------------------------------
inbox.updated
----------------------------------------

data:
- ticket_id

Topic:
- agent/{agent_id}/inbox

---

# ⚠️ PAYLOAD RULES

DO NOT include:

- message content
- full entities
- computed fields

ONLY identifiers + minimal state.

Frontend MUST re-fetch via REST.

---

# 🔄 OUTBOX PUBLISHER (MANDATORY)

Service: OutboxPublisherService

Logic:

1. SELECT events WHERE published_at IS NULL
   ORDER BY created_at ASC

2. Publish to Mercure

3. On success:
   → set published_at

4. On failure:
   → increment retry_count
   → DO NOT delete

MUST be safe to run multiple times.

---

# 🔁 IDEMPOTENCY

Guaranteed by:

- event_id (unique)
- seq (ordering)

Clients MUST:

- deduplicate by event_id
- ignore seq <= last_applied_seq

---

# 🔄 INTEGRATION POINTS

UseCases MUST:

- collect domain events
- push to Outbox (NOT publish)

Integration:

- HandleInboundMessageUseCase
- HandleOutboundMessageUseCase
- ChangeTicketStatusUseCase
- AssignTicketUseCase
- Tag UseCases

---

# ⚠️ TRANSACTION RULE

Outbox INSERT MUST be in SAME transaction as business change.

If transaction fails → NO event.

---

# 🌐 MERCURE INTEGRATION

Use Symfony Mercure component.

Publisher MUST:

- send topic
- send JSON payload
- include event_id as SSE id

---

# 🔄 REPLAY & RECONNECT (CRITICAL)

Clients use:

- Last-Event-ID header

System MUST:

- allow replay via Mercure
- tolerate duplicate delivery

NO server-side custom replay logic needed.

---

# ⚠️ FAILURE STRATEGY

- Publish failure MUST NOT break main flow
- Outbox guarantees retry
- Logs MUST be generated

---

# 🧪 TEST CASES (MANDATORY)

1. Event stored in outbox
2. Event published after commit
3. Sequence increments correctly
4. Concurrent updates preserve ordering
5. Assignment emits to both agents
6. Duplicate publish safe
7. Unauthorized topic blocked
8. Retry works after failure

---

# 🧼 CODE QUALITY

- Domain events ≠ infrastructure events
- No Mercure calls in UseCases
- Outbox logic isolated
- No duplicated event construction

---

# 🚫 PROHIBITIONS

DO NOT:

- publish inside transaction
- skip outbox
- use polling
- expose global topics
- include large payloads

---

# 📦 OUTPUT REQUIRED

1. OutboxEvent entity
2. Ticket.last_event_seq implementation
3. Domain event classes
4. Event → Outbox mapper
5. OutboxPublisherService
6. Mercure config
7. JWT topic security
8. UseCase integrations
9. Tests

---

# 🎯 FINAL VALIDATION

System is correct ONLY IF:

- No lost events
- No duplicate side-effects
- Strict ordering per ticket
- Secure topics
- Works under concurrency
- Client can safely replay

---

Execute Sprint 9B now.

- Events emitted after commit only
- Ordering guaranteed via seq
- Idempotency ensured
- Topics secured
- No lost events (outbox)
- Angular-ready

---

Execute Sprint 9B now.