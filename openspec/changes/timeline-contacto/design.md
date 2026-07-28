# Design: Timeline de Contacto

## Technical Approach

Read-only chronological feed of contact interactions via a single cursor-paginated `GET /api/contacts/{id}/timeline?before={ISO8601}&limit=20`. Backend: SQL `UNION ALL` over three sources (outbox_events→tickets, messages→conversations, assignments→tickets), all normalized to one `occurred_at` column. Frontend: OnPush virtual-scrollable list fed by a dedicated `ContactTimelineStore`. No realtime — snapshot refreshed on revisit.

## Architecture Decisions

| # | Decision | Alternatives | Why |
|---|----------|--------------|-----|
| 1 | Single `UNION ALL` SQL via direct DBAL (matches `GetContactDetailQuery` style) | Per-source subqueries, ORM DQL, multiple endpoints | Single round-trip, consistent cursor, query naturally bounded per contact. DBAL is the established pattern. |
| 2 | Cursor = `before` query param (ISO 8601), `occurred_at DESC` | Offset, sequence number, ID cursor | ISO 8601 maps 1:1 to all three sources (`messages.created_at`, `outbox_events.created_at`, `assignments.assigned_at`); no second field needed. |
| 3 | Truncate message content server-side to 100 chars | Send full content, client truncates | Saves bandwidth; matches proposal's "message preview (truncated)". |
| 4 | Standalone `ContactTimelineStore` separate from `ContactsStore` | Extend `ContactsStore` | Timeline has its own pagination/error lifecycle; mixing pollutes the contact-list store. |
| 5 | Embed `ContactTimelineComponent` inside `ContactDetailComponent` (parent passes `contactId`) | New top-level route, parallel panel | Reuses existing detail layout; no new routing. |
| 6 | Render via existing `VirtualScrollViewportComponent` (shared) | Custom CDK virtual scroll, plain `@for` | Already exists, supports `variable` mode for mixed event heights. |
| 7 | Realtime out of scope; agent refreshes on revisit | Mercure per-contact topic | Proposal + exploration agree; Approach C deferred. |

## Data Flow

```
ContactDetailComponent (contact = UIContact)
  │  passes contactId down
  ▼
ContactTimelineComponent (OnPush, standalone)
  │  inject ContactTimelineStore; effect on contactId change
  ▼
ContactTimelineStore.loadTimeline(contactId, before?)
  ▼ ContactsApiService.getTimeline()
GET /api/contacts/{id}/timeline?before=…&limit=20
  ▼
TimelineController → GetContactTimelineQuery → DBAL UNION
  ▼ TimelineEventDTO[]
patch events() / cursor() / hasMore() / loading()
  ▼
VirtualScrollViewport (items = events); scroll-up → store.loadOlder(cursor)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/api/src/Application/DTO/TimelineEventDTO.php` | Create | DTO with `type, occurredAt, content, ticketId, ticketStatus, assignedTo, direction`; `toArray()`. |
| `apps/api/src/Application/Query/GetContactTimelineQuery.php` | Create | DBAL `UNION ALL` of 3 subqueries, cursor filter, limit. Returns `TimelineEventDTO[]`. |
| `apps/api/src/Controller/ContactTimelineController.php` | Create | `GET /api/contacts/{id}/timeline` — parses `before`+`limit`, returns `{ events, cursor, hasMore }`. |
| `apps/api/config/routes.yaml` | Modify | Add `app_contacts_timeline` route. |
| `apps/api/tests/Integration/Sprint15ContactTimelineTest.php` | Create | HTTP integration: fixtures for contact+ticket+message+assignment; assert merged order, cursor, limit, 401/404. |
| `apps/web/src/app/core/dto/timeline-transport.dto.ts` | Create | Frontend `TimelineEventDTO` + `TimelineEventResponse`. |
| `apps/web/src/app/core/api/contacts-api.service.ts` | Modify | Add `getTimeline(contactId, before?, limit?)`. |
| `apps/web/src/app/features/contacts/stores/contact-timeline.store.ts` | Create | Signal store: `events, loading, hasMore, cursor, error`. |
| `apps/web/src/app/features/contacts/stores/contact-timeline.store.spec.ts` | Create | Unit tests: initial load, cursor append, error, reset. |
| `apps/web/src/app/features/contacts/components/contact-timeline/contact-timeline.component.{ts,html,scss}` | Create | Standalone OnPush; reads store, renders virtual-scroll, emits `openTicket(ticketId)`. |
| `apps/web/src/app/features/contacts/components/contact-detail/contact-detail.component.{ts,html}` | Modify | Import `ContactTimelineComponent`; embed below actions; add `@Output() openTicket`. |

## Interfaces / Contracts

```php
// TimelineEventDTO — unified timeline entry
class TimelineEventDTO {
  public function __construct(
    public string  $type,        // 'message.sent'|'message.received'|'ticket.created'
                                  // |'ticket.status_changed'|'ticket.assigned'
    public string  $occurredAt,  // ISO 8601
    public ?string $content,     // preview, truncated to 100 chars
    public ?int    $ticketId,    // for navigation
    public ?string $ticketStatus,
    public ?string $assignedTo,  // agent name (assignments)
    public ?string $direction,   // 'inbound'|'outbound' (messages)
  ) {}
}
```

```ts
// GET /api/contacts/{id}/timeline?before=…&limit=20
interface TimelineEventResponse {
  events: TimelineEventDTO[];
  cursor: string | null;   // ISO 8601 of oldest event; null = no more
  hasMore: boolean;
}
```

Source → type mapping:

| Source | `type` | Extra fields |
|--------|--------|--------------|
| `messages` JOIN `conversations` | `direction === 'outbound' ? 'message.sent' : 'message.received'` | `content`, `direction` |
| `outbox_events` LIKE `%TicketCreated%` JOIN `tickets` | `ticket.created` | `ticketId`, `ticketStatus` |
| `outbox_events` LIKE `%StatusChanged%` JOIN `tickets` | `ticket.status_changed` | `ticketId`, `ticketStatus` |
| `assignments` JOIN `tickets` JOIN `agents` | `ticket.assigned` | `ticketId`, `assignedTo` |

## Testing Strategy

| Layer | What | Approach |
|-------|------|----------|
| Backend integration | Merged DESC order; cursor `before` filters; `limit` caps; 401/404 | `Sprint15ContactTimelineTest` extends `WebTestCase`, mirrors `Sprint10ControllerTest` style. |
| Frontend unit | Store — initial load, cursor append, error, reset | `*.spec.ts` with mocked `ContactsApiService`; follows `conversation.store` test pattern. |
| Frontend manual | Empty state, 3 event types, infinite scroll-up | `npx ng build` clean + dev smoke. |

Verification gate per phase: `cd apps/api && php bin/phpunit` + `cd apps/web && npx ng build`.

## Migration / Rollout

No DB migration. Read-only endpoint. Rollback = remove controller + route + query + DTO + frontend component/store/service-method + revert `contact-detail.component.html` (per proposal).

## Open Questions

- [ ] **Realtime**: deferred (Mercure per-contact topic) — confirm.
- [ ] **Truncation length**: 100 chars for message preview — confirm.
- [ ] **Ticket navigation**: `openTicket` event assumed handled by parent router — out of scope.
