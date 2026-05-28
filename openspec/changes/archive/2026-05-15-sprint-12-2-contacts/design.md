# Design: Sprint 12.2 — Contacts MVP

## Technical Approach

Implement the contacts feature following the existing inbox pattern: feature-scoped directory, signal-based store, HTTP API service with mock backend, and Mercure-based realtime updates. Three-layer model mapping stays at the feature boundary (models/), not leaking into stores.

## Architecture Decisions

### Decision: Feature Directory Structure

**Choice**: `features/contacts/` with `containers/`, `components/`, `stores/`, `application/contacts/`, `models/{domain,ui,dto}/`, `services/`, `pages/`, `index.ts`

**Alternatives considered**:
- Flat `core/` structure — rejected because contacts are feature-scoped, not core infrastructure. Inbox and contacts should be symmetric.
- Splitting models across `core/` layers — rejected because contacts models are new and isolated; no need to pollute core.

**Rationale**: Mirrors the existing `features/inbox/` layout exactly. Predictable for any developer who knows the project. No horizontal coupling — contacts doesn't import inbox internals and vice versa.

### Decision: ContactsStore Signal Surface

**Choice**: All signals public readonly, with `filteredContacts` and `contactById` as computed signals. Max cap 500 contacts.

**Alternatives considered**:
- Private signals with accessor methods — rejected; existing TicketStore uses public signals, consistency wins.
- Filtering in use cases — rejected; computed signals in store keep components reactive without manual re-fetching.

**Rationale**: Matches TicketStore exactly (signal + computed pattern). Max cap 500 is half of TicketStore's 1000 because contact metadata is lighter. Incremental patch methods: `addContact()`, `updateContact()`, `patchContact()` for realtime integration.

```ts
// Signal surface (mirrors TicketStore pattern)
contacts = signal<UIContact[]>([]);
selectedContact = signal<UIContact | null>(null);
loading = signal<boolean>(false);
filters = signal<ContactFilters>({});
searchTerm = signal<string>('');
pagination = signal<{ page: number; total: number; hasMore: boolean }>({ page: 1, total: 0, hasMore: true });
error = signal<string | null>(null);

filteredContacts = computed(() => {
  const all = this.contacts();
  const f = this.filters();
  return all.filter(c => /* filter logic */);
});

contactById = computed(() => {
  const map = new Map(this.contacts().map(c => [c.id, c]));
  return (id: string) => map.get(id);
});
```

### Decision: ContactApiService with Mock Backend

**Choice**: `ContactsApiService` at `core/api/contacts-api.service.ts` returns `Observable` with faked responses. Per-spec: use cases own business logic, service only handles HTTP.

**Alternatives considered**:
- Inline mock in use cases — rejected; spec requires use cases to have input/output DTOs, and service layer keeps mocks swappable.
- Returning `Signal` or `Promise` — rejected; existing `TicketApiService` and `MessageApiService` use Observables, consistency.

**Rationale**: `HttpClient.get<T>()` returns Observable. `Observable` is the RxJS norm across the stack. Mocking via `of(fakedData)` is idiomatic and test-friendly.

### Decision: Model Mapping Location

**Choice**: Mapping functions live in `features/contacts/models/mappers.ts`. Used exclusively in use cases (inbound, DTO→UI) and services would not call them (services return raw DTOs).

**Alternatives considered**:
- Mapping in services — rejected; keeps mapping concern in wrong layer.
- Mapping in store — rejected; stores should be pure state holders.
- Mapping in components — rejected; components become logic-heavy.

**Rationale**: Single responsibility. Use cases transform DTO→UI (their job per spec's input/output DTOs). Mapping functions are pure, testable in isolation. Components receive UI models ready to render.

```ts
// mappers.ts — pure, exportable, unit-testable
export function mapDTOToUIContact(dto: TransportContactDTO): UIContact { ... }
export function mapDTOToDomainContact(dto: TransportContactDTO): DomainContact { ... }
export function mapUIContactToDomain(contact: UIContact): DomainContact { ... }
```

### Decision: Realtime Integration with MercureService

**Choice**: Extend MercureService with a new topic subscription (`contact/*`) and a new event handler case in `applyStorePatch()` that patches `ContactsStore`.

**Alternatives considered**:
- Separate `ContactsMercureService` — rejected; over-architecture for what is effectively a new topic handler.
- ContactsStore subscribing directly to Mercure — rejected; existing pattern is MercureService calling store methods.

**Rationale**: MercureService already has the subscription mechanism and store injection pattern. Adding contact event handling is one new case in `applyStorePatch()`, plus one new topic in `connect()`. No new class needed.

```ts
// In connect():
const hubUrl = `${environment.mercureUrl}/.well-known/mercure?topic=ticket/*&topic=contact/*`;

// In applyStorePatch():
case 'contact.created':
  this.contactsStore.addContact(mapDTOToUIContact(event.payload.data));
  break;
case 'contact.updated':
  this.contactsStore.patchContact(event.payload.contactId, event.payload.data);
  break;
// ticket.assigned updates contact's activeTicketId + owner
// new.message updates contact's lastInteraction
```

**Open question**: Does MercureService need to inject ContactsStore? Yes — but only after ContactsStore is created. This is a forward reference that resolves once both are implemented.

### Decision: UI Smart/Presentational Split

**Choice**:
- `ContactsPageComponent` — smart container, injects use cases, loads data on init, subscribes to store signals
- `ContactsListComponent` — presentational, receives `filteredContacts`, `loading`, emits selection events
- `ContactCardComponent` — presentational, receives single `UIContact`, no injected stores
- `ContactDetailPanelComponent` — presentational, receives `selectedContact`, handles "Open conversation" event
- `ContactSearchComponent` — presentational, receives/emits search term with debounce from parent

**Rationale**: Standard Angular container/presentational pattern. Container is `OnPush` but subscribes to store signals reactively. Presentational components are pure renderers — no API calls, no store injection (except for read-only signal inputs).

### Decision: "Open Conversation" Flow

**Choice**: `OpenConversationFromContactUseCase` is executed from `ContactDetailPanelComponent`. It queries `TicketStore` for existing open ticket with the contact's ID. If found → sets `activeTicket` via `TicketStore.setActiveTicket()`. If not → dispatches a synthetic "ticket.assigned" event that triggers backend ticket creation (handled in Sprint 12.3).

**Rationale**: Keeps TicketStore as the single source for ticket state. ContactsStore doesn't hold open tickets — TicketStore does. The use case mediates between the two.

### Decision: Routing — No New Route

**Choice**: No `/contacts` route. Contacts live inside the inbox shell (Sprint 11A sidebar pattern: Contacts nav item is "future" for now, but the ContactsPage could be lazy-loaded into the same shell or as a panel alongside the inbox).

**Alternatives considered**:
- Full `/contacts` route — premature; no nav UX defined yet.
- Contacts as a route child of inbox — possible future path.

**Rationale**: Minimal delivery. ContactsPage is a standalone page component that could live at `/contacts` or be integrated into inbox. No routing commitment until Sprint 12.3/13.

### Decision: Message Model Migration Path

**Choice**: Deferred. Document the future split now; no code changes in this sprint.

**Future split** (triggered by Sprint 13 when ConversationStore is refactored):
- `DomainMessage` → canonical shape in `domain/contracts/`
- `TransportMessageDTO` → API response shape in `dto/message.dto.ts`
- `UIMessage` → enriched display shape in `features/inbox/models/ui/`
- Migration trigger: when `BridgeMessage` in `ConversationStore` is removed
- Affected files (Sprint 13): `message.model.ts`, `conversation.store.ts`, `message-bubble.component.ts`

**Rationale**: Existing `BridgeMessage` + `Message` + `DomainMessage` triple already shows the problem. The fix requires a coordinated sweep across stores, components, and DTOs — too large for this sprint.

## Data Flow

```
[Backend API] ← HTTP → [ContactsApiService] ← Observable → [Use Cases] ←
                                                                          ↓
[Mercure SSE] ← contact.* events → [MercureService.applyStorePatch()] ←
                                                                          ↓
                                                         [ContactsStore signals]
                                                                          ↓
  [ContactDetailPanelComponent] ←──────────────────────── [ContactsListComponent]
                                                                         ↓
                                                        [ContactsPageComponent (smart)]
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/web/src/app/features/contacts/` | Create | Feature directory |
| `apps/web/src/app/features/contacts/index.ts` | Create | Barrel exports |
| `apps/web/src/app/features/contacts/models/domain/contact.model.ts` | Create | `DomainContact` interface |
| `apps/web/src/app/features/contacts/models/ui/contact-ui.model.ts` | Create | `UIContact` interface + `ContactFilters` |
| `apps/web/src/app/features/contacts/models/dto/contact-transport.dto.ts` | Create | `TransportContactDTO` + API response types |
| `apps/web/src/app/features/contacts/models/mappers.ts` | Create | `mapDTOToUIContact`, `mapDTOToDomainContact`, `mapUIContactToDomain` |
| `apps/web/src/app/features/contacts/stores/contacts.store.ts` | Create | Signal store with all signals + computed + patch methods |
| `apps/web/src/app/features/contacts/services/contacts-api.service.ts` | Create | HTTP service with mock Observable responses |
| `apps/web/src/app/features/contacts/application/contacts/get-contacts.use-case.ts` | Create | |
| `apps/web/src/app/features/contacts/application/contacts/search-contacts.use-case.ts` | Create | |
| `apps/web/src/app/features/contacts/application/contacts/create-contact.use-case.ts` | Create | |
| `apps/web/src/app/features/contacts/application/contacts/update-contact.use-case.ts` | Create | |
| `apps/web/src/app/features/contacts/application/contacts/open-conversation.use-case.ts` | Create | |
| `apps/web/src/app/features/contacts/application/contacts/index.ts` | Create | Barrel exports |
| `apps/web/src/app/features/contacts/pages/contacts-page/contacts-page.component.ts` | Create | Smart container |
| `apps/web/src/app/features/contacts/components/contacts-list/contacts-list.component.ts` | Create | Presentational list |
| `apps/web/src/app/features/contacts/components/contact-card/contact-card.component.ts` | Create | Presentational row |
| `apps/web/src/app/features/contacts/components/contact-detail-panel/contact-detail-panel.component.ts` | Create | Detail sidebar |
| `apps/web/src/app/features/contacts/components/contact-search/contact-search.component.ts` | Create | Search input w/ debounce |
| `apps/web/src/app/features/contacts/components/contact-empty/contact-empty.component.ts` | Create | Empty state |
| `apps/web/src/app/features/contacts/components/contact-skeleton/contact-skeleton.component.ts` | Create | Loading skeleton |
| `apps/web/src/app/features/contacts/components/contact-error/contact-error.component.ts` | Create | Error state |
| `apps/web/src/app/core/api/contacts-api.service.ts` | Create | HTTP service |
| `apps/web/src/app/core/realtime/mercure.service.ts` | Modify | Add contact topic + event handling |
| `apps/web/src/app/core/stores/ticket.store.ts` | Modify | Inject ContactsStore reference for cross-store navigation |
| `apps/web/src/app/core/application/providers/index.ts` | Modify | Register ContactsApiService in DI |

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | Mapping functions (`mapDTOToUIContact`, etc.) | Direct function calls, assert shape + derived fields |
| Unit | ContactsStore patch methods (`addContact`, `updateContact`, `patchContact`) | Inject store, call patch, assert signal state |
| Unit | Use cases (input validation, error handling, DTO→UI mapping) | Mock ContactsApiService, inject store, execute, assert |
| Unit | ContactCardComponent input binding | Component fixture, input, assert DOM render |
| Integration | ContactsPageComponent load flow | Mock API + store, init component, assert signals updated |
| E2E | Contact CRUD flow | Playwright: create → view → update → delete contact |

## Open Questions

- [ ] **Route decision**: Should contacts have `/contacts` or live inside inbox shell? Need UX input before Sprint 12.3.
- [ ] **Mock data**: Should mock data be realistic (per-channel) or minimal? Recommend realistic for dev UX.
- [ ] **ContactsApiService path**: `core/api/` vs `features/contacts/services/`? Choosing `core/api/` for consistency with `ticket-api.service.ts`, but `features/` placement may be cleaner.
- [ ] **Mercure topic URL**: Does the backend publish `contact/*` on the same Mercure hub? Depends on backend implementation — confirm in Sprint 12.3 backend work.
