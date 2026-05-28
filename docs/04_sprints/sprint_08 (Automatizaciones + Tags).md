You are a senior Symfony 7.4 developer working on a production-grade system called "Inbiox".

Your task is to implement ONLY Sprint 8 (Automation Rules + Tagging System).

This is a STRICT execution task. Follow all rules exactly.

---

# 🎯 SPRINT 8 GOAL

Introduce deterministic automation and tagging:

- Tagging system (manual + system)
- Rule engine (synchronous, deterministic, idempotent)
- Auto-close tickets (via rule)
- Auto-reopen logic (WITHOUT breaking FSM)

---

# 🧠 DESIGN PRINCIPLES (MANDATORY)

1. Deterministic rules (same input → same output)
2. Idempotent execution (safe on retries)
3. FSM MUST NEVER be violated
4. No direct entity mutation (UseCases ONLY)
5. Rules MUST be auditable
6. Execution MUST be synchronous
7. Rules MUST be decoupled (no hardcoding inside executor)

---

# 🧱 ENTITY DEFINITIONS

----------------------------------------
## Tag
----------------------------------------

Fields:

- id (BIGINT, PK)
- name (VARCHAR, UNIQUE, NOT NULL)
- created_at (DATETIME_IMMUTABLE, NOT NULL)

Normalization:

- lowercase
- trim
- no leading/trailing spaces

---

----------------------------------------
## TicketTag
----------------------------------------

Fields:

- ticket_id (FK → Ticket.id)
- tag_id (FK → Tag.id)

Constraints:

- UNIQUE(ticket_id, tag_id)

---

----------------------------------------
## TicketEvent (EXTENSION)
----------------------------------------

Add new types:

- "tag_added"
- "tag_removed"
- "auto_closed"
- "auto_reopened"

Add field:

- source (ENUM: "system","manual")

---

----------------------------------------
## RuleExecutionLog (NEW - REQUIRED)
----------------------------------------

Fields:

- id
- rule_name
- ticket_id
- execution_key (UNIQUE)
- executed_at

Purpose:

- guarantee idempotency per rule execution

---

# 🔄 TAGGING USE CASES

----------------------------------------
## AddTagToTicketUseCase
----------------------------------------

Input:
- ticket_id
- tag_name
- source ("manual" | "system")

Rules:

1. Normalize tag_name
2. Create Tag if not exists
3. Insert TicketTag (idempotent)
4. If inserted:
   → create TicketEvent(type="tag_added", source)

---

----------------------------------------
## RemoveTagFromTicketUseCase
----------------------------------------

Input:
- ticket_id
- tag_name

Rules:

1. Remove relation if exists
2. If removed:
   → create TicketEvent(type="tag_removed")

---

# 🧠 RULE ENGINE (DECOUPLED)

----------------------------------------
## RuleInterface
----------------------------------------

Methods:

- supports(context): bool
- execute(context): void
- getName(): string
- buildExecutionKey(context): string

---

----------------------------------------
## RuleContext
----------------------------------------

Fields:

- ticket
- message (nullable)
- trigger_type ("inbound" | "outbound" | "manual")

---

----------------------------------------
## RuleExecutorService
----------------------------------------

Responsibilities:

1. Receive RuleContext
2. Load all rules (injected as services)
3. FOR each rule:

   IF rule.supports(context):

       key = rule.buildExecutionKey(context)

       IF execution_key already exists:
           SKIP (idempotent)

       EXECUTE rule

       INSERT RuleExecutionLog

Execution order MUST be deterministic (service order)

---

# 🔄 RULES IMPLEMENTATION

----------------------------------------
## RULE 1 — Auto-Reopen (FSM SAFE)
----------------------------------------

Trigger:
- inbound message

Condition:

- ticket.status == "resolved"

Action:

- ChangeTicketStatusUseCase("waiting")
- Create TicketEvent(type="auto_reopened", source="system")

Idempotency key:

auto_reopen:{ticketId}:{messageId}

IMPORTANT:

- DO NOT transition to "active"
- MUST respect FSM

---

----------------------------------------
## RULE 2 — Auto-Close (RULE-BASED)
----------------------------------------

Trigger:
- manual (invoked by service or controller)

Condition:

- ticket.status == "resolved"
- last_message_at < now - threshold

Action:

- ChangeTicketStatusUseCase("closed")
- Create TicketEvent(type="auto_closed", source="system")

Idempotency key:

auto_close:{ticketId}

---

----------------------------------------
## RULE 3 — Keyword Tagging (BASE)
----------------------------------------

Trigger:
- inbound message

Condition:

- message.content LIKE "%error%"

Action:

- AddTagToTicketUseCase("error", source="system")

Idempotency key:

tag_error:{ticketId}:{messageId}

---

# ⚠️ FSM INTEGRATION (STRICT)

- NO FSM rule changes allowed
- NO exception for "system" transitions
- ALL transitions MUST go through UseCases

---

# 🔄 INTEGRATION POINTS

----------------------------------------
HandleInboundMessageUseCase (UPDATE)
----------------------------------------

After message persistence:

1. IF ticket.status == "waiting":
   → ChangeTicketStatusUseCase("active")

2. Execute RuleExecutorService with:

context:
- trigger_type = "inbound"

---

----------------------------------------
HandleOutboundMessageUseCase (UPDATE)
----------------------------------------

After message persistence:

- Execute RuleExecutorService (trigger_type="outbound")

---

# 🌐 CONTROLLERS

----------------------------------------
POST /tickets/{id}/tags
----------------------------------------

{
  "tag": "urgent"
}

→ source = "manual"

---

----------------------------------------
DELETE /tickets/{id}/tags/{tag}
----------------------------------------

---

----------------------------------------
POST /automation/auto-close
----------------------------------------

{
  "threshold_hours": 24
}

→ triggers AutoClose rule

---

# 🧪 TEST CASES (MANDATORY)

1. Tag add:
   → normalized
   → idempotent

2. Tag remove:
   → correct

3. Auto-reopen:
   → resolved → waiting

4. Closed ticket:
   → NOT reopened

5. Auto-close:
   → resolved → closed

6. Double execution (same message):
   → no duplicate effects

7. FSM integrity:
   → no invalid transitions

8. Rule execution log:
   → prevents duplicates

9. Concurrency:
   → no double execution

---

# 🧼 CODE QUALITY RULES

- Rules MUST be isolated classes
- No duplicated logic
- All writes via UseCases
- RuleExecutor MUST be generic
- Tag normalization centralized

---

# 🚫 STRICT PROHIBITIONS

DO NOT implement:

- DSL for rules
- async queues
- cron jobs
- external integrations
- SLA-based rules

---

# 📦 OUTPUT REQUIRED

Generate:

1. Tag entity
2. TicketTag relation
3. TicketEvent update
4. RuleExecutionLog entity
5. RuleInterface
6. RuleContext
7. RuleExecutorService
8. Rules (AutoReopen, AutoClose, KeywordTag)
9. Tag UseCases
10. Integrations
11. Controllers
12. Tests

---

# 🧪 FINAL VALIDATION CHECKLIST

- Rules deterministic
- Rules idempotent (via execution log)
- FSM integrity preserved
- Tags consistent
- No duplicated side-effects
- System stable under retries

---

Execute Sprint 8 now.

- Rules deterministic
- Rules idempotent
- FSM integrity preserved
- Tags consistent
- No duplicated side-effects
- System stable under retries

---

Execute Sprint 8 now.