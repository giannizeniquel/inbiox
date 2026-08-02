# Proposal: Ticket List Assign (Asignación desde lista)

## Intent

Eliminate the 3-click detour to ticket detail for assignment. Agents and supervisors need to assign/reassign directly from the inbox list card.

## Scope

### In Scope
- `GET /api/workspaces/{id}/available-agents` — workspace agents with name + availability + capacity count
- Inline dropdown on `TicketCardComponent`: agent name + availability dot (green/grey)
- Permission gate: visible only to ticket owner, admin, or supervisor
- State gate: hidden on resolved/closed tickets; visible on new/active/waiting
- `TicketApiService.assignTicket()` + `WorkspaceApiService.getAvailableAgents()` methods
- Optimistic update in `TicketStore` on assignment; error rollback with inline badge (5s auto-dismiss)
- Agent self-reassignment: agent may reassign tickets they currently own

### Out of Scope
- Notes field in list-view assignment
- Bulk selection + toolbar assignment
- Mobile expanded-card UX (desktop first)
- Audit log UI changes

## Capabilities

### New Capabilities
- `workspace-agent-listing`: endpoint returning workspace agents with availability status and current ticket load

### Modified Capabilities
- `authorization`: agents SHALL be able to reassign tickets they own (modifies "Reassign tickets" row; enforced via voter at use-case level, not controller attribute)

## Approach

**Backend**: Thin controller combining `findByWorkspace()` + `listAvailableAgents()`. Returns DTO per agent. Existing `POST /tickets/{id}/assign` reused unchanged. New `TicketOwnerVoter` grants agent access when `agent_id === ticket.owner.id`.

**Frontend**: `TicketListComponent` loads agents once per workspace (cached signal, workspace-switch invalidation). `TicketCardComponent` receives `agents` input + `assign` output. Dropdown positioned absolute within card; trigger `stopPropagation()` prevents card selection. `AuthStore` + `ticket.owner` determine dropdown visibility.

## Affected Areas

| Area | Impact |
|------|--------|
| `apps/api/src/Controller/` — new `WorkspaceAgentController` | New |
| `apps/api/src/Repository/AgentRepository.php` | Modified |
| `apps/api/src/Security/Voter/` — `TicketOwnerVoter` | New |
| `apps/web/.../ticket-api.service.ts` — `assignTicket()` | Modified |
| `apps/web/.../workspace-api.service.ts` — `getAvailableAgents()` | Modified |
| `apps/web/.../ticket-card/` — dropdown + assign output | Modified |
| `apps/web/.../ticket-list/` — agent loading + caching | Modified |

## Risks

| Risk | Mitigation |
|------|------------|
| Virtual scroll absolute dropdown clipping | Test real viewport; fallback to `position: fixed` + viewport coords |
| Empty agent list | "Sin agentes disponibles" disabled state |
| 409 Conflict on concurrent reassignment | Inline error badge, auto-dismiss 5s |
| Admin without agent_id | Dropdown hidden; assign via detail view only |

## Rollback

Revert frontend commit (additive, no existing behavior changed). Backend endpoint is read-only GET — zero rollback risk. Feature-flag behind env variable if caution required.

## Success Criteria

- [ ] Assign own ticket from inbox in ≤2 clicks
- [ ] Supervisor reassigns any new/active/waiting ticket
- [ ] Dropdown hidden for resolved/closed tickets
- [ ] Optimistic update reflects instantly; rolls back on error
- [ ] 0 new N+1 queries (agents loaded once per workspace)
