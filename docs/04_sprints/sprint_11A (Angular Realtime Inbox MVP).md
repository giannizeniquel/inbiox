You are a senior Angular + realtime systems engineer working on a production-grade SaaS platform called "Inbiox".

Your task is to implement ONLY Sprint 11A:

# Sprint 11A — Angular Realtime Inbox MVP

This is NOT a design exercise.
This is an operational frontend implementation focused on:

- realtime ticket updates
- operational usability
- deterministic UI state
- backend integration

The backend already exists and is production-grade.

The frontend MUST integrate with the existing backend architecture.

---

# 🎯 SPRINT GOAL

Build the FIRST operational Angular frontend capable of:

- listing tickets
- opening conversations
- receiving realtime updates via Mercure SSE
- sending messages
- changing ticket status
- visualizing assignment/state
- handling reconnect/realtime ordering safely

This is an MVP inbox for agents.

---

# ⚠️ IMPORTANT CONTEXT

Backend already supports:

- FSM workflow
- auto-assignment
- SLA
- automation rules
- outbox pattern
- Mercure SSE realtime
- ordering via seq
- idempotent events

Frontend MUST respect backend contracts.

REST remains source of truth.

Realtime only updates local UI state.

---

# 🧱 TECH STACK (MANDATORY)

Use:

- Angular 17+
- Standalone Components
- TypeScript strict mode
- RxJS
- Angular Signals where useful
- SCSS
- Native EventSource (NO websocket libraries)

DO NOT use:

- Angular 15
- NgModules architecture
- NgRx
- Akita
- Redux
- socket.io
- Tailwind
- Material UI (for now)

Use lightweight custom CSS only.

---

# 📦 PROJECT STRUCTURE

Create:

frontend/
  inbiox-web/

Structure:

src/app/
  core/
    api/
    realtime/
    models/
    services/
  features/
    inbox/
      components/
      pages/
      services/
  shared/
  layouts/

---

# 🎯 MVP FEATURES

Implement ONLY:

## 1. Ticket List

Display:

- ticket id
- status
- assigned agent
- last message timestamp
- unread indicator (basic)
- seq indicator (debug/dev only)

Capabilities:

- select ticket
- auto-refresh via SSE

---

## 2. Conversation View

Display:

- inbound messages
- outbound messages
- timestamps
- status
- tags (readonly)

Capabilities:

- send outbound message
- optimistic append OPTIONAL
- realtime updates

---

## 3. Ticket Status Actions

Allow:

- active → waiting
- waiting → active
- active → resolved
- resolved → closed

Use backend FSM endpoints.

Frontend MUST NOT bypass FSM rules.

---

## 4. Realtime Integration (CRITICAL)

Implement Mercure SSE client.

Subscribe to:

ticket/{ticketId}

Use:

EventSource

---

# ⚠️ REALTIME RULES (MANDATORY)

Realtime MUST:

- reconnect automatically
- ignore duplicate seq
- ignore older seq
- preserve ordering

Maintain:

Map<ticketId, lastSeq>

Rules:

- seq > current → apply
- seq == current → ignore duplicate
- seq < current → ignore old event

---

# 📡 SSE SERVICE

Create:

core/realtime/mercure.service.ts

Responsibilities:

- create EventSource
- manage subscriptions
- reconnect handling
- expose RxJS observables/signals
- seq validation

NO business logic inside components.

---

# 🌐 API SERVICE LAYER

Create typed API services:

- TicketApiService
- MessageApiService
- WorkflowApiService

Use Angular HttpClient.

Strict typing required.

---

# 🧱 STATE MANAGEMENT

DO NOT use NgRx.

Use:

- signals
- RxJS BehaviorSubjects
- lightweight local state services

State must remain deterministic.

REST is authoritative.

Realtime patches local state.

---

# 🎨 UI REQUIREMENTS

This is NOT a design sprint.

Use simple operational layout:

LEFT:
- ticket list

RIGHT:
- conversation

Minimal CSS only.

Focus on:

- usability
- realtime responsiveness
- stability

---

# 📊 DEBUG PANEL (MANDATORY)

Add lightweight dev panel showing:

- SSE connection state
- reconnect count
- last seq per ticket
- events received

This is temporary but REQUIRED.

---

# 🔄 RECONNECT BEHAVIOR

On SSE disconnect:

- auto reconnect
- keep UI alive
- no full app reload

On reconnect:

- continue processing seq correctly

---

# ⚠️ SOURCE OF TRUTH

Frontend MUST NEVER assume local state is authoritative.

If inconsistency detected:

→ re-fetch ticket from REST.

---

# 📦 MODELS

Create typed interfaces for:

- Ticket
- Message
- TicketEvent
- RealtimeEvent

---

# 🧪 VALIDATION CHECKLIST

Must validate:

- realtime messages arrive
- ordering preserved
- duplicates ignored
- reconnect works
- no UI crashes
- FSM respected
- REST + SSE consistency

---

# 🚫 STRICT PROHIBITIONS

DO NOT implement:

- authentication
- permissions
- multi-tenant
- advanced design system
- ngrx
- websocket custom server
- notifications
- file uploads
- emoji systems
- typing indicators
- infinite scroll

---

# 📦 REQUIRED OUTPUT

Generate:

1. Angular project
2. Core architecture
3. Inbox page
4. SSE Mercure service
5. API services
6. Ticket list
7. Conversation panel
8. Status actions
9. Debug panel
10. Minimal operational styling

---

# 🎯 FINAL SUCCESS CRITERIA

Sprint 11A is COMPLETE only if:

- ticket updates appear realtime
- messages appear realtime
- seq ordering preserved
- reconnect stable
- FSM transitions work
- inbox usable operationally
- backend contracts respected

---

# 🚀 EXECUTION PLAN

Recommended order:

1. Create Angular 17 app
2. Configure architecture
3. Implement REST services
4. Build inbox layout
5. Build conversation panel
6. Integrate Mercure SSE
7. Implement seq ordering
8. Add reconnect handling
9. Add debug panel
10. Validate realtime behavior

---

# 🧠 IMPORTANT

This sprint is about OPERATIONAL VALIDATION of the product.

Focus on:

- correctness
- realtime stability
- backend integration
- operational usability

NOT visual polish.

Implement Sprint 11A now.