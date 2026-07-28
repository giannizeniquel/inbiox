# Proposal: Workspace Switcher (Multi-Number)

## Intent

Multi-number WhatsApp operations in one Inbiox instance. Agents switch between workspaces; data (inbox, contacts, reports) segregates by active workspace.

## Scope

### In Scope
- `Workspace` entity (phoneNumberId UNIQUE, displayName, isActive) + migration + seed from DB
- Agent N:M workspace assignment + active workspace preference (`/api/me/workspace`)
- `WorkspaceScope` service: resolve active phoneNumberId for query filtering
- Filter injection: ListTicketsQuery, ListContactsQuery, ReportingRepository
- Admin CRUD: `/api/admin/workspaces` (ROLE_ADMIN) + agent assignment UI
- Frontend: WorkspaceStore + WorkspaceSwitcherComponent (sidebar dropdown) + admin CRUD page

### Out of Scope
- Mercure re-subscription on switch, contact deduplication across workspaces, workspace-specific settings, NULL phoneNumberId migration (manual seed)

## Capabilities

### New Capabilities
- `workspace-management`: Workspace entity, admin CRUD, N:M agent assignment, RBAC entries for workspace operations
- `workspace-switching`: Agent active workspace endpoint, WorkspaceScope filter service, frontend store + switcher UI

### Modified Capabilities
- `authorization`: Add workspace management rows to RBAC matrix (ROLE_ADMIN only for CRUD)

## Approach

Full Workspace entity (Approach A from exploration). Active workspace stored in `agents.active_workspace_id`. Selective store reload on switch (`reload()` — not full page reload). Switcher in sidebar above nav links. Query filtering via `WorkspaceScope` (mirrors `OwnershipScope` pattern). Outbound messages pass active phoneNumberId to `GraphClient` override.

## Affected Areas

| Area | Impact |
|------|--------|
| `apps/api/src/Domain/Workspace/` | New entity + repository |
| `apps/api/src/Domain/Agent/Agent.php` | Add workspaces (M:N) + activeWorkspace relations |
| `apps/api/src/Migrations/` | New workspaces + agent_workspace tables, agents.active_workspace_id FK |
| `apps/api/src/Controller/Admin/WorkspaceController.php` | New admin CRUD |
| `apps/api/src/Controller/MeController.php` | New GET/PUT /api/me/workspace |
| `apps/api/src/Application/Security/WorkspaceScope.php` | New scope resolver |
| `apps/api/src/Application/Query/ListTicketsQuery.php` | Add phoneNumberId WHERE clause |
| `apps/api/src/Repository/ReportingRepository.php` | Inject phoneNumberId to all queries |
| `apps/api/src/Application/Query/ListContactsQuery.php` | JOIN conversations for phoneNumberId filter |
| `apps/web/src/app/core/stores/workspace.store.ts` | New signals + reload trigger |
| `apps/web/src/app/core/api/workspace-api.service.ts` | New API service |
| `apps/web/src/app/shell/sidebar/workspace-switcher.component.ts` | New dropdown UI |
| `apps/web/src/app/features/admin/workspaces/` | New admin CRUD page + use cases |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| NULL phoneNumberId → empty data after filtering | High | Seed migration sets phoneNumberId from META_PHONE_NUMBER_ID env var |
| Contact in multiple workspaces → duplicates in list | Medium | JOIN conversations; accept duplicates for MVP |
| Missing indexes on join tables | Low | Create in migration |
| Wrong outbound phone number after switch | Low | Pass active workspace phoneNumberId to GraphClient |

## Rollback Plan

Revert migration (drop tables + column), remove WorkspaceScope injection from controllers (null phoneNumberId = no filter), hide workspace switcher via feature flag.

## Dependencies

- CAP-7 phoneNumberId columns + indexes (already deployed)
- Existing admin patterns: AgentController, use-case classes, FormBuilder

## Success Criteria

- [ ] Agent switches workspaces; inbox/contacts/reports show segregated data
- [ ] Admin creates, edits, deactivates workspaces and assigns agents
- [ ] Outbound WhatsApp messages use the active workspace's phone number
- [ ] Seed workspace shows existing tickets unchanged post-migration
- [ ] All backend tests pass, frontend builds clean
