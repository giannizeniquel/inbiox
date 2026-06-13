# Sprint 13.3 Charlie — Tasks

## T-01 — Ensure HandleStatusUpdateUseCase publishes events

- [x] Verify EventCollector usage in HandleStatusUpdateUseCase
- [x] Verify OutboxEvent is created with event_type containing 'MessageUpdated'

**Status**: Already implemented. The use case already collects `MessageUpdatedEvent` via `EventCollector`, persists it as `OutboxEvent`, and flushes via `EntityManager`.

---

## T-02 — Update MessageUpdatedEvent for Mercure contract

- [x] Verify MessageUpdatedEvent has all required fields (messageId, ticketId, deliveryStatus, failedReason)
- [x] Verify RealtimeEventFactory produces valid message.updated payload
- [x] Fix field mapping: factory reads `delivery_status` from outbox payload (was reading wrong key `status`)

**Status**: Event has required fields. Factory had a bug reading `$s['status']` instead of `$s['delivery_status']` — FIXED. Added `failedReason` to factory output and TypeScript `MessageUpdatedPayload` interface.

---

## T-03 — Frontend: Handle message.updated in MercureService

- [x] Verify `RealtimeEventType.MessageUpdated` case exists in `handleEvent()`
- [x] Verify it calls `conversationStore.updateMessageStatus()`

**Status**: Already implemented. Handler extracts `{messageId, ticketId, status}` from payload and calls `updateMessageStatus()`.

---

## T-04 — Frontend: ConversationStore.updateMessageStatus()

- [x] Verify store method exists to update delivery status by messageId

**Status**: Already implemented. `updateMessageStatus(idOrTempId, status, messageId?)` updates messages by `id` or `tempId`.

---

## T-05 — Frontend: UI reflects status changes

- [x] Add `'delivered'` to `DeliveryStatus` union type in `provider.interface.ts`
- [x] Add `'delivered'` to `Message.deliveryStatus` in `message.model.ts`
- [x] Add `'delivered'` to `BridgeMessage.deliveryStatus` in `conversation.store.ts`
- [x] Add `'delivered'` case to `statusIcon()` in `message-bubble.component.ts`
- [x] Add `'delivered'` to `MessageUpdatedPayload.failedReason` in `realtime-event.model.ts`

**Status**: All type definitions updated. `delivered` now shows `✓✓` icon.

---

## T-06 — End-to-end verification

- [ ] Manual test: trigger status webhook and verify full pipeline

**Status**: Not executed (requires running environment with seeded data).
