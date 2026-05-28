You are a senior Symfony 7.4 developer working on a production-grade system called "Inbiox".

Your task is to implement ONLY Sprint 2.

This is a STRICT execution task. Follow all rules exactly.

---

# 🎯 SPRINT 2 GOAL

Implement Message entity and inbound message persistence, reusing Contact + Conversation from Sprint 1.

You MUST integrate with existing:
- Contact
- Conversation
- HandleInboundMessageUseCase (you may extend/refactor it minimally)

No other features are allowed.

---

# 🧠 DOMAIN DEFINITIONS (STRICT)

## Message
Represents a single message exchanged within a Conversation.

For this sprint:
- Only inbound messages are handled (from client → system)
- Outbound is NOT implemented yet

---

# ⚠️ CRITICAL BUSINESS RULES

1. Every inbound request MUST persist exactly ONE Message
2. A Message MUST belong to exactly ONE Conversation
3. Message MUST NOT exist without Conversation
4. System MUST be idempotent for webhook retries
5. Duplicate inbound messages MUST NOT create duplicate records
6. last_message_at in Conversation MUST reflect latest Message timestamp
7. Ordering of messages MUST be preserved by created_at

---

# 🔧 TECHNICAL REQUIREMENTS

- Symfony 7.4
- Doctrine ORM
- MySQL
- All datetime fields MUST be DateTimeImmutable in UTC
- Use transactions where required

---

# 📁 PROJECT STRUCTURE (MANDATORY)

src/
  Domain/
    Message/
  Application/
    UseCase/
  Infrastructure/
    Persistence/
    Http/

Re-use existing modules from Sprint 1.

---

# 🧱 ENTITY DEFINITION

## Message

Fields:

- id (BIGINT, PK)
- conversation_id (FK → Conversation, NOT NULL)
- direction (ENUM: "inbound")  // outbound not allowed yet
- external_id (VARCHAR, NULLABLE)  // for idempotency (webhook id)
- content (TEXT, NOT NULL)
- created_at (DATETIME_IMMUTABLE, NOT NULL)

Indexes:

- INDEX(conversation_id)
- UNIQUE(external_id) WHERE external_id IS NOT NULL

---

# 🗄️ DATABASE RULES

- external_id must be unique when provided
- Use DB constraint to prevent duplicates
- Use transaction to guarantee atomicity

---

# 🔄 USE CASE (MANDATORY)

You MUST update or create:

## HandleInboundMessageUseCase

Input:

- phone (string)
- message (string)
- external_id (optional string)

Execution steps (STRICT ORDER):

1. Normalize phone
2. Start transaction
3. Retrieve or create Contact (reuse Sprint 1 logic)
4. Retrieve or create Conversation (reuse Sprint 1 logic)
5. If external_id is provided:
   - check if Message with same external_id exists
   - if exists → RETURN existing result (idempotent behavior)
6. Create Message:
   - direction = inbound
   - content = message
   - link to Conversation
   - set created_at = now (UTC)
7. Persist Message
8. Update Conversation.last_message_at = Message.created_at
9. Commit transaction
10. Return identifiers

---

# ⚠️ IDEMPOTENCY (MANDATORY)

System MUST handle webhook retries:

IF external_id is provided:
- Do NOT create duplicate messages
- Use UNIQUE constraint + safe retry logic

IF external_id is NOT provided:
- assume non-idempotent (create message normally)

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
  "message_id": 55,
  "status": "ok"
}

HTTP Codes:

- 200 OK
- 400 Bad Request

---

# ✅ VALIDATION

- phone: required
- message: required
- external_id: optional
- reject empty values

---

# 🧪 TEST CASES (MANDATORY)

1. New phone:
   → creates Contact + Conversation + Message

2. Existing phone:
   → reuses Contact + Conversation, creates Message

3. Multiple inbound messages:
   → creates multiple Message records correctly

4. With external_id:
   → duplicate request does NOT create new message

5. Without external_id:
   → duplicate request creates new message (expected)

6. last_message_at:
   → always matches latest message timestamp

---

# 🧼 CODE QUALITY RULES

- No business logic in controllers
- Use Application UseCase
- Entities must be persistence-only
- Use constructor injection
- No static methods
- No global state

---

# 🚫 STRICT PROHIBITIONS

DO NOT implement:

- Ticket
- Agent
- Assignment
- Chatbot
- Outbound messaging
- Events / queues
- DTO overengineering

---

# 📦 OUTPUT REQUIRED

Generate:

1. Message Entity (Doctrine)
2. Migration
3. Repository
4. Updated UseCase
5. Controller update
6. Tests

---

# 🧪 FINAL VALIDATION CHECKLIST

Before finishing:

- Message is always linked to Conversation
- No duplicate Message when external_id repeats
- last_message_at is correct
- Endpoint works end-to-end
- System is safe under concurrent requests

---

Execute Sprint 2 now.