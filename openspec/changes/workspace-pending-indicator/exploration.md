# Exploration: Workspace Pending Message Indicator

**Change**: `workspace-pending-indicator`
**Date**: 2026-07-31
**Phase**: sdd-explore
**Status**: Complete

---

## Current State

### 1. Backend — `listMyWorkspaces` endpoint

`MeController::listMyWorkspaces()` (line 390–424) returns `WorkspaceDTO[]` with fields:

```php
// WorkspaceDTO fields:
id, phone_number_id, display_name, is_active, created_at, updated_at, agent_count
```

No count or pending data is included. For admin/supervisor roles it fetches all active workspaces; for agents it returns only assigned workspaces.

### 2. Frontend — WorkspaceStore and workspace-switcher

- `WorkspaceStore.workspaces` holds `WorkspaceDTO[]` loaded via `listMyWorkspaces()`
- `WorkspaceSwitcherComponent.hasPending(_workspaceId)` (line 290) is a **stub that always returns `false`**
- The envelope icon in the dropdown template is already wired to `hasPending(ws.id)` (line 51), but no data flows to it
- No signal or state exists for per-workspace pending counts

### 3. Unread Tracking Architecture

**Per-ticket only**, driven by `last_read_at` on the `Ticket` entity:

- `Ticket.last_read_at` (`datetime_immutable`, nullable) — set when the owner agent opens a ticket
- **Unread count per ticket** is computed in `ListTicketsQuery` (lines 110–131): count inbound `messages` with `created_at > COALESCE(ticket.last_read_at, '1970-01-01')` per conversation
- `TicketDetailDTO` includes `metrics: { unread_count: int }`
- `InboxController::markAsRead()` (line 181) sets `ticket.last_read_at = now()` and emits a `ticket.read` Mercure event to `outbox_events` with topic `tickets`

**Realtime events** (`RealtimeEventType`):
- `message.added` — TicketStore increments `unread_count` for non-active tickets
- `ticket.read` — TicketStore calls `markAsRead()` clearing unread for that ticket
- All events are broadcast via Mercure to topic `tickets` (global, no workspace filter)

### 4. Mercure Subscription

`MercureService.connect()` (line 85) subscribes to a single global topic:

```typescript
const hubUrl = `${environment.mercureUrl}/.well-known/mercure?topic=tickets`;
```

No per-workspace topic. All ticket events for all workspaces arrive on this single stream.

---

## How to Compute Pending Count Per Workspace

A workspace has a pending indicator when **at least one ticket in that workspace has unread messages for the current agent**.

The SQL pattern already exists in `ListTicketsQuery` (lines 117–123). Extending it per workspace:

```sql
SELECT t.phone_number_id, COUNT(DISTINCT t.id) as pending_tickets
FROM tickets t
JOIN conversations conv ON conv.id = t.conversation_id
JOIN messages m ON m.conversation_id = conv.id
WHERE t.assigned_agent_id = :agentId
  AND t.phone_number_id = :workspacePhoneNumberId
  AND m.direction = 'inbound'
  AND m.created_at > COALESCE(t.last_read_at, '1970-01-01')
GROUP BY t.phone_number_id
```

However, `last_read_at` is per-ticket (owned by one agent), so the "pending for me" question only makes sense for tickets **assigned to me**. The unread computation counts messages in conversations where the ticket's `last_read_at` is older than the message.

**Simpler boolean approach** — return only a `has_pending: bool` per workspace, not a count:
- For each workspace, check: does any ticket in this workspace (assigned to me, non-closed status) have a message with `created_at > last_read_at`?
- This avoids per-conversation N+1 and collapses to a single EXISTS query per workspace.

---

## Affected Areas

### Backend
- `MeController.php` — add pending counts to `listMyWorkspaces` response OR new dedicated endpoint
- `WorkspaceDTO.php` — add `has_pending: bool` field
- New SQL query or query modifier to compute per-workspace pending state for the current agent
- Optionally: `TicketRepository` for the workspace-scoped pending query

### Frontend
- `WorkspaceDTO` interface — add `has_pending?: boolean` field
- `WorkspaceSwitcherComponent` — replace `hasPending()` stub with signal lookup
- `WorkspaceStore` — add `pendingWorkspaceIds: signal<Set<number>>`, populate on load, update on realtime events
- `EventBusService` or `TicketStore` — intercept `MessageAdded` and `TicketRead` events to update per-workspace pending state

---

## Approaches

### Approach A — Extend `listMyWorkspaces` with `has_pending` (Recommended)

**What**: Add `has_pending: bool` to `WorkspaceDTO`. `MeController::listMyWorkspaces()` runs a lightweight EXISTS query per workspace to set it. Frontend populates a `pendingWorkspaceIds` signal on load and subscribes to `MessageAdded`/`TicketRead` events to update it incrementally.

**Backend changes**:
- Modify `WorkspaceDTO::fromEntity()` to accept `hasPending: bool`
- In `listMyWorkspaces()`, for each workspace run:
  ```sql
  SELECT EXISTS(
    SELECT 1 FROM tickets t
    JOIN conversations c ON c.id = t.conversation_id
    JOIN messages m ON m.conversation_id = c.id
    WHERE t.phone_number_id = :phoneNumberId
      AND t.assigned_agent_id = :agentId
      AND t.status NOT IN ('closed', 'resolved')
      AND m.direction = 'inbound'
      AND m.created_at > COALESCE(t.last_read_at, '1970-01-01')
  ) AS has_pending
  ```
- This is one extra query per workspace (acceptable for the typical 1–5 workspace range)

**Frontend changes**:
- `WorkspaceStore.pendingWorkspaceIds: signal<Set<number>>` — set of workspace IDs with pending messages
- On load: populate from API response `has_pending` field
- On `MessageAdded`: check if `event.payload.ticketId` belongs to a non-active workspace → add that workspace to the set
- On `TicketRead`: check if the ticket was in a non-active workspace → remove from set if no other pending tickets

**Pros**:
- Minimal backend surface — one endpoint, already called on app load
- Frontend real-time updates are incremental (no re-fetching)
- `has_pending` is exactly what the UI needs (envelope icon, no count needed)
- Reuses existing event types (`message.added`, `ticket.read`)

**Cons**:
- One extra SQL query per workspace on initial load (N workspaces, typically small N)
- Frontend must track pending state in a new signal and keep it in sync

**Effort**: Low-Medium

---

### Approach B — New dedicated endpoint `GET /api/me/workspaces/pending`

**What**: Keep `listMyWorkspaces()` unchanged. Add `GET /api/me/workspaces/pending` returning `{ workspace_id: int, has_pending: bool }[]`. Frontend calls it on load and on SSE reconnect.

**Pros**:
- Separates concerns — workspace metadata vs. real-time state
- `listMyWorkspaces` stays lean (no per-workspace subquery)

**Cons**:
- Extra HTTP call on every app load
- No real-time update without also subscribing to events
- Two endpoints to maintain instead of one

**Effort**: Medium

---

### Approach C — Frontend-only tracking from event bus

**What**: Frontend tracks `pendingWorkspaceIds` purely via `MessageAdded` and `TicketRead` events. On load, initially mark all non-active workspaces as "unknown" (no icon). On first `MessageAdded` for a workspace, show the icon. No backend changes.

**Pros**:
- No backend changes at all
- No extra API calls

**Cons**:
- **False negatives on load**: after a page refresh, the frontend has no idea which non-active workspaces have pending messages unless it re-fetches. The icon would be missing for workspaces that actually have pending tickets.
- Requires tracking which ticket belongs to which workspace — needs a `ticket → phone_number_id` mapping on the frontend (not currently available)
- Fragile — state depends solely on SSE event stream

**Effort**: Medium (but incomplete solution)

---

### Approach D — Workspace-scoped Mercure topics

**What**: Subscribe to `tickets/{phoneNumberId}` Mercure topics per workspace. Backend emits per-workspace events. Requires changing Mercure topic structure and backend event emission.

**Pros**:
- Clean separation at the transport layer

**Cons**:
- Significant refactor: Mercure topic structure, backend emission, frontend subscription management
- Overkill for a simple indicator
- Out of scope for the sprint

**Effort**: High

---

## Recommendation

**Approach A** — extend `listMyWorkspaces` with `has_pending: bool` and use existing realtime events for incremental frontend updates.

### Why not Approach B?
Two endpoints when one suffices. The lean `listMyWorkspaces` is valuable but `has_pending` is a single boolean — no reason to split it.

### Why not Approach C?
It creates false negatives on every page load. The user could have 3 pending messages in workspace B, switch to workspace A, refresh — and the icon would be gone. Not acceptable.

### Why not Approach D?
Out of scope. Purely a UI concern; no need to change the transport layer.

---

## Real-Time Update Strategy

With Approach A, the frontend tracks pending state incrementally:

1. **On load**: `pendingWorkspaceIds` populated from `has_pending` in `listMyWorkspaces` response
2. **On `message.added`**: need to know which workspace the ticket belongs to
   - Currently `MessageAddedPayload` has only `ticketId`
   - Need to resolve `ticket.phone_number_id` to workspace — either:
     - (a) include `phoneNumberId` in `MessageAddedPayload` (backend change, minimal)
     - (b) maintain a `ticketId → phoneNumberId` map in `TicketStore` or a dedicated service
   - Option (a) is cleaner — add one field to the event payload
3. **On `ticket.read`**: same resolution needed
4. **On workspace switch**: `pendingWorkspaceIds` should **not** clear — the icon should still show pending for other workspaces
5. **On SSE reconnect** (`resyncAllTickets`): no special action needed; the API re-fetch on load will refresh `has_pending`

---

## Risks

1. **Performance of `has_pending` query**: Running an EXISTS subquery per workspace on every `listMyWorkspaces` call (on app load, after logout/login). For workspaces with thousands of tickets, the subquery could be slow. Mitigation: add a database index on `(phone_number_id, assigned_agent_id, status)` to make the EXISTS fast.

2. **`MessageAdded` payload needs `phoneNumberId`**: The existing event doesn't carry workspace context. Need to either add it to the payload (breaking change to the event contract) or track ticket→workspace mapping in the frontend. Best to add it to the payload — it's a pure extension.

3. **Pending state and page refresh**: After a hard refresh, the frontend re-fetches `listMyWorkspaces` and gets fresh `has_pending` values. This is the correct behavior — no risk.

4. **Agent without active workspace**: Edge case — if the agent has no active workspace set, all workspaces are "inactive" from the perspective of "not the current one." The pending indicator should still work normally.

---

## Ready for Proposal

**Yes.** The implementation path is clear:

1. Backend: add `has_pending` to `WorkspaceDTO` + EXISTS query in `listMyWorkspaces`
2. Backend: optionally add `phoneNumberId` to `MessageAddedPayload` (or use the ticket→workspace map in frontend)
3. Frontend: add `pendingWorkspaceIds` signal to `WorkspaceStore`
4. Frontend: update `hasPending()` in `WorkspaceSwitcherComponent` to check the signal
5. Frontend: subscribe to `MessageAdded`/`TicketRead` events to update the signal incrementally
