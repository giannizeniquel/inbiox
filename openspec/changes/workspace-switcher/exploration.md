# Exploration: Workspace Switcher (Multi-Number)

**Change**: `workspace-switcher`
**Date**: 2026-07-28
**Phase**: sdd-explore
**Status**: Complete

---

## Current State

### 1. phoneNumberId — Already Present in Schema

The `phone_number_id` column **already exists** in the database from migration `Version20260613000002.php` (CAP-7 multi-number model):

| Table | Column | Index |
|---|---|---|
| `tickets` | `phone_number_id VARCHAR(36) NULL` | `idx_tickets_phone_number_id` |
| `conversations` | `phone_number_id VARCHAR(36) NULL` | `idx_conversations_phone_number_id` |
| `webhook_payloads` | `phone_number_id VARCHAR(36) NULL` | `idx_webhook_payloads_phone_number_id` |

The ORM entities already have the field + getter/setter:
- `apps/api/src/Domain/Ticket/Ticket.php` — `phoneNumberId` (line 61-62, 207-216)
- `apps/api/src/Domain/Conversation/Conversation.php` — `phoneNumberId` (line 40-41, 109-118)
- `apps/api/src/Domain/Webhook/WebhookPayload.php` — `phoneNumberId` (line 35-36, 69-78)

`GraphClient` already accepts `?string $phoneNumberId = null` as an override parameter on `sendMessage()`, `uploadMedia()`, `sendTemplate()`, and `sendMediaMessage()` (lines 63, 110, 209, 273).

**The column exists, the field exists, the index exists — the application just doesn't filter by it yet.**

### 2. Agent Entity — No Workspace Relation

`apps/api/src/Domain/Agent/Agent.php` has these fields: `id`, `name`, `user` (OneToOne), `active`, `is_available`, `max_active_tickets`, `timezone`, `is_available_override`, `override_until`, `created_at`, `updated_at`, `shifts` (OneToMany).

**No workspace relation exists.** There is no `active_workspace_id`, no many-to-many with workspaces.

### 3. Queries That Need phoneNumberId Filtering

| Query/Service | File | Currently Filters by phoneNumberId? |
|---|---|---|
| `ListTicketsQuery` | `apps/api/src/Application/Query/ListTicketsQuery.php` | **NO** — raw SQL JOINs tickets→conversations→contacts but never filters by `t.phone_number_id` |
| `ReportingRepository` | `apps/api/src/Repository/ReportingRepository.php` | **NO** — all methods query `tickets` table without `phone_number_id` WHERE clause |
| `ReportingService` | `apps/api/src/Application/Service/ReportingService.php` | **NO** — passes only `$agentId`, no `$phoneNumberId` |
| `ListContactsQuery` | `apps/api/src/Application/Query/ListContactsQuery.php` | **NO** — queries `contacts` table directly, no join to conversation/ticket |

### 4. Admin Controller Pattern

Controllers follow a consistent pattern:
- Route prefix: `#[Route('/api/admin/...')]` + `#[IsGranted('ROLE_SUPERVISOR')]` or `ROLE_ADMIN`
- List: paginated `{data: [...], meta: {page, limit, total, has_next}}`
- Create: JSON body validation, return 201
- Get: single item
- Update: partial JSON body
- Delete: soft delete (set `active = false`)
- Error handling: `referenceId = bin2hex(random_bytes(8))` + 500 with reference
- Serialization: private method returning array

### 5. Auth Context

- `Security` bundle with JWT (`lexik/jwt-authentication-bundle`)
- `User` entity → `Agent` via OneToOne (`getAgent()`)
- `OwnershipScope` — resolves current agent ID from `User::getAgent()->getId()`, returns `null` for ADMIN/SUPERVISOR (they see everything)
- `AuthController.me()` returns: `{id, email, roles, is_active, agent_id}` — **no workspace info**
- No `CurrentUser` attribute/service; use `Security $security` + `$this->getUser()` inside controllers

### 6. Frontend Architecture

- `AuthStore` — signals for `currentUser`, `accessToken`, `isLoading`, `error`. Has `isAdmin()` and `isSupervisor()` computed signals.
- `SidebarComponent` — simple nav links + SSE indicator. Dropdown should go at the top (above nav), using the `<ng-content>` slot at bottom is for user menu.
- `TicketStore` — signal-based, manages ticket list + active ticket + filter tabs. No workspace awareness.
- `ContactsStore` — signal-based, manages contact list. No workspace awareness.
- Admin pages use **use-case classes** (e.g., `ListAgentsUseCase`) not direct API calls.

---

## Affected Areas

### Backend (needs new code)

- `apps/api/src/Domain/Workspace/Workspace.php` — **NEW entity**: id, phoneNumberId, displayName, isActive, timestamps
- `apps/api/src/Migrations/Version*.php` — new `workspaces` table + `agent_workspace` join table + `agents.active_workspace_id` column
- `apps/api/src/Domain/Agent/Agent.php` — add `workspaces` (ManyToMany) and `activeWorkspace` (ManyToOne) relations
- `apps/api/src/Controller/Admin/WorkspaceController.php` — **NEW** admin CRUD following AgentController pattern
- `apps/api/src/Controller/MeController.php` — **NEW** (or add to AuthController): `GET /api/me/workspace`, `PUT /api/me/workspace`
- `apps/api/src/Application/Security/WorkspaceScope.php` — **NEW**: resolve active workspace phoneNumberId for current agent (similar to OwnershipScope)
- `apps/api/src/Application/Query/ListTicketsQuery.php` — **MODIFY**: add `phoneNumberId` parameter, add `AND t.phone_number_id = :phoneNumberId` to WHERE
- `apps/api/src/Repository/ReportingRepository.php` — **MODIFY**: add `phoneNumberId` parameter to all query methods, add `AND t.phone_number_id = :phoneNumberId`
- `apps/api/src/Application/Service/ReportingService.php` — **MODIFY**: add `phoneNumberId` parameter to all methods, pass through to repository
- `apps/api/src/Controller/ReportingController.php` — **MODIFY**: inject `WorkspaceScope`, pass `phoneNumberId` to service
- `apps/api/src/Controller/InboxController.php` — **MODIFY**: inject `WorkspaceScope`, pass `phoneNumberId` to `ListTicketsQuery`
- `apps/api/src/Application/Query/ListContactsQuery.php` — **MODIFY**: add `phoneNumberId` filter (needs JOIN conversations → phone_number_id)
- `apps/api/src/Controller/ContactController.php` — **MODIFY**: pass `phoneNumberId` to `ListContactsQuery`
- `apps/api/config/packages/security.yaml` — add access control for `/api/me/workspace`

### Frontend (needs new code)

- `apps/web/src/app/core/stores/workspace.store.ts` — **NEW**: signals for workspaces list, active workspace, loading
- `apps/web/src/app/core/api/workspace-api.service.ts` — **NEW**: `GET /api/me/workspace`, `PUT /api/me/workspace`, `GET /api/admin/workspaces`, `POST/PUT/DELETE`
- `apps/web/src/app/shell/sidebar/workspace-switcher.component.ts` — **NEW**: dropdown component
- `apps/web/src/app/shell/sidebar/workspace-switcher.component.html` — **NEW**: dropdown template
- `apps/web/src/app/shell/sidebar/workspace-switcher.component.scss` — **NEW**: styles
- `SidebarComponent` — **MODIFY**: import and render `WorkspaceSwitcherComponent`
- `apps/web/src/app/features/admin/workspaces/workspaces-page.component.ts` — **NEW**: admin CRUD page
- `apps/web/src/app/features/admin/workspaces/workspaces-page.component.html` — **NEW**
- `apps/web/src/app/features/admin/workspaces/workspaces-page.component.scss` — **NEW**
- Use cases: `ListWorkspacesUseCase`, `CreateWorkspaceUseCase`, `UpdateWorkspaceUseCase`, `SetActiveWorkspaceUseCase` — **NEW**
- `TicketStore` — **MODIFY**: add `reload()` or accept workspace context; data loading components need to re-fetch on workspace change
- `ContactsStore` — **MODIFY**: same pattern as TicketStore
- `apps/web/src/app/features/reports/pages/reports-page/reports-page.component.ts` — **MODIFY**: reload on workspace switch
- `SidebarComponent` routing: add `/admin/workspaces` route in sidebar nav

---

## Approaches

### Approach A — Full Workspace Entity + PhoneNumberId Filter (Recommended)

**What**: Create a `Workspace` entity that maps 1:1 to a `phoneNumberId`. Agents have a many-to-many assignment + one active workspace preference. All queries get an implicit `phoneNumberId` filter from `WorkspaceScope`.

**Pros**:
- Clean conceptual model: workspace = phone number + display name + metadata
- Single source of truth for which phone numbers exist
- Admin CRUD for workspaces is straightforward
- Filtering is centralized in `WorkspaceScope` — queries don't need individual phoneNumberId params
-符合 sprint spec exactly

**Cons**:
- Requires a new entity + migration
- Need to seed existing phone numbers as workspaces
- `ListContactsQuery` needs a JOIN through conversations to filter by phoneNumberId (more complex SQL)

**Effort**: Medium-High (5 phases worth of work)

### Approach B — Direct phoneNumberId on Agent, No Workspace Entity

**What**: Add `active_phone_number_id` column directly to `agents` table. Add `assigned_agents` column to `phone_numbers` table (or a simple cross-ref). Filter queries by `active_phone_number_id` without a dedicated workspace entity.

**Pros**:
- Simpler, fewer files
- Faster to implement

**Cons**:
- No admin CRUD for "workspaces" — just raw phone number IDs
- No display name, no isActive per workspace
- Harder to extend later (workspace-specific settings, etc.)
- Doesn't match sprint spec's scope

**Effort**: Medium (but delivers less)

---

## Recommendation

**Approach A** — Full Workspace entity, matching the sprint spec exactly. The sprint defines a clear data model (`Workspace` → `phoneNumberId`, `displayName`, `isActive`) and a full admin CRUD scope. Skipping the entity to go direct on columns creates technical debt and a half-baked feature.

### Key Design Decisions to Resolve at Proposal

1. **`WorkspaceScope` vs injecting phoneNumberId into each query**: The cleanest pattern is a dedicated `WorkspaceScope` service (mirroring `OwnershipScope`) that resolves `phoneNumberId` from the current agent's active workspace. Queries then get this as a parameter rather than each controller manually passing it.

2. **`/api/me/workspace` endpoint vs extending `/api/auth/me`**: The sprint specifies dedicated endpoints. Since `AuthController.me()` already returns `agent_id`, adding workspace there would be a breaking change to the API response shape. Better to keep separate.

3. **Soft delete for workspaces**: Following the agent pattern — `isActive` boolean rather than hard delete.

4. **Contacts filtering**: `ListContactsQuery` queries the `contacts` table directly. The phone number ID lives on `conversations` (not `contacts`). So filtering contacts by workspace requires: `JOIN conversations c2 ON c2.contact_id = c.id WHERE c2.phone_number_id = :phoneNumberId`. Need to verify if a contact can belong to multiple conversations with different phone numbers.

5. **Seeding existing data**: Migration should seed a default workspace from the existing `META_PHONE_NUMBER_ID` env var so current tickets/conversations get a workspace.

---

## Risks

1. **Contacts multi-conversation**: If a single contact can have conversations on different phone numbers (same person texting from two numbers), workspace-based filtering of contacts would need careful handling — deduplication when same contact appears across conversations with the same phoneNumberId.

2. **Existing data**: All current tickets/conversations have `phone_number_id = NULL` (nullable). A workspace seed step is needed; otherwise queries filtered by phoneNumberId return empty for existing data.

3. **Realtime**: Mercure subscriptions are per-ticket (not per-workspace). When switching workspaces, the frontend must re-subscribe to the new workspace's tickets. This is already noted as out-of-scope in the sprint.

4. **GraphClient**: The single `META_PHONE_NUMBER_ID` env var currently powers all outbound messages. After workspace switch, outbound messages should go from the active workspace's phone number. The GraphClient already accepts a `phoneNumberId` override — the outbound controllers need to pass the active workspace's phoneNumberId.

---

## Ready for Proposal

**Yes.** The codebase is thoroughly understood. The phoneNumberId infrastructure is already in place (columns, indexes, entity fields) — the missing work is entirely in the application layer:

1. New `Workspace` entity + migration
2. Agent ↔ Workspace assignment + active workspace preference
3. `WorkspaceScope` service resolving current `phoneNumberId`
4. Filter all queries by `phoneNumberId` via that scope
5. Admin CRUD + `/api/me/workspace` endpoints
6. Frontend `WorkspaceStore` + `WorkspaceSwitcherComponent` in sidebar + admin page

The sprint document is well-scoped and aligns with existing patterns (agent CRUD, OwnershipScope). No blockers identified.
