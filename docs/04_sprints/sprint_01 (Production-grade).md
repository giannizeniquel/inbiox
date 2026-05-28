You are a senior Symfony 7.4 developer working on a production-grade system called "Inbiox".

Your task is to implement ONLY Sprint 1.

This is a STRICT execution task. Follow all rules exactly.

---

# 🎯 SPRINT 1 GOAL

Implement Contact + Conversation creation/retrieval via inbound message.

No other features are allowed.

---

# 🧠 DOMAIN DEFINITIONS (STRICT)

## Contact
Represents a unique person identified by phone number.

## Conversation
Represents a continuous communication channel with a Contact.

MVP Rule:
- ONE Contact → ONE active Conversation

---

# ⚠️ CRITICAL BUSINESS RULES

1. Contact is uniquely identified by `phone`
2. Phone MUST be normalized before persistence
3. A Contact MUST NOT be duplicated under any circumstance
4. A Conversation MUST be unique per Contact (status = "open")
5. If multiple concurrent requests occur:
   - System MUST NOT create duplicate Contact or Conversation
6. last_message_at MUST be updated on every inbound message
7. System MUST be idempotent for same phone input

---

# 🔧 TECHNICAL REQUIREMENTS

- Symfony 7.4
- Doctrine ORM
- MySQL
- Use UTC for all datetime fields
- Use immutable DateTime (DateTimeImmutable)

---

# 📁 PROJECT STRUCTURE (MANDATORY)

src/
  Domain/
    Contact/
    Conversation/
  Application/
    UseCase/
  Infrastructure/
    Persistence/
    Http/

---

# 🧱 ENTITY DEFINITIONS

## Contact

Fields:
- id (BIGINT, PK)
- phone (VARCHAR, UNIQUE, NOT NULL)
- name (VARCHAR, NULLABLE)
- created_at (DATETIME_IMMUTABLE)
- updated_at (DATETIME_IMMUTABLE)

Constraints:
- UNIQUE(phone)

---

## Conversation

Fields:
- id (BIGINT, PK)
- contact_id (FK → Contact)
- status (ENUM: "open", "archived") DEFAULT "open"
- last_message_at (DATETIME_IMMUTABLE)
- created_at (DATETIME_IMMUTABLE)
- updated_at (DATETIME_IMMUTABLE)

Constraints:
- ONE open conversation per contact (enforced at application level)

---

# 🗄️ DATABASE RULES

- Add UNIQUE INDEX on contact.phone
- Add INDEX on conversation.contact_id
- Use transactions where needed
- Prevent race conditions

---

# 🔄 USE CASE

## HandleInboundMessageUseCase

Input:
- phone (string)
- message (string)

Steps (MANDATORY ORDER):

1. Normalize phone (remove spaces, enforce format)
2. Start transaction
3. Try find Contact by phone
4. If NOT exists:
   - create Contact
   - flush immediately
5. Try find open Conversation by contact
6. If NOT exists:
   - create Conversation
7. Update last_message_at
8. Commit transaction

---

# ⚠️ CONCURRENCY HANDLING (MANDATORY)

You MUST prevent duplicates using:

- DB unique constraint
- try/catch on insert conflict
- re-fetch entity if conflict occurs

---

# 🌐 CONTROLLER

POST /messages/inbound

Request:

{
  "phone": "+5437XXXXXXX",
  "message": "Hola"
}

Response:

{
  "contact_id": 1,
  "conversation_id": 10,
  "status": "ok"
}

HTTP Codes:
- 200 OK
- 400 Bad Request (invalid input)

---

# ✅ VALIDATION

- phone: required, string
- message: required, string
- reject empty values

---

# 🧪 TEST CASES (MANDATORY)

1. New phone:
   → creates Contact + Conversation

2. Existing phone:
   → reuses both

3. Concurrent requests (simulate):
   → no duplicates created

4. Multiple messages:
   → only updates last_message_at

---

# 🧼 CODE QUALITY RULES

- No logic in controllers
- Use constructor injection
- Entities must not contain business logic
- Use services for orchestration
- No static methods
- No global state

---

# 🚫 STRICT PROHIBITIONS

DO NOT implement:

- Message entity
- Ticket
- Agent
- Chatbot
- Events
- Queues
- DTO overengineering

---

# 📦 OUTPUT REQUIRED

Generate:

1. Entities (Doctrine)
2. Repositories
3. UseCase
4. Controller
5. Migration
6. Tests

---

# 🧪 FINAL VALIDATION CHECKLIST

Before finishing:

- Contact is never duplicated
- Conversation is never duplicated
- Endpoint works end-to-end
- last_message_at updates correctly
- Concurrency is handled

---

Execute Sprint 1 now.