# Tasks: Workspace Switcher (Multi-Number)

**Change**: `workspace-switcher`
**Phases**: 9
**Total tasks**: 24
**Gates**: `php bin/phpunit` (backend) + `npx ng build --configuration=production` (frontend) MUST pass after every phase.

---

## Phase 1 — Workspace Entity & Migration

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 1.1 | Create `Workspace` entity with Doctrine mapping: `id`, `phoneNumberId` (UNIQUE), `displayName`, `isActive`, timestamps. Constructor sets createdAt/updatedAt. Getters/setters follow `Agent` pattern (`setActive` updates `updatedAt`). | `src/Domain/Workspace/Workspace.php` | `php bin/console doctrine:schema:validate` passes |
| 1.2 | Create `WorkspaceRepository` extending `ServiceEntityRepository`. Add `findByPhoneNumberId(string)`, `findActiveById(int)`. | `src/Repository/WorkspaceRepository.php` | `php -r "require 'vendor/autoload.php'; ..."` smoke |
| 1.3 | Create migration `Version20260728000001`: (a) `CREATE TABLE workspaces` with UNIQUE on `phone_number_id`; (b) `CREATE TABLE agent_workspace` join with composite PK and FK indexes; (c) `ALTER TABLE agents ADD active_workspace_id BIGINT NULL` + FK `ON DELETE SET NULL`; (d) **seed** workspace from `META_PHONE_NUMBER_ID` env var; (e) backfill `tickets.phone_number_id` and `conversations.phone_number_id` for NULL rows; (f) auto-assign all existing agents to seeded workspace. `down()` drops tables + column. | `migrations/Version20260728000001.php` | `php bin/console doctrine:migrations:migrate --no-interaction` succeeds; rollback test |
| 1.4 | Update `Agent` entity: add `ManyToMany $workspaces` (mappedBy="agents", targetEntity=Workspace) and `ManyToOne $activeWorkspace` (JoinColumn nullable, onDelete=SET NULL). Add helpers `addWorkspace`, `removeWorkspace`, `setActiveWorkspace`, `getActiveWorkspace`. Add `inverseSide` on `Workspace::$agents`. | `src/Domain/Agent/Agent.php`, `src/Domain/Workspace/Workspace.php` | `doctrine:schema:validate` clean |

**Phase 1 gate**: `php bin/phpunit tests/Unit` + `doctrine:schema:validate` ✅

---

## Phase 2 — WorkspaceScope Service

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 2.1 | Create `WorkspaceScope` service in `src/Application/Security/`. Inject `Security`. Method `getActivePhoneNumberId(): ?string` — returns null for ADMIN/SUPERVISOR (no scope) and unauthenticated; returns `agent.activeWorkspace.phoneNumberId` for AGENT; returns null if agent has no active workspace. | `src/Application/Security/WorkspaceScope.php` | Unit test covers all 4 paths |
| 2.2 | Write `WorkspaceScopeTest`: 4 cases — ADMIN → null, SUPERVISOR → null, AGENT w/ workspace → phoneNumberId, AGENT w/o workspace → null. | `tests/Unit/WorkspaceScopeTest.php` | `php bin/phpunit tests/Unit/WorkspaceScopeTest.php` ✅ |

**Phase 2 gate**: `php bin/phpunit` ✅

---

## Phase 3 — Query Filtering

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 3.1 | Add `?string $phoneNumberId = null` parameter to `ListTicketsQuery::execute()`. When not null, append `AND t.phone_number_id = ?` to WHERE + params. | `src/Application/Query/ListTicketsQuery.php` | Existing tests still green; new test |
| 3.2 | Modify `ListContactsQuery`: add `?string $phoneNumberId = null`. When not null, JOIN `conversations conv ON conv.contact_id = c.id` and add `AND conv.phone_number_id = ?` (use EXISTS subquery to avoid duplicates — `WHERE EXISTS (SELECT 1 FROM conversations WHERE contact_id = c.id AND phone_number_id = ?)`). | `src/Application/Query/ListContactsQuery.php` | Test seeds 2 contacts in different workspaces |
| 3.3 | Add `?string $phoneNumberId = null` to `ReportingRepository::getFrtMetrics`, `getRtMetrics`, `getCountsByStatus`, `getVolumeOverTime`. Inject into WHERE clauses. Skip `getAgentPerformance` (agent-level metric, not workspace-scoped). | `src/Repository/ReportingRepository.php` | Unit/integration tests assert filter |
| 3.4 | Update `ReportingService` to accept + pass-through `$phoneNumberId`. | `src/Application/Service/ReportingService.php` | Existing tests still pass |
| 3.5 | Wire `WorkspaceScope` into `InboxController`, `ContactController`, `ReportingController`. Each resolves `$phoneNumberId = $this->workspaceScope->getActivePhoneNumberId()` and passes to the query/service. | `src/Controller/InboxController.php`, `src/Controller/ContactController.php`, `src/Controller/ReportingController.php` | Smoke: log in as AGENT, hit `/api/tickets`, verify only active workspace's tickets returned |
| 3.6 | Integration tests: seed 2 workspaces, create tickets in each, log in as AGENT assigned to one, assert API returns only that workspace's tickets. Same for contacts and `/api/reports/overview`. | `tests/Integration/WorkspaceFilteringTest.php` | Tests ✅ |

**Phase 3 gate**: `php bin/phpunit` ✅

---

## Phase 4 — API Endpoints

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 4.1 | Create `MeController` with two methods: `getActiveWorkspace()` (returns `ActiveWorkspaceDTO` or 404 if none), `setActiveWorkspace(Request)` (validates `workspaceId` in body, looks up agent, sets `activeWorkspace`, returns DTO). Both require `IS_AUTHENTICATED_FULLY`. | `src/Controller/MeController.php` | `curl -X PUT /api/me/workspace -d '{"workspaceId":1}'` |
| 4.2 | Add `/api/me/workspace` route prefix to `MeController` + `security.yaml` access control (or rely on controller-level `#[IsGranted('IS_AUTHENTICATED_FULLY')]`). | `config/packages/security.yaml` | Unauthenticated request → 401 |
| 4.3 | Create `Admin\WorkspaceController` with full CRUD following `AgentController` pattern: list (paginated), get, create, update, soft-delete (sets `isActive = false`). `#[IsGranted('ROLE_ADMIN')]` at class level. | `src/Controller/Admin/WorkspaceController.php` | WebTestCase covers 5 endpoints + 403 for non-ADMIN |
| 4.4 | Add `assignAgents` action: `PUT /api/admin/workspaces/{id}/agents` with body `{ agentIds: int[] }`. Loads workspace, clears `$workspace->agents`, adds each agent, flushes. Returns `{ workspaceId, agentIds }`. | `src/Controller/Admin/WorkspaceController.php` | Test: assign 3 agents, unassign all (empty array), verify join table state |
| 4.5 | Add `WorkspaceDTO` and `ActiveWorkspaceDTO` (shown in design). | `src/Application/DTO/WorkspaceDTO.php`, `src/Application/DTO/ActiveWorkspaceDTO.php` | `doctrine:schema:validate` clean |

**Phase 4 gate**: `php bin/phpunit` ✅

---

## Phase 5 — Backend Tests

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 5.1 | Unit test for `Workspace` entity: constructor defaults, `setActive` updates `updatedAt`, `setActiveWorkspace` accepts null. | `tests/Unit/Entity/WorkspaceTest.php` | ✅ |
| 5.2 | Integration test for `Admin\WorkspaceController`: list (paginated), create (201), get, update, soft-delete (asserts `isActive = false` in DB), `assignAgents` round-trip. Plus 403 for ROLE_AGENT. | `tests/Controller/Admin/WorkspaceControllerTest.php` | ✅ |
| 5.3 | Integration test for `MeController`: GET returns active workspace, PUT updates preference, 404 when no active workspace, 401 unauthenticated. | `tests/Controller/MeControllerTest.php` | ✅ |
| 5.4 | Migration test: run migration up, assert tables exist + seeded workspace + backfilled phone_number_ids; run down, assert clean. | `tests/Integration/MigrationTest.php` | ✅ |

**Phase 5 gate**: `php bin/phpunit` (full suite) ✅

---

## Phase 6 — Frontend Store & API

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 6.1 | Create `WorkspaceDTO`, `ActiveWorkspaceDTO` interfaces in `core/models/workspace.model.ts`. | `apps/web/src/app/core/models/workspace.model.ts` | Compiles |
| 6.2 | Create `WorkspaceApiService` (providedIn root) with: `getActive(): Observable<ActiveWorkspaceDTO>`, `setActive(id: number): Observable<ActiveWorkspaceDTO>`, `listMine(): Observable<WorkspaceDTO[]>` (for sidebar dropdown), `listAll()`, `create()`, `update()`, `deactivate()`, `assignAgents()`. Use `environment.apiUrl`. Mirror `ContactsApiService` style. | `core/api/workspace-api.service.ts` | `npx ng build` |
| 6.3 | Create `WorkspaceStore` (signals): `workspaces`, `activeWorkspace`, `loading`, `error`. Methods: `loadMyWorkspaces()`, `loadActive()`, `setActiveWorkspace(id)` (calls API, updates signal, fires reload callbacks), `registerReload(fn)` (callback registry for TicketStore/ContactsStore/Reports). | `core/stores/workspace.store.ts` | Unit test: `setActiveWorkspace` calls API + registered reload fns |
| 6.4 | Add `reload()` public method to `TicketStore`. Inject loader or expose a `loadFromApi()` that `InboxPageComponent` triggers; same pattern in `ContactsStore`. Wire both stores into `WorkspaceStore.registerReload` in `AppShellComponent` constructor. | `core/stores/ticket.store.ts`, `features/contacts/stores/contacts.store.ts`, `app-shell.component.ts` | Manual: switch workspace → tickets reload |

**Phase 6 gate**: `npx ng build --configuration=production` ✅

---

## Phase 7 — WorkspaceSwitcherComponent

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 7.1 | Create standalone `WorkspaceSwitcherComponent` (`changeDetection: OnPush`) with signals/computed for: `workspaces` (from store), `active` (computed), `isOpen` (dropdown state). Template: button showing `displayName` + `phoneNumberId` truncated; click toggles dropdown listing all assigned workspaces; click outside closes (use `HostListener` or simple `@if` + click handler). Highlight active row. | `shell/sidebar/workspace-switcher.component.{ts,html,scss}` | Renders in browser |
| 7.2 | On workspace select: call `workspaceStore.setActiveWorkspace(id)`. Use `effect()` or explicit subscription to update local `isOpen`. | same | Manual: click → API call → list reloads |
| 7.3 | Mount component in `SidebarComponent` template at the top (above nav, below header). Pass `isCollapsed` input — show only icon when collapsed. | `shell/sidebar/sidebar.component.{ts,html,scss}` | Visible in browser |
| 7.4 | `AppShellComponent`: on init, call `workspaceStore.loadMyWorkspaces()` + `loadActive()`. If user has no active workspace but has assignments, auto-set the first. (Per Open Question in design — implement auto-pick for friendliness.) | `app-shell.component.ts` | Login → workspace shows in dropdown |

**Phase 7 gate**: `npx ng build --configuration=production` ✅

---

## Phase 8 — Admin Workspaces Page

| # | Task | Files | Verifiable when |
|---|------|-------|-----------------|
| 8.1 | Create use cases: `ListWorkspacesUseCase`, `CreateWorkspaceUseCase`, `UpdateWorkspaceUseCase`, `DeactivateWorkspaceUseCase`, `AssignWorkspaceAgentsUseCase`, `ListWorkspaceAgentsUseCase`. Mirror `agents/` use case patterns (factory fns + `extractApiError`). | `core/application/admin/workspaces/*.use-case.ts` | Unit tests pass |
| 8.2 | Create `WorkspacesPageComponent` (standalone OnPush, ReactiveForms, `_admin-common.scss`). Table: `id`, `phoneNumberId`, `displayName`, `isActive`, agent count. Create/Edit modal: `phoneNumberId` (validated pattern, unique), `displayName`, `isActive`. Deactivate button + confirm. Reuse `VirtualListCoordinatorDirective` if list > 50 rows. | `features/admin/workspaces/workspaces-page.component.{ts,html,scss}` | Page renders, CRUD works |
| 8.3 | Add "Manage agents" button per row → modal with checkboxes listing all agents (from `ListAgentsUseCase`); save calls `AssignWorkspaceAgentsUseCase`. Pre-select currently-assigned. | same | Assign 3 agents → reload → still 3 |
| 8.4 | Add route `/admin/workspaces` (lazy) and sidebar link under `/admin/agents` (visible to `authStore.isAdmin()`). | `app.routes.ts`, `shell/sidebar/sidebar.component.html` | Route navigable |
| 8.5 | Component unit test: form validation (phoneNumberId pattern), submit dispatches use case, error display. | `workspaces-page.component.spec.ts` | ✅ |

**Phase 8 gate**: `npx ng build --configuration=production` ✅

---

## Phase 9 — Verification & Smoke

| # | Task | Verifiable when |
|---|------|-----------------|
| 9.1 | Update `apps/api/config/packages/security.yaml` to ensure `/api/admin/workspaces` requires ROLE_ADMIN, `/api/me/workspace` requires authenticated. | `curl` matrix: 401/403/200 |
| 9.2 | Outbound message path: verify `GraphClient` calls in `OutboxController`/`MessageController` accept `$phoneNumberId` from `WorkspaceScope` (not the env var). Add minimal wiring if missing. | Send a message from switched workspace → logs show correct phoneNumberId |
| 9.3 | Full backend test suite + frontend build both clean. | `php bin/phpunit` + `npx ng build` ✅ |
| 9.4 | Manual smoke: log in as AGENT → see 2 workspaces in dropdown → switch → inbox/contacts/reports all reload. Log in as ADMIN → go to `/admin/workspaces` → create new workspace → assign agent → agent sees it in dropdown. | Manual run-through ✅ |
| 9.5 | Update docs: append "Workspace switching" section to `docs/05_architecture/` (or wherever architecture overview lives); add admin guide to `docs/06_operations/` if exists. | Files committed |

**Phase 9 gate**: All earlier gates + manual smoke ✅

---

## Cross-cutting Concerns

- **Transactional safety**: `assignAgents` must run in a transaction (`$em->wrapInTransaction(fn() => ...)`) so partial assignments don't occur on FK violation.
- **Soft-delete consistency**: deactivating a workspace MUST clear `agents.active_workspace_id` for any agent pointing at it (use `ON DELETE SET NULL` already set in migration; verify in code too).
- **Outbox messages**: queued messages with a `phoneNumberId` from a now-deactivated workspace should fall back to the agent's new active workspace (or skip). Document as known limitation for MVP.
- **Realtime**: Mercure subscriptions are per-ticket — switching workspace does NOT auto-unsubscribe stale tickets. Out of scope per sprint; document for follow-up.

## Verification Commands (per phase)

```bash
# Backend (run from apps/api)
php bin/console doctrine:schema:validate
php bin/phpunit

# Frontend (run from apps/web)
npx ng build --configuration=production
npx ng test --watch=false
```

Both commands MUST exit 0 before the phase is considered complete.
