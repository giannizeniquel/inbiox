# Proposal: Workspace Pending Message Indicator

## Intent

Users need visual feedback when messages arrive in workspaces they are not currently viewing. Without an indicator, agents must manually check every workspace to see if work is waiting. The envelope icon in the workspace switcher dropdown fills this gap.

## Scope

### In Scope
- Add `has_pending: bool` to `WorkspaceDTO` (backend) and `listMyWorkspaces` API response
- Backend EXISTS query per workspace in `GET /api/me/workspaces`
- Extend `MessageAdded` Mercure payload with `phoneNumberId`
- Add `pendingWorkspaceIds` signal to `WorkspaceStore` (frontend)
- Replace `hasPending()` stub in `WorkspaceSwitcherComponent` with signal lookup
- Incremental signal updates on `MessageAdded` and `TicketRead` events
- Database index `(phone_number_id, assigned_agent_id, status)`

### Out of Scope
- Per-ticket unread counts per workspace (boolean only — envelope icon, no badge number)
- Workspace-scoped Mercure topics
- Browser notification integration
- Dedicated pending endpoint (extend existing — approach A)

## Capabilities

### New Capabilities
- `workspace-pending-indicator`: Real-time pending message indicator per workspace. Backend exposes `has_pending` on workspace load; frontend increments/decrements pending state reactively via existing Mercure events (`message.added`, `ticket.read`).

### Modified Capabilities
- None. No existing spec covers `GET /api/me/workspaces` or the workspace switcher UI.

## Approach

**Approach A**: Extend `GET /api/me/workspaces` with `has_pending: bool`, reuse existing `message.added`/`ticket.read` Mercure events for incremental frontend updates.

1. **Backend**: `WorkspaceDTO` + `has_pending`. `MeController::listMyWorkspaces()` runs a lightweight SQL EXISTS per workspace (`SELECT EXISTS(... WHERE t.phone_number_id = :id AND t.assigned_agent_id = :agent AND status NOT IN ('closed','resolved') AND m.direction='inbound' AND m.created_at > COALESCE(last_read_at, '1970-01-01'))`). Add `phoneNumberId` to `MessageAddedPayload`.
2. **Frontend**: `WorkspaceStore.pendingWorkspaceIds: signal<Set<number>>` populated from API on load. `WorkspaceSwitcherComponent.hasPending()` reads the signal. On `MessageAdded`: add workspace to set if non-active. On `TicketRead`: remove if no other pending tickets remain.
3. **Real-time**: No new Mercure topics. Existing event types carry the data needed.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Controller/MeController.php` | Modified | EXISTS subquery per workspace |
| `apps/api/src/DTO/WorkspaceDTO.php` | Modified | Add `has_pending: bool` field |
| `apps/api/src/Event/MessageAddedPayload.php` | Modified | Add `phoneNumberId: string` field |
| `apps/api/src/Repository/TicketRepository.php` | Modified | New `hasPendingInWorkspace()` EXISTS query |
| `apps/web/src/app/stores/workspace.store.ts` | Modified | Add `pendingWorkspaceIds` signal |
| `apps/web/src/app/shared/components/workspace-switcher/` | Modified | Replace `hasPending()` stub |
| Migration (new) | New | Composite index on tickets table |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| EXISTS query slow for workspaces with many tickets | Medium | Composite DB index; typical agent has 1–5 workspaces |
| `MessageAdded` payload contract extension | Low | Additive field — backward compatible |
| Pending state drifts after SSE reconnect | Low | `listMyWorkspaces` re-fetches on reconnect, corrects drift |
| Agent without active workspace | Low | Icon shows for all workspaces equally — no special case |

## Rollback Plan

Revert `WorkspaceDTO.has_pending` field and controller query. Frontend stub returns `false`. Migration index can be dropped or kept (harmless).

## Dependencies

None — `listMyWorkspaces`, `MessageAdded`, and `TicketRead` are existing endpoints and events.

## Success Criteria

- [ ] Workspace switcher shows envelope icon for non-active workspaces with pending messages on page load
- [ ] Icon appears in real-time when a `message.added` event fires for a non-active workspace
- [ ] Icon disappears when the pending ticket is marked read or closed
- [ ] Active workspace never shows its own pending icon
- [ ] Page refresh preserves correct pending state (no false positives or negatives)
