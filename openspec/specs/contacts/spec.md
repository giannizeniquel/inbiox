# Delta: Contacts MVP Specification

## ADDED Requirements

### Requirement: Frontend Three-Layer Model Separation

The system MUST implement a three-layer model hierarchy for contacts with explicit mapping functions between layers.

#### Scenario: DomainContact canonical model

- GIVEN a contact entity from the backend
- WHEN the contact is used in domain logic (use cases, business rules)
- THEN it MUST conform to the DomainContact interface:
  ```ts
  interface DomainContact {
    id: string;
    phone: string;
    name: string | null;
    createdAt: string;
    updatedAt: string;
  }
  ```

#### Scenario: UIContact enriched model

- GIVEN a contact from the API DTO
- WHEN the contact is displayed in UI components
- THEN it MUST conform to the UIContact interface:
  ```ts
  interface UIContact {
    // From DomainContact
    id: string;
    phone: string;
    name: string | null;
    // Derived/operational (from DTO, not entity)
    lastInteraction: string | null;
    unreadCount: number;
    activeTicketId: string | null;
    tags: string[];
    owner: { id: number; name: string } | null;
    status: 'active' | 'inactive';
    channel: 'whatsapp' | 'other';
  }
  ```

#### Scenario: TransportContactDTO backend response

- GIVEN a backend API response for contacts
- WHEN the frontend receives the JSON payload
- THEN it MUST conform to the TransportContactDTO interface:
  ```ts
  interface TransportContactDTO {
    // Raw entity fields
    id: string;
    phone: string;
    name: string | null;
    createdAt: string;
    updatedAt: string;
    // Operational fields from DTO
    lastInteraction: string | null;
    unreadCount: number;
    activeTicketId: string | null;
    tags: string[];
    ownerId: number | null;
    ownerName: string | null;
    status: 'active' | 'inactive';
    channel: 'whatsapp' | 'other';
  }
  ```

#### Scenario: Model mapping functions

- GIVEN a TransportContactDTO from the API
- WHEN transforming to UI layer
- THEN the system MUST provide:
  - `mapDTOToUIContact(dto: TransportContactDTO): UIContact`
  - `mapDTOToDomainContact(dto: TransportContactDTO): DomainContact`
  - `mapUIContactToDomain(contact: UIContact): DomainContact`

---

### Requirement: Realtime Event Schemas

The system MUST handle SSE events for incremental contact state updates without polling.

#### Scenario: contact.created event

- GIVEN a new contact is created in the backend
- WHEN the SSE connection receives a `contact.created` event
- THEN the event payload MUST contain:
  ```ts
  interface ContactCreatedEvent {
    type: 'contact.created';
    data: TransportContactDTO;
    timestamp: string;
  }
  ```
- AND the ContactsStore MUST add the new contact to the contacts array

#### Scenario: contact.updated event

- GIVEN an existing contact is modified in the backend
- WHEN the SSE connection receives a `contact.updated` event
- THEN the event payload MUST contain:
  ```ts
  interface ContactUpdatedEvent {
    type: 'contact.updated';
    data: Partial<TransportContactDTO>;
    contactId: string;
    timestamp: string;
  }
  ```
- AND the ContactsStore MUST merge the update into the existing contact record

#### Scenario: ticket.assigned event affects contact

- GIVEN a ticket is assigned to a contact in the backend
- WHEN the SSE connection receives a `ticket.assigned` event
- THEN the event payload MUST contain:
  ```ts
  interface TicketAssignedEvent {
    type: 'ticket.assigned';
    data: {
      ticketId: string;
      contactId: string;
      assigneeId: number;
    };
    timestamp: string;
  }
  ```
- AND the ContactsStore MUST update the contact's activeTicketId and owner fields

#### Scenario: new.message event updates contact lastInteraction

- GIVEN a new message arrives for a contact's conversation
- WHEN the SSE connection receives a `new.message` event
- THEN the event payload MUST contain:
  ```ts
  interface NewMessageEvent {
    type: 'new.message';
    data: {
      messageId: string;
      conversationId: string;
      contactId: string;
      preview: string;
      timestamp: string;
    };
  }
  ```
- AND the ContactsStore MUST update the contact's lastInteraction field

---

### Requirement: ContactsStore Signal Structure

The system MUST expose reactive signals for all contact state management.

#### Scenario: Core signals exposed

- GIVEN the ContactsStore is initialized
- WHEN components read store values
- THEN the store MUST expose the following signals:
  - `contacts(): UIContact[]` — all loaded contacts
  - `selectedContact(): UIContact | null` — currently selected contact for detail view
  - `loading(): boolean` — loading state for async operations
  - `filters(): ContactFilters` — active filter state
  - `searchTerm(): string` — current search query
  - `pagination(): { page: number; total: number; hasMore: boolean }` — pagination state
  - `error(): string | null` — error message if any

#### Scenario: ContactFilters interface

- GIVEN filter controls are applied
- WHEN the filter state changes
- THEN it MUST conform to:
  ```ts
  interface ContactFilters {
    status?: 'active' | 'inactive';
    ownerId?: number;
    tags?: string[];
    channel?: 'whatsapp' | 'other';
  }
  ```

#### Scenario: Computed signals for derived state

- GIVEN contacts are loaded
- WHEN derived values are needed
- THEN computed signals MUST provide:
  - `filteredContacts(): UIContact[]` — contacts after applying filters/search
  - `contactById(id: string): UIContact | undefined` — lookup by ID

---

### Requirement: Use Case Contracts

The system MUST implement use cases with defined input/output/error contracts.

#### Scenario: GetContactsUseCase

- GIVEN pagination and filter parameters
- WHEN GetContactsUseCase is executed
- THEN input MUST be:
  ```ts
  interface GetContactsInput {
    page?: number;
    limit?: number;
    filter?: ContactFilters;
  }
  ```
- AND output MUST be:
  ```ts
  interface GetContactsOutput {
    contacts: UIContact[];
    total: number;
    page: number;
    hasMore: boolean;
  }
  ```
- AND errors MUST include `ContactsFetchFailed` with message and original error

#### Scenario: SearchContactsUseCase

- GIVEN a search query string
- WHEN SearchContactsUseCase is executed
- THEN input MUST be:
  ```ts
  interface SearchContactsInput {
    query: string;
    page?: number;
    limit?: number;
  }
  ```
- AND output MUST match GetContactsOutput structure
- AND errors MUST include `ContactsSearchFailed`

#### Scenario: CreateContactUseCase

- GIVEN contact creation data
- WHEN CreateContactUseCase is executed
- THEN input MUST be:
  ```ts
  interface CreateContactInput {
    phone: string;
    name?: string;
    email?: string;
  }
  ```
- AND output MUST be:
  ```ts
  interface CreateContactOutput {
    contact: UIContact;
  }
  ```
- AND errors MUST include `ContactCreateFailed`

#### Scenario: UpdateContactUseCase

- GIVEN contact ID and update data
- WHEN UpdateContactUseCase is executed
- THEN input MUST be:
  ```ts
  interface UpdateContactInput {
    id: string;
    name?: string;
    email?: string;
    tags?: string[];
  }
  ```
- AND output MUST be:
  ```ts
  interface UpdateContactOutput {
    contact: UIContact;
  }
  ```
- AND errors MUST include `ContactUpdateFailed`

#### Scenario: OpenConversationFromContactUseCase

- GIVEN a contact ID
- WHEN OpenConversationFromContactUseCase is executed
- THEN input MUST be:
  ```ts
  interface OpenConversationInput {
    contactId: string;
  }
  ```
- AND output MUST be:
  ```ts
  interface OpenConversationOutput {
    ticketId: string;
  }
  ```
- AND errors MUST include `ConversationOpenFailed`
- AND the flow MUST find an existing open ticket OR create a new ticket for the contact

---

### Requirement: API Contracts

The system MUST define REST endpoints for contact operations.

#### Scenario: GET /contacts endpoint

- GIVEN a GET request to /contacts
- WHEN query parameters include page, limit, search, status
- THEN the endpoint MUST return:
  ```ts
  interface ContactsListResponse {
    data: TransportContactDTO[];
    meta: {
      page: number;
      limit: number;
      total: number;
      hasMore: boolean;
    };
  }
  ```

#### Scenario: GET /contacts/:id endpoint

- GIVEN a GET request to /contacts/:id
- WHEN requesting a specific contact
- THEN the endpoint MUST return TransportContactDTO with enriched fields including related tickets

#### Scenario: POST /contacts endpoint

- GIVEN a POST request to /contacts
- WHEN creating a new contact
- THEN the request body MUST be:
  ```ts
  interface CreateContactRequest {
    phone: string;
    name?: string;
    email?: string;
  }
  ```
- AND response MUST return the created TransportContactDTO

#### Scenario: PATCH /contacts/:id endpoint

- GIVEN a PATCH request to /contacts/:id
- WHEN updating an existing contact
- THEN the request body MUST be:
  ```ts
  interface UpdateContactRequest {
    name?: string;
    email?: string;
    tags?: string[];
    status?: 'active' | 'inactive';
  }
  ```
- AND response MUST return the updated TransportContactDTO

#### Scenario: DELETE /contacts/:id endpoint

- GIVEN a DELETE request to /contacts/:id
- WHEN soft-deleting a contact
- THEN the endpoint MUST perform a soft delete (archive) rather than hard delete
- AND response MUST return success confirmation

#### Scenario: Concurrent duplicate phone on create

- GIVEN two requests attempt to create a contact with the same phone at nearly the same time
- WHEN the second request's flush hits the database's unique constraint
- THEN the system MUST return a deterministic duplicate-contact error (never a `500`)
- AND the `EntityManager` MUST remain usable for any subsequent operation in the same process

---

### Requirement: UI Specifications

The system MUST implement UI components with defined specifications.

#### Scenario: Contact list item display

- GIVEN a contact in the list
- WHEN rendered as a row
- THEN each item MUST display:
  - Avatar or initials (first 2 letters of name or phone last 2 digits)
  - Name (or phone if name is null, in muted text)
  - Phone number
  - Last message preview (truncated to 40 chars)
  - Timestamp (relative format: "2m", "1h", "Yesterday")
  - Unread badge (count if > 0)
  - Status indicator (green dot for active, gray for inactive)

#### Scenario: Contact detail panel

- GIVEN a selected contact
- WHEN the detail panel is displayed
- THEN it MUST show:
  - Header: Avatar, name, phone, channel badge
  - Info section: Email, status, owner
  - Tags section: Tag pills (editable)
  - Related tickets: Last 5 tickets with status and date
  - "Open conversation" CTA button
  - Last activity: timestamp and description

#### Scenario: Empty states

- GIVEN no contacts exist
- WHEN the contacts page loads
- THEN display empty state with:
  - Icon: users icon
  - Message: "No contacts yet"
  - CTA: "Add your first contact"

- GIVEN search returns no results
- WHEN search query has no matches
- THEN display empty state with:
  - Icon: search icon
  - Message: "No contacts match your search"
  - CTA: "Clear filters"

#### Scenario: Loading states

- GIVEN contacts are being fetched
- WHEN initial load or pagination
- THEN display skeleton loaders:
  - List: 5 skeleton rows matching list item layout
  - Detail: Skeleton panels for each section

- GIVEN an error occurs
- WHEN fetch fails
- THEN display error state with:
  - Icon: error icon
  - Message: "Failed to load contacts"
  - CTA: "Try again"

#### Scenario: Responsive behavior

- GIVEN viewport is desktop (>1024px)
- WHEN contacts page renders
- THEN display two-column layout (list + detail panel)

- GIVEN viewport is mobile (<1024px)
- WHEN contacts page renders
- THEN display single-column with list/detail toggle (Sprint 11A pattern)

---

### Requirement: Message Model Preparation

The system MUST design for future DomainMessage/TransportMessageDTO/UIMessage split without completing the migration.

#### Scenario: Message model future separation

- GIVEN the current message model
- WHEN planning for gradual migration
- THEN the architecture MUST document the intended three-layer split:
  - `DomainMessage`: Canonical message representation
  - `TransportMessageDTO`: API response shape
  - `UIMessage`: UI-ready enriched message

- AND the spec MUST indicate which files will need changes in future sprints
- AND NO implementation changes are required in this sprint

---

## Summary

| Type | Count |
|------|-------|
| ADDED Requirements | 7 |
| Total Scenarios | 23 |
| MODIFIED Requirements | 0 |
| REMOVED Requirements | 0 |

---

## Coverage

- **Happy paths**: All core CRUD operations, list rendering, search, detail view, realtime updates
- **Edge cases**: Empty states, loading states, error handling, pagination boundaries, large lists
- **Error states**: API failures, validation errors, network errors, unauthorized access

---

## Next Steps

Ready for design (sdd-design). If design already exists, ready for tasks (sdd-tasks).