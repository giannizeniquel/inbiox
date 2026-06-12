# Tasks: Sprint 12.5 — Realtime Stabilization (Pre-Stress)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1800–2200 (additions + deletions across 28 tasks) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (Alpha: backend) → PR 2 (Alpha: frontend) → PR 3 (Bravo) → PR 4 (Charlie+Final) |
| Delivery strategy | ask-on-risk |
| Chain strategy | stacked-to-main |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Notes |
|------|------|-----------|-------|
| 1 | Alpha backend: migration + contract + publisher + C-4 fix + dead-letter | PR 1 | Base: main. ~500 lines. Tests updated. |
| 2 | Alpha frontend: enum + single dispatch + handleEvent rewrite | PR 2 | Base: PR 1 branch. ~400 lines. |
| 3 | Bravo: resync endpoint + gap detection + lifecycle + N+1 fix | PR 3 | Base: PR 2 branch. ~600 lines. |
| 4 | Charlie + Final: send pipeline + deprecations + regression | PR 4 | Base: PR 3 branch. ~400 lines. |

---

## Task Summary

| ID | Checkpoint | Layer | Description | Depends On | Est. Lines |
|----|-----------|-------|-------------|------------|------------|
| T-01 | ✅ Alpha | backend | OutboxEvent migration: status + sequence columns | — | ~80 |
| T-02 | ✅ Alpha | backend | RealtimeEventType enum + EventTypeMapper + RealtimeEventFactory | T-01 | ~150 |
| T-03 | ✅ Alpha | backend | OutboxPublisherService wire format (RealtimeEventFactory adapter) | T-02 | ~60 |
| T-04 | ✅ Alpha | backend | HandleInboundMessageUseCase: move TicketCreatedEvent post-flush | T-01 | ~30 |
| T-05 | ✅ Alpha | backend | Enforce MAX_RETRIES + dead-letter in OutboxPublisherService | T-01 | ~40 |
| T-06 | ✅ Alpha | frontend | RealtimeEventType enum + RealtimeEvent\<T\> interface | — | ~50 |
| T-07 | ✅ Alpha | frontend | MercureService.handleMessage() single dispatch (remove applyStorePatch) | T-06 | ~60 |
| T-08 | ✅ Alpha | frontend | MercureService.handleEvent() strict switch over RealtimeEventType | T-06, T-07 | ~80 |
| T-09 | ✅ Alpha | test | Update test_event_payload_contract for content field | T-02, T-04 | ~20 |
| T-10 | ✅ Bravo | backend | TicketEventsController: GET /api/tickets/{id}/events | T-02 | ~100 |
| T-11 | ✅ Bravo | backend | OutboxPublishCommand status filter + OutboxDeadLetterCommand | T-05 | ~120 |
| T-12 | ✅ Bravo | frontend | MercureService.resyncTicket() + resyncAllTickets() | T-08, T-10 | ~80 |
| T-13 | ✅ Bravo | frontend | MercureService.onopen calls resyncAllTickets() | T-12 | ~15 |
| T-14 | ✅ Bravo | frontend | Per-ticket gap state machine (TicketSeqState) | T-08 | ~70 |
| T-15 | ✅ Bravo | frontend | InboxPageComponent.ngOnDestroy → mercure.disconnect() | — | ~10 |
| T-16 | ✅ Bravo | frontend | InboxService: remove per-ticket HTTP subscriptions (N+1 fix) | T-08 | ~40 |
| T-17 | Bravo | frontend | ConnectionStore: setPermanentFailure() + lastEventTime + circuit breaker | T-14 | ~40 |
| T-18 | ✅ Bravo | frontend | DebugPanelComponent: "Retry Now" button | T-17 | ~30 |
| T-19 | Charlie | frontend | MessageSenderService.send(): retryWithBackoff (RxJS) | T-08 | ~50 |
| T-20 | Charlie | frontend | RetryMessageUseCase: remove internal cache | T-19 | ~30 |
| T-21 | Charlie | frontend | ConversationComponent.onSendMessage() → messageSender.send() | T-19 | ~20 |
| T-22 | Charlie | frontend | ComposerComponent: remove unused MessageApiService injection | T-21 | ~15 |
| T-23 | Charlie | frontend | InboxService.sendMessage: removed | T-21 | ~10 |
| T-24 | Final | frontend | StateService: @deprecated JSDoc | — | ~5 |
| T-25 | Final | frontend | InboxStore: @deprecated JSDoc | — | ~5 |
| T-26 | Final | frontend | ConversationPanelComponent: @deprecated JSDoc | — | ~5 |
| T-27 | Final | test | Full regression: backend ~66 tests pass | T-01–T-11 | ~0 |
| T-28 | Final | test | Sprint 13 readiness checklist (12 criteria) | T-01–T-26 | ~0 |

**Total estimated: ~1800 lines changed**

---

## Checkpoint Alpha — Foundation (contract + dispatch + ticketId)

> Must complete first. Blocks all other phases.
> **Verification**: Real Mercure event published by OutboxPublisherService is parsed and applied by frontend without errors. Single mutation per event.

### T-01 — ✅ OutboxEvent migration: status + sequence columns

- **Checkpoint**: Alpha
- **Layer**: backend
- **Files affected**:
  - `apps/api/src/Domain/Outbox/OutboxEvent.php` — add `status` (string, default 'pending') and `sequence` (int, default 0) fields with getters/setters; add `?int $sequence = 0` constructor param
  - `apps/api/migrations/Version20260612000001.php` — NEW: ALTER TABLE add columns, backfill sequence from `JSON_EXTRACT(payload, '$.seq')`, create indexes `idx_outbox_ticket_seq (aggregate_id, sequence)` and `idx_outbox_status (status, published_at)`
  - `apps/api/src/Application/Service/OutboxPublisherService.php` — `hydrateEvent()`: set sequence via reflection from row
- **Description**: Add `status VARCHAR(20) DEFAULT 'pending'` and `sequence INT DEFAULT 0` columns to `outbox_events`. Backfill `sequence` from existing JSON payload. Add `getStatus()`, `setStatus()`, `isDeadLetter()` to entity. Constructor gets optional `?int $sequence = 0` param (backward compat — existing test `test_publisher_marks_event_as_published` uses 5-arg constructor).
- **Depends on**: —
- **Verification**: `docker exec inbiox_php php bin/console doctrine:migrations:migrate` succeeds. `DESCRIBE outbox_events` shows new columns. Existing tests still pass (default values).
- **Risk**: R1 — malformed payload rows get sequence=0; filtered by status index.
- **Estimated lines**: ~80

### T-02 — ✅ RealtimeEventType enum + EventTypeMapper + RealtimeEventFactory

- **Checkpoint**: Alpha
- **Layer**: backend
- **Files affected**:
  - `apps/api/src/Application/Realtime/RealtimeEventType.php` — NEW: backed enum with 6 cases
  - `apps/api/src/Application/Realtime/EventTypeMapper.php` — NEW: FQCN→enum mapping, `toEnum()`, `isTicketAssigned()`
  - `apps/api/src/Application/Realtime/RealtimeEventFactory.php` — NEW: `build(OutboxEvent): array` returns contract v1 shape; `buildPayload()` match over 6 enum values
  - `apps/api/src/Domain/Event/MessageUpdatedEvent.php` — NEW: stub class (no callers yet)
  - `apps/api/src/Domain/Event/ConversationUpdatedEvent.php` — NEW: stub class (no callers yet)
- **Description**: Create the PHP-side contract mapping layer. `RealtimeEventType` enum: `ticket.created`, `ticket.status_changed`, `ticket.assigned`, `message.added`, `message.updated`, `conversation.updated`. `EventTypeMapper` maps PHP FQCN→enum. `RealtimeEventFactory::build()` reads stored snake_case payload and returns camelCase contract array: `{eventId, sequence, timestamp, eventType, ticketId, payload}`. Stub `MessageUpdatedEvent` and `ConversationUpdatedEvent` (reserved enum values, no wire emission yet).
- **Depends on**: T-01 (needs OutboxEvent entity)
- **Verification**: Unit test: `RealtimeEventFactory::build()` with a `TicketCreatedEvent` outbox row returns `{eventType: 'ticket.created', payload: {ticketId, contactId, ...}}` in camelCase. `EventTypeMapper::toEnum()` throws on unknown FQCN.
- **Risk**: R12 — `message.added` payload requires `content` field; ensure `HandleInboundMessageUseCase::persistEventsToOutbox()` includes it.
- **Estimated lines**: ~150

### T-03 — ✅ OutboxPublisherService wire format (RealtimeEventFactory adapter)

- **Checkpoint**: Alpha
- **Layer**: backend
- **Files affected**:
  - `apps/api/src/Application/Service/OutboxPublisherService.php` — inject `RealtimeEventFactory`; `publishEvent()`: replace `json_encode($event->getPayload())` with `json_encode($this->factory->build($event))`; `findUnpublishedEvents()`: add `AND status = 'pending'` to WHERE clause; `markPublished()`: also set `status = 'published'`
- **Description**: Wire `RealtimeEventFactory` into the publisher. The Mercure SSE `data` field now sends the contract v1 JSON (camelCase, string eventType) instead of raw stored payload. Query filter adds `status = 'pending'`. `markPublished()` sets both `published_at` and `status = 'published'`.
- **Depends on**: T-02
- **Verification**: `docker exec inbiox_php php bin/console app:outbox:publish` publishes an event. Mercure subscriber receives `{eventType: "ticket.created", ...}` (not FQCN). Existing test `test_outbox_event_persisted_after_inbound_message` still passes (stored payload unchanged).
- **Risk**: R10 — stored payload format unchanged; Sprint 09B tests check stored values, not wire format.
- **Estimated lines**: ~60

### T-04 — ✅ HandleInboundMessageUseCase: move TicketCreatedEvent post-flush

- **Checkpoint**: Alpha
- **Layer**: backend
- **Files affected**:
  - `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` — move `TicketCreatedEvent` collection from line ~168 (before flush) to after line 204 (after flush); change `ticketId: (int) $conversation->getId()` to `ticketId: $ticket->getId()`; capture `seq` from `incrementLastEventSeq()` before flush
- **Description**: Fix C-4: TicketCreatedEvent currently uses conversation ID because ticket isn't persisted yet. Move collection AFTER `$em->flush()` so `$ticket->getId()` returns the real DB ID. The `seq` value is captured via `$ticket->incrementLastEventSeq()` which is called before flush (the entity field is updated by Doctrine on flush).
- **Depends on**: T-01
- **Verification**: Unit test: create new contact+message, assert OutboxEvent for TicketCreatedEvent has `aggregate_id = $ticket->getId()` (not `$conversation->getId()`). Test `test_sequence_increments_correctly`: verify seq ordering (TicketCreatedEvent=1, first MessageCreatedEvent=2, second=3).
- **Risk**: R2, R6 — test `test_sequence_increments_correctly` may need seq assertion update (TicketCreatedEvent now seq=1, MessageCreatedEvent seq=2).
- **Estimated lines**: ~30

### T-05 — ✅ Enforce MAX_RETRIES + dead-letter in OutboxPublisherService

- **Checkpoint**: Alpha
- **Layer**: backend
- **Files affected**:
  - `apps/api/src/Application/Service/OutboxPublisherService.php` — `handleFailure()`: check `retryCount + 1 >= MAX_RETRIES (5)`; if exceeded, set `status = 'dead_letter'` via SQL UPDATE, log error with event_id/retry_count/event_type/aggregate_id; do NOT increment further
- **Description**: Current `handleFailure()` blindly increments retry_count forever. Add guard: if new retryCount >= 5, set `status = 'dead_letter'` and emit error log. Events with `status = 'dead_letter'` are excluded from `findUnpublishedEvents()` by the `status = 'pending'` filter (added in T-03).
- **Depends on**: T-01
- **Verification**: Unit test: mock Mercure to return 500 six times; assert event transitions pending→...→dead_letter at retry_count=5. Error log contains event_id.
- **Risk**: Low — additive guard, existing retry logic unchanged for count < 5.
- **Estimated lines**: ~40

### T-06 — RealtimeEventType enum + RealtimeEvent\<T\> interface

- **Checkpoint**: Alpha
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/core/models/realtime-event.model.ts` — NEW: `RealtimeEventType` enum (6 values matching PHP), `RealtimeEvent<T>` interface, payload type interfaces per event
  - `apps/web/src/app/core/models/ticket-event.model.ts` — keep file but add `@deprecated` comment at top (do NOT delete; old types still imported by MercureService until T-07/T-08)
- **Description**: Create the TypeScript-side contract. `RealtimeEventType` enum: `TicketCreated = 'ticket.created'`, etc. `RealtimeEvent<T>` interface: `{eventId: string, sequence: number, timestamp: string, eventType: RealtimeEventType, ticketId?: string, conversationId?: string, payload: T}`. Typed payload interfaces: `MessageAddedPayload`, `TicketCreatedPayload`, `TicketStatusChangedPayload`, etc.
- **Depends on**: —
- **Verification**: `ng build` succeeds. New file exports compile. Enum values match PHP `RealtimeEventType` exactly.
- **Risk**: Low — new file, no callers yet.
- **Estimated lines**: ~50

### T-07 — MercureService.handleMessage() single dispatch (remove applyStorePatch)

- **Checkpoint**: Alpha
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/core/realtime/mercure.service.ts` — remove `applyStorePatch()` method entirely; remove `subscriptions` Map and `subscribe()` method; `handleMessage()`: remove call to `applyStorePatch()` and subscriber callback dispatch; keep only `handleEvent()` call + `eventsSubject.next()`
- **Description**: Eliminate dual dispatch. Current `handleMessage()` calls BOTH `applyStorePatch()` and `handleEvent()` for the same event, causing duplicate store mutations. Remove `applyStorePatch()` entirely. Remove per-ticket `subscriptions` Map (replaced by `events$` observable). Single path: parse → dedup → seq check → `handleEvent()` → emit on `events$`.
- **Depends on**: T-06
- **Verification**: `grep -rn "applyStorePatch" apps/web/src` returns 0 results. `grep -rn "subscribe(ticketId" apps/web/src` returns 0 results (except test files). Each event produces exactly one store mutation.
- **Risk**: R7 — no frontend tests exist for realtime pipeline (deferred to Sprint 13).
- **Estimated lines**: ~60

### T-08 — MercureService.handleEvent() strict switch over RealtimeEventType

- **Checkpoint**: Alpha
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/core/realtime/mercure.service.ts` — rewrite `handleEvent()` to accept `RealtimeEvent` (from T-06); strict switch over `RealtimeEventType`: `message.added` → `conversationStore.addMessage()` or `confirmMessage()`; `ticket.created/status_changed/assigned` → `ticketStore.addOrUpdateTicket()`; `message.updated` → `conversationStore.updateMessage()`; `conversation.updated` → no-op; unknown → `console.warn` + drop; update import from `ticket-event.model` to `realtime-event.model`
- **Description**: Rewrite `handleEvent()` to use the new `RealtimeEvent<T>` contract. Switch over `event.eventType` (the string enum). Each case extracts `event.payload` with correct typed interface and calls the appropriate store method. No more `event.event?.type` nested access. No more `as any` casts. Unknown event types logged and dropped.
- **Depends on**: T-06, T-07
- **Verification**: `ng build` succeeds. Manual: publish a `ticket.created` event via backend, verify `TicketStore` receives it. Publish `message.added`, verify `ConversationStore` receives it.
- **Risk**: Medium — store method signatures must match new payload shapes.
- **Estimated lines**: ~80

### T-09 — Update test_event_payload_contract for content field

- **Checkpoint**: Alpha
- **Layer**: test
- **Files affected**:
  - `apps/api/tests/Integration/Sprint09BOutboxTest.php` — `test_event_payload_contract()`: remove `assertArrayNotHasKey('content', $payload)` assertion; add `assertArrayHasKey('content', $payload)` for `MessageCreatedEvent` events; verify `test_sequence_increments_correctly()` assertions match new ordering (TicketCreated=1, Message1=2, Message2=3)
- **Description**: The new wire contract requires `content`, `direction`, `sender_name`, `external_id` in the `MessageCreatedEvent` outbox payload. Current test asserts `content` is NOT present (Sprint 09B design). Update: assert `content` IS present for message events. Also verify seq ordering after T-04 move.
- **Depends on**: T-02, T-04
- **Verification**: `docker exec inbiox_php php bin/phpunit tests/Integration/Sprint09BOutboxTest.php` — all 5 tests pass.
- **Risk**: R12 — high. Test currently asserts content NOT in payload; must flip assertion.
- **Estimated lines**: ~20

---

## Checkpoint Bravo — Reconnect (gap detection + resync + lifecycle + N+1)

> Builds on Alpha. All tasks below require the unified contract from Alpha.
> **Verification**: Disconnect for 5s, reconnect. Ticket list + active conversation match server. No duplicates.

### T-10 — ✅ TicketEventsController: GET /api/tickets/{id}/events

- **Checkpoint**: Bravo
- **Layer**: backend
- **Files affected**:
  - `apps/api/src/Controller/TicketEventsController.php` — NEW controller: `listSince(int $id, Request $request): JsonResponse`; route `GET /api/tickets/{id}/events`; validates JWT topic `ticket/{id}`; queries `outbox_events WHERE aggregate_id = :id AND sequence > :afterSequence AND status IN ('pending','published') ORDER BY sequence ASC LIMIT 100`; returns `{events: [...], lastSequence: N, hasMore: bool}` using `RealtimeEventFactory::build()` per row
- **Description**: Resync endpoint for frontend gap recovery. Response shape uses `events`/`lastSequence`/`hasMore` (NOT `data`/`highestSequence` — per critical adjustment). Auth: JWT must include `ticket/{id}` in subscribe topics. Query uses indexed `(aggregate_id, sequence)` from T-01. Cap at 100 events; `hasMore = true` when capped.
- **Depends on**: T-02 (needs RealtimeEventFactory)
- **Verification**: `curl -H "Authorization: Bearer <jwt>" http://localhost/api/tickets/42/events?afterSequence=10` returns `{events: [...], lastSequence: 12, hasMore: false}`. 403 without JWT topic. 400 with negative afterSequence. 404 for nonexistent ticket.
- **Risk**: R5 — JWT topic validation critical for security.
- **Estimated lines**: ~100

### T-11 — ✅ OutboxPublishCommand status filter + OutboxDeadLetterCommand

- **Checkpoint**: Bravo
- **Layer**: backend
- **Files affected**:
  - `apps/api/src/Command/OutboxPublishCommand.php` — add `--status` option (default: 'pending'); pass to publisher
  - `apps/api/src/Command/OutboxDeadLetterCommand.php` — NEW: 3 subcommands: `list` (shows dead_letter events), `retry {id}` (resets status='pending', retry_count=0), `purge {before}` (deletes dead_letter older than N days)
- **Description**: Extend `OutboxPublishCommand` with optional `--status` filter. Create `OutboxDeadLetterCommand` for operator management of dead-lettered events. Uses Doctrine Connection for direct SQL (consistent with existing patterns).
- **Depends on**: T-05
- **Verification**: `php bin/console app:outbox:dead-letter list` shows dead-lettered events. `php bin/console app:outbox:dead-letter retry <id>` resets event to pending. `php bin/console app:outbox:publish --status=pending` works.
- **Risk**: Low — new CLI commands, no production callers.
- **Estimated lines**: ~120

### T-12 — ✅ MercureService.resyncTicket() + resyncAllTickets()

- **Checkpoint**: Bravo
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/core/realtime/mercure.service.ts` — add `private resyncTicket(ticketId: string): Observable<void>` (HTTP GET, apply events in order via `handleEvent()`, update seqState); add `private resyncAllTickets(): void` (iterate `seqState.keys()`, call `resyncTicket` for each); inject `HttpClient`
- **Description**: Implement sequence-based recovery. `resyncTicket()` fetches `GET /api/tickets/{id}/events?afterSequence={lastSeq}`, sorts by sequence ASC, applies each via `handleEvent()`, updates `seqState`. `resyncAllTickets()` iterates all tracked tickets. Uses `HttpClient` with JWT auth (existing interceptor).
- **Depends on**: T-08 (needs handleEvent contract), T-10 (needs backend endpoint)
- **Verification**: Manual: call `resyncTicket('42')` from debug console, verify missed events applied in order. Network tab shows single GET request.
- **Risk**: R8 — 100-event cap may miss events in high-throughput; `hasMore` pagination deferred.
- **Estimated lines**: ~80

### T-13 — ✅ MercureService.onopen calls resyncAllTickets()

- **Checkpoint**: Bravo
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/core/realtime/mercure.service.ts` — in `connect()`, `eventSource.onopen` handler: after `connectionStore.setOnline()` and reset attempts, call `this.resyncAllTickets()`
- **Description**: On every (re)connection, trigger full resync of all tracked tickets. This ensures no events are missed during disconnect window. The resync runs once per `onopen`, applies missed events, then normal SSE processing resumes.
- **Depends on**: T-12
- **Verification**: Manual: disconnect SSE for 5s, reconnect. Verify ticket list and active conversation match server state. Network tab shows GET `/events?afterSequence=N` on reconnect.
- **Risk**: Low — additive call in existing handler.
- **Estimated lines**: ~15

### T-14 — ✅ Per-ticket gap state machine (TicketSeqState) + Circuit Breaker

- **Checkpoint**: Bravo
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/core/realtime/mercure.service.ts` — replace `SeqState` interface with `TicketSeqState {lastSeq, state, inFlightResync?}`; `seqState` becomes `Map<string, TicketSeqState>`; add `transitionTo()` method; `handleMessage()`: implement gap detection (seq > expectedSeq → GAP_DETECTED → trigger resync; seq < expectedSeq → drop; seq === expectedSeq → apply + increment); states: 'connected' | 'gap_detected' | 'resyncing'
- **Description**: Upgrade flat `lastSeq` tracking to a per-ticket state machine. On gap detection (event.seq > lastSeq + 1), transition to 'gap_detected', trigger targeted resync for that ticket only. Other tickets' events continue processing. `inFlightResync` AbortController cancels prior resync if new gap detected.
- **Depends on**: T-08
- **Verification**: Manual: simulate gap (event with seq=14 when lastSeq=10), verify targeted resync triggered for that ticket. Other tickets' events still processed. Console logs show state transitions.
- **Risk**: R3 — Map grows unbounded; full clear on `disconnect()` mitigates.
- **Estimated lines**: ~70

### T-15 — ✅ InboxPageComponent.ngOnDestroy → mercure.disconnect()

- **Checkpoint**: Bravo
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/features/inbox/pages/inbox-page/inbox-page.component.ts` — implement `OnDestroy`; add `ngOnDestroy(): void { this.mercure.disconnect(); }`
- **Description**: Fix connection lifecycle leak. Currently `InboxPageComponent` calls `mercure.connect()` in `ngOnInit()` but never disconnects. Navigating away leaves stale EventSource connections. Add `ngOnDestroy()` cleanup.
- **Depends on**: —
- **Verification**: Navigate to inbox, away, back. Network tab shows exactly ONE EventSource connection at any time. No accumulated connections.
- **Risk**: Low — 3-line change.
- **Estimated lines**: ~10

### T-16 — ✅ InboxService: remove per-ticket HTTP subscriptions (N+1 fix)

- **Checkpoint**: Bravo
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/features/inbox/services/inbox.service.ts` — delete `subscribeToTickets()` method; delete `handleRealtimeEvent()` method; `loadTickets()`: remove `subscribeToTickets(tickets)` call; delete `sendMessage()` method (use MessageSenderService directly)
- **Description**: Eliminate N+1 HTTP refetch pattern. Current `subscribeToTickets()` registers per-ticket Mercure callbacks that issue `GET /tickets/{id}` on every SSE event. With the unified dispatch from T-07/T-08, all realtime updates come through `MercureService.events$` → stores directly. Remove the per-ticket subscription layer.
- **Depends on**: T-08
- **Verification**: `grep -rn "subscribeToTickets\|handleRealtimeEvent" apps/web/src` returns 0 results. Network tab: send SSE event, verify zero HTTP GET requests for ticket refresh.
- **Risk**: Low — methods have no other callers.
- **Estimated lines**: ~40

### T-17 — ConnectionStore: setPermanentFailure() + lastEventTime + circuit breaker

- **Checkpoint**: Bravo
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/core/stores/connection.store.ts` — add `'permanent_failure'` to status union type; add `setPermanentFailure()` method; add `lastEventTime = signal<number | null>(null)` signal; add `setLastEventTime(ts: number)` method
  - `apps/web/src/app/core/realtime/mercure.service.ts` — `handleError()`: add circuit breaker check — if `reconnectAttempts >= 10`, call `connectionStore.setPermanentFailure()` and STOP auto-retry; `handleMessage()`: call `connectionStore.setLastEventTime(Date.now())`
- **Description**: Extend ConnectionStore with permanent failure state and last-event tracking. MercureService circuit breaker: after 10 consecutive failed reconnects, stop auto-retry and set `permanent_failure`. User must manually click "Retry Now" (T-18). `lastEventTime` enables stale-connection detection (future sprint).
- **Depends on**: T-14
- **Verification**: Simulate 10 failed reconnects (mock EventSource to always error). Verify `connectionStore.status() === 'permanent_failure'`. No further auto-reconnect attempts.
- **Risk**: R9 — threshold 10 may be aggressive; configurable via DI in future.
- **Estimated lines**: ~40

### T-18 — ✅ DebugPanelComponent: "Retry Now" button

- **Checkpoint**: Bravo
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/features/inbox/components/debug-panel/debug-panel.component.ts` — add `forceReconnect()` method: calls `mercure.connect()` with reset attempt counter; add `showRetryButton` computed signal: `connectionStore.status() === 'permanent_failure'`
  - `apps/web/src/app/features/inbox/components/debug-panel/debug-panel.component.html` — add "Retry Now" button, shown when `showRetryButton()` is true; `(click)="forceReconnect()"`
  - `apps/web/src/app/core/realtime/mercure.service.ts` — add `forceReconnect()` public method: reset `reconnectAttempts = 0`, call `connect()`
- **Description**: UI for circuit breaker recovery. When connection enters `permanent_failure`, show a "Retry Now" button in the debug panel. Clicking resets the attempt counter and reconnects.
- **Depends on**: T-17
- **Verification**: Trigger permanent failure (T-17 verification). "Retry Now" button appears. Click it. Connection re-established.
- **Risk**: Low — UI addition only.
- **Estimated lines**: ~30

---

## Checkpoint Charlie — Send Pipeline (composer wiring + retry + elimination)

> Builds on Bravo. Message sending requires working SSE for confirmation.
> **Verification**: Type message, press send, see deliveryStatus transition from 'sending' → 'sent' within 5s.

### T-19 — MessageSenderService.send(): retryWithBackoff (RxJS)

- **Checkpoint**: Charlie
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/features/inbox/services/message-sender.service.ts` — replace `setTimeout` retry chain in `send()` with `retryWithBackoff()` from `core/utils/retry.util.ts`; `send()`: `retryWithBackoff(this.sendUseCase.execute({ticketId, content}), {maxRetries: 2, initialDelayMs: 1000, maxDelayMs: 5000}).subscribe({next: confirm, error: fail})`; replace `retryByTempId()` setTimeout recursion with `retryWithBackoff` wrapping `retryUseCase.execute()`
- **Description**: Replace nested `setTimeout` retry logic with reactive `retryWithBackoff`. Current implementation uses recursive `setTimeout` calls with manual delay calculation. New implementation uses the existing `retry.util.ts` utility (RxJS `retry` + exponential backoff). Each send gets independent retry pipeline. Max 2 retries (3 total attempts), backoff 1s → 2s.
- **Depends on**: T-08 (needs working SSE for confirmation)
- **Verification**: Send message. DevTools console shows `[Retry]` logs only on failure. On success: `deliveryStatus` transitions 'sending' → 'sent'. On failure after 3 attempts: 'sending' → 'failed'. No nested setTimeout in source.
- **Risk**: Low — `retryWithBackoff` already exists and is tested.
- **Estimated lines**: ~50

### T-20 — RetryMessageUseCase: remove internal cache

- **Checkpoint**: Charlie
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/core/application/messaging/retry-message.use-case.ts` — delete `messageCache` Map; delete `registerFailedMessage()` method; delete `clearCache()` method; `execute()`: require `originalContent` and `ticketId` in input (no cache fallback); caller passes payload explicitly
- **Description**: Remove duplicate cache. `RetryMessageUseCase` currently maintains its own `messageCache` Map, duplicating `ConversationStore.messageCache`. After this change, the caller (ConversationComponent.onRetry) reads from `ConversationStore` and passes `originalContent`/`ticketId` directly in `RetryMessageInput`.
- **Depends on**: T-19
- **Verification**: `grep -rn "messageCache\|registerFailedMessage" apps/web/src/app/core/application` returns 0 results. Retry flow still works: fail → retry button → passes content from store → succeeds.
- **Risk**: Low — caller updated in same checkpoint.
- **Estimated lines**: ~30

### T-21 — ConversationComponent.onSendMessage() → messageSender.send()

- **Checkpoint**: Charlie
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/features/inbox/containers/conversation/conversation.component.ts` — inject `MessageSenderService`; rewrite `onSendMessage()`: read active ticket + composer text, call `this.messageSender.send(ticket.ticket_id, text)`, clear composer text, scroll to bottom
- **Description**: Wire the send pipeline. Current `onSendMessage()` only calls `scrollToBottom()` — it never actually sends. New implementation: inject `MessageSenderService`, read ticket and text, call `send()`. This connects ComposerComponent → ConversationComponent → MessageSenderService → ProviderInterface.
- **Depends on**: T-19
- **Verification**: Type "Hello" in composer, press Enter. Optimistic message appears with `deliveryStatus: 'sending'`. Within 5s, transitions to `'sent'`. Console shows no errors.
- **Risk**: Medium — end-to-end wiring; multiple integration points.
- **Estimated lines**: ~20

### T-22 — ComposerComponent: remove unused MessageApiService injection

- **Checkpoint**: Charlie
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/features/inbox/components/composer/composer.component.ts` — remove `private readonly messageApi = inject(MessageApiService)`; remove `private readonly conversationStore = inject(ConversationStore)` (move optimistic add to MessageSenderService); remove `generateTempId` import and call; `send()`: only emit `sendMessage` output + clear text
- **Description**: Slim down ComposerComponent. Currently it injects `MessageApiService` (unused — M-5 from design) and creates optimistic messages locally. After T-21, `MessageSenderService.send()` handles optimistic add. Composer becomes a pure input component: text + emit.
- **Depends on**: T-21
- **Verification**: `grep -rn "MessageApiService" apps/web/src/app/features/inbox/components/composer` returns 0 results. Composer still emits `sendMessage` on Enter. Text clears after send.
- **Risk**: Low — removal of unused injection.
- **Estimated lines**: ~15

### T-23 — InboxService.sendMessage: removed

- **Checkpoint**: Charlie
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/features/inbox/services/inbox.service.ts` — delete `sendMessage()` method; delete `getSelectedTicketId()` private helper (no longer needed after sendMessage removal)
- **Description**: Remove dead send path. `InboxService.sendMessage()` uses `MessageApiService` directly (legacy path). After T-21, all sending goes through `MessageSenderService`. This method has no callers (ComposerComponent emits to ConversationComponent, not InboxService).
- **Depends on**: T-21
- **Verification**: `grep -rn "inboxService.sendMessage\|inbox\.sendMessage" apps/web/src` returns 0 results. `ng build` succeeds.
- **Risk**: Low — dead code removal.
- **Estimated lines**: ~10

---

## Checkpoint Final — Cleanup + Verification

> **Verification**: All P0 + P1 fixed. Sprint 13 gate openable.

### T-24 — StateService: @deprecated JSDoc

- **Checkpoint**: Final
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/core/services/state.service.ts` — add `/** @deprecated Use TicketStore + ConversationStore signal stores. Removal in Sprint 13.5. */` at class level
- **Description**: Mark `StateService` as deprecated. Do NOT delete. BehaviorSubject-based state is superseded by signal stores. Components still reading from it work but new code must use `TicketStore`/`ConversationStore`.
- **Depends on**: —
- **Verification**: IDE shows deprecation warning on `StateService` usage. File unchanged functionally.
- **Risk**: Low — comment only.
- **Estimated lines**: ~5

### T-25 — InboxStore: @deprecated JSDoc

- **Checkpoint**: Final
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/core/stores/inbox.store.ts` — add `/** @deprecated Superseded by TicketStore. Removal in Sprint 13.5. */` at class level (upgrade existing `TODO: Migrate` comment)
- **Description**: Mark `InboxStore` as deprecated. Already has a `TODO: Migrate` comment. Upgrade to formal `@deprecated` JSDoc.
- **Depends on**: —
- **Verification**: IDE shows deprecation warning. File unchanged functionally.
- **Risk**: Low — comment only.
- **Estimated lines**: ~5

### T-26 — ConversationPanelComponent: @deprecated JSDoc

- **Checkpoint**: Final
- **Layer**: frontend
- **Files affected**:
  - `apps/web/src/app/features/inbox/components/conversation-panel/conversation-panel.component.ts` — add `/** @deprecated Use ConversationComponent (container). Removal in Sprint 13.5. */` at class level
- **Description**: Mark legacy `ConversationPanelComponent` as deprecated. Uses `StateService` + `MessageApiService` (legacy path). `ConversationComponent` is the canonical container. Do NOT delete.
- **Depends on**: —
- **Verification**: IDE shows deprecation warning. File unchanged functionally.
- **Risk**: Low — comment only.
- **Estimated lines**: ~5

### T-27 — Full regression: backend ~66 tests pass

- **Checkpoint**: Final
- **Layer**: test
- **Files affected**: None (verification only)
- **Description**: Run full backend test suite. All ~66 existing tests must pass. Key tests to watch: `Sprint09BOutboxTest` (5 tests — especially `test_event_payload_contract` and `test_sequence_increments_correctly` after T-04/T-09 changes), `HandleInboundMessageUseCaseTest`, all Sprint08/Sprint09A tests.
- **Depends on**: T-01 through T-11 (all backend tasks)
- **Verification**: `docker exec inbiox_php php bin/phpunit` — all tests pass. Zero failures. Zero errors.
- **Risk**: R2 — `test_sequence_increments_correctly` may fail if seq ordering changed by T-04.
- **Estimated lines**: ~0 (verification only)

### T-28 — Sprint 13 readiness checklist (12 criteria)

- **Checkpoint**: Final
- **Layer**: test
- **Files affected**: None (verification only)
- **Description**: Verify all 12 Sprint 13 gate criteria from spec §7:
  1. Message send → optimistic → SSE confirm → 'sent' (manual + integration)
  2. Disconnect 5s + reconnect → ticket list matches server
  3. Disconnect + reconnect → active conversation matches server
  4. No duplicate messages after reconnect
  5. No lost messages across disconnect boundary
  6. Backend event parsed by frontend without errors
  7. Single store mutation per event (code audit: `grep applyStorePatch` = 0)
  8. TicketCreatedEvent has correct ticket ID (unit test)
  9. OutboxEvent retry_count >= 5 → dead_letter (unit test)
  10. InboxPageComponent cleans up EventSource on destroy
  11. No per-ticket HTTP refetch on SSE events (network inspection)
  12. Backend ~66 tests pass (T-27)
- **Depends on**: T-01 through T-26
- **Verification**: Each criterion documented as PASS/FAIL with evidence. ALL must PASS for Sprint 13 gate.
- **Risk**: If ANY criterion fails → Sprint 13 BLOCKED.
- **Estimated lines**: ~0 (verification only)

---

## Dependency Graph

```
                    ┌─────────────────────────────────────────────────────────┐
                    │              CHECKPOINT ALPHA (Foundation)               │
                    └─────────────────────────────────────────────────────────┘

  T-01 (migration) ──────┬────→ T-02 (enum+mapper+factory) ──→ T-03 (publisher wire)
       │                 │                                          │
       │                 └────→ T-04 (C-4 ticketId fix) ───────────┤
       │                                                         T-09 (test update)
       └────→ T-05 (MAX_RETRIES)
                    │
  T-06 (TS enum) ───┤
       │            │
       └──→ T-07 (remove applyStorePatch) ──→ T-08 (handleEvent rewrite)
                                                     │
                    ┌─────────────────────────────────────────────────────────┐
                    │              CHECKPOINT BRAVO (Reconnect)                │
                    └─────────────────────────────────────────────────────────┘
                                                     │
  T-08 ────→ T-10 (resync endpoint) ──→ T-12 (resync methods) ──→ T-13 (onopen resync)
    │
    ├───→ T-14 (gap state machine) ──→ T-17 (circuit breaker) ──→ T-18 (Retry Now btn)
    │
    ├───→ T-16 (remove N+1)
    │
  T-15 (ngOnDestroy) [independent]

  T-05 ────→ T-11 (dead-letter CLI)

                    ┌─────────────────────────────────────────────────────────┐
                    │             CHECKPOINT CHARLIE (Send Pipeline)           │
                    └─────────────────────────────────────────────────────────┘

  T-08 ────→ T-19 (retryWithBackoff) ──→ T-20 (remove cache)
                                      │
                                      └──→ T-21 (wire onSendMessage) ──→ T-22 (slim Composer)
                                                                   └──→ T-23 (remove InboxService.send)

                    ┌─────────────────────────────────────────────────────────┐
                    │              CHECKPOINT FINAL (Cleanup)                  │
                    └─────────────────────────────────────────────────────────┘

  T-24, T-25, T-26 [independent — deprecation comments]

  T-01..T-11 ──→ T-27 (backend regression)
  T-01..T-26 ──→ T-28 (Sprint 13 gate)
```

---

## Review Workload Forecast

| Metric | Value |
|--------|-------|
| Total estimated changed lines | ~1800–2200 |
| Backend changed lines | ~600–700 |
| Frontend changed lines | ~900–1100 |
| Test changed lines | ~20–50 |
| Deprecation comments | ~15 |
| Files created | ~6 (3 PHP, 1 TS model, 1 migration, 1 CLI command) |
| Files modified | ~15 |
| 400-line budget risk | **High** |
| Chained PRs recommended | **Yes** |
| Chain strategy | stacked-to-main |
| Decision needed before apply | **Yes** |

### PR Split Recommendation

| PR | Checkpoints | Scope | Est. Lines | Base |
|----|------------|-------|------------|------|
| PR 1 | Alpha (backend) | T-01 through T-05, T-09 | ~500 | main |
| PR 2 | Alpha (frontend) | T-06 through T-08 | ~250 | PR 1 branch |
| PR 3 | Bravo | T-10 through T-18 | ~550 | PR 2 branch |
| PR 4 | Charlie + Final | T-19 through T-28 | ~200 | PR 3 branch |

Each PR is independently reviewable. PR 1 establishes the backend contract. PR 2 mirrors it on the frontend. PR 3 adds reconnect resilience. PR 4 completes the send pipeline and marks deprecations.
