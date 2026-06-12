# Design: Sprint 12.5 — Realtime Stabilization (Pre-Stress)

> Fix 5 CRITICAL + 4 HIGH structural defects in the Mercure realtime pipeline.
> This is **architectural stabilization**, not bug fixing. The system works today
> only because `FakeWhatsappProvider` writes directly to stores, bypassing SSE.
> Goal: make the backend → Mercure → frontend pipeline function end-to-end so
> Sprint 13 (stress testing) can begin.

---

## 1. Architecture Overview

**Current state (broken)**: backend publishes `event_id`/`ticket_id`/`seq` (snake_case + PHP FQCN as type) → Mercure → frontend cannot parse → `handleMessage()` drops events as "old seq ignored" because `undefined > 0` is false. Two parallel dispatch paths (`applyStorePatch` + `handleEvent`) cause duplicate mutations. No replay endpoint on reconnect. No ticket-ID fix in `HandleInboundMessageUseCase`. No dead-letter on outbox retry exhaustion. Composer never reaches `MessageSenderService`. InboxService issues N+1 HTTP GETs on every SSE event.

**Target state**: one event contract (`RealtimeEventContract v1`, camelCase, string-typed), one dispatch path, per-ticket gap state machine, sequence-based replay endpoint, dead-letter on retry exhaustion, complete send lifecycle, single canonical TicketStore, lifecycle-safe InboxPageComponent.

```
[Backend] Domain Event → EventCollector → OutboxEvent (DB)
              ↓ (OutboxPublisherService)
         EventTypeMapper + RealtimeEventFactory → contract JSON
              ↓ (HTTP POST Mercure hub)
         SSE → MercureService.handleMessage()
              ↓
         eventId dedup → per-ticket seqState gap check → handleEvent() (single path)
              ↓
         TicketStore | ConversationStore | ConnectionStore | UiStore
              ↑
[Frontend]  Composer → MessageSenderService → SendMessageUseCase → ProviderInterface
              ↓ (provider returns or fails)
         confirmMessage(tempId) | RetryMessageUseCase(retryWithBackoff) | updateMessageStatus('failed')
```

**What changes vs. current state**:

| Layer | Before | After |
|---|---|---|
| Outbox payload (DB row) | snake_case + FQCN type | unchanged (backward compat) + adapter transforms on publish |
| Outbox payload (Mercure wire) | raw `payload` array | `RealtimeEventContract v1` JSON |
| Resync endpoint | does not exist | `GET /api/tickets/{id}/events?afterSequence={n}` |
| Outbox table | no `status` column | adds `status` ENUM (pending/published/dead_letter) + index `(aggregate_id, sequence)` |
| MercureService dispatch | `applyStorePatch()` + `handleEvent()` | `handleEvent()` only |
| MercureService seq state | `{ lastSeq }` flat | `{ lastSeq, state: 'connected'\|'gap_detected'\|'resyncing' }` per ticket |
| ComposerComponent | emits output, no actual send | output → `MessageSenderService.send()` in parent |
| InboxService | per-ticket HTTP refetch callbacks | removed; uses `MercureService.events$` |
| InboxPageComponent | no `ngOnDestroy` | adds `ngOnDestroy` → `mercure.disconnect()` |
| MessageSenderService | nested `setTimeout` retry | `retryWithBackoff` from `retry.util.ts` |
| RetryMessageUseCase | owns duplicate `messageCache` | removes cache; reads from `ConversationStore` |
| Reconnect circuit | infinite backoff | 10-failure circuit breaker → "Retry Now" button |

---

## 2. Diagram 1 — Contract Mapping (RealtimeEventContract v1)

```
   PHP DOMAIN EVENT               PERSISTED (outbox_events.payload)           WIRE (Mercure SSE)              TYPESCRIPT (MercureService)
   ──────────────────             ───────────────────────────────            ──────────────────────          ───────────────────────────
  ┌────────────────────┐  collect  ┌───────────────────────────────┐ publish  ┌─────────────────────────┐ JSON  ┌──────────────────────────┐
  │ TicketCreatedEvent │ ────────▶ │ event_id: "01JQX..."         │ ───────▶ │ eventId: "01JQX..."     │ ────▶ │ interface RealtimeEvent  │
  │  ticketId: 42      │           │ type: "...\TicketCreatedEvent"│          │ sequence: 1             │       │   eventId: string        │
  │  seq: 1            │           │ ticket_id: 42                 │          │ timestamp: "2026-..."   │       │   sequence: number       │
  │  occurredAt        │           │ occurred_at: "2026-..."       │          │ eventType: "ticket.     │       │   timestamp: string      │
  │  type: FQCN        │           │ seq: 1                        │          │   created"              │       │   eventType:             │
  │  status            │           │ status: "new"                 │          │ ticketId: "42"          │       │     RealtimeEventType    │
  │  priority          │           │ priority: 1                   │          │ payload: {              │       │   ticketId?: string      │
  └────────────────────┘           └───────────────────────────────┘          │   ticketId: 42,          │       │   conversationId?: string│
           │                                                                   │   contactId: 7,         │       │   payload: T             │
  ┌────────────────────┐                                                       │   conversationId: 3,    │       └──────────────────────────┘
  │ MessageCreatedEvent│                                                       │   status: "new",        │                │
  │  direction         │                                                       │   createdAt: "2026-..." │                │
  │  messageId         │                                                       │ }                        │                │
  │  ...               │                                                       └─────────────────────────┘                │
  └────────────────────┘                                                                │                                     │
           │                                                                           │                                     │
  ┌────────────────────┐                                                                │                                     │
  │ TicketStatusChanged│                                                       EventTypeMapper:                          │
  │  prevStatus, new   │                                                       FQCN → enum string                       │
  │  ...               │                                                       (single source of truth)                   │
  └────────────────────┘                                                                │                                     │
                                                                                        ▼                                     ▼
                                                                       RealtimeEventFactory::build()                Strict TypeScript
                                                                       returns array matching                      (no `as any`,
                                                                       RealtimeEventContract                       no adapter)
```

**Exact field mapping table** (single source of truth for both sides):

| Domain event (PHP) | outbox_events.event_type (stored) | eventType (wire, camelCase) | ticket.idx source | payload schema (wire `payload`) |
|---|---|---|---|---|
| `TicketCreatedEvent` | `App\Domain\Event\TicketCreatedEvent` | `ticket.created` | `aggregate_id` | `{ ticketId, contactId, conversationId, status, priority, createdAt }` |
| `TicketUpdatedEvent` (status only) | `App\Domain\Event\TicketUpdatedEvent` | `ticket.status_changed` | `aggregate_id` | `{ ticketId, previousStatus, newStatus, changedAt }` |
| `TicketUpdatedEvent` (assign) | `App\Domain\Event\TicketUpdatedEvent` | `ticket.assigned` | `aggregate_id` | `{ ticketId, assigneeId, assignedAt }` |
| `MessageCreatedEvent` | `App\Domain\Event\MessageCreatedEvent` | `message.added` | `aggregate_id` | `{ messageId, ticketId, content, direction, senderName, createdAt, externalId }` |
| `MessageUpdatedEvent` (TBD) | `App\Domain\Event\MessageUpdatedEvent` | `message.updated` | `aggregate_id` | `{ messageId, ticketId, status, updatedAt }` |
| `ConversationUpdatedEvent` | `App\Domain\Event\ConversationUpdatedEvent` | `conversation.updated` | `aggregate_id` | `{ conversationId, ticketId, lastMessageAt }` |

**Critical rules** (no implicit conversions, no adapters, no `as any`):

1. PHP stores its own snake_case payload in `outbox_events.payload` (unchanged — Sprint 09B tests assert on this).
2. `RealtimeEventFactory` transforms the row into camelCase at the **Mercure boundary only**.
3. The `payload` of the wire event contains **only the contract schema** above — never the raw stored payload.
4. `eventType` is the **string enum value**, never a PHP FQCN.
5. Frontend `RealtimeEvent` interface matches the wire shape 1:1. No `applyStorePatch` "legacy shape" path.

**TypeScript contract** (the literal interface used by `MercureService.handleMessage()`):

```ts
export enum RealtimeEventType {
  TicketCreated = 'ticket.created',
  TicketStatusChanged = 'ticket.status_changed',
  TicketAssigned = 'ticket.assigned',
  MessageAdded = 'message.added',
  MessageUpdated = 'message.updated',
  ConversationUpdated = 'conversation.updated',
}

export interface RealtimeEvent<T = unknown> {
  eventId: string;          // ULID, unique per event
  sequence: number;         // per-ticket monotonic
  timestamp: string;        // ISO 8601 UTC
  eventType: RealtimeEventType;
  ticketId?: string;        // present for ticket-scoped events
  conversationId?: string;  // present when relevant
  payload: T;               // see schema table above
}
```

**PHP mapping (the literal class that the publisher uses)**:

```php
final readonly class RealtimeEventFactory
{
    public function __construct(private EventTypeMapper $mapper) {}

    public function build(OutboxEvent $event): array
    {
        $stored = $event->getPayload(); // snake_case from DB (unchanged)
        $type   = $this->mapper->toEnum($event->getEventType()); // FQCN → enum

        return [
            'eventId'   => $stored['event_id'],
            'sequence'  => (int) $stored['seq'],
            'timestamp' => $stored['occurred_at'],
            'eventType' => $type,
            'ticketId'  => (string) $event->getAggregateId(),
            'payload'   => $this->payloadFor($type, $stored, $event->getAggregateId()),
        ];
    }
}
```

`payloadFor()` is a `match` over the 6 enum values; each branch returns the explicit schema above. Adding a new event type requires (1) a new `case` in `EventTypeMapper::toEnum()`, (2) a new branch in `payloadFor()`, and (3) a new `RealtimeEventType` enum value. Miss any of the three and the test `test_event_type_enum_mapping` fails.

---

## 3. Diagram 2 — Send Pipeline (Composer → Provider → Mercure → Store)

```
   USER ACTION                              FRONTEND (apps/web)                                          BACKEND (apps/api)                       MERCURE
   ───────────                              ─────────────────────                                         ──────────────────────                    ───────
   Type "Hello" + Enter                       ComposerComponent.send(text)
        │                                            │ tempId = generateTempId()     // "tmp-abc123"
        │                                            │ ConversationStore.addOptimisticMessage(tempId, text, ticketId)
        │                                            │      deliveryStatus: 'sending'
        │                                            │      messageCache.set(tempId, {content, ticketId})
        │                                            │
        │                                            ▼
        │                                    ConversationComponent.onSendMessage()  // parent container
        │                                            │ 1. emit tempId via output() (input clear)
        │                                            │ 2. messageSender.send(ticketId, content, tempId)
        │                                            ▼
        │                                    MessageSenderService.send()
        │                                            │ (no setTimeout here)
        │                                            │ pipe SendMessageUseCase.execute() through retryWithBackoff()
        │                                            ▼
        │                                    SendMessageUseCase.execute({ticketId, content, tempId})
        │                                            │
        │                                            ▼
        │                                    ProviderInterface.sendMessage(ticketId, content)
        │                                            │  → FakeWhatsappProvider: HTTP POST /api/tickets/{id}/messages
        │                                            │  → Real provider: WhatsApp Cloud API
        │                                            │
        │            ┌───────────────────────────────┼───────────────────────────────┐
        │            │                               │                               │
        │            ▼ SUCCESS                       ▼ FAILURE (transient)           ▼ FAILURE (permanent)
        │     output.message                       retryWithBackoff:               retryWithBackoff exhausts
        │     deliveryStatus: 'sent'               1st retry: ~1000ms              (maxRetries=2, total 3 tries)
        │            │                            2nd retry: ~2000ms                      │
        │            │                                    │                               ▼
        │            │                                    │                       ConversationStore.updateMessageStatus(tempId, 'failed')
        │            │                                    │                       sendResults.next({tempId, success: false})
        │            │                                    ▼                       UiStore.notifyNewMessages()  // show "Retry"/"Dismiss"
        │            │                            [if any retry succeeds: same   User can: retry → messageSender.retryByTempId(tempId)
        │            │                             path as SUCCESS branch]                  OR dismiss → messageSender.removeFailed(tempId)
        │            │                                                                                │
        │            ▼                                                                                ▼
        │     ConversationStore.confirmMessage(tempId, serverMessage)                     ConversationStore.removeMessage(tempId)
        │            │ deliveryStatus: 'sent'                                                messageCache.delete(tempId)
        │            │ tempId preserved for tracking
        │            │
        │            ▼
        │     ─── ASYNC: backend emits message.added event (out of band) ───
        │                                                                                ┌──────────────────────────┐
        │                                                                                │ HandleInboundMessage:     │
        │                                                                                │   MessageCreatedEvent     │
        │                                                                                │   → OutboxEvent (DB)      │
        │                                                                                │   → OutboxPublisher       │
        │                                                                                │   → Mercure SSE           │
        │                                                                                └────────────┬─────────────┘
        │                                                                                             │
        │            ┌────────────────────────────────────────────────────────────────────────────────┘
        │            ▼
        │     MercureService.handleMessage(event)  // SINGLE DISPATCH PATH
        │            │
        │            ├─ eventId dedup (Set)        → if dup, return
        │            ├─ per-ticket seqState       → if gap, trigger resync
        │            │                                  (see Diagram 3)
        │            └─ handleEvent(event)         → ConversationStore.confirmMessage(tempId, ...)
        │                                            (re-merge if SSE arrives after local confirm;
        │                                            deduped by tempId match)

   CORRELATION IDENTIFIERS (end-to-end):
     tempId:        "tmp-abc123"     flows Composer → MessageSenderService → ProviderInterface
                                            ↓
                                            on response: ConversationStore.confirmMessage(tempId, serverMessage)
                                            on SSE:      ConversationStore.confirmMessage(tempId, ...)  // idempotent

     serverId:      456              assigned by backend, present in SSE message.added.payload.messageId
                                            ↓
                                            ConversationStore stores both tempId (for tracking) and serverId/message_id (for API calls)

     eventId:       "01JQX..."       ULID, assigned at domain event collection; enables idempotency dedup across reconnects
```

**Correlation rules**:

1. `tempId` is generated by `generateTempId()` in the frontend, passed through `SendMessageInput.tempId`, attached to the provider response, and stored in `ConversationStore.messageCache`. It survives across retries.
2. `serverId` (the backend's `message_id`) is assigned by `HandleInboundMessageUseCase`, returned in the provider response, and also delivered via the SSE `message.added` event.
3. `eventId` is the ULID from the `MessageCreatedEvent`, used only for idempotency. Not user-visible.
4. **SSE confirmation dedup**: if `handleEvent` receives a `message.added` whose `tempId` matches an existing optimistic message in `ConversationStore`, it merges (replaces payload but preserves `tempId`). If the optimistic message has already been confirmed, the SSE event is dropped (the message is already in the canonical state).

---

## 4. Diagram 3 — Reconnect/Resync Protocol

### 4.1 Per-Ticket State Machine

```
                       ┌───────────────────┐
                       │                   │  seq > expectedSeq (gap detected on active SSE
                       │    CONNECTED      │  OR on reconnect after event loss)
                       │                   │ ────────────────────────────────────────┐
                       │ lastSeq=N         │                                        │
                       │ state='connected' │                                        ▼
                       └────────┬──────────┘                              ┌──────────────────┐
                                │                                         │                  │
                                │ onopen fires (initial OR reconnect)    │  GAP_DETECTED    │
                                │ AND seqState has any tickets            │                  │
                                ▼                                         │ state=           │
                       ┌───────────────────┐                              │  'gap_detected'  │
                       │                   │                              │                  │
                       │    RESYNCING      │                              │ pause processing │
                       │                   │                              │ for this ticket  │
                       │ FOR EACH ticket  │                              │ (other tickets   │
                       │   with seqState:  │                              │  unaffected)     │
                       │   GET /events?    │                              └────────┬─────────┘
                       │     afterSequence │                                       │
                       │   =lastSeq        │                                       │ immediately trigger
                       │   apply events    │                                       │ targeted resync
                       │   in seq order    │                                       │ for THIS ticket only
                       │                   │◀──────────────────────────────────────┘
                       └────────┬──────────┘
                                │ all missed events applied
                                ▼
                       ┌───────────────────┐
                       │                   │
                       │    CONNECTED      │  resume normal SSE
                       │                   │  processing
                       │ lastSeq=highest   │
                       │ state='connected' │
                       └───────────────────┘


   TRANSITIONS (NEVER broken):
     CONNECTED → GAP_DETECTED     when event.seq > expectedSeq (where expectedSeq = lastSeq + 1)
     GAP_DETECTED → RESYNCING     immediately on detection
     RESYNCING → CONNECTED        when all missed events applied OR resync returns empty (gap confirmed)
     RESYNCING → GAP_DETECTED     if resync returns a 5xx or network error (retry with backoff)

   PER-TICKET SCOPE: a gap on ticket T1 does NOT pause events for ticket T2.
   Each ticket has its own entry in seqState: Map<string, { lastSeq, state }>.

   INVALID: "CONNECTED → keep processing events, ignoring gap"
            (this is what the current code does and what breaks consistency)
```

### 4.2 Reconnect Sequence (full)

```
   TIME  EVENT
   ────  ─────
   T0    MercureService.connect()      // initial
         │
         │ new EventSource(ticket/*)
         │
   T1    onopen
         │ ├─ connectionStore.setOnline()
         │ ├─ reconnectAttempts = 0
         │ ├─ for each ticket in seqState.keys():
         │ │     GET /api/tickets/{ticketId}/events?afterSequence={lastSeq}
         │ │     apply each event via handleEvent()
         │ └─ resume normal SSE processing
         │
   T2    [NORMAL] events arrive
         │
   T3    onerror
         │ ├─ connectionStore.setOffline()
         │ ├─ eventSource.close()
         │ ├─ delay = min(1000 * 2^(attempt-1), 30000)
         │ └─ setTimeout(connect, delay)
         │
   T4    [RECONNECT] connect() called
         │ │
         │ │ new EventSource(...)
         │ │
   T5    onopen
         │ ├─ connectionStore.setOnline()
         │ ├─ reconnectAttempts = 0
         │ ├─ for each ticket in seqState.keys():
         │ │     GET /api/tickets/{ticketId}/events?afterSequence={lastSeq}
         │ │     [some events were missed during T3→T5]
         │ │     apply each missed event via handleEvent() in sequence order
         │ └─ resume normal SSE processing
         │
         ▼
         [STEADY STATE]

   GAP DURING ACTIVE SSE (no reconnect):
   T6    event arrives with seq = 14
         │ expectedSeq for T1 = 11 (lastSeq=10)
         │ GAP: 14 > 11
         │ ├─ state = 'gap_detected' for T1
         │ ├─ DO NOT apply event 14 yet
         │ └─ trigger resync: GET /events?afterSequence=10
         │
   T7    resync returns events [11, 12, 13]
         │ apply each via handleEvent() in order
         │ update lastSeq = 13
         │ state = 'resyncing' → 'connected'
         │
   T8    NOW apply event 14 (re-enqueue if buffered, or fetch fresh)
         │ lastSeq = 14
         ▼
         [STEADY STATE]

   CIRCUIT BREAKER (10 consecutive failed reconnects):
   T9    attempt 11 fails
         │ ├─ connectionStore.setPermanentFailure()
         │ ├─ STOP auto-retry
         │ └─ DebugPanelComponent shows "Retry Now" button
         │
   T10   user clicks "Retry Now"
         │ └─ connect() called with reset attempt counter
         ▼
         [RESUME NORMAL RECONNECT FLOW]
```

### 4.3 Per-Ticket State Type

```ts
// apps/web/src/app/core/realtime/mercure.service.ts

interface TicketSeqState {
  lastSeq: number;                                          // highest sequence applied
  state: 'connected' | 'gap_detected' | 'resyncing';        // per-ticket state
  inFlightResync?: AbortController;                         // cancel on new gap or disconnect
}

private readonly seqState = new Map<string, TicketSeqState>();

private transitionTo(ticketId: string, next: TicketSeqState['state']): void {
  const cur = this.seqState.get(ticketId) ?? { lastSeq: 0, state: 'connected' as const };
  this.seqState.set(ticketId, { ...cur, state: next });
}
```

### 4.4 Backend Resync Endpoint Contract

```http
GET /api/tickets/{ticketId}/events?afterSequence={n}
Authorization: Bearer <jwt with ticket topic>
```

**Response (200)** — array of `RealtimeEventContract v1` events, sorted by `sequence` ASC, capped at 100:

```json
{
  "data": [
    { "eventId": "01JQ...", "sequence": 11, "timestamp": "...", "eventType": "message.added",
      "ticketId": "42", "payload": { ... } },
    { "eventId": "01JQ...", "sequence": 12, ... }
  ],
  "meta": { "count": 2, "highestSequence": 12, "hasMore": false }
}
```

**Query** (in `TicketEventsController::listSince()`):
```sql
SELECT id, payload, sequence, status
FROM outbox_events
WHERE aggregate_id = :ticketId
  AND sequence > :afterSequence
  AND status IN ('pending', 'published')
ORDER BY sequence ASC
LIMIT 100
```

**Errors**:
- `401` — JWT missing or invalid.
- `403` — JWT topic does not include `ticket/{ticketId}`.
- `404` — ticket not found.
- `400` — `afterSequence` is not an integer.

---

## 5. Diagram 4 — Store Ownership

```
   CANONICAL (signals — write here, read from here)
   ═════════════════════════════════════════════════
   ┌────────────────────────────────────────────────────────────┐
   │ TicketStore (apps/web/src/app/core/stores/ticket.store.ts) │
   │  SINGLE SOURCE OF TRUTH for ticket list & metadata         │
   │  ──────────────────────────────────────────────────────    │
   │  tickets:        Signal<ReadonlyArray<Ticket>>             │
   │  activeTicket:   Signal<Ticket | null>                     │
   │  filter:         Signal<TicketFilter>                      │
   │  metrics:        Signal<TicketMetrics>                     │
   │  filterCounts:   Computed<Record<TicketFilter, number>>    │
   │  totalUnread:    Computed<number>                          │
   │  loading:        Signal<boolean>                           │
   │                                                            │
   │  Writers: TicketApiService, MercureService.handleEvent()   │
   │  Readers: TicketListComponent, ConversationComponent,     │
   │           TicketFiltersComponent, DebugPanelComponent      │
   └────────────────────────────────────────────────────────────┘

   ┌────────────────────────────────────────────────────────────┐
   │ ConversationStore                                          │
   │  (apps/web/src/app/core/stores/conversation.store.ts)     │
   │  ──────────────────────────────────────────────────────    │
   │  messages:       Signal<ReadonlyArray<BridgeMessage>>      │
   │  activeTicketId: Signal<string | null>                    │
   │  loading:        Signal<boolean>                           │
   │  hasMore:        Signal<boolean>                           │
   │  messageCache:   Map<tempId, {content, ticketId}>          │ ← SINGLE CACHE
   │                                                            │
   │  Writers: MessageApiService, MessageSenderService,        │
   │           MercureService.handleEvent()                     │
   │  Readers: MessageBubbleComponent, ConversationComponent,  │
   │           ComposerComponent, RetryMessageUseCase           │ ← reads cache only
   └────────────────────────────────────────────────────────────┘

   ┌────────────────────────────────────────────────────────────┐
   │ ConnectionStore                                            │
   │  (apps/web/src/app/core/stores/connection.store.ts)        │
   │  AUXILIARY: connection health ONLY                         │
   │  ──────────────────────────────────────────────────────    │
   │  status:         Signal<'online' | 'reconnecting' |        │
   │                       'offline' | 'permanent_failure'>    │
   │  eventCount:     Signal<number>                            │
   │  lastEventTime:  Signal<number | null>                     │ ← NEW
   │  reconnectCount: Signal<number>                            │
   │                                                            │
   │  Writers: MercureService (single source)                   │
   │  Readers: DebugPanelComponent, ConnectionIndicator         │
   └────────────────────────────────────────────────────────────┘

   ┌────────────────────────────────────────────────────────────┐
   │ UiStore                                                    │
   │  (apps/web/src/app/core/stores/ui.store.ts)                │
   │  AUXILIARY: UI ephemeral state ONLY                        │
   │  ──────────────────────────────────────────────────────    │
   │  hasNewMessages: Signal<boolean>                           │
   │  isAtBottom:     Signal<boolean>                           │
   │                                                            │
   │  Writers: MercureService (notifyNewMessages),              │
   │           ConversationComponent (setIsAtBottom)            │
   │  Readers: ConversationComponent, NewMessagesIndicator      │
   └────────────────────────────────────────────────────────────┘


   DEPRECATED (READ-ONLY — no new writes; migrate callers)
   ═══════════════════════════════════════════════════════
   ┌────────────────────────────────────────────────────────────┐
   │ StateService (BehaviorSubject-based)                       │
   │  (apps/web/src/app/core/services/state.service.ts)         │
   │  STATUS: DEPRECATED                                         │
   │  WHY: Replaced by TicketStore + ConversationStore.         │
   │       BehaviorSubject API does not match signals pattern.  │
   │  MIGRATION: any new code reads from TicketStore/           │
   │             ConversationStore. StateService is deleted in   │
   │             a follow-up sprint.                            │
   └────────────────────────────────────────────────────────────┘

   ┌────────────────────────────────────────────────────────────┐
   │ InboxStore                                                 │
   │  (apps/web/src/app/core/stores/inbox.store.ts)             │
   │  STATUS: DEPRECATED (already has "TODO: Migrate" comment)  │
   │  WHY: Duplicate of TicketStore using Chat model.          │
   │  MIGRATION: superseded by TicketStore. No current callers.  │
   └────────────────────────────────────────────────────────────┘

   ┌────────────────────────────────────────────────────────────┐
   │ ConversationPanelComponent                                 │
   │  (apps/web/src/app/features/inbox/components/              │
   │   conversation-panel/conversation-panel.component.ts)     │
   │  STATUS: DEPRECATED (NOT removed in this sprint)           │
   │  WHY: Uses legacy StateService + MessageApiService;       │
   │       does not use optimistic message flow.                │
   │  MIGRATION: file annotated with @deprecated. Conversation  │
   │             Component (singular) is the canonical container.│
   │             Removal deferred to a follow-up sprint.        │
   └────────────────────────────────────────────────────────────┘


   RULES (enforced in code review + code audit checklist):
   ─────────────────────────────────────────────────────
   1. Each piece of state has exactly ONE canonical owner.
   2. Components read from signal stores via store.property() (auto-tracking).
   3. MercureService is the ONLY writer to TicketStore/ConversationStore
      from the realtime pipeline.
   4. No component maintains parallel state for the same data.
   5. No new code reads from StateService or InboxStore.
   6. ConversationPanelComponent is imported nowhere in new code.
```

---

## 6. Component Design

### 6.1 MercureService (refactored)

**File**: `apps/web/src/app/core/realtime/mercure.service.ts`

**Public surface** (unchanged signature, cleaner internals):
```ts
readonly events$: Observable<RealtimeEvent>;          // global event stream
readonly isConnected$: Observable<boolean>;
readonly connectionState: Signal<ConnectionState>;
readonly reconnectCount: Signal<number>;

connect(): void;
disconnect(): void;
getSeqForTicket(ticketId: string): number;            // for debugging
```

**Internal state**:
```ts
private eventSource: EventSource | null;
private reconnectAttempts = 0;
private readonly processedEventIds = new Set<string>();        // dedup, cap 1000
private readonly seqState = new Map<string, TicketSeqState>(); // per-ticket
private reconnectTimeoutId: ReturnType<typeof setTimeout> | null;
```

**Methods (refactored)**:
- `connect()` — opens `EventSource('ticket/*')`. On `onopen`: calls `resyncAllTickets()`.
- `disconnect()` — closes EventSource, clears `seqState` and `processedEventIds`, cancels pending resyncs.
- `private handleMessage(event: MessageEvent)` — single dispatch path:
  1. Parse JSON
  2. Update `lastEventTime` signal
  3. `eventId` dedup
  4. Per-ticket gap check (see 4.1) → may trigger resync, may drop event
  5. Call `handleEvent(parsed)` (single path)
  6. Emit on `events$`
- `private handleEvent(event: RealtimeEvent)` — strict switch over `eventType`:
  - `message.added` → `conversationStore.addMessage(payload)` (or `confirmMessage` if `tempId` matches)
  - `ticket.created` → `ticketStore.addOrUpdateTicket(payload)`
  - `ticket.status_changed` → `ticketStore.addOrUpdateTicket(payload)`
  - `ticket.assigned` → `ticketStore.addOrUpdateTicket(payload)`
  - `message.updated` → `conversationStore.updateMessage(payload.messageId, payload)`
  - `conversation.updated` → no-op (metadata only; future use)
  - Unknown → log warning, drop
  - End: `connectionStore.incrementEventCount()`
- `private resyncTicket(ticketId: string)` — fetches `GET /api/tickets/{id}/events?afterSequence={lastSeq}`, applies in order.
- `private resyncAllTickets()` — called from `onopen`; iterates `seqState.keys()`.
- `private handleError()` — exponential backoff (1s, 2s, 4s, …, cap 30s) + circuit breaker at 10.

**Removed entirely**:
- `applyStorePatch(ticketId, event)` — DELETED. Single dispatch = single path.
- `subscriptions: Map<string, callback>` — DELETED. Replaced by `events$` observable.
- `subscribe(ticketId, callback)` — DELETED. No callers after InboxService refactor.

### 6.2 InboxService (slimmed)

**File**: `apps/web/src/app/features/inbox/services/inbox.service.ts`

**Removed**:
- `subscribeToTickets(tickets)` — DELETED.
- `handleRealtimeEvent(ticketId, event)` — DELETED.
- `getSelectedTicketId()` using `take(1)` — replaced by `state.selectedTicketIdSubject.getValue()` if needed, but `TicketStore.activeTicket()` is preferred.

**Kept (refactored to use signal stores)**:
- `initialize()` — calls `mercure.connect()` and `ticketStore.loadTickets(...)`.
- `loadTickets()` — uses `TicketApiService.getTickets()`, writes to `TicketStore`.
- `selectTicket(ticketId)` — sets `TicketStore.activeTicket`, triggers `ConversationStore.loadMessages()`.
- `loadMessages(ticketId)` — uses `MessageApiService.getMessages()`, writes to `ConversationStore`.
- `sendMessage(content)` — REMOVED (ComposerComponent → MessageSenderService directly).
- `transitionTicket(ticketId, newStatus)` — kept; uses `WorkflowApiService`.

### 6.3 InboxPageComponent (lifecycle fix)

**File**: `apps/web/src/app/features/inbox/pages/inbox-page/inbox-page.component.ts`

```ts
export class InboxPageComponent implements OnInit, OnDestroy {
  private readonly mercure = inject(MercureService);
  private readonly ticketStore = inject(TicketStore);

  ngOnInit(): void {
    this.mercure.connect();
  }

  ngOnDestroy(): void {
    this.mercure.disconnect();          // closes EventSource, clears state
  }
}
```

### 6.4 ConversationComponent (parent — wires send)

**File**: `apps/web/src/app/features/inbox/containers/conversation/conversation.component.ts`

`onSendMessage()` (refactored):
```ts
private readonly messageSender = inject(MessageSenderService);

onSendMessage(): void {
  const ticket = this.activeTicket();
  if (!ticket) return;
  const text = this.composerText().trim();   // read from ComposerComponent via input
  if (!text) return;
  this.messageSender.send(ticket.ticket_id, text);
  this.composerText.set('');
  this.scrollToBottom();
}
```

**Removal of broken logic**:
- The current `onSendMessage()` only calls `scrollToBottom()`. Replaced with the above.

### 6.5 ComposerComponent (slimmed)

**File**: `apps/web/src/app/features/inbox/components/composer/composer.component.ts`

**Removed**:
- `private readonly messageApi = inject(MessageApiService);` — DELETED (unused — M-5).
- `private readonly generateTempId` import — DELETED. `tempId` is now generated in `MessageSenderService.send()`.

**Kept (signature unchanged)**:
- `chatId = input<string>('')`
- `sendMessage = output<void>()`
- `text = signal('')`
- `onKeyDown(event)` — calls `send()`.
- `autoResize()`
- `send()` — only emits `sendMessage`; does NOT create optimistic message locally (delegated to `MessageSenderService`).

### 6.6 MessageSenderService (refactored to RxJS retry)

**File**: `apps/web/src/app/features/inbox/services/message-sender.service.ts`

**Replaced `setTimeout` chains with `retryWithBackoff`** from `core/utils/retry.util.ts`:

```ts
import { retryWithBackoff } from '../../../core/utils/retry.util';

send(ticketId: string, content: string, tempId = generateTempId()): string {
  this.conversationStore.addOptimisticMessage(tempId, content, ticketId);

  retryWithBackoff(
    this.sendUseCase.execute({ ticketId, content, tempId }),
    { maxRetries: 2, initialDelayMs: 1000, maxDelayMs: 5000 }
  ).subscribe({
    next: (output) => {
      this.conversationStore.confirmMessage(tempId, /*...*/);
      this.sendResults.next({ tempId, success: true });
    },
    error: () => {
      this.conversationStore.updateMessageStatus(tempId, 'failed');
      this.sendResults.next({ tempId, success: false, error: 'Send failed' });
    },
  });

  return tempId;
}
```

**Removed**:
- `retryByTempId()` nested `setTimeout` recursion — replaced by `RetryMessageUseCase.execute()` (which itself uses `retryWithBackoff` if needed; the spec says "first retry after 1s, second after 2s").

### 6.7 RetryMessageUseCase (cache removed)

**File**: `apps/web/src/app/core/application/messaging/retry-message.use-case.ts`

**Removed**:
- `private messageCache = new Map<string, ...>()` — DELETED (M-7).
- `registerFailedMessage()` — DELETED. Caller passes `originalContent` and `ticketId` directly.

**Refactored `execute()`**:
```ts
execute(input: RetryMessageInput): Observable<RetryMessageOutput> {
  // No cache lookup. Inputs are explicit.
  return retryWithBackoff(
    this.provider.sendMessage(input.ticketId, input.originalContent),
    { maxRetries: 1, initialDelayMs: 1000 }
  ).pipe(/* same reconciliation as before */);
}
```

Caller (ConversationComponent.onRetry) populates input from `ConversationStore.getCachedMessage(tempId)`.

### 6.8 ConnectionStore (extended)

**File**: `apps/web/src/app/core/stores/connection.store.ts`

**New**:
- `lastEventTime = signal<number | null>(null)` — set by `MercureService.handleMessage()`.
- `setPermanentFailure()` — sets `status = 'permanent_failure'`.

### 6.9 DebugPanelComponent (circuit-breaker UI)

**File**: `apps/web/src/app/features/inbox/components/debug-panel/debug-panel.component.ts`

**New**:
- "Retry Now" button shown when `connectionStore.status() === 'permanent_failure'`.
- On click: emits `retryMercure` output → InboxPageComponent calls `mercure.connect()` with `reconnectAttempts = 0` (new public method `forceReconnect()` on `MercureService`).

---

## 7. Backend Design

### 7.1 New Services

#### EventTypeMapper (`apps/api/src/Application/Realtime/EventTypeMapper.php`)

```php
final readonly class EventTypeMapper
{
    private const MAP = [
        \App\Domain\Event\TicketCreatedEvent::class      => RealtimeEventType::TicketCreated,
        \App\Domain\Event\TicketUpdatedEvent::class      => RealtimeEventType::TicketStatusChanged, // when status only
        \App\Domain\Event\MessageCreatedEvent::class     => RealtimeEventType::MessageAdded,
        \App\Domain\Event\MessageUpdatedEvent::class     => RealtimeEventType::MessageUpdated,
        \App\Domain\Event\ConversationUpdatedEvent::class=> RealtimeEventType::ConversationUpdated,
    ];

    public function toEnum(string $phpClassName): string
    {
        if (!isset(self::MAP[$phpClassName])) {
            throw new \InvalidArgumentException("Unknown event class: $phpClassName");
        }
        return self::MAP[$phpClassName];
    }

    public function isTicketAssigned(string $phpClassName, array $payload): bool
    {
        // TicketUpdatedEvent with assigneeId → ticket.assigned
        return $phpClassName === \App\Domain\Event\TicketUpdatedEvent::class
            && isset($payload['assignee_id']);
    }
}
```

#### RealtimeEventType (enum)

```php
enum RealtimeEventType: string
{
    case TicketCreated         = 'ticket.created';
    case TicketStatusChanged   = 'ticket.status_changed';
    case TicketAssigned        = 'ticket.assigned';
    case MessageAdded          = 'message.added';
    case MessageUpdated        = 'message.updated';
    case ConversationUpdated   = 'conversation.updated';
}
```

#### RealtimeEventFactory (`apps/api/src/Application/Realtime/RealtimeEventFactory.php`)

```php
final readonly class RealtimeEventFactory
{
    public function __construct(private EventTypeMapper $mapper) {}

    public function build(\App\Domain\Outbox\OutboxEvent $event): array
    {
        $stored = $event->getPayload();
        $phpClass = $event->getEventType();
        $enumValue = $this->resolveEnum($phpClass, $stored);

        return [
            'eventId'   => (string) $stored['event_id'],
            'sequence'  => (int) $stored['seq'],
            'timestamp' => $stored['occurred_at'],
            'eventType' => $enumValue->value,
            'ticketId'  => (string) $event->getAggregateId(),
            'payload'   => $this->buildPayload($enumValue, $stored, (int) $event->getAggregateId()),
        ];
    }

    private function resolveEnum(string $phpClass, array $stored): RealtimeEventType
    {
        if ($this->mapper->isTicketAssigned($phpClass, $stored)) {
            return RealtimeEventType::TicketAssigned;
        }
        return RealtimeEventType::from($this->mapper->toEnum($phpClass));
    }

    private function buildPayload(RealtimeEventType $type, array $s, int $ticketId): array
    {
        return match ($type) {
            RealtimeEventType::TicketCreated => [
                'ticketId' => $ticketId,
                'contactId' => (int) $s['contact_id'],
                'conversationId' => (int) $s['conversation_id'],
                'status' => $s['status'],
                'priority' => (int) $s['priority'],
                'createdAt' => $s['created_at'],
            ],
            RealtimeEventType::MessageAdded => [
                'messageId' => (int) $s['message_id'],
                'ticketId' => $ticketId,
                'content' => $s['content'] ?? '',
                'direction' => $s['direction'],
                'senderName' => $s['sender_name'] ?? null,
                'createdAt' => $s['created_at'] ?? $s['occurred_at'],
                'externalId' => $s['external_id'] ?? null,
            ],
            RealtimeEventType::TicketStatusChanged => [
                'ticketId' => $ticketId,
                'previousStatus' => $s['previous_status'] ?? null,
                'newStatus' => $s['status'],
                'changedAt' => $s['changed_at'] ?? $s['occurred_at'],
            ],
            RealtimeEventType::TicketAssigned => [
                'ticketId' => $ticketId,
                'assigneeId' => (int) $s['assignee_id'],
                'assignedAt' => $s['assigned_at'] ?? $s['occurred_at'],
            ],
            RealtimeEventType::MessageUpdated => [
                'messageId' => (int) $s['message_id'],
                'ticketId' => $ticketId,
                'status' => $s['status'] ?? null,
                'updatedAt' => $s['updated_at'] ?? $s['occurred_at'],
            ],
            RealtimeEventType::ConversationUpdated => [
                'conversationId' => (int) $s['conversation_id'],
                'ticketId' => $ticketId,
                'lastMessageAt' => $s['last_message_at'] ?? null,
            ],
        };
    }
}
```

### 7.2 OutboxPublisherService (refactored)

**File**: `apps/api/src/Application/Service/OutboxPublisherService.php`

**Changes**:
- Inject `RealtimeEventFactory`.
- New constants: `MAX_RETRIES = 5`, `DEAD_LETTER_STATUS = 'dead_letter'`.
- `publishEvent($event)`: instead of `json_encode($event->getPayload())`, call `$this->factory->build($event)` and use the result as the SSE `data`.
- `handleFailure($event)`: if `retryCount + 1 >= MAX_RETRIES`, set `status = 'dead_letter'`, log error, do NOT increment further.
- `findUnpublishedEvents()`: `WHERE status = 'pending' AND published_at IS NULL` (replaces `WHERE published_at IS NULL`).
- `markPublished($event)`: set `status = 'published'` and `published_at = NOW()`.

**New CLI command** (`apps/api/src/Command/OutboxDeadLetterCommand.php`):
```
app:outbox:dead-letter list          # lists all events with status='dead_letter'
app:outbox:dead-letter retry {id}    # resets status='pending', retry_count=0
app:outbox:dead-letter purge {before}# deletes events older than X days with status='dead_letter'
```

### 7.3 OutboxEvent Entity (extended)

**File**: `apps/api/src/Domain/Outbox/OutboxEvent.php`

**New field**:
```php
#[ORM\Column(name: 'status', type: 'string', length: 20, options: ['default' => 'pending'])]
private string $status = 'pending';

public function getStatus(): string { return $this->status; }
public function setStatus(string $s): self { $this->status = $s; return $this; }
public function isDeadLetter(): bool { return $this->status === 'dead_letter'; }
```

### 7.4 Migration

**New file**: `apps/api/migrations/Version20260612000001.php`

```php
public function up(Schema $schema): void
{
    $this->addSql("ALTER TABLE outbox_events
        ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending',
        ADD COLUMN sequence INT NOT NULL DEFAULT 0");
    $this->addSql('UPDATE outbox_events
        SET sequence = JSON_EXTRACT(payload, \'$.seq\')
        WHERE sequence = 0');
    $this->addSql('CREATE INDEX idx_outbox_ticket_seq
        ON outbox_events (aggregate_id, sequence)');
    $this->addSql('CREATE INDEX idx_outbox_status
        ON outbox_events (status, published_at)');
}
```

**Rationale for new column**:
- Current schema: `seq` is inside the JSON `payload` column. Querying `JSON_EXTRACT(payload, '$.seq')` is not indexable.
- New dedicated `sequence` column populated at insert time → indexed scan for resync endpoint.
- Backward compatible: existing rows have `sequence = 0`; the migration's UPDATE populates them.

**Note on TicketCreatedEvent ticket ID (C-4)**:
- The `HandleInboundMessageUseCase` already collects `MessageCreatedEvent` AFTER `$em->flush()` (line 207-215). For `TicketCreatedEvent`, the collection happens BEFORE flush (line 168-176).
- The fix is to **move the `TicketCreatedEvent` collection AFTER `$em->flush()`** (after line 204), then call `$ticket->getId()` which now has the persisted ID. The `seq` value is already captured in the closure variable before the move.
- Verified by reading lines 156-216 of `HandleInboundMessageUseCase.php`: moving the event collection requires no other refactor.

### 7.5 TicketEventsController (new)

**File**: `apps/api/src/Controller/TicketEventsController.php`

```php
final class TicketEventsController
{
    public function __construct(
        private Security $security,
        private Connection $conn,
        private \App\Application\Realtime\RealtimeEventFactory $factory,
    ) {}

    #[Route('/api/tickets/{id}/events', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function listSince(int $id, Request $request): JsonResponse
    {
        // 1. Auth: JWT must include ticket/{id} topic
        $tokenTopics = $this->security->getUser()?->getMercureSubscribe() ?? [];
        if (!in_array("ticket/$id", $tokenTopics, true)) {
            return new JsonResponse(['error' => 'Forbidden'], 403);
        }

        // 2. Validate afterSequence
        $afterSequence = (int) $request->query->get('afterSequence', 0);
        if ($afterSequence < 0) {
            return new JsonResponse(['error' => 'afterSequence must be >= 0'], 400);
        }

        // 3. Query outbox_events
        $rows = $this->conn->fetchAllAssociative(
            'SELECT id, payload, sequence, status, event_type
             FROM outbox_events
             WHERE aggregate_id = :id
               AND sequence > :seq
               AND status IN (\'pending\', \'published\')
             ORDER BY sequence ASC
             LIMIT 100',
            ['id' => $id, 'seq' => $afterSequence]
        );

        // 4. Build contract events
        $events = [];
        $highest = $afterSequence;
        foreach ($rows as $row) {
            $outboxEvent = $this->hydrate($row);
            $events[] = $this->factory->build($outboxEvent);
            $highest = max($highest, (int) $row['sequence']);
        }

        return new JsonResponse([
            'data' => $events,
            'meta' => [
                'count' => count($events),
                'highestSequence' => $highest,
                'hasMore' => count($events) === 100,
            ],
        ]);
    }
}
```

### 7.6 PersistEventsToOutbox changes (HandleInboundMessageUseCase)

**File**: `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php`

**Change 1 (C-4 fix)**: move `TicketCreatedEvent` collection after flush.

```php
// BEFORE (line 168-176, before flush):
$this->em->persist($ticket);
$isNewTicket = true;
$this->eventCollector->collect(new TicketCreatedEvent(
    eventId: new Ulid(),
    ticketId: (int) $conversation->getId(),  // WRONG
    ...
));

// AFTER (after line 204 flush):
if ($isNewTicket) {
    $ticket->incrementLastEventSeq();  // already called, seq=1 reserved
    $this->em->flush();                // already done at line 204
    $this->eventCollector->collect(new TicketCreatedEvent(
        eventId: new Ulid(),
        ticketId: $ticket->getId(),     // CORRECT — now persisted
        occurredAt: new \DateTimeImmutable(),
        seq: 1,                          // captured at first incrementLastEventSeq
        version: 1,
        status: TicketStatus::NEW,
        priority: 1,
    ));
}
```

**Change 2**: `persistEventsToOutbox` should set the new `sequence` column from the payload:
```php
$outboxEvent = new OutboxEvent(
    aggregateType: 'ticket',
    aggregateId: (int) $event->ticketId,
    topic: 'ticket/' . $event->ticketId,
    eventType: $event->type,
    payload: $payload,
    sequence: (int) $payload['seq']    // NEW constructor arg
);
```
The `OutboxEvent` constructor adds a `?int $sequence = 0` parameter; the field is populated via reflection (or a new setter) in `hydrateEvent()`.

### 7.7 MessageUpdatedEvent and ConversationUpdatedEvent (add or alias)

The current event taxonomy in `apps/api/src/Domain/Event/` includes:
- `MessageCreatedEvent` ✅ (mapped to `message.added`)
- `TicketCreatedEvent` ✅ (mapped to `ticket.created`)
- `TicketUpdatedEvent` (status + owner) — disambiguated by payload content into `ticket.status_changed` or `ticket.assigned`
- `InboxUpdatedEvent` — unused legacy; can be deleted or aliased
- `TicketEvent` (entity, not domain event) — different; ignore
- `TicketAutoAssignmentRequestedEvent` — internal Symfony event, not a domain event for Mercure

**Missing for full v1 contract**:
- `MessageUpdatedEvent` — does not exist. Create stub in this sprint OR defer the `message.updated` enum case (mark it as "deferred — first release only emits message.added").
- `ConversationUpdatedEvent` — does not exist. Same decision: stub or defer.

**Decision**: stub both with empty constructors and no `MessageCreatedEvent`-style payload; `RealtimeEventFactory::buildPayload()` returns `[]` for these until they're actually emitted. The enum value is reserved; the wire contract does not break if no event of that type is published.

---

## 8. Data Flow

### 8.1 Inbound Message → Outbox → Mercure → Frontend

```
[Provider]                [Backend]                                          [Mercure]              [Frontend]
                          ──────────                                          ────────                ─────────
Webhook POST /inbound                                                                                 
        │                                                                                             
        ▼                                                                                             
HandleInboundMessageUseCase                                                                           
   ├─ persist Contact, Conversation, Message, Ticket (transaction)                                   
   ├─ em->flush()                                                                                     
   ├─ collect MessageCreatedEvent (post-flush)                                                       
   └─ collect TicketCreatedEvent (post-flush)  ← C-4 FIX                                               
        │                                                                                             
        ▼                                                                                             
persistEventsToOutbox()                                                                               
   └─ insert OutboxEvent rows with sequence populated                                                 
        │                                                                                             
        ▼                                                                                             
[CRON / CLI / controller POST /admin/outbox/publish]                                                  
        │                                                                                             
        ▼                                                                                             
OutboxPublisherService::publishPending()                                                              
   ├─ SELECT * FROM outbox_events WHERE status='pending'                                              
   └─ FOR EACH:                                                                                       
       ├─ RealtimeEventFactory::build($event) ← NEW: contract v1 shape                                
       ├─ POST Mercure hub: topic=ticket/{id}, data=<contract JSON>                                   
       ├─ success → UPDATE outbox_events SET status='published', published_at=NOW()                    
       └─ failure → handleFailure():                                                                  
            ├─ retry_count < 5 → retry_count++, status stays 'pending'                                
            └─ retry_count >= 5 → status='dead_letter', log error, skip                              
                                                                                                       │
                                                                                                       ▼
                                                                          SSE onmessage                
                                                                          JSON.parse                   
                                                                          MercureService.handleMessage
                                                                              ├─ eventId dedup         
                                                                              ├─ per-ticket gap check  
                                                                              ├─ handleEvent() (ONE)  
                                                                              │   ├─ message.added →   
                                                                              │   │   ConversationStore
                                                                              │   │   .addMessage or   
                                                                              │   │   .confirmMessage  
                                                                              │   └─ ticket.* →        
                                                                              │       TicketStore      
                                                                              └─ events$.next()       
```

### 8.2 Send → Provider → Backend → Outbox → Mercure → Confirm

```
[Composer]              [Frontend]                                                [Backend]                  [Mercure]
                        ─────────                                                 ────────                    ────────
User types text + Enter                                                                                       
        │                                                                                                     
        ▼                                                                                                     
ComposerComponent.send()                                                                                      
   └─ emits sendMessage()                                                                                     
        │                                                                                                     
        ▼                                                                                                     
ConversationComponent.onSendMessage()                                                                        
   └─ messageSender.send(ticketId, content)                                                                  
        │                                                                                                     
        ▼                                                                                                     
MessageSenderService.send()                                                                                   
   ├─ tempId = generateTempId()                                                                               
   ├─ ConversationStore.addOptimisticMessage(tempId, content, ticketId)                                      
   │     deliveryStatus: 'sending', messageCache.set(tempId, {content, ticketId})                           
   └─ retryWithBackoff(                                                                                      
        SendMessageUseCase.execute({ticketId, content, tempId})                                              
      )                                                                                                      
        │                                                                                                     
        ▼                                                                                                     
ProviderInterface.sendMessage(ticketId, content)                                                             
   └─ Fake: HTTP POST /api/tickets/{id}/messages                                                             
                                                                                                              ▼
                                                                                              (Backend processes
                                                                                               via legacy or new path
                                                                                               → emits MessageCreatedEvent
                                                                                               → outbox → Mercure)
                                                                                                                       │
        ┌────────────────────────────────────────────────────┐                                                     │
        ▼                                                    ▼                                                     │
   SUCCESS                                              FAILURE                                                  │
   output.message.id=N                                  retryWithBackoff retries (1s, 2s)                        │
        │                                                    │                                                   │
        ▼                                                    ▼                                                   │
ConversationStore                                   exhausted                                                  │
.confirmMessage(tempId, ...)                          ConversationStore                                         │
deliveryStatus: 'sent'                               .updateMessageStatus(tempId, 'failed')                     │
   │                                                  UiStore.notifyNewMessages()                               │
   │                                                  (user can retry or dismiss)                               │
   │                                                                                                            │
   │  ── ASYNC: SSE message.added arrives ──                                                                    │
   ▼                                                                                                            ▼
MercureService.handleMessage                                                                                  
   ├─ eventId dedup (Set)                                                                                      
   ├─ per-ticket gap check                                                                                     
   └─ handleEvent(event)                                                                                       
       └─ message.added → ConversationStore                                                                   
            .confirmMessage(tempId, payload)  ← idempotent: re-merges if tempId matches                        
```

### 8.3 Reconnect → Resync

```
[T0] SSE onerror fires
     │ MercureService.handleError()
     │ ├─ connectionStore.setOffline()
     │ ├─ eventSource.close()
     │ ├─ delay = 1000 * 2^(attempt-1), cap 30s
     │ └─ setTimeout(connect, delay)
[T1] attempt 2 — connect() called
     │ new EventSource('ticket/*')
[T2] onopen fires
     │ ├─ connectionStore.setOnline()
     │ ├─ reconnectAttempts = 0
     │ └─ resyncAllTickets()
     │      FOR EACH ticketId in seqState.keys():
     │          state = seqState.get(ticketId)
     │          transitionTo(ticketId, 'resyncing')
     │          HTTP GET /api/tickets/{ticketId}/events?afterSequence={state.lastSeq}
     │          for each event in response (sorted by sequence ASC):
     │              handleEvent(event)         ← single dispatch path
     │              seqState.set(ticketId, {lastSeq: event.sequence, state: 'connected'})
     │
[T3] resume normal SSE processing
     │
     ▼
[STEADY STATE]
```

---

## 9. Error Handling

### 9.1 Mercure SSE errors

| Failure | Detection | Action |
|---|---|---|
| SSE connection drops | `eventSource.onerror` | `handleError()` → exponential backoff → `connect()`. Per-ticket state preserved. |
| Repeated drops (≥ 10) | counter `reconnectAttempts >= 10` | `connectionStore.setPermanentFailure()`. Stop retry. Show "Retry Now" button. |
| Silent disconnect (no onerror) | (DEFERRED) | Not in this sprint — heartbeat/health check is out of scope. |
| Mercure hub returns 5xx | HTTP error in `OutboxPublisherService::publishEvent` | `handleFailure()` → increment retry count. If `>= MAX_RETRIES (5)` → `status='dead_letter'`. |
| Mercure hub 401 (JWT expired) | HTTP 401 | Same as 5xx. Operator must rotate JWT secret. |

### 9.2 Outbox dead-letter

```
retries exhausted (retry_count = 5, then attempt 6 fails)
  │
  ▼
handleFailure($event):
   retryCount = $event->retryCount + 1   // = 6
   if (retryCount >= MAX_RETRIES):
       $event->setStatus('dead_letter')
       $this->em->flush()
       $logger->error('Outbox event dead-lettered', [
           'event_id' => $event->getId(),
           'retry_count' => $retryCount,
           'event_type' => $event->getEventType(),
           'aggregate_id' => $event->getAggregateId(),
       ])
       return   // do NOT increment further
   else:
       retryCount++ normally, status stays 'pending'
```

Dead-letter events are visible via `app:outbox:dead-letter list` and can be retried with `app:outbox:dead-letter retry {id}` (sets `status='pending'`, `retry_count=0`).

### 9.3 Frontend send failure

```
MessageSenderService.send() — error path
  │
  ▼
retryWithBackoff exhausts (maxRetries=2, total 3 tries)
  │
  ▼
ConversationStore.updateMessageStatus(tempId, 'failed')
  │
  ▼
MessageBubbleComponent shows "Failed — Retry / Dismiss"
  │
  ├── Retry:
  │     MessageSenderService.retryByTempId(tempId)  // uses RetryMessageUseCase
  │     └─ updates status to 'sending' then 'sent' or 'failed' again
  │
  └── Dismiss:
        MessageSenderService.removeFailed(tempId)
        ConversationStore.removeMessage(tempId)
        messageCache.delete(tempId)
```

### 9.4 Backend resync endpoint errors

| Status | Cause | Frontend action |
|---|---|---|
| 200 | success | apply events in order |
| 200 with empty `data` | no missed events (server confirms) | transition to `connected`, lastSeq stays |
| 401/403 | JWT missing or no ticket topic | log error, do NOT trigger further resyncs for this ticket. User must re-authenticate. |
| 5xx | transient server error | exponential backoff retry; transition ticket back to `gap_detected` |
| Network error (fetch throws) | offline | treat as onerror, reconnect sequence handles |

### 9.5 Gap detection edge cases

- **Concurrent SSE event + resync response** arriving in same tick: `inFlightResync?: AbortController` on each `TicketSeqState` cancels prior request when a new gap is detected. The resync response handler checks `state.inFlightResync` is still the current controller before applying.
- **Resync returns more than lastSeq=10, e.g., lastSeq=10, response=10..50**: apply all 40 events, set lastSeq=50. The buffered SSE event for sequence 14 (still incoming) is processed normally after.
- **Resync returns same sequence twice** (idempotency on backend): the `eventId` dedup Set rejects duplicates. No harm.

---

## 10. Sprint 13 Readiness Checklist

Mapping each criterion from `spec.md §7` to its implementation point and verification.

| # | Criterion (from spec) | Implements In | Verified By |
|---|---|---|---|
| 1 | Message sent from Composer → appears optimistic → SSE confirmation → status `'sent'` | `MessageSenderService.send()` + `ConversationStore.addOptimisticMessage` + `ConversationStore.confirmMessage` (from SSE `handleEvent`) | Integration test: send message, observe store transition, assert `deliveryStatus === 'sent'` within 5s |
| 2 | After disconnect (5s) + reconnect, ticket list matches server state | `MercureService.onopen` → `resyncAllTickets()` → `GET /api/tickets/{id}/events?afterSequence={n}` | Integration test: simulate disconnect, assert `TicketStore.tickets` matches `GET /tickets` after reconnect |
| 3 | After disconnect + reconnect, active conversation matches server state | Same resync path + `ConversationStore.applyMissed` (called from `handleEvent` for `message.added` events) | Integration test: load conversation, disconnect, simulate 2 new messages, reconnect, assert `ConversationStore.messages` contains them |
| 4 | No duplicate messages after reconnect | `processedEventIds` Set (cap 1000) + per-ticket seq state | Store assertion: count messages before and after reconnect, assert no duplicates (compare by `message_id` or `eventId`) |
| 5 | No lost messages across disconnect/reconnect boundary | Per-ticket seq gap detection + targeted resync endpoint | Sequence verification: capture all eventIds published by backend during disconnect window, assert all present in `ConversationStore.messages` after resync |
| 6 | Event published by OutboxPublisherService is parsed and applied by frontend | `RealtimeEventFactory.build()` produces contract v1 JSON; `MercureService.handleMessage()` parses the same shape; no `applyStorePatch` adapter | E2E test: publish 6 event types via backend, assert frontend `MercureService.handleEvent` called once per event with parsed `RealtimeEvent` |
| 7 | Single store mutation per event (no dual dispatch) | `applyStorePatch()` DELETED; `handleEvent()` is the only path from `handleMessage` to stores | Code audit: `grep -n "applyStorePatch" apps/web/src` returns 0 results. PHPUnit/Pest test asserts that for 100 events, exactly 100 store mutations occur. |
| 8 | TicketCreatedEvent contains correct ticket ID (not conversation ID) | `HandleInboundMessageUseCase::executeInternal()` collects `TicketCreatedEvent` AFTER `$em->flush()`, using `$ticket->getId()` | Backend unit test: create new contact + message, assert `OutboxEvent.payload.ticket_id === $ticket->getId()` (and not `conversation.id`) |
| 9 | OutboxEvent with retry_count >= 5 is dead-lettered | `OutboxPublisherService::handleFailure()` checks `retryCount >= MAX_RETRIES` and sets `status='dead_letter'` | Backend unit test: mock `Mercure hub` to return 500 six times, assert event status transitions pending→pending→pending→pending→pending→pending→dead_letter |
| 10 | InboxPageComponent cleans up EventSource on destroy | `InboxPageComponent.ngOnDestroy` calls `mercure.disconnect()` | Component lifecycle test: mount, navigate away, assert `mercure.eventSource === null` |
| 11 | No per-ticket HTTP refetch on SSE events | `InboxService.subscribeToTickets` and `handleRealtimeEvent` DELETED; all updates go through `MercureService.events$` | Network inspection: spy on `HttpClient.get`, assert zero calls per SSE event for `N=50` tickets |
| 12 | Backend ~66 existing tests still pass | All changes are additive: new columns nullable/default, new services injected, no signature changes to existing public methods | CI pipeline: `docker exec inbiox_php php bin/phpunit` |

---

## 11. Migration Notes

### 11.1 Order of Changes (per proposal Phase 1-3)

**Phase 1 — Foundation (sequential, must be first)**

1. **Backend — OutboxEvent entity + migration** (`Version20260612000001`):
   - Add `status` and `sequence` columns.
   - Backfill `sequence` from `payload.seq`.
   - Add indexes.
   - Run migration. **Existing rows remain valid; default values are sane.**
2. **Backend — `RealtimeEventType` enum + `EventTypeMapper` + `RealtimeEventFactory`**: new files, no callers yet.
3. **Backend — `OutboxPublisherService.publishEvent()`**: call `factory->build($event)` instead of `json_encode($event->getPayload())`.
4. **Backend — `OutboxPublisherService.handleFailure()`**: enforce `MAX_RETRIES` + dead-letter.
5. **Backend — `HandleInboundMessageUseCase`**: move `TicketCreatedEvent` collection to after flush.
6. **Backend — `OutboxEvent` constructor**: add `?int $sequence = 0` parameter; set in `hydrateEvent()`.
7. **Backend — `TicketEventsController`**: new controller for `GET /api/tickets/{id}/events`.
8. **Frontend — `RealtimeEventType` enum + `RealtimeEvent<T>` interface**: replace `ticket-event.model.ts` types.
9. **Frontend — `MercureService.handleMessage()` refactor**: single dispatch path; `handleEvent()` rewritten with strict switch over `RealtimeEventType`.
10. **Frontend — `MercureService.handleEvent()`**: dispatch to `TicketStore` / `ConversationStore` using new contract payload shapes.

**Checkpoint Alpha**: real Mercure event published by `OutboxPublisherService` is parsed and applied by `MercureService.handleEvent()` without errors. Single mutation per event.

**Phase 2 — Reconnect (builds on Phase 1)**

11. **Backend — `OutboxPublishCommand` extended status filter**.
12. **Backend — `OutboxDeadLetterCommand`**: new CLI command.
13. **Frontend — `MercureService.resyncTicket()` + `resyncAllTickets()`**: new methods.
14. **Frontend — `MercureService.onopen`**: call `resyncAllTickets()`.
15. **Frontend — per-ticket gap state machine**: `seqState` becomes `Map<string, TicketSeqState>`.
16. **Frontend — `InboxPageComponent.ngOnDestroy`**: cleanup.
17. **Frontend — `InboxService`**: remove per-ticket subscriptions; remove `handleRealtimeEvent`.
18. **Frontend — `ConnectionStore.setPermanentFailure()`** + `lastEventTime` signal.
19. **Frontend — `DebugPanelComponent`**: "Retry Now" button.

**Checkpoint Bravo**: disconnect for 5s, reconnect, ticket list and active conversation match server state. No duplicate messages.

**Phase 3 — Send pipeline (builds on Phase 2)**

20. **Frontend — `MessageSenderService.send()`**: replace `setTimeout` with `retryWithBackoff`.
21. **Frontend — `RetryMessageUseCase.execute()`**: remove internal cache; caller passes payload.
22. **Frontend — `ConversationComponent.onSendMessage()`**: call `messageSender.send()`.
23. **Frontend — `ComposerComponent`**: remove unused `MessageApiService` injection; remove `tempId` generation (now in `MessageSenderService`).
24. **Frontend — `InboxService.sendMessage`**: removed (use `MessageSenderService` directly).

**Checkpoint Charlie**: type message, press send, see `'sending'` → `'sent'` within 5s.

**Final Phase — Cleanup**

25. **Frontend — `StateService`**: add `@deprecated` JSDoc; do not delete (out of scope).
26. **Frontend — `InboxStore`**: add `@deprecated` JSDoc; already has TODO.
27. **Frontend — `ConversationPanelComponent`**: add `@deprecated` JSDoc; do not delete.

### 11.2 Dependencies Between Components

```
Phase 1:
  OutboxEvent migration ──→ OutboxPublisherService refactor ──→ RealtimeEventFactory usable
                                                                      │
                                                                      └─→ MercureService.handleEvent (must use new contract)

  HandleInboundMessageUseCase (C-4 fix) — independent of MercureService, but must land
  before E2E test #8 (TicketCreatedEvent unit test)

  Frontend RealtimeEventType enum ──→ MercureService.handleEvent strict switch

Phase 2:
  resyncAllTickets (MercureService) requires TicketEventsController backend to exist
  DebugPanel button requires ConnectionStore.setPermanentFailure

Phase 3:
  MessageSenderService.send() uses retryWithBackoff (already exists in retry.util.ts)
  ConversationComponent.onSendMessage wires output → messageSender — independent
```

### 11.3 Backward Compatibility

- **OutboxEvent.payload** (DB column): stored format unchanged (snake_case, FQCN as `event_type`). `RealtimeEventFactory` reads it as-is. Sprint 09B tests `test_outbox_event_persisted_after_inbound_message` and `test_event_payload_contract` continue to pass.
- **Mercure topic format**: unchanged (`ticket/{id}`). Existing JWT topic authorization works.
- **Existing components**: `TicketListComponent`, `MessageBubbleComponent`, `TicketFiltersComponent` read from signal stores — no change needed.
- **`FakeWhatsappProvider`**: writes directly to `ConversationStore`. Not affected by SSE pipeline. Continues to work.
- **Legacy `MessageApiService`**: still injected in `InboxService.loadMessages`; not used for sends anymore.

---

## 12. Risks & Mitigations

| # | Risk | Likelihood | Mitigation |
|---|---|---|---|
| R1 | `sequence` column backfill from `JSON_EXTRACT` may fail for rows with malformed `payload` | Low | Migration script logs count of rows updated. Skipped rows get `sequence=0` and are filtered by `WHERE status IN ('pending','published')` — they remain dead-lettered candidates. |
| R2 | `HandleInboundMessageUseCase` refactor (moving `TicketCreatedEvent` collection) breaks Sprint 09B test `test_sequence_increments_correctly` (expects seq=2 for first message) | Medium | The seq counter logic does not change — the event still collects seq=N where N is the ticket's `lastEventSeq` after `incrementLastEventSeq()`. Verified by reading line 168-176 (current) vs post-refactor position. If the test fails, the seq for TicketCreatedEvent moves to 1 (now from `incrementLastEventSeq` at the new location) and MessageCreatedEvent seq=2. Test must be updated. |
| R3 | Per-ticket `seqState` Map grows unbounded over a long session | Low | Tickets that haven't emitted events for >1 hour are evicted in a periodic cleanup (added in `MercureService.disconnect()` is full clear; partial cleanup is a future concern). |
| R4 | SSE eventId collision between backend instances | Negligible | ULIDs are 128-bit monotonic; collision probability is astronomically low. If observed in production, the `processedEventIds` cap of 1000 is the safety net. |
| R5 | The resync endpoint reveals events for tickets the user has unsubscribed from (JWT topic change) | Low | The endpoint reads the JWT topic list from the Security context; if `ticket/{id}` is not in the subscribe list, return 403. Documented in the controller. |
| R6 | Moving `TicketCreatedEvent` collection breaks the `Ticket` entity's `incrementLastEventSeq()` call order (currently called before flush with seq=1; new code calls it after) | Low | The seq value is captured in the closure before the move (`seq: $ticket->incrementLastEventSeq()` returns 1 immediately). The new collect call uses the literal `seq: 1`. The Ticket entity's `lastEventSeq` field is updated by Doctrine on flush, regardless of when the event is collected. |
| R7 | `applyStorePatch` removal breaks tests that depend on the dual-mutation behavior (if any exist) | Low | `grep` shows no frontend tests for the realtime pipeline (test suite deferred to Sprint 13). Risk is theoretical. |
| R8 | The 100-event cap on resync endpoint may miss events for a high-throughput ticket with >100 events during a long disconnect | Medium | Endpoint returns `meta.hasMore=true` when capped. Frontend resync loop continues until `hasMore=false`. With 100 events × ~5s SSE processing, this is bounded. |
| R9 | Circuit breaker at 10 attempts may be too aggressive in a slow-network region | Low | Threshold is configurable via `MercureService` constructor injection (default 10). Operators can tune via DI. |
| R10 | Sprint 09B integration test `test_outbox_event_persisted_after_inbound_message` asserts `payload.type === 'App\\Domain\\Event\\TicketCreatedEvent'` (FQCN). The new contract moves this to `eventType: 'ticket.created'` on the wire but the DB column still stores the FQCN. Test should pass unchanged. | Confirmed safe | Verified: `Sprint09BOutboxTest::test_outbox_event_persisted_after_inbound_message` checks `$event->getPayload()['type']`, which is the stored value, unchanged. |
| R11 | `OutboxEvent` constructor signature change (adding `?int $sequence = 0`) breaks `Sprint09BOutboxTest::test_publisher_marks_event_as_published` which uses `new OutboxEvent('ticket', 1, 'ticket/1', 'message.created', [...])` (no `sequence` arg) | Medium | Default value `?int $sequence = 0` keeps the existing call site working. New callers pass the explicit value. |
| R12 | The new `RealtimeEventFactory` requires a `payload.content` field for `message.added` events, but the current `HandleInboundMessageUseCase::persistEventsToOutbox()` does not store message content in the outbox payload (per test `test_event_payload_contract` asserts `content` is NOT in payload) | High | Add `content`, `sender_name`, `external_id` to the outbox payload for `MessageCreatedEvent`. Update test `test_event_payload_contract` to allow these specific fields (or split: keep `event_id/type/ticket_id/seq/occurred_at` as core, add `content/direction/senderName/externalId` as message-specific). Sprint 09B test asserts `content` is NOT in payload because the original Sprint 09B design didn't include it for the wire contract — but with the new contract it MUST be included. **Update the test** to assert the new shape. |

---

## 13. Open Questions

None — all decisions encoded in the design above per the orchestrator's pre-approved instructions.
