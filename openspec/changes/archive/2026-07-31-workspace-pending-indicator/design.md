# Design: Workspace Pending Message Indicator

## Technical Approach

Extend `GET /api/me/workspaces` with a per-row `has_pending: bool` from a small `SELECT EXISTS(...)` subquery, then mirror it on the frontend as `WorkspaceStore.pendingWorkspaceIds: signal<Set<number>>` seeded from the API and updated incrementally through existing `message.added` and `ticket.read` Mercure events. One wire extension: add `phoneNumberId` to `MessageAddedPayload`. No new topics, no new endpoint.

## Architecture Decisions

### Decision: Per-workspace EXISTS subquery

| Option | Tradeoff | Decision |
|--------|----------|----------|
| One `EXISTS` per workspace (N ≤ 5 typical) | Sub-ms on `(phone_number_id, assigned_agent_id, status)` index; linear controller | **Chosen** |
| Single batched query returning a map | One round-trip but controller branches; N=1 edge case | Rejected |
| Dedicated `GET /me/workspaces/pending` endpoint | Extra HTTP call on every load + SSE drift | Rejected |

### Decision: Add `phoneNumberId` to `MessageAddedPayload`

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Wire-contract extension (one field) | Always resolvable, even when ticket not yet in `TicketStore.tickets()` (e.g., `TicketCreated` and `MessageAdded` arrive separately) | **Chosen** |
| Look up via `TicketStore` by `ticketId` | Brittle under event race | Rejected |

### Decision: `pendingWorkspaceIds` lives in `WorkspaceStore`

Pending state is a property of workspaces, not tickets. `TicketStore` already injects other stores; it now injects `WorkspaceStore` and calls `markWorkspacePending` / `recheckWorkspacePending`. Switcher keeps a single store dependency.

### Decision: Active-workspace rule enforced in display layer only

`setActiveWorkspace` already does `window.location.reload()`, so `pendingWorkspaceIds` is re-seeded on every switch. Switcher renders the icon only when `pendingWorkspaceIds.has(id) && id !== activeWorkspace()?.workspace_id` — pure display filter, no mutation. Covers the "no active workspace" edge case automatically.

## Data Flow

```
Page load:   GET /me/workspaces ─► WorkspaceStore
                                    ├─► workspaces.set(...)
                                    └─► pendingWorkspaceIds = {id : ws.has_pending}

MessageAdded (inbound, non-active):
  Mercure ─► EventBus ─► TicketStore ─► workspaceStore.markWorkspacePending(phoneNumberId)
                                                       └► pendingWorkspaceIds.add(workspaceId)

TicketRead:
  TicketStore ─► markAsRead(ticketId)
              └► workspaceStore.recheckWorkspacePending(ticketId):
                  if no other ticket in same workspace has unread_count > 0
                       pendingWorkspaceIds.delete(workspaceId)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/api/src/Application/DTO/WorkspaceDTO.php` | Modify | Add `hasPending: bool` (ctor + `fromEntity` + `toArray`) |
| `apps/api/src/Controller/MeController.php` | Modify | `listMyWorkspaces`: per-workspace `EXISTS`; pass bool to DTO |
| `apps/api/src/Domain/Event/MessageCreatedEvent.php` | Modify | Add `?string $phoneNumberId` arg |
| `apps/api/src/Application/UseCase/HandleInboundMessageUseCase.php` | Modify | Pass `$ticket->getPhoneNumberId()`; add `phone_number_id` to outbox payload |
| `apps/api/src/Application/Realtime/RealtimeEventFactory.php` | Modify | Add `phoneNumberId` to `MessageAdded` wire payload |
| `apps/api/migrations/Version20260804000001.php` | Create | `CREATE INDEX idx_tickets_pending ON tickets (phone_number_id, assigned_agent_id, status)` |
| `apps/web/src/app/core/dto/workspace.dto.ts` | Modify | Add `has_pending?: boolean` |
| `apps/web/src/app/core/models/ticket.model.ts` | Modify | Add `phone_number_id?: string` |
| `apps/web/src/app/core/models/realtime-event.model.ts` | Modify | Add `phoneNumberId: string \| null` to `MessageAddedPayload` |
| `apps/web/src/app/core/stores/workspace.store.ts` | Modify | Add `pendingWorkspaceIds` + `markWorkspacePending` / `recheckWorkspacePending` / `seedPending` |
| `apps/web/src/app/core/stores/ticket.store.ts` | Modify | `MessageAdded` (inbound, non-active) → `markWorkspacePending`; `TicketRead` → `recheckWorkspacePending`; inject `WorkspaceStore` |
| `apps/web/src/app/shell/sidebar/workspace-switcher.component.ts` | Modify | Replace stub: `pendingWorkspaceIds().has(id) && id !== activeWorkspace()?.workspace_id` |
| `apps/api/tests/.../MeControllerListWorkspacesTest.php` | Create | Kernel test: 4 fixtures → 4 booleans |

## Interfaces / Contracts

```php
// WorkspaceDTO
public readonly bool $hasPending;
public static function fromEntity(Workspace $w, int $agentCount = 0, bool $hasPending = false): self;

// MessageCreatedEvent
public function __construct(..., public ?string $phoneNumberId = null) {}
```

```typescript
// MessageAddedPayload
export interface MessageAddedPayload { /* ... */ phoneNumberId: string | null }

// WorkspaceStore (new surface)
readonly pendingWorkspaceIds: signal<Set<number>>;
markWorkspacePending(phoneNumberId: string): void;
recheckWorkspacePending(ticketId: string): void;
seedPending(workspaces: WorkspaceDTO[]): void;
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit (BE) | `has_pending` for 4 fixture states (no tickets, only-read, inbound after `last_read_at`, closed) | PHPUnit kernel test on `MeController::listMyWorkspaces` |
| Unit (FE) | Add on `MessageAdded` (inbound), no-op on outbound, remove on `TicketRead` when no other unread | Jasmine spec on `WorkspaceStore` + `TicketStore` |
| Component | Switcher renders envelope only when `pendingWorkspaceIds.has(id) && id !== active` | Component test with stubbed `WorkspaceStore` |
| Smoke | End-to-end: inbound in non-active workspace → icon within one event tick | Manual: open app, send inbound via webhook, observe icon |

## Migration / Rollout

Add composite index migration. Backward compatible: old clients ignore `has_pending`; new clients fall back to no-icon on parse error. Rollback: drop the field from DTO and stop emitting `phoneNumberId` (index harmless to keep).

## Open Questions

- **`TicketRead` without ticket in store**: `recheckWorkspacePending` resolves via `TicketStore.tickets[]`. If the ticket isn't loaded yet, the recheck is a no-op and the icon stays until the next `MessageAdded` or page load. Acceptable per spec. No `phoneNumberId` on `TicketRead` payload to keep that change minimal.
- **Admin/Supervisor scope**: `has_pending` is computed across *all* tickets in each workspace, not just `admin`'s own — confirmed by proposal (no special case).
