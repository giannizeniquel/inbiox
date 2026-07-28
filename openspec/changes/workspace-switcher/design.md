# Design: Workspace Switcher (Multi-Number)

## Technical Approach

A new `Workspace` aggregate (1:1 with `phoneNumberId`) sits on top of the
`phone_number_id` columns shipped by CAP-7. Agents get M:N assignment and a
single `active_workspace_id` preference. A `WorkspaceScope` service (mirrors
`OwnershipScope`) resolves the active `phoneNumberId`; controllers inject it
into list queries. Frontend carries a `WorkspaceStore` (signals) + sidebar
`WorkspaceSwitcherComponent`; switching fires `reload()` on the dependent
stores — no full page refresh, no navigation.

## Architecture Decisions

| # | Decision | Choice | Why |
|---|---|---|---|
| 1 | Workspace abstraction | Full entity (not raw `phoneNumberId` on agent) | Admin CRUD, future settings, matches sprint spec |
| 2 | Active workspace persistence | `agents.active_workspace_id` FK column | One read, no join; preference not history |
| 3 | Filter injection point | Dedicated `WorkspaceScope` service | Single source of truth; mirrors `OwnershipScope` |
| 4 | Frontend reload strategy | Store `reload()` callbacks, not full page | Preserves scroll/selection; SPA-native |
| 5 | Contact filtering | `EXISTS` subquery on `conversations.phone_number_id` | No JOIN duplicates; consistent with inbox pattern |
| 6 | Scope for SUPERVISOR | null (see all) | Matches `OwnershipScope`; explore doc §"Approach A" |
| 7 | No active workspace on AGENT | 404 from `/api/me/workspace`; voter 403 on list endpoints | Explicit over silent auto-pick |

## Data Flow

```
WorkspaceSwitcherComponent
  └─ WorkspaceStore.setActiveWorkspace(id)
       ├─ PUT /api/me/workspace      (persist preference)
       ├─ activeWorkspace.set(...)   (signal)
       └─ reload callbacks fire      (TicketStore, ContactsStore, Reports)
```

```
InboxController::listTickets
  ├─ OwnershipScope::getAgentId()              → int|null
  └─ WorkspaceScope::getActivePhoneNumberId()  → "1234567890" | null
  → ListTicketsQuery::execute(phoneNumberId)
       → WHERE t.phone_number_id = :phoneNumberId
```

## File Changes

### Backend

| File | Action | Purpose |
|---|---|---|
| `src/Domain/Workspace/Workspace.php` | NEW | Entity: id, phoneNumberId UNIQUE, displayName, isActive, timestamps, $agents M:N |
| `src/Repository/WorkspaceRepository.php` | NEW | `findByPhoneNumberId`, `findActiveById` |
| `migrations/Version20260728000001.php` | NEW | Tables + FK + seed `META_PHONE_NUMBER_ID` + backfill NULL + auto-assign agents |
| `src/Application/Security/WorkspaceScope.php` | NEW | `getActivePhoneNumberId(): ?string` (mirrors OwnershipScope) |
| `src/Application/DTO/{Workspace,ActiveWorkspace}DTO.php` | NEW | Read models |
| `src/Controller/MeController.php` | NEW | `GET/PUT /api/me/workspace` |
| `src/Controller/Admin/WorkspaceController.php` | NEW | CRUD + `PUT /{id}/agents` |
| `src/Domain/Agent/Agent.php` | MOD | Add `$workspaces` (M:N mappedBy) + `$activeWorkspace` (M:1) + helpers |
| `src/Application/Query/{ListTickets,ListContacts}Query.php` | MOD | Add `?string $phoneNumberId`; contacts uses EXISTS subquery on conversations |
| `src/Application/Service/ReportingService.php` | MOD | Pass-through `$phoneNumberId` to all methods |
| `src/Repository/ReportingRepository.php` | MOD | Inject filter in FRT/RT/counts/volume; skip `getAgentPerformance` |
| `src/Controller/{Inbox,Contact,Reporting}Controller.php` | MOD | Inject `WorkspaceScope`, pass to query/service |
| `config/packages/security.yaml` | MOD | `/api/admin/workspaces*` → ROLE_ADMIN; `/api/me/workspace` → authenticated |

### Frontend

| File | Action | Purpose |
|---|---|---|
| `core/models/workspace.model.ts` | NEW | `WorkspaceDTO`, `ActiveWorkspaceDTO` |
| `core/api/workspace-api.service.ts` | NEW | HTTP client (mirrors `ContactsApiService`) |
| `core/stores/workspace.store.ts` | NEW | Signals + `setActiveWorkspace` + reload callback registry |
| `core/application/admin/workspaces/*.use-case.ts` | NEW | list/create/update/deactivate/assign-agents use cases |
| `core/application/me/set-active-workspace.use-case.ts` | NEW | Persist + fire store reloads |
| `shell/sidebar/workspace-switcher.component.{ts,html,scss}` | NEW | Standalone OnPush dropdown |
| `features/admin/workspaces/workspaces-page.component.{ts,html,scss}` | NEW | Admin CRUD + agent assignment |
| `shell/sidebar/sidebar.component.{ts,html}` | MOD | Mount switcher at top, add admin nav link |
| `core/stores/ticket.store.ts` | MOD | Add `reload()` method |
| `features/contacts/stores/contacts.store.ts` | MOD | Add `reload()` method |
| `features/reports/pages/reports-page/*.ts` | MOD | Subscribe to `activeWorkspace`, reload on change |
| `app-shell.component.ts` | MOD | Init `loadMyWorkspaces()` + `loadActive()`; auto-pick first if none |
| `app.routes.ts` | MOD | Add `/admin/workspaces` lazy route |

## Interfaces / Contracts

### Endpoints

| Method | Path | Auth | Body | Returns |
|---|---|---|---|---|
| GET | `/api/me/workspace` | `IS_AUTHENTICATED_FULLY` | — | `ActiveWorkspaceDTO` or 404 |
| PUT | `/api/me/workspace` | `IS_AUTHENTICATED_FULLY` | `{ workspaceId: int }` | `ActiveWorkspaceDTO` |
| GET | `/api/admin/workspaces` | `ROLE_ADMIN` | paginated query | `{ data, meta }` |
| POST | `/api/admin/workspaces` | `ROLE_ADMIN` | `{ phoneNumberId, displayName, isActive? }` | `WorkspaceDTO` (201) |
| PUT | `/api/admin/workspaces/{id}` | `ROLE_ADMIN` | partial | `WorkspaceDTO` |
| DELETE | `/api/admin/workspaces/{id}` | `ROLE_ADMIN` | — | 204 (soft: `isActive = false`) |
| PUT | `/api/admin/workspaces/{id}/agents` | `ROLE_ADMIN` | `{ agentIds: int[] }` | `{ workspaceId, agentIds }` |

### `WorkspaceScope` contract

```php
public function getActivePhoneNumberId(): ?string;
// ADMIN/SUPERVISOR/unauthenticated: null (no filter)
// AGENT w/ active workspace:        "1234567890"
// AGENT w/o active workspace:       null (voter blocks → 403)
```

### Frontend model

```ts
export interface WorkspaceDTO {
  id: number; phoneNumberId: string; displayName: string;
  isActive: boolean; createdAt: string; updatedAt: string;
}
export interface ActiveWorkspaceDTO {
  workspaceId: number; phoneNumberId: string; displayName: string;
}
```

## Testing Strategy

| Layer | What | Approach |
|---|---|---|
| Unit | `WorkspaceScope` 4-role paths; `Workspace` invariants | Mock Security; direct entity tests |
| Integration | Admin `WorkspaceController` CRUD + assign + RBAC 403; `MeController` GET/PUT/404/401 | WebTestCase |
| Integration | `ListTicketsQuery`, `ListContactsQuery`, `ReportingRepository` filter by phoneNumberId | Seed 2 workspaces, assert scope |
| Migration | Up + down + seed + backfill idempotency | Round-trip |
| Frontend | `WorkspaceStore.setActiveWorkspace` fires reloads; switcher renders active | Mock API; render test |
| E2E | Login → switch → inbox/contacts/reports reload | Manual smoke |

## Migration / Rollout

Single migration `Version20260728000001`:
1. `CREATE TABLE workspaces` (UNIQUE on `phone_number_id`)
2. `CREATE TABLE agent_workspace` (composite PK, FK indexes)
3. `ALTER TABLE agents ADD active_workspace_id BIGINT NULL` + FK `ON DELETE SET NULL`
4. **Seed** workspace from `META_PHONE_NUMBER_ID` env; backfill NULL `tickets`/`conversations.phone_number_id`; auto-assign all existing agents (idempotent)
5. `down()`: drop tables, drop column (FKs cascade)

**Rollback**: revert migration; code treats `phoneNumberId = null` as no-op filter (graceful degradation).

## Open Questions

- [ ] Auto-pick first workspace when AGENT has assignments but no `active_workspace_id`? **Defaulted yes** in 7.4 — confirm in review.
- [ ] Mercure re-subscription on switch — out of scope; stale tickets from prior workspace may receive events. Document as follow-up.
- [ ] Contact cross-workspace duplicates — accepted for MVP; same contact can appear twice if reached on two numbers. De-dup is a separate change.
