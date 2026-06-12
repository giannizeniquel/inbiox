# Sprint 12.5 — Realtime Stabilization Specification

> **Purpose**: Fix 5 CRITICAL + 4 HIGH structural defects in the Mercure realtime pipeline. Establish unified event contract, single dispatch path, sequence-based reconnection, and complete send lifecycle. This is a PRE-STRESS stabilization — no chaos simulation.

---

## 1. Capabilities

### 1.1 New Capabilities

| Capability | Namespace | Description |
|---|---|---|
| `realtime-event-contract` | `core.realtime.contract` | Unified backend→frontend event model. Single JSON shape, shared event type enum, camelCase fields on both sides. |
| `reconnect-state-sync` | `core.realtime.reconnect` | Sequence-based gap detection and targeted resync on disconnect/reconnect boundary. |

### 1.2 Modified Capabilities

None. The realtime pipeline has no prior spec.

---

## 2. Requirements

### RT-STAB-001 — RealtimeEventContract v1 [P0]

The backend SHALL publish events using the RealtimeEventContract v1 shape. The frontend SHALL parse events using the identical shape. No implicit field transformation (snake_case↔camelCase, PHP class names↔string enums) SHALL exist between publisher and consumer.

#### Scenario: Backend publishes ticket.created event

- GIVEN a new ticket is created by HandleInboundMessageUseCase
- WHEN the OutboxPublisherService publishes to Mercure
- THEN the Mercure payload SHALL be: `{"eventType":"ticket.created","entityId":"<ticketId>","sequence":<N>,"timestamp":"<ISO8601>","payload":{...}}`
- AND all field names SHALL be camelCase
- AND `eventType` SHALL be a string from the RealtimeEventType enum (NOT a PHP class name)

#### Scenario: Frontend parses ticket.created event

- GIVEN MercureService receives an SSE message with `eventType: "ticket.created"`
- WHEN `handleMessage()` parses the JSON payload
- THEN the parsed object SHALL conform to the `RealtimeEventContract` TypeScript interface
- AND `eventType` SHALL match a value in the `RealtimeEventType` enum
- AND no field transformation adapter SHALL be applied

#### Scenario: Unknown event type received

- GIVEN the frontend receives an event with `eventType` not in the enum
- WHEN `handleMessage()` processes the event
- THEN the event SHALL be dropped
- AND a warning SHALL be logged with the unknown `eventType` value

---

### RT-STAB-002 — Single Dispatch Path [P0]

MercureService SHALL route each valid event through exactly ONE dispatch path to stores. Dual dispatch (both `applyStorePatch()` and `handleEvent()` for the same event) is PROHIBITED.

#### Scenario: Event produces exactly one store mutation

- GIVEN a `message.added` event arrives via SSE
- WHEN MercureService processes the event after idempotency and seq validation
- THEN exactly ONE store mutation SHALL occur (either `conversationStore.addMessage()` OR equivalent — not both)
- AND `applyStorePatch()` SHALL NOT be called for any event

#### Scenario: Audit confirms single path

- GIVEN the MercureService source code
- WHEN an architect reviews the `handleMessage()` method
- THEN there SHALL be exactly one call chain from event parse to store mutation
- AND no branching that invokes both `applyStorePatch` and `handleEvent`

---

### RT-STAB-003 — Post-Reconnect State Sync [P0]

Upon SSE reconnection, the system SHALL recover any events missed during the disconnect window using sequence-based targeted resync.

#### Scenario: Reconnect after brief disconnect (gap detected)

- GIVEN the frontend was connected with `lastSequence = 10` for ticket T1
- AND the SSE connection drops for 5 seconds
- WHEN the connection re-establishes (`onopen` fires)
- THEN the system SHALL call `GET /api/tickets/{ticketId}/events?afterSequence=10`
- AND received events SHALL be applied to stores in sequence order
- AND the SSE stream SHALL resume for new events after resync completes

#### Scenario: Gap detected during active SSE

- GIVEN `expectedSeq = 11` for ticket T1 (lastSeq was 10)
- WHEN an event arrives with `sequence = 14`
- THEN a gap SHALL be detected (14 > 11)
- AND an immediate targeted resync SHALL be triggered for ticket T1 (`afterSequence=10`)
- AND events with seq 11, 12, 13 SHALL be applied before event 14

#### Scenario: Duplicate/old event during active SSE

- GIVEN `expectedSeq = 11` for ticket T1
- WHEN an event arrives with `sequence = 9`
- THEN the event SHALL be dropped (already processed)
- AND no resync SHALL be triggered

#### Scenario: Exact expected sequence

- GIVEN `expectedSeq = 11` for ticket T1
- WHEN an event arrives with `sequence = 11`
- THEN the event SHALL be applied normally
- AND `expectedSeq` SHALL be incremented to 12

---

### RT-STAB-004 — TicketCreatedEvent Uses Correct Ticket ID [P0]

The TicketCreatedEvent SHALL contain the actual persisted ticket ID (post-flush), NOT the conversation ID.

#### Scenario: New ticket created from inbound message

- GIVEN a contact sends a message that creates a new ticket
- WHEN HandleInboundMessageUseCase persists the Ticket entity
- THEN the TicketCreatedEvent collected SHALL use `$ticket->getId()` (the post-flush ID)
- AND the outbox payload `entityId` SHALL match the ticket's database ID
- AND the value SHALL NOT be `(int) $conversation->getId()`

#### Scenario: Event published with correct ID

- GIVEN a new ticket with ID=42 was created
- WHEN OutboxPublisherService publishes the TicketCreatedEvent
- THEN the Mercure payload SHALL contain `"entityId": "42"`
- AND the frontend SHALL receive the correct ticket ID for store insertion

---

### RT-STAB-005 — MAX_RETRIES Enforced in OutboxPublisherService [P0]

OutboxPublisherService SHALL enforce `MAX_RETRIES = 5`. Events exceeding this threshold SHALL NOT be retried.

#### Scenario: Event exceeds max retries

- GIVEN an OutboxEvent with `retry_count = 5`
- WHEN OutboxPublisherService processes the event and publish fails
- THEN `handleFailure()` SHALL increment `retry_count` to 6
- AND the event SHALL be marked as dead-letter (status = 'dead_letter' or equivalent)
- AND an error-level log SHALL be emitted with the event ID and retry count
- AND the event SHALL NOT be retried on subsequent cron runs

#### Scenario: Event within retry limit

- GIVEN an OutboxEvent with `retry_count = 3`
- WHEN publish fails
- THEN `retry_count` SHALL be incremented to 4
- AND the event SHALL remain in 'pending' status for next retry

---

### RT-STAB-006 — Complete Send Pipeline [P1]

The send pipeline SHALL connect ComposerComponent through MessageSenderService to the provider, with full optimistic message lifecycle.

#### Scenario: Message sent from Composer

- GIVEN a user types "Hello" in ComposerComponent and presses send
- WHEN `send()` is called
- THEN an optimistic message SHALL be added to ConversationStore with `deliveryStatus: 'sending'`
- AND MessageSenderService.send() SHALL be invoked with the message content
- AND on provider success, `deliveryStatus` SHALL transition to `'sent'`
- AND on provider failure after retries exhausted, `deliveryStatus` SHALL transition to `'failed'`

#### Scenario: Failed message rollback

- GIVEN a message has `deliveryStatus: 'failed'`
- WHEN the user acknowledges the failure (dismiss or retry action)
- THEN if dismissed, the optimistic message SHALL be removed from the store
- AND no orphaned `'sending'` messages SHALL remain

#### Scenario: SSE confirmation arrives for sent message

- GIVEN an optimistic message with tempId="tmp-1" is in the store
- WHEN the backend publishes a `message.added` event via SSE with matching content
- THEN `conversationStore.confirmMessage(tempId, confirmedData)` SHALL reconcile the optimistic message
- AND the message SHALL display with server-assigned ID and timestamps

---

### RT-STAB-007 — Eliminate N+1 Per-Ticket HTTP Requests [P1]

InboxService SHALL NOT issue per-ticket HTTP GET requests on each SSE event. Realtime updates SHALL be applied directly from event payload to stores.

#### Scenario: SSE event updates store without HTTP refetch

- GIVEN an agent has 50 tickets visible
- WHEN a `message.added` event arrives via SSE for ticket T1
- THEN ConversationStore SHALL be updated directly from the event payload
- AND zero HTTP GET requests SHALL be issued for other tickets
- AND TicketStore SHALL be updated if the event affects ticket metadata

#### Scenario: Per-ticket subscriptions removed

- GIVEN InboxService initialization
- WHEN `subscribeToTickets()` is called
- THEN no per-ticket Mercure callback with HTTP refetch SHALL be registered
- AND ticket updates SHALL come exclusively from MercureService's single event stream

---

### RT-STAB-008 — Connection Lifecycle Management [P1]

Components that initiate Mercure connections SHALL clean up on destruction.

#### Scenario: InboxPageComponent destroyed

- GIVEN the user navigates away from the inbox page
- WHEN `InboxPageComponent.ngOnDestroy()` is called
- THEN `mercure.disconnect()` SHALL be called
- AND the EventSource connection SHALL be closed
- AND no stale connections SHALL remain

#### Scenario: Re-navigation creates single connection

- GIVEN the user navigates to inbox, away, and back
- WHEN `ngOnInit()` calls `mercure.connect()`
- THEN exactly ONE EventSource connection SHALL exist
- AND no duplicate or leaked connections SHALL accumulate

---

### RT-STAB-009 — Exponential Retry with RxJS [P1]

MessageSenderService SHALL use RxJS `retryWhen` (or equivalent reactive operator) for send retries instead of nested `setTimeout` chains.

#### Scenario: Send fails and retries with exponential backoff

- GIVEN a message send fails on the first attempt
- WHEN the retry pipeline activates
- THEN the first retry SHALL occur after ~1000ms
- AND the second retry SHALL occur after ~2000ms
- AND after max retries exhausted, the message SHALL be marked `'failed'`
- AND no nested setTimeout callbacks SHALL exist

#### Scenario: Concurrent sends do not create retry storm

- GIVEN 3 messages fail simultaneously
- WHEN all 3 enter the retry pipeline
- THEN each message SHALL retry independently with its own backoff
- AND retries SHALL NOT synchronize or amplify each other

---

### RT-STAB-010 — Signals as Single Source of Truth [Architectural]

Signal-based stores (TicketStore, ConversationStore, ConnectionStore, UiStore) SHALL be the canonical state. BehaviorSubject-based state (StateService, InboxStore) SHALL be deprecated.

#### Scenario: Components read from signal stores

- GIVEN ConversationComponent renders the active conversation
- WHEN it reads messages and ticket data
- THEN data SHALL come from ConversationStore and TicketStore signals
- AND no data SHALL be read from StateService BehaviorSubjects

#### Scenario: ConversationPanelComponent marked deprecated

- GIVEN the ConversationPanelComponent exists
- WHEN a developer opens the file
- THEN a `@deprecated` comment SHALL be present with migration note
- AND the component SHALL NOT be removed in this sprint

---

### RT-STAB-011 — Event Ordering with Gap Detection [Architectural]

MercureService SHALL maintain per-ticket sequence tracking with gap detection. No buffering — gaps trigger immediate resync.

#### Scenario: Sequential events applied in order

- GIVEN events arrive with seq 1, 2, 3 for ticket T1
- WHEN each event arrives in order
- THEN each SHALL be applied and `expectedSeq` SHALL increment: 2 → 3 → 4

#### Scenario: Out-of-order event triggers resync

- GIVEN `expectedSeq = 5` for ticket T1
- WHEN event with seq=7 arrives (skipping 5 and 6)
- THEN a gap SHALL be detected
- AND targeted resync SHALL be triggered for `afterSequence=4`
- AND events 5, 6 SHALL be fetched and applied before event 7

---

### RT-STAB-012 — Meta Readiness Criteria [Architectural]

Sprint 13 (stress testing) SHALL NOT begin until ALL criteria below pass.

#### Scenario: End-to-end message delivery verified

- GIVEN the stabilized system with real Mercure events
- WHEN a message is sent from ComposerComponent
- THEN the optimistic message SHALL appear with `deliveryStatus: 'sending'`
- AND the SSE confirmation SHALL arrive
- AND `deliveryStatus` SHALL transition to `'sent'`

#### Scenario: State survives disconnect/reconnect

- GIVEN an active conversation with 5 messages
- WHEN the SSE connection drops for 5 seconds and reconnects
- THEN the ticket list SHALL match server state after resync
- AND the active conversation SHALL match server state
- AND no duplicate messages SHALL appear
- AND no messages SHALL be lost across the disconnect boundary

#### Scenario: Single store mutation per event confirmed

- GIVEN the MercureService event pipeline
- WHEN any event type is processed
- THEN exactly one store mutation SHALL occur per event
- AND verification SHALL be via automated test or code audit

---

## 3. Contracts — RealtimeEventContract v1

### 3.1 Event Shape (Both Sides)

```json
{
  "eventType": "ticket.created | ticket.status_changed | ticket.assigned | message.added | message.updated | conversation.updated",
  "entityId": "string (ID of the primary entity)",
  "sequence": "number (per-ticket monotonic counter)",
  "timestamp": "string (ISO 8601 UTC)",
  "payload": { }
}
```

### 3.2 Event Type Enum

| Value | Backend PHP Event | Trigger |
|---|---|---|
| `ticket.created` | `TicketCreatedEvent` | New ticket from inbound message |
| `ticket.status_changed` | `TicketStatusChangedEvent` | FSM transition |
| `ticket.assigned` | `TicketAssignedEvent` | Agent assignment |
| `message.added` | `MessageCreatedEvent` | New message (inbound or outbound) |
| `message.updated` | `MessageUpdatedEvent` | Message status change (delivered, read) |
| `conversation.updated` | `ConversationUpdatedEvent` | Conversation metadata change |

### 3.3 Payload Schemas by Event Type

**message.added:**
```json
{
  "messageId": "number",
  "ticketId": "number",
  "content": "string",
  "direction": "inbound | outbound",
  "senderName": "string | null",
  "createdAt": "string (ISO 8601)",
  "externalId": "string | null"
}
```

**ticket.created:**
```json
{
  "ticketId": "number",
  "contactId": "number",
  "conversationId": "number",
  "status": "string",
  "createdAt": "string (ISO 8601)"
}
```

**ticket.status_changed:**
```json
{
  "ticketId": "number",
  "previousStatus": "string",
  "newStatus": "string",
  "changedAt": "string (ISO 8601)"
}
```

### 3.4 Backend Adapter

`OutboxPublisherService` SHALL transform domain events into RealtimeEventContract v1 before publishing to Mercure. The adapter:
- Maps PHP class names → string enum values
- Uses camelCase for all fields
- Wraps domain-specific fields in `payload`
- Sets `entityId` to the primary entity's ID
- Sets `sequence` from `ticket.lastEventSeq`

---

## 4. State Model

### 4.1 Store Hierarchy (Single Source of Truth)

```
Signal Stores (CANONICAL)
├── TicketStore        — tickets[], activeTicketId, filter, metrics
├── ConversationStore  — messages[], loading, hasMore, messageCache
├── ConnectionStore    — status, eventCount, lastEventTime
└── UiStore            — hasNewMessages, isAtBottom

Deprecated (MIGRATE AWAY)
├── StateService       — BehaviorSubject-based (selectedTicketId$, tickets$, messages$)
└── InboxStore         — Deprecated chat list (TODO: remove)
```

### 4.2 State Ownership Rules

1. Each piece of state has exactly ONE canonical store
2. Components read from signal stores via `store.property()`
3. MercureService writes to signal stores only
4. No component SHALL maintain parallel state for the same data
5. Deprecated stores MAY be read during migration but SHALL NOT be written to by new code

---

## 5. Reconnection Protocol

```
1. SSE onerror fires
   ├─ connectionStore.setOffline()
   ├─ eventSource.close()
   └─ calculate delay: min(1000 * 2^(attempt-1), 30000)

2. setTimeout → connect()
   ├─ new EventSource(url, { withCredentials: true })
   └─ onopen fires:
       ├─ connectionStore.setOnline()
       ├─ reconnectAttempts = 0
       └─ FOR EACH ticket with active seqState:
           ├─ lastSeq = seqState.get(ticketId).lastSeq
           ├─ GET /api/tickets/{ticketId}/events?afterSequence={lastSeq}
           ├─ Sort response by sequence ASC
           ├─ FOR EACH event in response:
           │   ├─ Validate: event.sequence > lastSeq
           │   ├─ Apply to stores via handleEvent()
           │   └─ Update seqState: lastSeq = event.sequence
           └─ Resume normal SSE event processing

3. During active SSE (gap detection):
   ├─ expectedSeq = seqState.get(ticketId).lastSeq + 1
   ├─ IF event.sequence == expectedSeq → apply, increment
   ├─ IF event.sequence > expectedSeq → GAP:
   │   ├─ Trigger targeted resync: GET events?afterSequence={lastSeq}
   │   ├─ Apply missed events in order
   │   └─ Then apply the gap-triggering event
   └─ IF event.sequence < expectedSeq → drop (already processed)
```

---

## 6. Send Lifecycle — Optimistic Message State Machine

```
                    ComposerComponent.send()
                           │
                           ▼
                   ┌───────────────┐
                   │   CREATED     │  deliveryStatus: 'sending'
                   │  (optimistic) │  tempId assigned, in ConversationStore
                   └───────┬───────┘
                           │
              MessageSenderService.send()
                           │
                    ┌──────┴──────┐
                    │             │
                 SUCCESS       FAILURE
                    │             │
                    ▼             ▼
            ┌───────────┐  ┌───────────┐
            │   SENT    │  │  RETRYING │  deliveryStatus: 'sending'
            │           │  │           │  RxJS retryWhen with backoff
            └─────┬─────┘  └─────┬─────┘
                  │              │
                  │       ┌──────┴──────┐
                  │    SUCCESS      MAX_RETRIES
                  │       │              │
                  │       ▼              ▼
                  │  ┌───────────┐  ┌───────────┐
                  │  │   SENT    │  │  FAILED   │  deliveryStatus: 'failed'
                  │  └───────────┘  └─────┬─────┘
                  │                       │
                  │                 User acknowledges
                  │                       │
                  │                       ▼
                  │                ┌───────────┐
                  │                │ ROLLBACK  │  Removed from store
                  │                └───────────┘
                  │
            SSE confirmation arrives
                  │
                  ▼
          confirmMessage(tempId, confirmedData)
          Reconcile optimistic → server data
```

**Rules:**
- No message SHALL remain in `'sending'` state indefinitely
- Max retry count: 2 (configurable)
- Backoff: 1s, 2s (exponential)
- On permanent failure: `'failed'` status, user can retry or dismiss
- On dismiss: remove from store (rollback)
- On SSE confirmation: `confirmMessage()` merges tempId data with server data

---

## 7. Meta Readiness Criteria — Sprint 13 Gate

ALL of the following MUST pass before Sprint 13 (stress testing) begins:

| # | Criterion | Verification Method |
|---|---|---|
| 1 | Message sent from Composer → appears optimistic → SSE confirmation arrives → status transitions to `'sent'` | Manual + integration test |
| 2 | After disconnect (5s) + reconnect, ticket list matches server state | Manual + integration test |
| 3 | After disconnect + reconnect, active conversation matches server state | Manual + integration test |
| 4 | No duplicate messages after reconnect | Visual inspection + store assertion |
| 5 | No lost messages across disconnect/reconnect boundary | Sequence number verification |
| 6 | Event published by OutboxPublisherService is parsed and applied by frontend without errors | End-to-end test with real Mercure |
| 7 | Single store mutation per event (no dual dispatch) | Code audit + test assertion |
| 8 | TicketCreatedEvent contains correct ticket ID (not conversation ID) | Backend unit test |
| 9 | OutboxEvent with retry_count >= 5 is dead-lettered | Backend unit test |
| 10 | InboxPageComponent cleans up EventSource on destroy | Component lifecycle test |
| 11 | No per-ticket HTTP refetch on SSE events | Network inspection (zero GETs per event) |
| 12 | Backend ~66 existing tests still pass | CI pipeline |

**Gate rule**: If ANY criterion fails → Sprint 13 is BLOCKED. Fix first, re-verify, then proceed.

---

## 8. Out of Scope (Explicit)

- Stress testing / chaos simulation / load scenarios → Sprint 13
- Meta WhatsApp Cloud API integration
- New features or business entities
- ConversationPanelComponent removal (deprecated only)
- Frontend test suite creation (deferred to Sprint 13)
- Heartbeat/health check mechanism (deferred — not blocking)
- Seq state persistence across page refresh (deferred — not blocking)
- Jitter on reconnect backoff (deferred — not blocking)
