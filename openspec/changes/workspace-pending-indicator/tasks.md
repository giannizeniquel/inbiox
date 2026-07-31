# Tasks: Workspace Pending Message Indicator

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~220 (3 BE + 4 FE files + migration + tests) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

## Phase 1: Backend Foundation (DTO + Migration)

- [ ] 1.1 `apps/api/src/Application/DTO/WorkspaceDTO.php` — add `public readonly bool $hasPending`; update ctor signature, `fromEntity(Workspace $w, int $agentCount = 0, bool $hasPending = false)`, and `toArray()` to emit `has_pending`.
- [ ] 1.2 `apps/api/migrations/Version20260804000001.php` — create migration adding composite index `idx_tickets_pending ON tickets (phone_number_id, assigned_agent_id, status)`.

## Phase 2: Backend Logic

- [ ] 2.1 `apps/api/src/Controller/MeController.php` — in `listMyWorkspaces()`, after fetching workspaces, run a `SELECT EXISTS(...)` per workspace (join tickets → conversations → messages; filter `assigned_agent_id = :agent`, `status NOT IN ('closed','resolved')`, `direction = 'inbound'`, `m.created_at > COALESCE(t.last_read_at, '1970-01-01')`). Pass bool to `WorkspaceDTO::fromEntity()`.
- [ ] 2.2 `apps/api/src/Application/Realtime/RealtimeEventFactory.php` — add `phoneNumberId` field to the `MessageAdded` wire payload (sourced from the event's `phoneNumberId` property).

## Phase 3: Frontend Foundation (DTO + Store)

- [ ] 3.1 `apps/web/src/app/core/dto/workspace.dto.ts` — add `has_pending?: boolean` to `WorkspaceDTO` interface.
- [ ] 3.2 `apps/web/src/app/core/stores/workspace.store.ts` — add `pendingWorkspaceIds = signal<Set<number>>(new Set())`; add `seedPending(workspaces)` (populate from `has_pending`), `markWorkspacePending(phoneNumberId)` (resolve workspace id via `workspaces()` then add), `recheckWorkspacePending(ticketId)` (remove workspace id if no remaining ticket in that workspace has `unread_count > 0`). Call `seedPending` after `listMyWorkspaces` resolves.

## Phase 4: Frontend Integration (Events + UI)

- [ ] 4.1 `apps/web/src/app/core/stores/ticket.store.ts` — inject `WorkspaceStore`. On `message.added` (inbound, non-active workspace): call `workspaceStore.markWorkspacePending(payload.phoneNumberId)`. On `ticket.read`: call `workspaceStore.recheckWorkspacePending(ticketId)`.
- [ ] 4.2 `apps/web/src/app/shell/sidebar/workspace-switcher/workspace-switcher.component.ts` — replace `hasPending(_workspaceId)` stub with `pendingWorkspaceIds().has(workspaceId) && workspaceId !== activeWorkspace()?.workspace_id`.

## Phase 5: Testing

- [ ] 5.1 `apps/api/tests/Controller/MeControllerListWorkspacesTest.php` — kernel test: 4 fixtures (no tickets, only-read, inbound after `last_read_at`, closed ticket) → assert `has_pending` booleans match per spec SC-4 / SC-5.
- [ ] 5.2 `apps/web/src/app/core/stores/workspace.store.spec.ts` — Jasmine: `seedPending` populates set; `markWorkspacePending` adds on inbound; `recheckWorkspacePending` removes when no unread remains; no-op for active workspace.
- [ ] 5.3 `apps/web/src/app/shell/sidebar/workspace-switcher/workspace-switcher.component.spec.ts` — component test: envelope icon renders only when `pendingWorkspaceIds.has(id) && id !== active`.
