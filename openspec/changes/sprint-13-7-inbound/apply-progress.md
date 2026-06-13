# sprint-13-7-inbound — Apply Progress

## Tasks

### T-01 — Auto-create Contact from Webhook ✅
- **Files**: `apps/api/src/Controller/WebhookController.php`, `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php`
- **What**: WebhookController now processes inbound messages through HandleInboundMessageUseCase:
  - Extracts `from` (phone), `id` (WAMID), `text.body` (content) from each message
  - Builds phone→name lookup from `value.contacts[]` using `wa_id` + `profile.name`
  - Calls `processInboundMessage(phone, content, wamid, phoneNumberId, contactName)`
  - Added `$contactName` (5th) parameter to use case for setting name on new/existing contacts
  - Skips non-text messages with a debug log
  - Catches per-message errors so one failure doesn't block other messages
- **Verification**: Simulated inbound webhook → Contact, Conversation, Ticket, Message, Outbox events all created

### T-02 — ContactRepository: findByPhone method ✅
- **Files**: `apps/api/src/Repository/ContactRepository.php`
- **What**: `findOneByPhone()` already exists — no changes needed

### T-03 — Frontend: Real-time inbox update for inbound ✅
- **Files**: `apps/web/src/app/core/realtime/mercure.service.ts`, `apps/web/src/app/core/models/realtime-event.model.ts`
- **What**: `MessageAdded` case exists (line 343) — adds message to ConversationStore, updates unread counts. `TicketCreated` case exists (line 375) — calls `addOrUpdateTicket`. No changes needed.

### T-04 — E2E Verification ✅
- **What**: Simulated complete inbound flow via curl with HMAC signature
- **Results**:
  1. ✅ Contact created with phone=+5491122334455, name="Cliente Nuevo"
  2. ✅ Conversation created (id=130, status=open, phone_number_id=1034664019738593)
  3. ✅ Ticket created (id=160, status=new, priority=medium, phone_number_id=1034664019738593)
  4. ✅ Message persisted (id=1729, direction=inbound, content="Hola, necesito ayuda", external_id=wamid.inbound.test1)
  5. ✅ Outbox events created: TicketCreatedEvent (seq=1) + MessageCreatedEvent (seq=2, direction=inbound, sender_name="Cliente Nuevo")

## Deviations from Design
None — implementation matches design.

## Issues Found
None.

## Verification Summary
```
webhook POST → 200 OK
contacts:  id=134, phone="+5491122334455", name="Cliente Nuevo"
conversations: id=130, contact_id=134, status="open", phone_number_id="1034664019738593"
tickets: id=160, contact_id=134, conversation_id=130, status="new", priority="medium", phone_number_id="1034664019738593"
messages: id=1729, conversation_id=130, direction="inbound", content="Hola, necesito ayuda", external_id="wamid.inbound.test1"
outbox_events: TicketCreatedEvent(ticket/160, seq=1) + MessageCreatedEvent(ticket/160, seq=2, sender_name="Cliente Nuevo", direction="inbound")
```
