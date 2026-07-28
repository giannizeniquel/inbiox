# Proposal: Timeline de Contacto

## Intent

Agents viewing a contact detail need a unified chronological feed of all interactions — messages, ticket lifecycle events, and assignments — to understand the full conversation history without navigating between tickets.

## Scope

### In Scope
- Backend: `GET /api/contacts/{id}/timeline?before={ISO8601}&limit=20` endpoint
- Backend: UNION query joining `outbox_events` (via tickets) + `messages` (via conversations) + `assignments`
- Backend: `TimelineEventDTO` with `type`, `occurred_at`, `ticket_id`, `summary`, `payload`
- Backend: Cursor pagination by ISO 8601 `occurred_at DESC`
- Frontend: `ContactTimelineComponent` — standalone, embedded in contact detail
- Frontend: `ContactTimelineStore` — signals for `events[]`, `loading`, `cursor`, `hasMore`
- Frontend: Infinite scroll-up loads older events
- Frontend: Event-type icons/colors (📩 green, 📤 blue, 🟢 created, 🔴 closed, 🔄 assigned)
- Frontend: Empty state: "No hay actividad registrada para este contacto"
- Frontend: Click message event → navigate to ticket conversation

### Out of Scope
- Realtime Mercure push per contact (polling acceptable for MVP; Mercure is per-ticket)
- Contact list "last event" preview
- Timeline event-type filtering
- Backend event store normalization (`contact_id` on `outbox_events` — future refactor)

## Capabilities

### New Capabilities
- `contact-timeline`: Read-only chronological view of all contact interactions

### Modified Capabilities
- `contacts`: ContactDetailPanel now includes timeline section with infinite scroll instead of static ticket list

## Approach

**Unified query (Approach A)**. Single endpoint, single round-trip, cursor-paginated. The query UNIONs three sources: (1) outbox_events JOIN tickets on `aggregate_id = ticket_id`, filtered by `tickets.contact_id`, (2) messages JOIN conversations on `conversations.contact_id`, (3) assignments JOIN tickets on `tickets.contact_id`. All ordered by `occurred_at DESC`, limit 20, cursor via `WHERE occurred_at < :before`. Uses direct DBAL (matches existing query pattern — `GetContactDetailQuery`).

Frontend: standalone `ContactTimelineStore` with Angular signals. Scroll-up detection appends older events. No realtime — timeline is a snapshot refreshed on revisit.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Controller/ContactTimelineController.php` | New | Endpoint `GET /contacts/{id}/timeline` |
| `apps/api/src/Application/Query/GetContactTimelineQuery.php` | New | UNION query + DTO mapping |
| `apps/api/src/Application/DTO/TimelineEventDTO.php` | New | Unified timeline event DTO |
| `apps/api/config/routes.yaml` | Modified | New route registration |
| `apps/web/.../components/contact-timeline/` | New | Component + template + styles |
| `apps/web/.../stores/contacts-timeline.store.ts` | New | Signal store for timeline state |
| `apps/web/.../models/dto/timeline-transport.dto.ts` | New | Frontend DTO |
| `apps/web/.../services/contacts-api.service.ts` | Modified | Add `getTimeline()` method |
| `apps/web/.../components/contact-detail/` | Modified | Embed timeline component |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| UNION of 3 tables slow for contacts with many tickets | Medium | Cursor pagination (limit 20). Add index on `tickets.contact_id`. |
| No `contact_id` on `outbox_events` → JOIN overhead | Medium | Query scoped to single contact; `aggregate_id` has FK index. |
| Different cursor formats across event sources | Low | Map to single `occurred_at` column; all sources have timestamps. |

## Rollback Plan

1. Remove `ContactTimelineController` and route registration
2. Delete `GetContactTimelineQuery` and `TimelineEventDTO`
3. Delete `ContactTimelineComponent`, `ContactTimelineStore`, and frontend DTO
4. Revert `contact-detail.component.html` to remove timeline section
5. No DB migration required (read-only endpoint)
6. Re-deploy

## Dependencies

- None — all source tables (`outbox_events`, `messages`, `conversations`, `tickets`, `assignments`) exist

## Success Criteria

- [ ] `GET /api/contacts/{id}/timeline` returns merged, time-ordered events with cursor pagination
- [ ] Timeline renders 20 events per page with distinct icons per event type
- [ ] Scroll-up triggers next page load appending older events
- [ ] Clicking a message event navigates to the ticket conversation
- [ ] Empty state shown when contact has no events
- [ ] Response time < 500ms for contacts with up to 1000 events
