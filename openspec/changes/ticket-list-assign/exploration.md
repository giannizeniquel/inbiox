# Exploration: ticket-list-assign

**Change**: `ticket-list-assign` — Asignación desde lista de tickets  
**Phase**: SDD Explore  
**Date**: 2026-08-02

---

## Current State

### Ticket List Architecture
- `TicketListComponent` (`ticket-list.component.ts`) — container with `VirtualScrollViewportComponent`, fixed 64px item height, 5-item buffer. Signals-based, calls `TicketApiService.getTickets()` on init.
- `TicketCardComponent` (`ticket-card.component.ts`) — OnPush presentational card. Has avatar, contact name, time, status chip, priority, unread badge. `select` output emits `ticketId` string on click/keyboard.
- `TicketStore` — manages ticket state via Angular signals. Already handles `TicketAssigned` realtime events: updates `owner` field inline.

### Ticket Model (frontend)
```typescript
owner: { id: number; name: string } | null  // already supports assigned agent
```

### Backend Assignment Endpoint
- `POST /tickets/{id}/assign` → `TicketController::assign()`
- DTO: `AssignTicketRequest` → `{ agentId: int, assignedBy: string, notes: string|null }`
- Uses `AssignTicketUseCase` → `AssignmentService.recordOwnershipChange()`
- Distinguishes `MANUAL_ASSIGN` vs `REASSIGN` by checking current owner
- Returns full assignment record + `status: 'ok'`

### Agent Availability (backend)
- `AgentAvailabilityService` — 5-step cascade: active check → override → shift schedule → capacity → default true
- `isAvailable(Agent, DateTimeImmutable): bool` — single agent
- `listAvailableAgents(DateTimeImmutable): Agent[]` — all active agents system-wide, batch-loaded (no N+1)

### Workspace-Agent Relationship
- ManyToMany via `agent_workspace` join table
- `AgentRepository::findByWorkspace(Workspace): Agent[]` — returns agents in a workspace
- `WorkspaceStore` exposes `activeWorkspace: WorkspacePreferenceResponse | null`

### Frontend Services
- `TicketApiService` — `getTickets()`, `getTicket()`. **No assign method exists.**
- `WorkspaceApiService` — `getActiveWorkspace()`, `listMyWorkspaces()`. No agent listing.
- `AuthStore` — `currentUser: UserDTO | null`, has `agent_id`.

---

## Affected Areas

| File | Why Affected |
|------|-------------|
| `apps/api/src/Controller/TicketController.php` | Already has `POST /tickets/{id}/assign` — no changes needed here |
| `apps/api/src/Controller/AssignTicketRequest.php` | Already has `assignedBy: string` — no changes needed |
| `apps/api/src/Application/Service/AgentAvailabilityService.php` | Logic already exists — no changes needed |
| `apps/api/src/Repository/AgentRepository.php` | Has `findByWorkspace(Workspace)` but no availability filtering |
| `apps/api/src/Repository/AgentRepository.php` | **MISSING**: method to find workspace agents with availability evaluated |
| `apps/api/src/Controller/AgentAvailabilityController.php` | Has individual agent availability. **MISSING**: endpoint to list available agents per workspace |
| `apps/web/src/app/core/api/ticket-api.service.ts` | **MISSING**: `assignTicket(ticketId, agentId, notes?)` method |
| `apps/web/src/app/core/api/workspace-api.service.ts` | **MISSING**: `getAvailableAgents(workspaceId)` method |
| `apps/web/src/app/features/inbox/components/ticket-card/ticket-card.component.ts` | Needs `assign` output + dropdown open state |
| `apps/web/src/app/features/inbox/components/ticket-card/ticket-card.component.html` | Needs dropdown button + agent list |
| `apps/web/src/app/features/inbox/components/ticket-card/ticket-card.component.scss` | Dropdown styling |
| `apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.ts` | Needs to provide agents list to cards |
| `apps/web/src/app/core/stores/ticket.store.ts` | May need `assignTicket` optimistic update support |

---

## Approaches

### Approach A — Inline dropdown button per card (Recommended)

Place a compact dropdown on each ticket card (right side, near the status chip area). When opened, it shows the list of available agents filtered by:
1. Member of the active workspace (from `agent_workspace`)
2. Currently available (full 5-step cascade evaluated server-side)
3. Sorted by name

**Flow**:
1. Card renders current `owner.name` or "Sin asignar" as the dropdown trigger label
2. On trigger click → open dropdown with loading state → fetch `GET /api/workspaces/{id}/available-agents`
3. On agent select → `POST /tickets/{id}/assign` with `assignedBy = currentUser.name`
4. Optimistic update: immediately update `owner` in `TicketStore`
5. On error: rollback + show inline error on that card's dropdown

**Backend changes**:
- New `GET /api/workspaces/{workspaceId}/available-agents` endpoint (or reuse existing)
- Combines `AgentRepository::findByWorkspace()` + `AgentAvailabilityService::listAvailableAgents()` per-workspace
- No new use case needed — logic already exists, just a thin controller

**Frontend changes**:
- `TicketApiService.assignTicket(ticketId, agentId, notes?)` — new method
- `WorkspaceApiService.getAvailableAgents(workspaceId)` — new method
- `TicketCardComponent` — add `agents` input, `assign` output, internal dropdown open state
- `TicketListComponent` — load and cache agents list, pass to cards

| Pros | Cons |
|------|------|
| True "from the list" UX — one click | Dropdown inside fixed 64px card is tight |
| Leverages existing assignment backend | Need to handle open/close per card + virtual scroll |
| Realtime already updates owner on `TicketAssigned` | All visible cards need the same agents list — could fetch once and share |
| Optimistic update feels instant | Error rollback requires per-card error state |

### Approach B — Slide-over detail with assignment

Keep the existing card click → detail view flow. Assignment happens inside the detail slide-over (already planned in other sprints). **Not recommended for this change** — explicitly against the "without going into ticket detail view" requirement.

### Approach C — Dedicated "assign mode" via toolbar

Select one or more tickets via checkboxes, then use a toolbar to assign. **Not recommended** — requires checkbox selection UX not in the current card design, and the requirements say "from the list card" specifically.

---

## Recommendation

**Approach A** (inline dropdown) is the only approach that satisfies the stated requirements. The implementation is straightforward given existing backend logic, but there are several non-obvious complexities:

### Key Implementation Complexities

1. **Virtual scroll + dropdowns**: The virtual scroll viewport only renders ~10-15 cards at a time. If each card renders an open dropdown, that's manageable. However, the dropdown list itself may render outside the card bounds — it must be `position: absolute` relative to the card, NOT the viewport. The card's `position: relative` CSS already exists. z-index stacking with the virtual scroll container needs testing.

2. **Dropdown data loading strategy**: Load agents once per workspace (cached in `TicketListComponent` signal). On workspace switch, invalidate. Do NOT load per-card.

3. **Stop propagation**: The dropdown trigger click must `stopPropagation()` so card selection (`selectTicket()`) is not fired.

4. **`assignedBy` field**: `AssignTicketRequest.assignedBy` requires a string. Use `AuthStore.currentUser()?.name ?? 'agent'` as the value. If the current user has no agent_id (admin-only), this might be an issue — confirm this is allowed or if the endpoint should use the session agent.

5. **Error handling**: `409 Conflict` from concurrent modification (pessimistic lock in `AssignTicketUseCase`). `400 BadRequest` for validation errors. Show inline error badge on the card's dropdown button, auto-dismiss after 5s.

6. **Mobile UX**: The 64px card height with a dropdown may be cramped on mobile. Consider a tap-to-expand approach where the dropdown renders below the card in expanded state.

7. **Notes field**: The `AssignTicketRequest` accepts `notes`. The requirements do not ask for notes in the list view, so this can be omitted from the dropdown.

8. **Unauthenticated/admin users**: Admins without an agent record cannot assign via this UI. The dropdown should be hidden or disabled if `AuthStore.currentUser()?.agent_id` is null.

---

## Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| Virtual scroll + absolutely-positioned dropdowns may clip or misalign | Medium | Test in real virtual scroll scenarios; dropdown uses `position: fixed` with viewport-relative coords if needed |
| N+1 on agent availability: `listAvailableAgents()` counts active tickets per agent via DQL — already batched, no N+1 risk | Low | Already addressed in `AgentAvailabilityService` |
| `assignedBy` validation: if current user has no agent_id, endpoint may reject | Medium | Verify `AssignTicketUseCase` validates `assignedBy` is non-blank; use current user name |
| Dropdown flicker on fast scroll: agents list loads async, card may briefly show wrong state | Low | Show skeleton or "..." while loading; cache per workspace |
| Concurrent reassignment: two supervisors assign the same ticket simultaneously | Low | Backend uses pessimistic lock; frontend shows error toast on 409 |
| Empty agent list: no available agents in workspace | Low | Dropdown shows "Sin agentes disponibles" as disabled option |

---

## Open Questions for Orchestrator

1. **Admin users without agent_id**: Should admins be able to assign from the list? If yes, what `assignedBy` value should they use? The backend requires it.
2. **Notes**: Confirm that notes are NOT required for list-view assignment. If they become required later, a modal or slide-over would be needed.
3. **"Available" definition**: The requirements say "in-shift, active, not at capacity". This matches the 5-step cascade exactly. Confirm no additional filters are needed (e.g., workspace-specific capacity limits).

---

## Next Steps

1. **Backend**: Create `GET /api/workspaces/{id}/available-agents` endpoint — thin controller combining `AgentRepository::findByWorkspace()` + `AgentAvailabilityService::listAvailableAgents()`
2. **Frontend API**: Add `assignTicket()` to `TicketApiService`, `getAvailableAgents()` to `WorkspaceApiService`
3. **Frontend UI**: Add dropdown to `TicketCardComponent`, wire to parent for agent list + assignment call
4. **sdd-propose**: Propose the full scope and approach for formal approval
