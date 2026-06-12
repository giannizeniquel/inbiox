# Sprint 12.5 — Realtime Reliability & Stress Validation

## Pipeline Map

```
                                    ┌─────────────────────────────────────────────────────────┐
                                    │                  BACKEND (apps/api/)                    │
                                    │                                                         │
  Provider (WhatsApp/Meta)          │  ┌──────────────┐    ┌──────────────────┐              │
     Webhook ───────────────────────┼─▶│MessageController│──▶│HandleInbound     │              │
                                    │  │  POST /inbound │   │MessageUseCase    │              │
                                    │  └──────────────┘    └────────┬─────────┘              │
                                    │                               │                        │
  Agent HTTP ───────────────────────┼─▶│OutboxController│            │ DB Transaction          │
  POST /messages/send               │  │(legacy path)   │            │ (Contact, Conversation,  │
                                    │  └────────────────┘            │  Message, Ticket)       │
                                    │                                │                        │
                                    │  ┌──────────────────┐          │                        │
                                    │  │HandleOutbound     │◀────────┘                        │
                                    │  │MessageUseCase     │                                  │
                                    │  │(optimistic lock)  │                                  │
                                    │  └────────┬─────────┘                                  │
                                    │           │                                            │
                                    │           ▼                                            │
                                    │  ┌──────────────────────┐                              │
                                    │  │ EventCollector        │                              │
                                    │  │ (collects DomainEvents)│                              │
                                    │  └────────┬─────────────┘                              │
                                    │           │                                            │
                                    │           ▼                                            │
                                    │  ┌──────────────────────┐   ┌──────────────────────┐   │
                                    │  │ OutboxEvent (DB row)  │──▶│OutboxPublisherService│   │
                                    │  │ published_at = null   │   │(cron/command)        │   │
                                    │  └──────────────────────┘   └──────────┬───────────┘   │
                                    │                                       │                │
                                    │                                       ▼                │
                                    │                               ┌──────────────┐         │
                                    │                               │  Mercure Hub  │         │
                                    │                               │  (SSE Server) │         │
                                    │                               └──────┬───────┘         │
                                    └──────────────────────────────────────┼──────────────────┘
                                                                           │
                     BROWSER ──────────────────────────────────────────────┼─────────────────
                                                                           │
                                    ┌──────────────────────────────────────┼──────────────────┐
                                    │               FRONTEND (apps/web/)   │                  │
                                    │                                      ▼                  │
                                    │  ┌──────────────────────────────────────────┐           │
                                    │  │          MercureService                   │           │
                                    │  │                                          │           │
                                    │  │  EventSource (ticket/* wildcard)         │           │
                                    │  │  ├─ onopen: setOnline()                  │           │
                                    │  │  ├─ onmessage: handleMessage()           │           │
                                    │  │  │   ├─ Idempotency (processedEventIds)  │           │
                                    │  │  │   ├─ Seq Validation (seqState)        │           │
                                    │  │  │   ├─ applyStorePatch()  ◄── DUP 1     │           │
                                    │  │  │   ├─ handleEvent()      ◄── DUP 2     │           │
                                    │  │  │   ├─ Ticket subscribers callback      │           │
                                    │  │  │   └─ eventsSubject.next()             │           │
                                    │  │  └─ onerror: handleError()               │           │
                                    │  │       └─ exponential backoff reconnect   │           │
                                    │  └──────────────────┬───────────────────────┘           │
                                    │                     │                                  │
                                    │        ┌────────────┼────────────┐                     │
                                    │        ▼            ▼            ▼                     │
                                    │  ┌─────────┐ ┌──────────┐ ┌──────────┐                │
                                    │  │Ticket   │ │Conversat.│ │Connection│                │
                                    │  │Store    │ │Store     │ │Store     │                │
                                    │  │         │ │          │ │          │                │
                                    │  │tickets  │ │messages  │ │status    │                │
                                    │  │active   │ │loading   │ │eventCount│                │
                                    │  │filter   │ │hasMore   │ │          │                │
                                    │  └────┬────┘ └────┬─────┘ └──────────┘                │
                                    │       │           │                                    │
                                    │       ▼           ▼                                    │
                                    │  ┌──────────────────────────────────┐                  │
                                    │  │    Components                    │                  │
                                    │  │                                  │                  │
                                    │  │  InboxPageComponent              │                  │
                                    │  │    ├─ TicketListComponent        │                  │
                                    │  │    ├─ ConversationComponent      │                  │
                                    │  │    │   ├─ ConversationHeader     │                  │
                                    │  │    │   ├─ MessageBubble          │                  │
                                    │  │    │   └─ ComposerComponent      │                  │
                                    │  │    └─ DebugPanelComponent        │                  │
                                    │  └──────────────────────────────────┘                  │
                                    │                                                       │
                                    └───────────────────────────────────────────────────────┘

    ┌─────────────────────────────────────────────────────────────────────────────────┐
    │                    OPTIMISTIC UPDATE PIPELINE                                   │
    │                                                                                 │
    │  ComposerComponent ──► ConversationStore.addOptimisticMessage()                 │
    │       │                      │                                                   │
    │       │                (optimistic UI shows "sending")                           │
    │       ▼                      │                                                   │
    │  MessageSenderService.send() │                                                   │
    │       │                      │                                                   │
    │       ├─► SendMessageUseCase ──► ProviderInterface.sendMessage()                │
    │       │       │                        │                                         │
    │       │       │                   ┌─────┴────────┐                               │
    │       │       │                   ▼              ▼                                │
    │       │       │              Success          Failure                             │
    │       │       │                  │              │                                 │
    │       │       │                  ▼              ▼                                 │
    │       │       │          confirmMessage()  retryByTempId()                        │
    │       │       │                  │              │                                 │
    │       │       │                  │         ┌─────┴─────┐                          │
    │       │       │                  │         ▼           ▼                          │
    │       │       │                  │    Retry again   Max retries                   │
    │       │       │                  │         │              │                       │
    │       │       │                  │         │         set 'failed'                 │
    │       │       │                  │         │              │                       │
    │       │       │                  │         └──────────────┘                       │
    │       │       │                  ▼                                                │
    │       │       │           updateMessageStatus('sent')                             │
    │       │       │                                                                   │
    │       └───────┴───── retryUseCase.registerFailedMessage()                         │
    └─────────────────────────────────────────────────────────────────────────────────┘
```

---

## Existing Safeguards

### Backend: What's Protected

| Safeguard | Location | Mechanism |
|-----------|----------|-----------|
| **Idempotency (inbound)** | `HandleInboundMessageUseCase` line 127-138 | `externalId` uniqueness check via `MessageRepository.findByExternalId()` — if same externalId exists, returns existing message |
| **Idempotency (outbound)** | `HandleOutboundMessageUseCase` line 55-72 | Deterministic `eventKey = outbound:{ticketId}:{contentHash}` checked against `ProcessedEventRepository`; `UNIQUE` constraint on `processed_events.event_id` |
| **Optimistic locking** | `Ticket` entity line 54-55 | `#[ORM\Version]` column `version` for optimistic concurrency |
| **Pessimistic write lock** | `HandleInboundMessageUseCase` line 179 | `$em->lock($ticket, LockMode::PESSIMISTIC_WRITE)` during seq update |
| **Transaction atomicity** | `HandleInboundMessageUseCase` line 52-54 | Entire `executeInternal` wrapped in `em->getConnection()->transactional()` |
| **Outbox retry count** | `OutboxPublisherService` line 17 | `MAX_RETRIES = 5` constant defined (but NEVER enforced — see Vulnerability Catalog) |
| **JWT topic authorization** | `MercureJwtService` line 46-51 | Per-ticket subscribe topics, per-agent inbox topic |
| **FSM validation** | `TicketStateMachine` line 62-76 | `transition()` throws `InvalidTransitionException` for invalid status changes |
| **Seq increment** | `Ticket` entity line 197-201 | `incrementLastEventSeq()` used atomically inside transaction |
| **Endpoint validation** | `InboundMessageRequest` | Symfony `#[Assert]` constraints on `phone`, `message`, `externalId` |

### Frontend: What's Protected

| Safeguard | Location | Mechanism |
|-----------|----------|-----------|
| **Idempotency (eventId)** | `MercureService` line 27, 110-114 | `processedEventIds` Set; events with duplicate eventId are dropped |
| **Seq ordering** | `MercureService` line 120-158 | `seqState` Map tracks last seq per ticket; old/duplicate seq rejected |
| **Store memory caps** | `ConversationStore` line 30, `TicketStore` line 4, `InboxStore` line 11 | `MAX_MESSAGES=200`, `MAX_TICKETS=1000`, `MAX_CHATS=1000` |
| **EventId Set cap** | `MercureService` line 130-134 | Processed event IDs capped at 1000; oldest evicted |
| **Exponential backoff** | `MercureService` line 259-269 | Reconnect delay: `min(1000 * 2^(attempt-1), 30000)` |
| **Immutable store updates** | All signal stores | Uses spread/`map()`/`filter()` — no direct mutation on arrays |
| **Unsubscribe on destroy** | `ConversationComponent` line 72-75 | `clearTimeout` + `subscription?.unsubscribe()` |
| **Unsubscribe on destroy** | `ConversationPanelComponent` line 102-104 | Unsubscribes all `subscriptions` |
| **Message caps enforced** | `ConversationStore` line 63-66 | `slice()` oldest messages when exceeding 200 |
| **tempId reconciliation** | `ConversationStore` line 124-141 | `confirmMessage()` merges confirmed data with optimistic tempId |

---

## Vulnerability Catalog

### 🔴 CRITICAL

#### C-1: Dual Event Dispatch — applyStorePatch() + handleEvent() process the same event twice

**File:** `apps/web/src/app/core/realtime/mercure.service.ts`
**Lines:** 138 and 141; methods at 168-205 and 211-243

**Description:**
`handleMessage()` calls BOTH `applyStorePatch()` (line 138) and `handleEvent()` (line 141) for EVERY valid event. Both methods contain the same switch cases for `MessageAdded`, `StatusChanged`, `Assigned`. Both call `conversationStore.addMessage()` and `ticketStore.addOrUpdateTicket()` with the same data. This means every incoming realtime event results in **DUPLICATE store mutations** — messages appear twice, unread counts double, tickets update twice.

**Impact:** Messages appear duplicated in the UI. Unread counts inflate. Store listeners fire twice. UI flickers.

**Root cause:** `applyStorePatch` was likely intended as an optimization to avoid full refetch, while `handleEvent` was the legacy dispatch. Both were kept active.

**Fix:** Remove `applyStorePatch()` entirely and keep only `handleEvent()`, or vice versa. Never both.

**Verification:** Git history suggests both were added in different sprints and never reconciled.

---

#### C-2: Backend Outbox Payload Shape Mismatch — Frontend cannot parse backend events

**File:** `apps/api/src/Application/Service/OutboxPublisherService.php` lines 131-135
**File:** `apps/web/src/app/core/realtime/mercure.service.ts` line 105

**Description:**
The backend publishes flat payloads to Mercure:
```json
{
  "event_id": "01JQX...",
  "type": "App\\Domain\\Event\\MessageCreatedEvent",
  "ticket_id": "123",
  "occurred_at": "2026-06-12T...",
  "seq": 5,
  "message_id": 456,
  "direction": "inbound"
}
```

But the frontend parses events as `RealtimeEvent`:
```typescript
interface RealtimeEvent {
  ticketId: string;      // backend sends "ticket_id"
  event: TicketEvent;    // backend has no nested "event" object
  seq: number;           // this matches
}
```

**Impact:** `data.ticketId` is `undefined` (backend sends `ticket_id`), `data.event` is `undefined` (backend doesn't nest under `event`), `data.seq` is present (backend DOES send `seq`). The event is either processed with undefined values or dropped entirely (`data.seq > currentSeq` where `undefined > 0` = `false` → falls into "old seq ignored").

**The entire backend-to-frontend realtime pipeline is effectively non-functional** as implemented. Events arrive at the browser (SSE connection is fine) but cannot be parsed into the expected model hierarchy.

**Fix:** Either:
1. Restructure backend outbox payload to match `{ ticketId, event: { type, payload: {...} }, seq }` shape
2. Or restructure frontend `RealtimeEvent` parsing to handle flat payload shape
3. Or add an adapter layer in `MercureService.handleMessage()` that transforms flat payloads into `RealtimeEvent`

**Additionally:** The backend uses `ticket_id` (snake_case) while the frontend model uses `ticketId` (camelCase). The backend's `type` field is the full PHP class name (`App\Domain\Event\MessageCreatedEvent`) while the frontend expects `TicketEventType.MessageAdded` (`message_added`). No mapping exists.

---

#### C-3: No State Sync After Reconnect — Data Lost on Disconnection

**File:** `apps/web/src/app/core/realtime/mercure.service.ts` lines 53-87

**Description:**
When `connect()` is called (initial or after reconnect in `handleError()`), it establishes a new EventSource connection with wildcard topic `ticket/*`. There is NO mechanism to:
1. Request missed events that occurred while disconnected
2. Trigger a full data refresh (GET /tickets, GET /messages) after reconnection
3. Compare local seq state with server state to detect gaps

The `onopen` handler (lines 66-73) sets connected state but does NOT trigger any data sync. Events that arrived during the disconnect window (which could be seconds to minutes with exponential backoff) are **permanently lost**.

**Impact:** Stale ticket lists, missing messages, incorrect unread counts after reconnection. Agents may miss customer replies.

**Fix options:**
- On reconnect: fetch `/tickets` and active ticket `/messages` to reconcile state
- Implement seq-based gap detection: on reconnect, request events from `lastSeq+1` to current
- Use Mercure's `Last-Event-ID` mechanism via `EventSource` (browser-native replay)

---

#### C-4: TicketCreatedEvent Uses Wrong ticketId — Conversation ID Instead of Ticket ID

**File:** `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` line 168-176

**Description:**
When creating a new ticket inside the transactional flow, `TicketCreatedEvent` is collected BEFORE `$em->flush()` (line 204), meaning the ticket entity has no ID yet. The code uses `(int) $conversation->getId()` as a workaround:
```php
$this->eventCollector->collect(new TicketCreatedEvent(
    ticketId: (int) $conversation->getId(),  // WRONG: conversation ID != ticket ID
    ...
));
```

**Impact:** The outbox event's `ticket_id` payload field contains the conversation ID, not the ticket ID. When this event is published to Mercure, the frontend receives the wrong ticket ID. Downstream event consumers (auto-assignment, reporting) will use the incorrect ID.

**Fix:** Collect `TicketCreatedEvent` AFTER the flush, or restructure the event collection to happen post-persist.

---

#### C-5: OutboxPublisherService.handleFailure Never Checks MAX_RETRIES — Infinite Retry Loop

**File:** `apps/api/src/Application/Service/OutboxPublisherService.php` lines 17, 214-227

**Description:**
`MAX_RETRIES = 5` is defined at line 17 but is never referenced in `handleFailure()` (line 214). The method unconditionally increments `retry_count` with no circuit breaker. Events that fail to publish (e.g., Mercure hub down, JWT expired) will retry indefinitely on every cron run, accumulating retry_count forever.

**Impact:** Bloated database, wasted CPU cycles on dead-letter events, no alerting mechanism when an event exceeds retry threshold.

**Fix:** In `handleFailure()`, check if `$event->getRetryCount() >= MAX_RETRIES` and either skip (move to dead letter) or log at error level with alert.

---

### 🟠 HIGH

#### H-1: Out-of-Order Seq Events Permanently Dropped — No Gap Detection or Replay

**File:** `apps/web/src/app/core/realtime/mercure.service.ts` lines 116-158

**Description:**
The seq validation logic accepts ONLY `seq > currentSeq` (line 120). If events arrive out of order (e.g., seq 3 arrives before seq 2), seq 2 is permanently rejected with "Old seq ignored" (line 156-158). There is:
- No gap detection
- No replay request mechanism
- No buffer for out-of-order events
- No time-based window for late arrivals

**Impact:** Under network disruption or Mercure topic delivery variance, events are silently dropped. The user sees gaps in message history.

**Fix:** Implement a gap buffer that holds out-of-order events for a configurable window (e.g., 5 seconds), accepts events with `seq > currentSeq`, and fills gaps when intermediate seqs arrive.

---

#### H-2: MessageSenderService Retry Chain — Exponential Retry on Linear Delay

**File:** `apps/web/src/app/features/inbox/services/message-sender.service.ts` lines 70-87, 140-153

**Description:**
When `send()` fails (line 68-87):
1. `retryUseCase.registerFailedMessage()` caches the payload
2. `setTimeout` with delay `(3 - maxRetries) * 1000` triggers `retryByTempId()`
3. If retry fails, `retryByTempId()` calls itself recursively via `setTimeout`

The delay formula `(3 - maxRetries) * 1000` is:
- maxRetries=2 → delay=1000ms
- maxRetries=1 → delay=2000ms
- maxRetries=0 → delay=3000ms (but by this point retries are exhausted)

This is a weak backoff (1s, 2s, 3s linear) rather than true exponential. Under high load, concurrent retries from multiple messages create a **retry storm**.

**Additionally:** The `retryByTempId` receives a fresh `maxRetries` parameter but also captures the current `maxRetries` from the closure — the error handler at line 140 checks `maxRetries > 0`, but the parameter has already been decremented. The error message at line 148 says "after 2 attempts" regardless of actual attempts.

**Fix:** Replace `setTimeout` with proper RxJS `retry`/`retryWhen` with exponential backoff. Use `RetryConfig` from `retry.util.ts` (which already exists but is unused here).

---

#### H-3: Two Active Code Paths for Message Sending — New vs Legacy Pipeline

**Files:**
- New path: `composer.component.ts` → `conversationStore.addOptimisticMessage()` (no actual send)
- Legacy path: `conversation-panel.component.ts` → `messageApi.sendMessage()` (bypasses stores)

**Description:**
There are TWO parallel message-sending paths:
1. **ComposerComponent** (newer, used in ConversationComponent): Creates optimistic message in `ConversationStore` and emits `sendMessage` output. The `ConversationComponent.onSendMessage()` only calls `scrollToBottom()` — **no actual HTTP/provider send occurs**. The `MessageSenderService` (which has the full send + retry pipeline) is never invoked from this path.
2. **ConversationPanelComponent** (older, standalone): Calls `messageApi.sendMessage()` directly, bypassing `SendMessageUseCase`, `ConversationStore`, and optimistic UI entirely. Updates `StateService` (legacy BehaviorSubject store) directly.

**Impact:** Messages typed in the new ComposerComponent appear optimistically but never actually send. Only the legacy ConversationPanelComponent can send messages, but it doesn't use the new store architecture.

**Fix:** Wire `ComposerComponent` output → `MessageSenderService.send()` (or its parent container). Deprecate and remove `ConversationPanelComponent`.

---

#### H-4: InboxService Creates Per-Ticket Subscriptions That Do Full HTTP Refetch

**File:** `apps/web/src/app/features/inbox/services/inbox.service.ts` lines 59-75

**Description:**
On initialization, `subscribeToTickets()` creates a Mercure subscription callback for every single ticket. Each callback (line 70-75) issues a full `GET /tickets/{ticketId}` HTTP request:
```typescript
this.ticketApi.getTicket(ticketId).subscribe({
    next: (ticket) => this.state.addOrUpdateTicket(ticket),
});
```

With the wildcard `ticket/*` Mercure topic, EVERY event triggers N HTTP requests (one per subscribed ticket callback). For an agent with 50 tickets, each incoming message causes 50 HTTP GET requests.

**Impact:** N+1 HTTP waterfall on every realtime event. Network saturation under moderate event volume.

**Fix:** Remove per-ticket subscriptions entirely. The wildcard topic already delivers all events. Use `MercureService`'s incremental store updates instead of full HTTP refetches. Or batch refresh calls.

---

#### H-5: InboxPageComponent Has No onDestroy — Mercure Connection Leak on Navigation

**File:** `apps/web/src/app/features/inbox/pages/inbox-page/inbox-page.component.ts` lines 16-24

**Description:**
`InboxPageComponent` implements `OnInit` but not `OnDestroy`. Each time the user navigates to the inbox page, `ngOnInit` calls `this.mercure.connect()`. If the connection is already established, `connect()` returns early (line 54: `if (this.eventSource) return;`). However, if the user navigates away and back, the initial connection is preserved — but there's no cleanup if the component is destroyed and the user never returns.

More critically: if an error scenario causes the EventSource to be nullified without the component knowing, re-navigation creates a second connection while the first may still be half-open.

**Impact:** Stale connections accumulate on repeated navigation. Browser connection limits could be exhausted.

**Fix:** Add `ngOnDestroy` with `this.mercure.disconnect()` or use a route guard that manages the connection lifecycle.

---

#### H-6: Optimistic Messages Created by ComposerComponent Never Sent

**File:** `apps/web/src/app/features/inbox/components/composer/composer.component.ts` lines 55-70

**Description:**
The `send()` method in ComposerComponent:
1. Adds an optimistic message to `ConversationStore` (line 62-66)
2. Emits `sendMessage` output (line 69)
3. Clears the text input (line 68)

But nowhere is `MessageSenderService.send()` called. The parent `ConversationComponent.onSendMessage()` just scrolls to bottom. The optimistic message remains in the store with `deliveryStatus: 'sending'` forever — it never transitions to 'sent' or 'failed'.

**Impact:** Messages appear as "sending" permanently. User thinks their message was sent but it never reaches the provider/API.

**Fix:** Wire the sendMessage output to `MessageSenderService.send()` in the parent container.

---

### 🟡 MEDIUM

#### M-1: ConversationPanelComponent and ConversationComponent Both Imported — Component Duplication

**Files:**
- `apps/web/src/app/features/inbox/containers/conversation/conversation.component.ts` (137 lines)
- `apps/web/src/app/features/inbox/components/conversation-panel/conversation-panel.component.ts` (105 lines)

**Description:**
Two separate components serve the same purpose with different implementations:
- `ConversationComponent` uses signal stores (`ConversationStore`, `TicketStore`, `UiStore`)
- `ConversationPanelComponent` uses legacy `StateService` (BehaviorSubject) and `MessageApiService`

Both are imported in their respective modules/templates. Both can coexist in the DOM, displaying different state.

**Impact:** Confusing developer experience, potential for both displaying different data simultaneously, duplicated maintenance.

**Fix:** Consolidate to `ConversationComponent` only. Remove `ConversationPanelComponent` and its template references.

---

#### M-2: No Heartbeat/Health Check for Mercure Connection — Silent Disconnect

**File:** `apps/web/src/app/core/realtime/mercure.service.ts` lines 53-87

**Description:**
The `EventSource` API fires `onerror` when the connection drops, but:
- Browser `EventSource` has no built-in heartbeat or ping mechanism
- There's no periodic health check verifying the connection is still active
- A silent disconnect (where the browser doesn't fire onerror) leaves the app thinking it's connected

Mercure's SSE may not fire `onerror` for idle disconnects (e.g., NAT timeout, proxy timeout). The `connectionState` signal stays `'connected'` while no events arrive.

**Impact:** Users see "Connected" in the UI while the connection is actually dead. Missed messages with no indication.

**Fix:** Add a heartbeat mechanism: a periodic (30s) check-ping or a received-event watchdog timer that resets on each message and triggers reconnect if no event received within a threshold.

---

#### M-3: processedEventIds Set Eviction Is O(n) — Performance Hotspot Under High Throughput

**File:** `apps/web/src/app/core/realtime/mercure.service.ts` lines 131-133

**Description:**
When `processedEventIds` exceeds 1000 entries, the oldest entry is evicted:
```typescript
const first = this.processedEventIds.values().next().value;
if (first) this.processedEventIds.delete(first);
```
`Set.values()` returns an iterator; `.next()` is O(1) but happens on EVERY event once at capacity. Memory-wise, 1000 string UUIDs at ~40 bytes each = ~40KB, which is fine. But the pattern is fragile — the `first` could be `undefined` if the set is empty (defensive check exists).

**Impact:** Minimal under normal load. Under very high event throughput (>1000 unique events), the oldest ID is always dropped, meaning recently-deduplicated events could be reprocessed if they arrive again.

**Fix:** Consider a `Map<string, timestamp>` or a ring buffer for smarter eviction that considers time, not just insertion order.

---

#### M-4: InboxService.getSelectedTicketId() Uses subscribe/unsubscribe for Synchronous Read

**File:** `apps/web/src/app/features/inbox/services/inbox.service.ts` lines 126-130

**Description:**
```typescript
private getSelectedTicketId(): string | null {
    let result: string | null = null;
    this.state.selectedTicketId$.pipe(take(1)).subscribe(id => result = id);
    return result;
}
```
Subscribing and immediately unsubscribing (`take(1)`) to read the current value of a BehaviorSubject works but is a code smell. `BehaviorSubject.getValue()` exists for this exact purpose.

**Impact:** Creates unnecessary subscription objects on every call. Minor performance concern.

**Fix:** Replace with `this.state.selectedTicketIdSubject.getValue()` or add a `getSelectedTicketIdSync()` method to StateService.

---

#### M-5: ComposerComponent Imports MessageApiService But Never Uses It

**File:** `apps/web/src/app/features/inbox/components/composer/composer.component.ts` lines 27

**Description:**
`MessageApiService` is injected (line 27) but never used in the component. This is dead code that creates confusion about the component's responsibilities.

**Impact:** Misleading dependency. Future developers may think the component handles API communication directly.

**Fix:** Remove the unused import and injection.

---

#### M-6: TicketRepository.findOpenByContact Uses Array Status Filter — Doctrine Query Inconsistency

**File:** `apps/api/src/Repository/TicketRepository.php` line 21

**Description:**
```php
return $this->findOneBy(['contact' => $contact, 'status' => ['new', 'active', 'waiting']]);
```
Doctrine's `findOneBy` translates array values to `IN()` clause and returns the first matching row. While functionally correct, this is inconsistent with the repository pattern — all other queries use DQL or custom query methods. The behavior is implicit and could break with different Doctrine configurations.

**Impact:** Low functional risk, but the method name `findOpenByContact` doesn't clearly indicate it searches for multiple statuses including 'waiting' (which might not be "open").

**Fix:** Use DQL or `createQueryBuilder()` for explicit WHERE IN clauses.

---

#### M-7: RetryMessageUseCase Owns a Separate messageCache — Duplicate Cache with ConversationStore

**File:** `apps/web/src/app/core/application/messaging/retry-message.use-case.ts` lines 55, 69-71
**File:** `apps/web/src/app/core/stores/conversation.store.ts` lines 59, 157-163

**Description:**
Both `RetryMessageUseCase` and `ConversationStore` maintain a `messageCache` for storing original payloads by tempId. `ConversationStore.retryMessage()` returns cached payload, while `RetryMessageUseCase.registerFailedMessage()` stores its own copy. This creates two independent caches for the same data.

**Impact:** If one cache is cleared but not the other, retry operations may find data in one but not the other. Inconsistent behavior.

**Fix:** Consolidate message cache ownership to a single store (preferably `ConversationStore`). `RetryMessageUseCase` should receive payload as input, not own a cache.

---

#### M-8: Seq Tracking Resets to 0 on Service Restart — Duplicates After Browser Refresh

**File:** `apps/web/src/app/core/realtime/mercure.service.ts` lines 30, 93-95, 245-248

**Description:**
`seqState` is an in-memory Map that resets to `{ lastSeq: 0 }` on service initialization. After a full page refresh, all previous seq values are lost. If the backend has already delivered events with seq 1-50, the frontend will accept all 50 again on page load (since it sees `0 < 1, 0 < 2, ...`).

The `processedEventIds` Set (line 27) provides some protection with a 1000-entry cap, but:
- It too resets on refresh
- If an event is older than the 1000-entry window, it won't be deduplicated

**Impact:** After browser refresh, all events in the current backpressure window are reprocessed, causing duplicate messages and store updates.

**Fix:** Persist seq state in sessionStorage or localStorage. On reconnect, restore `lastSeq` per ticket and merge with incoming events.

---

#### M-9: FakeWhatsappProvider `doSend()` Creates Subscription via `timer().subscribe()` — No Cleanup

**File:** `apps/web/src/app/core/providers/fake-whatsapp.provider.ts` lines 348-350

**Description:**
```typescript
timer(deliveryDelay).subscribe(() => {
    this.simulateDeliveryStatus(messageId, tempId);
});
```
The timer Subscription is created and never stored/unsubscribed. If the provider is destroyed or disconnected mid-delay, the callback still fires on a potentially stale reference.

**Impact:** Low (provider is a mock), but sets a pattern that could cause issues in real provider implementations.

**Fix:** Store the subscription and unsubscribe on `disconnect()`.

---

### 🟢 LOW

#### L-1: Missing Topics in MercureEvent Model — `direction` Field Not Modeled

**File:** `apps/web/src/app/core/models/ticket-event.model.ts` lines 1-26

**Description:**
The `RealtimeEvent` and `TicketEvent` models don't include `direction` (inbound/outbound) or `messageId` fields that the backend's `MessageCreatedEvent` payload includes. The frontend cannot distinguish between agent-sent and customer-sent messages via the event stream.

**Impact:** If the UI needs to show different handling for inbound vs outbound events, it can't determine this from the event alone.

**Fix:** Extend `RealtimeEvent` with optional `direction` and `messageId` fields.

---

#### L-2: No Error Reporting for Unknown Event Types

**File:** `apps/web/src/app/core/realtime/mercure.service.ts` lines 202-204

**Description:**
The `default` case in `applyStorePatch` silently ignores unknown event types. No error is logged to monitoring (Sentry, DataDog, etc.).

**Impact:** If a new event type is added on the backend, the frontend silently ignores it with no alert.

**Fix:** Log a warning with the unknown event type for operational visibility.

---

#### L-3: ConnectionStore Has No Time-Since-Last-Event Tracking

**File:** `apps/web/src/app/core/stores/connection.store.ts` lines 1-29

**Description:**
`ConnectionStore` tracks `status` and `eventCount` but not the timestamp of the last received event. The debug panel cannot show "last event received X seconds ago".

**Impact:** Reduced debuggability for connection health monitoring.

**Fix:** Add `lastEventTime` signal, updated each time `incrementEventCount()` is called.

---

#### L-4: Environment URLs Hardcoded — Different Ports in Dev vs Docker

**File:** `apps/web/src/environments/environment.ts` (mercureUrl: 'http://localhost:3000')
**File:** `apps/api/src/Application/Service/OutboxPublisherService.php` line 23 (mercureUrl: 'http://mercure:80/...')

**Description:**
The frontend connects to Mercure at `localhost:3000` while the backend publishes to `mercure:80` (Docker internal hostname). In non-Docker dev environments, the backend publisher will fail to reach Mercure. This configuration drift between environments can cause the publisher to silently fail while the frontend thinks everything is fine.

**Impact:** Events processed by backend but never published → stale frontend.

**Fix:** Make `mercureUrl` a configurable parameter in both services via environment variables.

---

#### L-5: OutboxPublisherService Fallback JWT Has Weak Signature

**File:** `apps/api/src/Application/Service/OutboxPublisherService.php` lines 178-190

**Description:**
When `MercureJwtService` is not available, the fallback generates a JWT with manual base64 encoding and HMAC-SHA256. However, the payload is not properly signed — the header and payload are base64-encoded JSON (not URL-safe base64), and the signature format may not match JWT standards (`base64(pack('H*', hex_hmac))`).

**Impact:** In environments without the JWT service, the fallback token is likely rejected by the Mercure hub, blocking all event publishing.

**Fix:** Remove the fallback entirely or ensure it produces RFC 7519-compliant tokens. Better: always require `MercureJwtService`.

---

## Store Analysis

### TicketStore

| Property | Value |
|----------|-------|
| **File** | `apps/web/src/app/core/stores/ticket.store.ts` |
| **Growth pattern** | `addOrUpdateTicket()` prepends new tickets, replaces existing by `ticket_id`. `loadTickets()` replaces all (capped at 1000). |
| **Bound checks** | `MAX_TICKETS = 1000` in `loadTickets()` only. `addOrUpdateTicket()` has NO bound check. |
| **Memory risk** | MEDIUM: `addOrUpdateTicket()` can grow unbounded if called repeatedly via realtime events. The 1000 cap only applies to bulk load, not incremental updates. |
| **Cleanup** | `reset()` clears all signals. Only called on full state reset. |
| **Immutability** | ✅ Uses spread and map for all mutations. Signals wrapped. |
| **Computed derived** | `filteredTickets`, `filterCounts`, `totalUnread` — all signal-based, efficient. |
| **Vulnerability** | `updateUnreadCount(line 98)` creates a shallow copy of metrics object — if metrics had nested properties, they'd be shared. Currently safe (metrics is flat). |

### ConversationStore

| Property | Value |
|----------|-------|
| **File** | `apps/web/src/app/core/stores/conversation.store.ts` |
| **Growth pattern** | `addMessage()` enforces cap (200). `loadMessages()` also enforces cap. |
| **Bound checks** | `MAX_MESSAGES = 200` enforced in BOTH addMessage and loadMessages. ✅ |
| **Memory risk** | LOW: Hard cap prevents unbounded growth. 200 messages ≈ ~50KB. |
| **Cleanup** | `reset()` clears messages, cache, loading, hasMore. Also per-message cache deletion on confirm/remove. |
| **Immutability** | ✅ Spread and map for all array operations. |
| **Cache management** | `messageCache` Map with tempId keys. Cleared on confirm, remove, reset. No bound checks though — hundreds of failed messages could accumulate. |
| **Vulnerability** | If `confirmMessage()` is never called for a tempId (crash mid-send), the cache entry persists until `reset()`. |

### InboxStore

| Property | Value |
|----------|-------|
| **File** | `apps/web/src/app/core/stores/inbox.store.ts` |
| **Status** | ⚠️ **Deprecated** — has a TODO comment at line 3: "TODO: Migrate to Ticket model or remove if unused" |
| **Growth pattern** | `loadChats()` capped at 1000. No incremental add — only full replace. |
| **Memory risk** | LOW. Only used via `loadChats` which replaces all. Likely unused in production. |
| **Immutability** | ✅ Uses spread and map. |
| **Note** | Coexists with `TicketStore` which is the active ticket store. Duplicate state. |

### ConnectionStore

| Property | Value |
|----------|-------|
| **File** | `apps/web/src/app/core/stores/connection.store.ts` |
| **Growth pattern** | No arrays — only single-value signals (`status`, `eventCount`). |
| **Memory risk** | NONE. |
| **Cleanup** | No cleanup needed. Status resets on component destroy by nature of signals. |
| **Vulnerability** | Very thin. No tracking of reconnection count, last event time, or connection duration. |

### UiStore

| Property | Value |
|----------|-------|
| **File** | `apps/web/src/app/core/stores/ui.store.ts` |
| **Growth pattern** | Two boolean signals (`hasNewMessages`, `isAtBottom`). No growth possible. |
| **Memory risk** | NONE. |
| **Cleanup** | No cleanup needed. |
| **Vulnerability** | `notifyNewMessages()` followed by `markViewed()` happens in sequence in the effect (ConversationComponent line 62-67). If `markViewed()` clears the flag before the component reads it, the indicator is missed. |

---

## Reconnection Analysis

### Current Behavior

```
MercureService.onerror (line 275)
  ├─ isConnected = false
  ├─ reconnectAttempts++
  ├─ metrics.increment('reconnectAttempts')
  └─ handleError() (line 251)
       ├─ connectionStatus.next(false)
       ├─ connectionState.set('disconnected')
       ├─ connectionStore.setOffline()
       ├─ eventSource.close()
       ├─ eventSource = null
       ├─ calculate delay: min(1000 * 2^(attempt-1), 30000)
       ├─ reconnectCount.set(attempt)
       ├─ connectionState.set('connecting')
       ├─ connectionStore.setReconnecting()
       └─ setTimeout(() => connect(), delay)
```

### What Works
- ✅ Exponential backoff with 30s max delay
- ✅ Connection state signals reflect current status
- ✅ ConnectionStore is updated on each state transition
- ✅ Metrics tracked for reconnect attempts
- ✅ Reconnect count displayed in debug panel

### What's Missing — CRITICAL GAPS

1. **No data sync after reconnect.** The new EventSource connection picks up from "now" — any events that arrived during the disconnect window are lost. Compare with Mercure's `Last-Event-ID` which is NOT used here.

2. **No max reconnect attempts limit.** The exponential backoff runs indefinitely. If the server is down for hours, the browser keeps retrying (plus making HTTP requests every 30s). No circuit breaker.

3. **No gap reconciliation.** The `seqState` Map is preserved (it's not cleared in `handleError`), but with no server-side seq request endpoint, it's useless for recovery.

4. **No jitter on backoff.** All clients reconnect simultaneously after the same delay window, potentially causing a thundering herd on the Mercure hub.

5. **No stale event cleanup.** The `processedEventIds` Set is preserved across reconnect. Event IDs that were dropped during disconnect can never be re-processed (they'd be detected as duplicates).

### Recommended Reconnection Protocol

```
1. Connect to Mercure
2. On onopen:
   a. Set connected state
   b. For each active ticket, check backend for seq > lastSeq
   c. Request replay of missed events (or batch refetch)
3. On each received event, update lastSeq
4. On onerror:
   a. Attempt reconnect with exponential backoff + jitter
   b. After N attempts (e.g., 10), show permanent "disconnected" state
   c. Offer "Retry now" button
5. On manual refresh:
   a. Clear all state
   b. Full refetch of tickets and active conversation
```

---

## Optimistic Update Analysis

### Full Lifecycle

```
1. User types message in ComposerComponent
   ├─ text.trim() → content
   ├─ generateTempId() → tempId
   ├─ conversationStore.addOptimisticMessage(tempId, content, chatId)
   │     └─ Creates BridgeMessage with deliveryStatus: 'sending'
   │           messageCache.set(tempId, { content, ticketId })
   └─ sendMessage.emit() → [BUT NO ACTUAL SEND — see H-6]

2. [IF wired to MessageSenderService.send()]:
   ├─ sendUseCase.execute({ ticketId, content })
   │     └─ provider.sendMessage(ticketId, content) → Observable<Message>
   │           ├─ SUCCESS: output.message.deliveryStatus !== 'failed'
   │           │    └─ conversationStore.confirmMessage(tempId, confirmed)
   │           │         ├─ Replaces tempId message with confirmed data
   │           │         ├─ messageCache.delete(tempId)
   │           │         └─ sendResults.next({ tempId, success: true })
   │           │
   │           └─ FAILURE: output.message.deliveryStatus === 'failed'
   │                └─ MessageSendFailed thrown
   │                     └─ error handler:
   │                          ├─ retryUseCase.registerFailedMessage(tempId, content, ticketId)
   │                          │    └─ messageCache.set(tempId, { content, ticketId })
   │                          └─ setTimeout → retryByTempId(tempId, maxRetries-1)
   │                               ├─ updateMessageStatus(tempId, 'sending')
   │                               └─ retryUseCase.execute({ tempId })
   │                                    └─ provider.sendMessage(ticketId, content)
   │                                         ├─ SUCCESS → confirmMessage()
   │                                         │    └─ messageCache.delete(tempId) [RetryMessageUseCase cache]
   │                                         └─ FAILURE → retry or permanent failure
   │                                              └─ updateMessageStatus(tempId, 'failed')
   └─ [Provider-side delivery status updates via FakeWhatsappProvider]
        └─ simulateDeliveryStatus()
             └─ emitStatusTransition(tempId, messageId, 'sent', 'delivered')
```

### What's Protected
- ✅ tempId reconciliation: optimistic → confirmed
- ✅ messageCache for retry payloads (though duplicated in two caches)
- ✅ deliveryStatus lifecycle: sending → sent/failed
- ✅ removeMessage for manual cleanup

### What's Broken or Missing

1. **H-6**: The optimistic message is created but never sent through the use case pipeline. The entire lifecycle above (steps 2+) is never triggered with the current ComposerComponent → ConversationComponent wiring.

2. **No rollback on failure**: When a message permanently fails, its status is set to 'failed' but it remains in the message list. There's no UI mechanism to auto-remove or collapse failed messages after a timeout.

3. **Duplicate caches**: `ConversationStore.messageCache` and `RetryMessageUseCase.messageCache` both store the same data (by reference from MessageSenderService). If one is cleared independently, retry operations find inconsistent state.

4. **No optimistic conflict detection**: If a message arrives via SSE while the optimistic message is still pending, both exist in the store. The SSE-delivered message has `tempId` undefined while the optimistic one has a matching `tempId`. There's no deduplication between SSE-delivered and optimistic messages of the same content.

5. **No send cancellation**: Once a send is initiated, there's no way to cancel it. If the user navigates away mid-send, the subscription fire-and-forget continues.

---

## Performance Boundaries

### Current Limits

| Resource | Limit | Configuration | Breakage Mode |
|----------|-------|---------------|---------------|
| Messages per conversation | 200 | `ConversationStore.MAX_MESSAGES = 200` | Oldest messages silently dropped; no pagination reload from cache |
| Tickets in list | 1000 | `TicketStore.MAX_TICKETS = 1000` | Tickets beyond 1000 not loaded; no search for them |
| Unique event IDs in dedup | 1000 | `MercureService.processedEventIds` Set (soft cap) | Oldest event ID evicted; duplicate could re-enter |
| Mercure reconnect delay | 30s max | `min(1000 * 2^(attempt-1), 30000)` | After ~6 attempts, stays at 30s indefinitely |
| Backend API limit (tickets) | 100/req | `InboxController` line 28: `min(100, limit)` | Paginated access, but frontend only loads first page |
| Backend API limit (messages) | 100/req | `InboxController` line 98: `min(100, limit)` | Cursor-based pagination |
| FakeWhatsappProvider failures | 5% default | `DEFAULT_CONFIG.failureRate = 0.05` | Configurable for stress testing |
| Retry attempts (use case) | 3 | `HandleOutboundMessageUseCase.$maxRetries = 3` | After 3rd failure, throws `ConcurrencyException` |
| Retry attempts (frontend) | 2 (send) + 2 (retry) | `MessageSenderService` default `maxRetries = 2` | 2 retries on initial send, 2 on each retry attempt |
| Outbox retries | 5 (defined, NOT enforced) | `OutboxPublisherService.MAX_RETRIES = 5` | Events retry forever; see C-5 |

### Where Things Break

1. **At 200+ messages**: Oldest messages in conversation are dropped. If user scrolls up to load older messages (`hasMore`), pagination cursor still works. But the cap causes visible "jumps" when new messages arrive and old ones disappear simultaneously.

2. **At 1000+ unique event IDs**: With high-frequency updates, the eviction threshold is crossed. Events that arrive again after eviction are re-processed, causing double-counting in metrics and store updates.

3. **At 30s disconnect window**: If the Mercure hub is down for >30s, the last known event is lost. The frontend has no mechanism to re-sync. Backend continues producing events. The gap widens indefinitely.

4. **At 100+ tickets**: Per-ticket subscriptions (InboxService line 59-64) create 100+ callback registrations in MercureService. Each realtime event triggers 100+ callbacks, each issuing an HTTP GET. The browser's 6-connection-per-origin limit is quickly exhausted.

5. **At 3+ failed outbound messages**: The retry chain (send → retry → retry...) creates nested `setTimeout` callbacks. Multiple concurrent failing messages create a callback storm. No backpressure mechanism across messages.

---

## Testability Assessment

### What's Easily Testable (Deterministic)

| Component | Approach | Challenges |
|-----------|----------|------------|
| **SendMessageUseCase** | Unit test with mock ProviderInterface | Simple: input → expected output |
| **RetryMessageUseCase** | Unit test with mock ProviderInterface | Cache state dependency |
| **ConversationStore** | Unit test all methods (addMessage, confirmMessage, etc.) | Signal-based — need `effect()` to spy |
| **TicketStore** | Unit test addOrUpdateTicket, filtering, unread counts | Signal-based |
| **MercureService.applyStorePatch** | Unit test with mock stores | Input event → which store methods called |
| **MercureService.handleEvent** | Unit test with mock stores | Same as applyStorePatch |
| **ConnectionStore** | Trivially testable | Just signal read/write |
| **Backend Use Cases** | PHPUnit with mocked repositories | Well-isolated |
| **TicketStateMachine** | Pure unit test | No dependencies; all valid/invalid transitions |
| **OutboxEvent** | Unit test | Simple entity |

### What Needs Chaos/Integration Testing

| Component | Test Type | What to Validate |
|-----------|-----------|------------------|
| **MercureService full lifecycle** | Integration | connect → receive events → disconnect → reconnect → data integrity |
| **EventSource reconnect** | Chaos | Network drop mid-stream, partial data, HTTP errors |
| **Seq out-of-order** | Chaos | Deliver events in reverse seq order, with delays |
| **Concurrent sends** | Load | 10+ messages sent simultaneously, track delivery order |
| **Optimistic + SSE collision** | Integration | Same message delivered via both optimistic and SSE paths |
| **Duplicate event handling** | Chaos | Same eventId received twice with delay, from different topics |
| **Store memory cap behavior** | Stress | Fill ConversationStore, TicketStore to max, validate oldest dropped |
| **Outbox publisher crash** | Chaos | Crash mid-publish, verify retry + no duplicate |
| **Optimistic locking retries** | Load | Concurrent outbound messages on same ticket |
| **Backend-to-frontend contract** | Integration | Publish real OutboxEvent from PHP, verify frontend parses correctly (C-2) |
| **Browser tab backgrounding** | Integration | Tab in background → SSE throttling → resume → state consistency |
| **Multiple tabs** | Integration | Two browser tabs, same agent, verify both receive same events |

### Existing Test Coverage

- **Backend**: ~66 integration/unit tests (outbox, concurrency, replay, security, automation)
- **Frontend**: **ZERO tests** as of report date

---

## Key Discoveries

### 1. 🚨 The Realtime Pipeline Is Broken at the Contract Level (C-2)

The most critical finding: the backend publishes flat domain event payloads to Mercure, but the frontend's `RealtimeEvent` model expects a nested structure with `ticketId` (camelCase) and a nested `event` property. Additionally, the backend uses PHP class names as event types (e.g., `App\Domain\Event\MessageCreatedEvent`) while the frontend expects `TicketEventType.MessageAdded` (`message_added`). No adapter layer exists to transform between these formats.

**Until this contract mismatch is fixed, all Mercure events from the real backend are silently dropped.** The app only works because `FakeWhatsappProvider` writes directly to stores, bypassing the SSE pipeline entirely.

### 2. 🚨 Dual Event Dispatch Causes Silent Data Corruption (C-1)

`MercureService.applyStorePatch()` and `MercureService.handleEvent()` both dispatch the same event to stores. This means every `MessageAdded` event calls `conversationStore.addMessage()` twice, and every `StatusChanged` calls `ticketStore.addOrUpdateTicket()` twice. Even if the pipeline contract is fixed (finding #1), the data reaches the stores in duplicate. The duplicate message would be added to the messages array (which has a cap of 200, so the second duplicate might push out a legitimate older message) — this is subtle data corruption.

### 3. 🚨 Messages Don't Actually Send (H-6)

The new architecture's message sending pipeline is incomplete. `ComposerComponent` creates optimistic messages in `ConversationStore` but never calls `MessageSenderService.send()`. The parent `ConversationComponent.onSendMessage()` only scrolls to bottom. Messages typed in the composer appear in the UI with `deliveryStatus: 'sending'` permanently — they never reach the provider or API. The `MessageSenderService` exists with full retry logic but is orphaned.

### 4. Dual Store Architecture — Two Parallel State Systems

The frontend has TWO independent state management systems running simultaneously:
- **Newer**: Signal-based stores (`TicketStore`, `ConversationStore`, `ConnectionStore`, `UiStore`) used by `ConversationComponent`
- **Legacy**: BehaviorSubject-based (`StateService` with `selectedTicketId$`, `tickets$`, `messages$`) used by `InboxService` and `ConversationPanelComponent`

Both coexist. Both receive events. Neither knows about the other. If both components are rendered, they show different state. The `InboxService` still uses the legacy `StateService` while progressively adopting new stores.

### 5. No Frontend Tests

Zero frontend tests exist. The backend has ~66 tests. For a realtime validation sprint, the complete absence of frontend tests means every identified vulnerability has no regression guard.

### 6. Reconnection Lacks Data Integrity

The reconnection logic re-establishes the EventSource connection but does NOT:
- Re-fetch server state to fill gaps
- Use `Last-Event-ID` for automatic replay
- Detect missed events via seq comparison
- Apply any jitter to backoff timing

A 5-second network glitch can cause permanent data loss.

### 7. N+1 HTTP Waterfall on Every Realtime Event (H-4)

`InboxService.subscribeToTickets()` creates a per-ticket callback that issues a full HTTP GET request for EVERY realtime event. With the wildcard topic delivering ALL events to ALL subscribers, each incoming message triggers N HTTP requests (N = number of tickets). For an agent with 50 open tickets, each customer message generates 50 HTTP GET requests to the backend. This is the single biggest performance vulnerability.

### 8. Seq Tracking Is In-Memory Only

Seq state for all tickets is stored in an in-memory `Map<string, SeqState>`. On page refresh, all seq values reset to 0. On page load, the frontend re-processes all events currently in the Mercure buffer (up to the 1000-event dedup cap), causing duplicate mutations. Persistent storage (sessionStorage) would mitigate this.

### 9. Two Identical Components for Conversation Display

`ConversationComponent` and `ConversationPanelComponent` serve the same purpose with different architectures. This is a migration artifact — the new component was created but the old one was never removed. Both are imported and could both render.

### 10. Outbox Has No Dead Letter Queue

Events that fail publishing accumulate `retry_count` indefinitely with no threshold enforcement (C-5). A failing event (bad topic, invalid payload) retries forever on every cron cycle, wasting resources and obscuring truly failing events.

---

## Summary of Action Items by Priority

### Fix Immediately (Blocking)
1. Reconcile `applyStorePatch` + `handleEvent` — keep only one dispatch path
2. Fix backend-to-frontend event contract mismatch (payload shape + topic type mapping)
3. Wire `ComposerComponent` → `MessageSenderService.send()` to complete the send pipeline
4. Add `ngOnDestroy` to `InboxPageComponent` with `mercure.disconnect()`
5. Fix `TicketCreatedEvent` ticketId (use post-flush ID, not conversation ID)
6. Enforce `MAX_RETRIES` in `OutboxPublisherService.handleFailure()`

### Fix Soon (High Impact)
7. Add state sync on reconnect (seq-based gap detection or full refetch)
8. Remove per-ticket subscriptions from `InboxService` (fix N+1 HTTP)
9. Add heartbeat/health check to MercureService
10. Persist seq state across page refresh (sessionStorage)
11. Consolidate dual stores (deprecate InboxStore + StateService)
12. Add circuit breaker to Mercure reconnection (max attempts)

### Fix When Possible (Medium)
13. Replace `setTimeout` retry with RxJS operators in MessageSenderService
14. Add out-of-order seq buffer with time window
15. Consolidate `messageCache` (remove duplicate in RetryMessageUseCase)
16. Add dead letter queue for outbox events exceeding max retries
17. Write frontend tests for store operations + MercureService event handling
18. Add jitter to reconnect backoff
