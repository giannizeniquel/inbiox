# Exploration: Timeline de Contacto

## Current State

### Backend

**`ContactController`** (`apps/api/src/Controller/ContactController.php`)
- `GET /contacts/{id}` returns `ContactDetailDTO` — basic contact fields only (id, phone, name, status, channel, owner_id, email, timestamps)
- No timeline data, no event history

**`GetContactDetailQuery`** (`apps/api/src/Application/Query/GetContactDetailQuery.php`)
- Pure contact entity query — no joins to tickets, conversations, or messages
- Returns flat contact row only

**`TicketEventsController`** (`apps/api/src/Controller/TicketEventsController.php`)
- `GET /api/tickets/{id}/events` — fetches from `outbox_events` table filtered by `aggregate_id = ticket_id`
- **No contact filtering** — events are keyed by ticket, not by contact
- Response shape: `{ events: RealtimeEvent[], lastSequence, hasMore }`

**Data model relationships:**
```
Contact (1) → (N) Conversation (1) → (N) Message
Contact (1) → (N) Ticket (1) → (N) Assignment
Ticket (N) → (1) Conversation
```

**`outbox_events` table** — the event store:
- `aggregate_type` = "Ticket", `aggregate_id` = ticket_id
- `event_type` = PHP class name (maps to `RealtimeEventType` enum)
- `sequence` = global ordering integer
- `topic` = string (e.g., "tickets")
- **No `contact_id` column** — contact must be resolved via ticket

**Existing event types** (`RealtimeEventType.php`):
- `ticket.created`, `ticket.status_changed`, `ticket.assigned`
- `message.added`, `message.updated`, `conversation.updated`
- `agent.availability_changed`, `agent.shift_changed`

**`Assignment` entity** (`apps/api/src/Domain/Assignment/Assignment.php`):
- Links Ticket → Agent with `assignedAt` timestamp, `type`, `assignedBy`
- Indexed on `(agent_id, assigned_at)` and `ticket_id`

**`Ticket` entity** — has `contact_id` FK, `status`, `priority`, timestamps

**`Message` entity** — linked to `Conversation` with `direction`, `content`, `created_at`

---

### Frontend

**`ContactDetailComponent`** (`apps/web/src/app/features/contacts/components/contact-detail/`)
- Standalone, OnPush, purely presentational — **no business logic**
- Current layout: header (avatar/name/phone/badges) → info (owner/last interaction) → tags → actions → "Conversaciones recientes" (shows activeTicketId only)
- Outputs: `openConversation(id)`, `editContact(id)`
- **No timeline section exists** — the "Conversaciones recientes" placeholder only shows active ticket

**`ContactsStore`** (`apps/web/src/app/features/contacts/stores/contacts.store.ts`)
- Signals: `contacts`, `selectedContact`, `selectedContactId`, `loading`, `filters`, `searchTerm`, `pagination`, `error`
- Mutation methods: `loadContacts`, `addContact`, `updateContact`, `patchContact`, `selectContact`, `clearSelection`
- **No timeline state** — timeline is not modelled

**`UIContact` model** (`apps/web/src/app/features/contacts/models/ui/contact-ui.model.ts`):
- Fields: id, phone, name, email, lastInteraction, unreadCount, activeTicketId, tags, owner, status, channel
- **No timeline or event history fields**

**Realtime stack:**
- `MercureService` subscribes to `?topic=tickets` (global SSE stream)
- Per-ticket sequence tracking via `seqState Map<ticketId, {lastSeq, state}>`
- Gap detection + resync via `GET /api/tickets/{id}/events`
- `EventBusService` dispatches `RealtimeEvent` to subscribers
- **No per-contact event routing** — events carry `ticketId`, not `contactId`
- `ConversationStore` handles `MessageAdded` / `MessageUpdated` events for the active chat only

---

## Data Sources for Timeline Events

| Event Type | Source Table | Filter Key | Ordering |
|---|---|---|---|
| Messages (inbound/outbound) | `messages` JOIN `conversations` | `conversations.contact_id` | `messages.created_at` |
| Ticket created | `outbox_events` WHERE `event_type` LIKE `%TicketCreated%` JOIN `tickets` | `tickets.contact_id` | `outbox_events.sequence` |
| Ticket status changes | `outbox_events` WHERE `event_type` LIKE `%StatusChanged%` | `aggregate_id → ticket.contact_id` | `outbox_events.sequence` |
| Ticket assignments | `assignments` JOIN `tickets` | `tickets.contact_id` | `assignments.assigned_at` |
| Conversation updates | `outbox_events` WHERE `event_type` LIKE `%ConversationUpdated%` | via conversation.contact_id | `outbox_events.sequence` |

**Key constraint**: `outbox_events` has no direct contact FK — contact must be resolved through `tickets.contact_id` via a JOIN on `aggregate_id = ticket_id`.

---

## Gaps That Need New Code

1. **Backend: No timeline query** — no query joins outbox_events + tickets + assignments by contact
2. **Backend: No timeline DTO** — no `TimelineEventDTO` class to represent a unified event entry
3. **Backend: No timeline endpoint** — `GET /contacts/{id}/timeline` does not exist
4. **Backend: No Assignment by contact query** — `AssignmentRepository` has `findByTicket`, `findByAgent`, no `findByContact`
5. **Backend: No Message by contact query** — `MessageRepository` has `findByConversation`, no `findByContact`
6. **Frontend: No TimelineComponent** — no UI component to render the chronological event list
7. **Frontend: No TimelineStore** — no signal/state for timeline entries
8. **Frontend: Realtime not contact-scoped** — Mercure subscribes to `tickets` topic, cannot filter SSE by contact
9. **Frontend: UIContact model** — no timeline-related fields; ContactDetailComponent receives `UIContact` only

---

## Approaches

### Approach A: Unified Timeline Query (Single Endpoint)
**`GET /contacts/{id}/timeline?after=<sequence>&limit=50`**

A single backend query that:
1. Queries `outbox_events` with `JOIN tickets ON aggregate_id = tickets.id WHERE tickets.contact_id = ?`
2. UNION with `SELECT * FROM assignments JOIN tickets ON assignments.ticket_id = tickets.id WHERE tickets.contact_id = ?`
3. Orders by `sequence` or `assigned_at` DESC, applies cursor pagination

Returns:
```php
TimelineEventDTO {
  type: 'message'|'ticket_created'|'ticket_status_changed'|'ticket_assigned'|'conversation_updated',
  timestamp: string,
  sequence: int,
  ticketId: int,
  summary: string,  // e.g., "Mensaje recibido: 'hola'", "Ticket asignado a Ana"
  payload: array,   // raw event payload for rich rendering
}
```

- **Pros**: Single round-trip, global sequence ordering, consistent pagination model, aligns with outbox pattern
- **Cons**: UNION query may be slower; assignments live in separate table (not in outbox_events)
- **Effort**: Medium — requires new query class, DTO, and endpoint

### Approach B: Separate Queries Per Event Type
**`GET /contacts/{id}/messages?after=<id>`**
**`GET /contacts/{id}/ticket-events?afterSequence=<n>`**
**`GET /contacts/{id}/assignments?after=<id>`**

Frontend merges and sorts by timestamp client-side.

- **Pros**: Reuses existing query patterns; simpler individual queries
- **Cons**: Multiple round-trips; client-side merge is error-prone; different pagination models per stream; N+1 risk on assignments
- **Effort**: Low-Medium — more incremental but more complex frontend

### Approach C: Hybrid — Unified Query + Realtime Fan-out
Same as Approach A for historical data, but:
- Mercure topic pattern becomes `contacts/{contactId}` (new topic per contact)
- Backend publishes `contact.timeline` events alongside existing `ticket.*` events
- Frontend subscribes to `contacts/{id}` topic on contact detail view

- **Pros**: Clean separation; contact-scoped realtime; scales to many agents viewing same contact
- **Cons**: Requires Mercure topic restructuring; new event types needed
- **Effort**: High — Mercure topic architecture change, new event publishing path

---

## Recommendation

**Approach A (Unified Timeline Query)** with a **phased realtime upgrade** (Approach C future work).

Rationale:
- The ContactDetail page is read-heavy, not write-heavy — polling a unified endpoint every 30s during view is acceptable for MVP
- The outbox_events table already has global sequence ordering — this is the natural timeline backbone
- Assignments are append-only and relatively infrequent — adding them to the timeline via the same query is straightforward
- Realtime per-contact fan-out (Approach C) is valuable but not blocking — the timeline can be polling-based initially and upgraded later
- Separating queries (Approach B) fragments the timeline and creates client-side merge complexity with no upside at this scale

**Realtime note**: The current Mercure setup (`?topic=tickets`) is per-ticket, not per-contact. For MVP, the timeline will not receive realtime updates — the agent refreshes or re-visits the contact to see new events. This is explicitly de-scoped for Sprint 01.

---

## Risks

1. **outbox_events JOIN performance** — querying across `outbox_events + tickets + assignments` with `contact_id` filter may hit large tables without proper indexes. Mitigation: add index on `tickets.contact_id` if not present; consider partitioning by date.
2. **Assignment not in outbox_events** — assignments are not published as outbox events today. If we want assignments in the timeline, they must be queried separately and merged. Mitigation: treat as a separate subquery in the UNION.
3. **Mercure topic restructuring** — if Approach C is planned, the current `?topic=tickets` subscription model must change. This affects `MercureService` substantially.
4. **No `contactId` in outbox_events** — every event must be joined through `tickets` to resolve contact. Adds JOIN complexity.
5. **Timeline pagination** — cursor-based pagination via `afterSequence` or `afterId` must be consistent across event types in Approach A.

---

## Files That Would Be Affected

### Backend (new)
- `apps/api/src/Application/DTO/TimelineEventDTO.php` — new DTO for timeline entries
- `apps/api/src/Application/Query/GetContactTimelineQuery.php` — new query class
- `apps/api/src/Controller/ContactTimelineController.php` — new endpoint `GET /contacts/{id}/timeline`
- `apps/api/src/Repository/AssignmentRepository.php` — optionally add `findByContact()` if needed

### Backend (modified)
- `apps/api/src/Repository/MessageRepository.php` — optionally add `findByContact()` if needed
- `apps/api/src/Repository/OutboxEventRepository.php` — check for existing contact-filtered query capability
- `apps/api/src/Application/Realtime/RealtimeEventType.php` — new `ContactTimelineUpdated` event (future)

### Frontend (new)
- `apps/web/src/app/features/contacts/components/contact-timeline/contact-timeline.component.ts` — new component
- `apps/web/src/app/features/contacts/components/contact-timeline/contact-timeline.component.html` — template
- `apps/web/src/app/features/contacts/components/contact-timeline/contact-timeline.component.scss` — styles
- `apps/web/src/app/features/contacts/stores/contacts-timeline.store.ts` — new store (or extend ContactsStore)
- `apps/web/src/app/features/contacts/models/dto/timeline-transport.dto.ts` — new DTO

### Frontend (modified)
- `apps/web/src/app/features/contacts/components/contact-detail/contact-detail.component.html` — add timeline section
- `apps/web/src/app/features/contacts/components/contact-detail/contact-detail.component.ts` — add timeline output/input
- `apps/web/src/app/features/contacts/stores/contacts.store.ts` — optionally add timeline state
- `apps/web/src/app/features/contacts/models/ui/contact-ui.model.ts` — optionally extend for timeline
- `apps/web/src/app/core/realtime/mercure.service.ts` — future: per-contact topic subscription
- `apps/web/src/app/core/realtime/event-bus.service.ts` — future: handle `contact.timeline_updated` events

---

## Ready for Proposal

**Yes.** The data model is well understood, the event sourcing pattern (outbox_events) provides a natural timeline backbone, and the ContactDetail component's layout has a clear insertion point for a timeline section.

The key decision the orchestrator/user should confirm: **Is realtime update required for Sprint 01, or is polling-based timeline acceptable?** If realtime is required, Approach C (per-contact Mercure topic) must be designed alongside the unified query.
