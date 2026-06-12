# Alpha Verify Report — Sprint 12.5

**Status: PASS** ✅

**Date**: 2026-06-12
**Branch**: pr2-alpha-frontend (PR1 + PR2)
**Bugs verified**: C-1, C-2, C-4, C-5

---

## V-01 — Contract Compatibility: PASS ✅

PHP enum (`RealtimeEventType.php`) and TypeScript enum (`realtime-event.model.ts`) are a 1:1 mirror.

| PHP | TypeScript | Match |
|-----|-----------|-------|
| `TicketCreated = 'ticket.created'` | `TicketCreated = 'ticket.created'` | ✅ |
| `TicketStatusChanged = 'ticket.status_changed'` | `TicketStatusChanged = 'ticket.status_changed'` | ✅ |
| `TicketAssigned = 'ticket.assigned'` | `TicketAssigned = 'ticket.assigned'` | ✅ |
| `MessageAdded = 'message.added'` | `MessageAdded = 'message.added'` | ✅ |
| `MessageUpdated = 'message.updated'` | `MessageUpdated = 'message.updated'` | ✅ |
| `ConversationUpdated = 'conversation.updated'` | `ConversationUpdated = 'conversation.updated'` | ✅ |

`RealtimeEvent<T>` interface fields match the wire JSON: `eventId`, `sequence`, `timestamp`, `eventType`, `ticketId?`, `conversationId?`, `payload`. All camelCase. Zero `as any` casts in `handleEvent()` dispatch.

**Evidence**: `apps/api/src/Application/Realtime/RealtimeEventType.php:12-17`, `apps/web/src/app/core/models/realtime-event.model.ts:16-21`, `mercure.service.ts` handleEvent switch

---

## V-02 — Single Mutation: PASS ✅

`grep -rn "applyStorePatch" apps/web/src/ --include="*.ts"` returns only a comment at line 142: `// Single dispatch path — no applyStorePatch, no subscriber callbacks`. Zero method definitions, zero method calls.

`handleEvent()` is the single dispatch path with strict switch over `RealtimeEventType`. For `MessageAdded`, exactly one call to `conversationStore.addMessage()` exists.

**Evidence**: `mercure.service.ts:142` (comment only), `mercure.service.ts:161-203` (handleEvent switch)

---

## V-03 — TicketCreatedEvent: PASS ✅

`TicketCreatedEvent` is collected AFTER `$em->flush()` at line 195-206 of `HandleInboundMessageUseCase.php`. Uses `$ticket->getId()` (line 199), not `(int) $conversation->getId()`.

```php
// Collect TicketCreatedEvent after flush (ticket now has a persisted ID)
if ($isNewTicket) {
    $this->eventCollector->collect(new TicketCreatedEvent(
        eventId: new Ulid(),
        ticketId: $ticket->getId(), // CORRECT — now persisted
        ...
    ));
}
```

**Evidence**: `HandleInboundMessageUseCase.php:195-206`

---

## V-04 — MAX_RETRIES: PASS ✅

`handleFailure()` at line 232-251 checks `$newRetryCount >= self::MAX_RETRIES` (line 237). On exceeding: sets `status = 'dead_letter'` (line 249), logs at error level. MAX_RETRIES = 5 constant defined at line 17.

```php
if ($newRetryCount >= self::MAX_RETRIES) {
    $sql = 'UPDATE outbox_events SET retry_count = :retry_count, status = :status WHERE id = :id';
    $conn->executeStatement($sql, [
        'retry_count' => $newRetryCount,
        'status' => self::DEAD_LETTER_STATUS,
        'id' => $event->getId(),
    ]);
}
```

**Evidence**: `OutboxPublisherService.php:232-251`

---

## V-05 — Sequence Field: PASS ✅

`grep -rn "\.seq\b"` on mercure.service.ts and debug panel returns zero results. All realtime code uses `.sequence`:

```
mercure.service.ts:135:  if (data.sequence > currentSeq) {
mercure.service.ts:137:    this.updateSeq(ticketId, data.sequence);
mercure.service.ts:147:    console.log('[Mercure] Applied event seq:', data.sequence, ...);
```

**Evidence**: `mercure.service.ts:135-150`, debug panel updated from `event.seq` → `event.sequence`

---

## V-06 — Payload Contract (R12): PASS ✅

`MessageCreatedEvent` constructor includes `content` and `senderName` (lines 18-19). `persistEventsToOutbox()` writes them to the stored payload.

Test `test_event_payload_contract` at line 248-250 now asserts content IS present:
```php
$this->assertArrayHasKey('content', $messageCreatedPayload, 'MessageCreated payload MUST include content (R12 fix)');
$this->assertSame('Test message', $messageCreatedPayload['content']);
```

**Evidence**: `MessageCreatedEvent.php:18-19`, `Sprint09BOutboxTest.php:248-250`

---

## V-07 — Build: PASS ✅

Angular build: `npx ng build` — compiled successfully.
Only pre-existing CSS budget warning (contact-detail.component.scss: 5.33kB vs 4kB limit).

```
Output location: /home/gianni/projects/inbiox/apps/web/dist/web
```

---

## Verdict

**Alpha checkpoint is ready for archive.** ✅

All 4 CRITICAL bugs (C-1, C-2, C-4, C-5) are fixed and verified:
- Contract unified (backend ↔ frontend 1:1)
- Single dispatch path (zero dual mutations)
- Correct ticketId in TicketCreatedEvent
- MAX_RETRIES enforced with dead-letter

The contract is stable. Brave checkpoint (reconnect + gap detection) can begin.
