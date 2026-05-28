# Proposal: Sprint 12.2 — Contacts MVP

## Intent

Build a CRM contacts module enabling agents to view, search, and manage customer contacts with bidirectional integration to tickets. ContactEntity stays minimal — DTOs carry operational data. Realtime incremental patching (no polling).

## Scope

### In Scope
- Contact list (table, virtualizable, search, filters, pagination/infinite scroll)
- Contact detail panel (name, phone, email, tags, last interaction, owner, channel)
- Timeline (related tickets, last message, activity date)
- CRUD (create, edit, soft delete/archive)
- CRM integration (contact ↔ ticket bidirectional navigation, "open conversation" CTA)
- Realtime incremental patching (contact.created, contact.updated, ticket.assigned, new.message)
- Responsive (desktop list+detail, mobile single-panel)
- Empty states, loading, error states

### Out of Scope
- Advanced segmentation, automations, bulk import/export, pipelines, analytics
- Contact entity extensions (stays minimal per architecture)

## Capabilities

### New Capabilities
- `contacts-list`: Paginated, searchable contact list with virtual scrolling
- `contact-detail`: Full contact view with timeline and CRM navigation
- `contact-crud`: Create, edit, archive contacts
- `contacts-realtime`: Incremental contact updates via SSE (no polling)
- `contact-ticket-integration`: Bidirectional navigation between contacts and tickets

### Modified Capabilities
- `message-model-prep`: Prepare gradual split DomainMessage/TransportMessageDTO/UIMessage (not completing migration, just design)

## Approach

- **Models**: Three-layer (DomainContact, UIContact, TransportContactDTO)
- **State**: Signal Stores with incremental patching (extends TicketStore pattern)
- **API**: REST for initial hydration, SSE for realtime updates
- **Architecture**: UI → Store → UseCase → Repository → Store patch (stores never call APIs directly)
- **Backend DTOs**: ContactListDTO (id, phone, name, lastInteraction, unreadCount, activeTicket, tags, owner), ContactDetailDTO (+ relatedTickets[], timeline, channel info)

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Domain/Contact/` | Modified | Minimal entity, new DTOs |
| `apps/api/src/Application/UseCases/Contacts/` | New | GetContacts, SearchContacts, CreateContact, UpdateContact, OpenConversationFromContact |
| `apps/web/src/app/features/contacts/` | New | Components, stores, services |
| `apps/web/src/app/core/realtime/` | Modified | New contact event handlers |
| `apps/web/src/app/core/services/` | Modified | Contact use cases |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Backend DTOs incomplete | Medium | Define DTO contracts upfront in specs phase |
| Realtime event schema changes | Medium | Version events, graceful degradation |
| Performance with large lists | Low | Virtual scrolling from day 1 |
| Mobile responsive complexity | Low | Single-panel pattern from Sprint 11A |

## Rollback Plan

- Feature flag for contacts module in frontend
- Backend endpoints are additive (no breaking changes)
- Revert to polling fallback if SSE fails (graceful degradation)

## Dependencies

- Backend: ContactListDTO, ContactDetailDTO endpoints (GET /contacts, GET /contacts/{id}, POST /contacts, PATCH /contacts/{id}, DELETE /contacts/{id})
- Backend: SSE events (contact.created, contact.updated, ticket.assigned, new.message)
- Frontend: Application Layer pattern from Sprint 12

## Success Criteria

- [ ] Contact list renders with 1000+ contacts via virtual scroll
- [ ] Search returns results in <200ms
- [ ] Contact detail shows all CRM fields
- [ ] Create/edit contact works end-to-end
- [ ] Realtime updates appear without page refresh
- [ ] Bidirectional navigation (contact → ticket, ticket → contact) works
- [ ] Mobile view is fully functional
- [ ] Empty, loading, error states display correctly