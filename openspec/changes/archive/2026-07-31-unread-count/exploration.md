# Exploration: Unread Message Count per Ticket

## Current State

### Backend — Hardcoded Zero

**`ListTicketsQuery.php` (line 164)**
```php
metrics: ['unread_count' => 0],
```
The unread count is hardcoded to 0. The query performs no actual calculation of unread messages.

**Related files:**
- `apps/api/src/Application/Query/ListTicketsQuery.php` — ticket list query
- `apps/api/src/Controller/InboxController.php` — REST endpoints for `/tickets` and `/tickets/{id}/messages`
- `apps/api/src/Application/Query/GetTicketMessagesQuery.php` — loads messages for a conversation (triggered when agent opens a ticket)

### Frontend — Local-Only Badge

**`ticket-card.component.html` (lines 40-42)**
```html
@if ((ticket().metrics?.unread_count ?? 0) > 0) {
  <span class="ticket-card__unread">{{ ticket().metrics?.unread_count }}</span>
}
```

**`ticket.store.ts` (lines 227-233)**
```typescript
markAsRead(ticketId: string): void {
  this.tickets.update(tickets =>
    tickets.map(t => t.ticket_id === ticketId
      ? { ...t, metrics: { ...t.metrics, unread_count: 0 } }
      : t
    )
  );
}
```

The frontend already has a `markAsRead()` action that zeros the count **locally**. This is client-side only — no backend call is made to persist the read state.

---

## Database Schema Analysis

### Existing Tables

| Table | Relevant Columns | Notes |
|-------|-----------------|-------|
| `tickets` | `id`, `conversation_id`, `assigned_agent_id`, `status` | Ticket → conversation is 1:1 |
| `messages` | `id`, `conversation_id`, `direction`, `created_at` | `direction` ∈ {`inbound`, `outbound`} |
| `conversations` | `id`, `contact_id` | Links to contacts |
| `agents` | `id`, `email` | Agent entity |

### No Existing Read-Tracking Tables

- No `agent_ticket_reads` table
- No `ticket_views` table
- No `last_read_at` columns on any table
- The Meta webhook subscription includes `message_reads` (from WhatsApp platform), but this tracks **read receipts sent by the customer's WhatsApp client** — not agent-ticket view state

---

## Relevant API Endpoints and Flows

### 1. List Tickets
```
GET /api/tickets
```
- Controller: `InboxController::listTickets()`
- Query: `ListTicketsQuery::execute()`
- Returns: `TicketListItemDTO[]` with `metrics.unread_count` (always 0)

### 2. Get Ticket Detail
```
GET /api/tickets/{id}
```
- Controller: `InboxController::getTicket()`
- Query: `GetTicketDetailQuery::execute()`
- Does NOT return `unread_count`

### 3. Get Messages (conversation open)
```
GET /api/tickets/{id}/messages
```
- Controller: `InboxController::getMessages()`
- Query: `GetTicketMessagesQuery::execute()`
- Returns messages ordered by `created_at ASC/DESC`
- **This is where we would mark the ticket as read** (agent opened the conversation)

### Frontend Flow
```
Agent clicks ticket → loadMessages() → GET /api/tickets/{id}/messages
                     → markAsRead(ticketId) → local state only (unread_count → 0)
```

---

## Frontend Badge Implementation

**`ticket.model.ts`**
```typescript
export interface Ticket {
  // ...
  metrics: {
    unread_count: number;
  };
  // ...
}
```

**Badge visibility condition:**
```html
@if ((ticket().metrics?.unread_count ?? 0) > 0) {
  <span class="ticket-card__unread">{{ ticket().metrics?.unread_count }}</span>
}
```

The badge only shows when `unread_count > 0`.

---

## Design Approaches

### Approach A: `agent_ticket_reads` Table (Recommended)

**Schema:**
```sql
CREATE TABLE agent_ticket_reads (
  agent_id BIGINT NOT NULL,
  ticket_id BIGINT NOT NULL,
  last_read_at DATETIME NOT NULL,
  unread_count INT NOT NULL DEFAULT 0,
  PRIMARY KEY (agent_id, ticket_id),
  INDEX idx_art_ticket (ticket_id),
  FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
  FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
);
```

**Behavior:**
- On `GET /api/tickets/{id}/messages` → upsert `last_read_at = NOW()` for `(agent_id, ticket_id)`
- On `GET /api/tickets` → join `agent_ticket_reads` and calculate:
  ```sql
  unread_count = (
    SELECT COUNT(*) FROM messages m
    JOIN tickets t ON t.conversation_id = m.conversation_id
    WHERE t.id = ticket_id
      AND m.direction = 'inbound'
      AND m.created_at > COALESCE(atr.last_read_at, '1970-01-01')
  )
  ```
- Alternative: denormalize `unread_count` into `agent_ticket_reads` and update it asynchronously via event/queue when new messages arrive

**Pros:**
- Per-agent read state (multiple agents can view same ticket)
- Accurate unread count = count of inbound messages after last read
- No writes to messages table
- Upsert is efficient (single row per agent-ticket pair)

**Cons:**
- Extra table + join in list query
- Need to handle the async update of unread_count when new messages arrive (or accept near-real-time accuracy)

**Effort:** Medium

---

### Approach B: `last_read_at` on `tickets` Table

**Schema:**
```sql
ALTER TABLE tickets ADD COLUMN last_read_at DATETIME NULL;
```

**Behavior:**
- `last_read_at` is updated when the **assigned agent** opens the conversation
- `unread_count` calculated same as Approach A but scoped to assigned agent

**Pros:**
- No new table
- Simple query

**Cons:**
- Only works for **assigned agent** — supervisors viewing tickets would see wrong count
- Multiple agents = race condition on `last_read_at` update
- Breaks if ticket is reassigned

**Effort:** Low

---

### Approach C: `read` Flag on Messages

**Schema:**
```sql
ALTER TABLE messages ADD COLUMN read_at DATETIME NULL;
-- Or a separate read_receipts table
```

**Behavior:**
- Each message has a `read_at` timestamp (or a `read_by_agent_id`)
- When agent opens conversation, update all unread inbound messages to `read_at = NOW()`
- Unread = count of inbound messages with `read_at IS NULL`

**Pros:**
- Most accurate (individual message read state)

**Cons:**
- Large number of writes when opening a conversation (one UPDATE per unread message)
- O(n) writes on conversation open where n = number of unread messages
- Complex query for unread count

**Effort:** High

---

## Recommendation

**Approach A (`agent_ticket_reads` table)** is recommended.

### Rationale:
1. **Multi-agent safety**: Different agents can view the same ticket and have independent read states
2. **Efficient writes**: Single upsert on conversation open (not O(n) message updates)
3. **Accurate count**: Count of inbound messages after `last_read_at` is semantically correct
4. **Existing pattern**: The workspace-switcher sprint (Version20260728000001) uses a similar join-table pattern for agent-workspace associations

### Key Implementation Points:

1. **Mark as read trigger**: `GET /api/tickets/{id}/messages` → upsert `agent_ticket_reads`
2. **Calculate unread**: Subquery in `ListTicketsQuery` joining messages filtered by `direction = 'inbound'` and `created_at > last_read_at`
3. **No-op for new tickets**: `COALESCE(last_read_at, '1970-01-01')` ensures tickets never opened show full unread count
4. **Optional optimization**: Use a materialized count in `agent_ticket_reads.unread_count` and increment/decrement via event handlers on `HandleInboundMessageUseCase` — deferred to a future optimization sprint

---

## Scope

### In Scope
- Backend: `agent_ticket_reads` table migration
- Backend: Upsert logic when agent opens conversation (`GetTicketMessagesQuery` or new middleware)
- Backend: Calculate `unread_count` in `ListTicketsQuery`
- Frontend: Already has the badge UI — no frontend changes needed for display
- Frontend: Backend will now return real counts; existing `markAsRead()` in store can be removed or repurposed (optional)

### Out of Scope
- Push notifications or real-time updates (Mercure already handles message events)
- Read receipts from WhatsApp (Meta `message_reads` webhook — separate concern)
- Mobile push for unread counts
- Bulk mark-as-read (select multiple tickets)

---

## Risks

1. **List query performance**: Adding a subquery per ticket in `ListTicketsQuery` may slow down the endpoint with many tickets. Mitigation: add an index on `messages(conversation_id, direction, created_at)` and test with realistic data volumes.
2. **Stale unread count**: If we don't update `unread_count` when new messages arrive (async), agents may briefly see outdated counts. Acceptable for MVP; denormalized count can be added later.
3. **First-time open edge case**: If agent has no entry in `agent_ticket_reads`, they see full unread count — which is correct behavior.

---

## Ready for Proposal

**Yes.** The investigation is complete. The backend hardcodes `0`, the frontend has local-only state, and the fix is a new `agent_ticket_reads` table with upsert-on-open and count-on-list.
