You are a senior Symfony 7.4 developer working on a production-grade system called "Inbiox".

Your task is to implement ONLY Sprint 7 (Ticket Workflow + SLA).

This is a STRICT execution task. Follow all rules exactly.

---

# 🎯 SPRINT 7 GOAL

Implement:

1. Finite State Machine (FSM) for Ticket lifecycle
2. Deterministic SLA tracking (FRT, RT)
3. Automatic transitions based on events
4. Persistent timestamps for auditability

---

# 🧠 DESIGN PRINCIPLES (MANDATORY)

1. FSM MUST be strict (no invalid transitions)
2. SLA MUST be based ONLY on persisted timestamps
3. All transitions MUST be deterministic
4. No recalculation of SLA from messages
5. All changes MUST be transactional
6. Realtime events emitted AFTER commit

---

# 🧱 TICKET STATE MACHINE

----------------------------------------
## STATES (STRICT)
----------------------------------------

- new
- active
- waiting
- resolved
- closed

---

----------------------------------------
## VALID TRANSITIONS (ONLY THESE)
----------------------------------------

new → active
active → waiting
waiting → active
active → resolved
waiting → resolved
resolved → closed

---

# ⚠️ INVALID TRANSITIONS (MUST FAIL)

- new → resolved
- new → waiting
- closed → any
- resolved → active (no reopen in this sprint)

Throw domain exception on invalid transition.

---

# 🧱 ENTITY UPDATE (Ticket)

Add fields:

- status (ENUM)
- created_at (UTC)
- first_response_at (nullable UTC)
- resolved_at (nullable UTC)
- closed_at (nullable UTC)

Indexes:

- (status)
- (created_at)
- (resolved_at)

---

# 🧠 SLA DEFINITIONS (STRICT)

----------------------------------------
## FRT (First Response Time)
----------------------------------------

Definition:

- start: created_at
- end: first_response_at

Rule:

- set ONLY once
- triggered on FIRST outbound message from agent

---

----------------------------------------
## RT (Resolution Time)
----------------------------------------

Definition:

- start: created_at
- end: resolved_at

Rule:

- set when ticket enters "resolved"

---

# 🔄 TRANSITION TRIGGERS

----------------------------------------
## On inbound message (customer)
----------------------------------------

IF status == waiting:
    → transition to active

---

----------------------------------------
## On outbound message (agent)
----------------------------------------

IF status == new:
    → transition to active
    → set first_response_at (if null)

IF status == active:
    → transition to waiting

---

----------------------------------------
## Resolve ticket
----------------------------------------

Manual or automated:

- transition to resolved
- set resolved_at

---

----------------------------------------
## Auto close
----------------------------------------

Background-safe logic (sync invocation allowed):

IF status == resolved AND inactivity >= X hours:
    → transition to closed
    → set closed_at

(Note: X can be hardcoded for now, e.g. 24h)

---

# ⚠️ CRITICAL RULES

1. first_response_at MUST NOT be overwritten
2. resolved_at MUST NOT change once set
3. closed_at MUST be set only once
4. Transitions MUST be atomic
5. All timestamps MUST be UTC

---

# 🔄 USE CASES

Implement:

----------------------------------------
## TransitionTicketStatusUseCase
----------------------------------------

- validates FSM transition
- sets timestamps
- persists changes

---

----------------------------------------
## HandleInboundMessageUseCase (UPDATE)
----------------------------------------

- apply FSM rules
- DO NOT compute SLA here directly (delegate)

---

----------------------------------------
## HandleOutboundMessageUseCase (UPDATE)
----------------------------------------

- trigger first_response_at when needed
- apply FSM transitions

---

----------------------------------------
## ResolveTicketUseCase
----------------------------------------

- transitions to resolved
- sets resolved_at

---

----------------------------------------
## AutoCloseTicketUseCase
----------------------------------------

- closes eligible tickets

---

# 🔐 VALIDATION RULES

- cannot resolve closed ticket
- cannot close non-resolved ticket
- cannot set SLA timestamps manually

---

# 🧪 TEST CASES (MANDATORY)

1. First response:
   → first_response_at set correctly

2. Second response:
   → first_response_at unchanged

3. Waiting → Active:
   → inbound message triggers correctly

4. Resolve:
   → resolved_at set

5. Auto close:
   → closed_at set

6. Invalid transition:
   → exception thrown

7. SLA correctness:
   → FRT and RT deterministic

---

# 🧼 CODE QUALITY

- FSM logic centralized
- No duplication
- No SLA logic in controllers
- Use domain services where needed

---

# 🚫 PROHIBITIONS

DO NOT implement:

- SLA by priority
- SLA breach alerts
- reopen logic
- async queues

---

# 📦 OUTPUT REQUIRED

Generate:

1. Ticket entity update
2. FSM transition logic
3. SLA timestamp handling
4. Updated UseCases
5. Tests

---

# 🧪 FINAL VALIDATION CHECKLIST

- FSM enforced
- SLA deterministic
- timestamps immutable
- transitions correct
- system consistent

---

Execute Sprint 7 now.

- FSM strictly enforced
- Guards working
- SLA timestamps immutable
- No invalid transitions possible
- No race conditions
- Message integration correct

---

Execute Sprint 7 now.