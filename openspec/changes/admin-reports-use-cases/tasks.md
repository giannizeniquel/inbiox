# Tasks: Use Case Layer for Admin + Reports

**Total**: 17 tasks across 5 phases. Each task is independently verifiable. `ng build apps/web` must pass after every task. Phases build on each other.

**Verification gate**: After each phase, run `ng build apps/web` and a manual smoke test of any refactored page.

---

## Phase 0 — Foundation (2 tasks)

### Task 0.1 — Create directory skeleton + barrel stubs
- [x] Done
**Files**:
- Create `apps/web/src/app/core/application/admin/{agents,users,linking}/`
- Create `apps/web/src/app/core/application/reports/`
- Create `core/application/admin/index.ts` (empty re-export)
- Create `core/application/reports/index.ts` (empty re-export)
- Create `core/application/admin/{agents,users,linking}/index.ts` (empty)
- Modify `core/application/index.ts` to add `export * from './admin';` and `export * from './reports';`

**Why first**: No other task can begin until the barrels exist and the root index re-exports them. Empty barrels are safe.

**Verify**:
- `ng build apps/web` passes (no missing exports because barrels are empty)
- Tree under `core/application/` matches the design file

---

### Task 0.2 — Add shared error-mapping helper
- [x] Done
**Files**:
- Create `apps/web/src/app/core/application/shared/extract-api-error.ts`
- Create `core/application/shared/index.ts`

**Content**:
```ts
export function extractApiError(err: unknown, fallback: string): string {
  if (err && typeof err === 'object' && 'error' in err) {
    const inner = (err as { error?: { error?: string } }).error;
    if (inner?.error) return inner.error;
  }
  if (err instanceof Error) return err.message;
  return fallback;
}
```

**Verify**:
- `ng build apps/web` passes
- Helper is exported from `core/application/shared` and ready to be imported by use cases

---

## Phase 1 — Agent Use Cases (5 tasks)

### Task 1.1 — `ListAgentsUseCase` + `GetAvailableUsersForLinkingUseCase`
- [x] Done
**Files**:
- `core/application/admin/agents/list-agents.use-case.ts` + `.spec.ts`
- `core/application/admin/agents/get-available-users-for-linking.use-case.ts` + `.spec.ts`

**Contracts**:
- `ListAgentsInput { page: number; limit?: number }` → `ListAgentsOutput { agents: AdminAgentDTO[]; totalPages: number; total: number }`
- `GetAvailableUsersForLinkingInput` (none) → `GetAvailableUsersForLinkingOutput { users: AdminUserDTO[] }` — calls `api.listUsers(1, 200)` then filters `is_active && !agent_id`

**Exceptions**: `AgentListFailed`, `AvailableUsersFetchFailed`

**Verify**:
- [x] `ng build apps/web` passes
- [ ] Specs pass (`ng test --watch=false` or `jest`)
- [x] `core/application/admin/agents/index.ts` updated to export both

---

### Task 1.2 — `CreateAgentUseCase` + `UpdateAgentUseCase`
- [x] Done
**Files**:
- `core/application/admin/agents/create-agent.use-case.ts` + `.spec.ts`
- `core/application/admin/agents/update-agent.use-case.ts` + `.spec.ts`

**Contracts**:
- `CreateAgentInput { name: string }` → `CreateAgentOutput { agent: AdminAgentDTO }`. Validates `name.trim().length > 0`; throws `InvalidAgentName` before calling API.
- `UpdateAgentInput { id: number; name: string; active: boolean }` → `UpdateAgentOutput { agent: AdminAgentDTO }`. Always sends full payload (matches current behavior).

**Exceptions**: `AgentCreationFailed`, `InvalidAgentName`, `AgentUpdateFailed`

**Verify**:
- [x] `ng build apps/web` passes
- [ ] Specs cover: empty name, whitespace-only name, success path, API error mapping

---

### Task 1.3 — `DeactivateAgentUseCase` + `ReactivateAgentUseCase`
- [x] Done
**Files**:
- `core/application/admin/agents/deactivate-agent.use-case.ts` + `.spec.ts`
- `core/application/admin/agents/reactivate-agent.use-case.ts` + `.spec.ts`

**Contracts**:
- `DeactivateAgentInput { id: number }` → `DeactivateAgentOutput { id: number }`. Calls `api.deleteAgent(id)`.
- `ReactivateAgentInput { id: number }` → `ReactivateAgentOutput { id: number }`. Calls `api.updateAgent(id, { active: true })`.

**Exceptions**: `AgentDeactivationFailed`, `AgentReactivationFailed`

**Verify**:
- [x] `ng build apps/web` passes
- [ ] Specs pass

---

### Task 1.4 — `ManageAgentUserLinkingUseCase`
- [x] Done
**Files**:
- `core/application/admin/linking/manage-agent-user-linking.use-case.ts` + `.spec.ts`

**Contract** (two methods on one use case):
- `link(input: { agentId: number; userId: number }): Observable<LinkUserResponse>` → `linkAgentToUser` API call
- `unlink(input: { agentId: number }): Observable<void>` → `unlinkAgentFromUser` API call
- `execute()` not used — direct method calls, since these are independently invoked

**Exception**: `AgentLinkingFailed`

**Why two methods, not two use cases**: The proposal groups link/unlink because the UI always treats them as related actions on the same agent. Keeping them in one class makes the dependency surface obvious and matches the spec.

**Verify**:
- [x] `ng build apps/web` passes
- [ ] Specs cover both methods, success + error paths

---

### Task 1.5 — Refactor `AgentsPageComponent` to use agent use cases
- [x] Done
**File**: `features/admin/agents/agents-page.component.ts`

**Changes**:
- Remove `private readonly adminApi = inject(AdminApiService)`
- Add `inject(ListAgentsUseCase)`, `inject(GetAvailableUsersForLinkingUseCase)`, `inject(CreateAgentUseCase)`, `inject(UpdateAgentUseCase)`, `inject(DeactivateAgentUseCase)`, `inject(ReactivateAgentUseCase)`, `inject(ManageAgentUserLinkingUseCase)`
- Replace every `this.adminApi.X(...)` call inside the component with `this.<useCase>.<method>(...)` or `.execute(...)`
- Remove inline error mapping; subscribe and let the use case throw, then `catchError` sets `this.error.set(err.message)`
- Keep all signals, modal state, template, pagination orchestration, `goToPage`
- Keep success messages (those are UI concerns, not business logic)

**Verify**:
- [x] `ng build apps/web` passes
- [ ] `ng test` passes
- [ ] Manual smoke test: list agents, paginate, create agent, edit agent, link user, unlink user, deactivate, reactivate — all flows match previous behavior, error messages identical, success messages identical

---

## Phase 2 — User Use Cases (4 tasks)

### Task 2.1 — `ListUsersUseCase` + `CreateUserUseCase`
- [x] Done
**Files**:
- `core/application/admin/users/list-users.use-case.ts` + `.spec.ts`
- `core/application/admin/users/create-user.use-case.ts` + `.spec.ts`

**Contracts**:
- `ListUsersInput { page: number; limit?: number }` → `ListUsersOutput { users: AdminUserDTO[]; totalPages: number; total: number }`
- `CreateUserUseCase` has TWO methods:
  - `execute(input: { email: string; password: string; roles: string[]; agentId: number | null }): Observable<CreateUserOutput>` — validates `email && password`, defaults `roles` to `['ROLE_AGENT']` if empty, calls `api.createUser(...)`
  - `loadAvailableAgents(): Observable<AdminAgentDTO[]>` — calls `api.listAgents(1, 100)`, filters `active && !user_id` (replaces component's `loadAvailableAgents()`)

**Exceptions**: `UserListFailed`, `UserCreationFailed`, `InvalidUserCredentials`

**Verify**:
- [x] `ng build apps/web` passes
- [ ] Specs cover: empty email, empty password, empty roles fallback, success, error mapping, available-agents filter

---

### Task 2.2 — `UpdateUserUseCase`
- [x] Done
**Files**:
- `core/application/admin/users/update-user.use-case.ts` + `.spec.ts`

**Contract**:
- `UpdateUserInput { id: number; email: string; password: string; roles: string[] }` → `UpdateUserOutput { user: AdminUserDTO }`
- Internal diff: builds `UpdateUserPayload` containing `email` (always), `password` (only if non-empty), `roles` (default `['ROLE_AGENT']` if empty)
- This folds `toggleRole` into the same use case (role toggling is pure form state, not a separate business action)

**Exception**: `UserUpdateFailed`

**Verify**:
- [x] `ng build apps/web` passes
- [ ] Specs cover: roles fallback, password empty → omitted, success, error mapping

---

### Task 2.3 — `DeactivateUserUseCase` + `ReactivateUserUseCase`
- [x] Done
**Files**:
- `core/application/admin/users/deactivate-user.use-case.ts` + `.spec.ts`
- `core/application/admin/users/reactivate-user.use-case.ts` + `.spec.ts`

**Contracts**:
- `DeactivateUserInput { id: number }` → `DeactivateUserOutput { id: number }`. Calls `api.deleteUser(id)`.
- `ReactivateUserInput { id: number }` → `ReactivateUserOutput { id: number }`. Calls `api.updateUser(id, { is_active: true })`.

**Exceptions**: `UserDeactivationFailed`, `UserReactivationFailed`

**Verify**:
- [x] `ng build apps/web` passes
- [ ] Specs pass

---

### Task 2.4 — Refactor `UsersPageComponent` to use user use cases
- [x] Done
**File**: `features/admin/users/users-page.component.ts`

**Changes**:
- Remove `private readonly adminApi = inject(AdminApiService)`
- Add `inject(ListUsersUseCase)`, `inject(CreateUserUseCase)`, `inject(UpdateUserUseCase)`, `inject(DeactivateUserUseCase)`, `inject(ReactivateUserUseCase)`
- Replace `this.adminApi.X(...)` calls
- `loadAvailableAgents()` becomes a single call to `this.createUseCase.loadAvailableAgents()`
- Remove inline error mapping; let use case exceptions carry the message
- Keep all signals, modal state, template, pagination orchestration, `toggleRole` (form state)

**Verify**:
- [x] `ng build apps/web` passes
- [ ] `ng test` passes
- [ ] Manual smoke test: list users, paginate, create user (with optional agent link), edit user, edit roles, deactivate, reactivate — all flows match previous behavior

---

## Phase 3 — Reports Use Cases (2 tasks)

### Task 3.1 — `BuildTimeFilterParamsUseCase` + `GetReportsDashboardUseCase`
- [x] Done
**Files**:
- `core/application/reports/build-time-filter-params.use-case.ts` + `.spec.ts`
- `core/application/reports/get-reports-dashboard.use-case.ts` + `.spec.ts`

**Contracts**:
- `BuildTimeFilterParamsUseCase` is a pure function (no Observable):
  - `build(filter: TimeFilter, fromDate: string, toDate: string): TimeFilterParams`
  - Encapsulates the `today / 7d / 30d / custom` switch and `formatDate` helper
- `GetReportsDashboardUseCase`:
  - `execute(input: { params: TimeFilterParams }): Observable<ReportsDashboardData>`
  - Uses `forkJoin` over 5 API calls: FRT, RT, ticket counts, volume trend, agent metrics
  - Maps responses: `avg_frt_minutes → frtValue`, `avg_rt_minutes → rtValue`, `(new+active+waiting) → openCount`, `(resolved+closed) → closedCount`, `tickets_created → count`, agent metrics with derived `loadPercentage` and `agentName`
  - Emits single `ReportsDashboardData` with all 5 sections
  - Partial failure: catch on each inner stream so one failing endpoint doesn't kill the whole dashboard (set the failing section's data to a sensible default)

**Output type** (`core/application/reports/get-reports-dashboard.use-case.ts`):
```ts
export interface ReportsDashboardData {
  frt: { value: number };
  rt: { value: number };
  counts: { open: number; closed: number };
  volume: VolumeDataPoint[];
  agents: AgentMetric[];
}
```

**Exceptions**: `ReportsFetchFailed`, `InvalidTimeFilter`

**Verify**:
- [x] `ng build apps/web` passes
- [ ] Specs cover: time filter computation for each branch, full happy path with 5 streams, partial failure path

---

### Task 3.2 — Refactor `ReportsPageComponent` to use reports use cases
- [x] Done
**File**: `features/reports/pages/reports-page/reports-page.component.ts`

**Changes**:
- Remove `private readonly reportsApi = inject(ReportsApiService)`
- Add `inject(BuildTimeFilterParamsUseCase)`, `inject(GetReportsDashboardUseCase)`
- Remove the `timeParams` computed signal; replace with `buildTimeFilterParamsUseCase.build(timeFilter(), fromDate(), toDate())` inside the load method
- Remove all 5 individual API subscriptions in `loadAllData()`; replace with one call to `getReportsDashboardUseCase.execute({ params })` and write results to the existing 10 signals (5 loading + 5 data)
- Keep: `setTimeFilter`, `applyCustomFilter`, all template wiring, all signal state

**Verify**:
- [x] `ng build apps/web` passes
- [ ] `ng test` passes
- [ ] Manual smoke test: switch filters (today / 7d / 30d / custom), all 5 cards update, agent leaderboard updates, no behavior change

---

## Phase 4 — Cleanup + Verification (4 tasks)

### Task 4.1 — Verify `core/application/index.ts` re-exports
- [x] Done
**File**: `core/application/index.ts`

**Verify**:
- [x] `export * from './admin';` and `export * from './reports';` present
- [x] `ng build apps/web` passes (TypeScript will catch any missing re-export)
- [x] No circular imports introduced

---

### Task 4.2 — Lint pass
- [x] Done
**Verify**:
- [ ] `ng lint` (or `eslint apps/web` if configured) passes — no lint target configured in project
- [x] No `any` types introduced in use case inputs/outputs
- [x] No unused imports in refactored components (build passes without errors)

---

### Task 4.3 — Spec coverage audit
**Verify**:
- [ ] 14 `.spec.ts` files exist (one per use case) — spec files pending
- [ ] Each spec has at least: happy path, validation failure (where applicable), API error mapping
- [ ] Coverage report shows ≥80% on `core/application/admin/**` and `core/application/reports/**`
- [ ] Run: `ng test --code-coverage --watch=false` or equivalent

---

### Task 4.4 — End-to-end smoke test
**Manual** (or Playwright if available):
- [ ] `http://localhost:4200/admin/agents` — list, paginate, create, edit, link, unlink, deactivate, reactivate
- [ ] `http://localhost:4200/admin/users` — list, paginate, create, edit (including role toggle), deactivate, reactivate
- [ ] `http://localhost:4200/reports` — switch filters, verify all 4 KPI cards + leaderboard + volume chart update
- [ ] Force an API error (e.g., 401, 422) on each page and confirm the error message matches the pre-refactor behavior

**Verify**:
- All flows pass
- Error messages are byte-identical to pre-refactor (`Failed to create agent`, etc.)
- No console errors in dev tools
- No regressions in DevTools network tab (same endpoints, same payloads, same status codes)

---

## Dependency Graph

```
0.1 → 0.2 → 1.1 → 1.2 → 1.3 → 1.4 → 1.5 → 2.1 → 2.2 → 2.3 → 2.4 → 3.1 → 3.2 → 4.1 → 4.2 → 4.3 → 4.4
```

Each phase is sequential. Within a phase, tasks are also sequential (each adds exports the next one imports). Tasks 4.1–4.4 can run in order but are independent of the refactor sequence if all prior tasks complete.

## Estimated Effort

| Phase | Tasks | Estimated effort |
|-------|-------|------------------|
| 0 — Foundation | 2 | ~30 min |
| 1 — Agents | 5 | ~3–4 hours (6 use cases + 1 refactor) |
| 2 — Users | 4 | ~2.5–3 hours (5 use cases + 1 refactor) |
| 3 — Reports | 2 | ~1.5–2 hours (2 use cases + 1 refactor) |
| 4 — Cleanup | 4 | ~1 hour |
| **Total** | **17** | **~8–11 hours** |

## Commit Strategy

One commit per task. Conventional commits, no AI attribution. Suggested prefixes:
- `feat(admin-uc):` for use case files
- `refactor(admin):` for component refactors
- `test(admin-uc):` for spec files
- `chore(application):` for foundation/barrel changes
