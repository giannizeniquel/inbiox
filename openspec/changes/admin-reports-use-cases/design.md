# Design: Use Case Layer for Admin + Reports

## Technical Approach

Extract 14 granular use cases from `AgentsPageComponent`, `UsersPageComponent`, and `ReportsPageComponent` into `core/application/{admin,reports}/`. Each use case wraps a single business action, encapsulates API + validation + error mapping, and exposes one `execute(input): Observable<output>` entry point. Components become thin orchestrators that hold only signals, modal state, and template logic.

The pattern follows the existing `SendMessageUseCase` / `RetryMessageUseCase` precedent: `@Injectable({ providedIn: 'root' })`, I/O DTOs in-file, custom `Error` subclasses, factory functions. Dependencies are injected via Angular's `inject()` (API services) rather than `@Inject(token)` (the messaging pattern uses token-based injection for `ProviderInterface`).

## Architecture Decisions

| # | Decision | Choice | Alternatives | Rationale |
|---|----------|--------|--------------|-----------|
| 1 | Directory layout | `core/application/{admin/agents, admin/users, admin/linking, reports}/` | Flat `admin/`; one `admin-reports/` dir | Mirrors the domain split in `core/api/`; groups related use cases; keeps each barrel small |
| 2 | Error handling | Custom `Error` subclass per use case (e.g., `AgentCreationFailed`) | Shared base `AdminError` class; single generic `UseCaseError` | Matches the messaging pattern (`MessageSendFailed`, `MessageNotFound`); each use case owns its own error taxonomy; a shared base adds indirection without reuse value |
| 3 | HTTP error extraction | Local helper `extractApiError(err, fallback)` per use case | Global HTTP interceptor; `ErrorHandler` | Interceptor is out of scope per proposal; helper is 3 lines, lives next to its only caller |
| 4 | Pagination | Stays in components; use cases accept `page`/`limit` inputs | Shared `PaginatedUseCase` base; `VirtualListDirective` | Proposal defers this to a future SDD; components already own the state |
| 5 | DTO location | I/O interfaces in each use case file; reuse DTOs from `core/api/` | Shared DTO files in `core/application/dto/` | Matches messaging; reuse happens via the API service DTOs (`AdminAgentDTO` etc.) |
| 6 | Barrel exports | Per-folder `index.ts` re-exporting class + types + exception; root `core/application/index.ts` adds two lines | Single flat re-export; per-use-case re-export | Mirrors `messaging/index.ts` exactly |
| 7 | Test strategy | One `.spec.ts` per use case, Jasmine + `jasmine.createSpyObj`; factory function used for instantiation | Jest; per-component integration tests | Matches `send-message.use-case.spec.ts` and `retry-message.use-case.spec.ts`; the project already uses Jasmine |

## Data Flow

    Component (signals + template)
        │
        │  inject(UseCase)
        ▼
    UseCase (execute)
        │  validation + payload diffing
        │  catchError → custom exception
        ▼
    AdminApiService / ReportsApiService
        │
        ▼
    HTTP → Backend

Components subscribe to `useCase.execute(input)` and translate outputs into signal writes. They never see `HttpErrorResponse` — exceptions carry the user-facing message.

## Use Case Catalog (14)

### Admin / Agents (6)
1. `ListAgentsUseCase` → `GET /api/admin/agents?page&limit`
2. `GetAvailableUsersForLinkingUseCase` → `GET /api/admin/users?page=1&limit=200` filtered `is_active && !agent_id`
3. `CreateAgentUseCase` → validates `name.trim()`, `POST /api/admin/agents`
4. `UpdateAgentUseCase` → diffs current vs. form, `PUT /api/admin/agents/{id}`
5. `DeactivateAgentUseCase` → `DELETE /api/admin/agents/{id}` (soft delete on backend)
6. `ReactivateAgentUseCase` → `PUT /api/admin/agents/{id} { active: true }`

### Admin / Linking (1)
7. `ManageAgentUserLinkingUseCase` → single class with `link(agentId, userId)` + `unlink(agentId)` methods (not two use cases — always invoked together from the same modal flow)

### Admin / Users (5)
8. `ListUsersUseCase` → `GET /api/admin/users?page&limit`
9. `CreateUserUseCase` → validates `email + password`, `POST /api/admin/users`; folds the "available agents for dropdown" pre-fetch via a `loadAvailableAgents(): Observable<AdminAgentDTO[]>` method on the same use case
10. `UpdateUserUseCase` → diffs email/password/roles (role toggle is pure form state, not a separate use case)
11. `DeactivateUserUseCase` → `DELETE /api/admin/users/{id}`
12. `ReactivateUserUseCase` → `PUT /api/admin/users/{id} { is_active: true }`

### Reports (2)
13. `GetReportsDashboardUseCase` → 5 concurrent calls (FRT, RT, counts, volume, agents) via `forkJoin`; response mapping (rename, sum open/closed, derive `loadPercentage`, build `agentName`); emits a single `ReportsDashboardData` object
14. `BuildTimeFilterParamsUseCase` → pure sync function returning `TimeFilterParams` from `(TimeFilter, fromDate, toDate)`; no Observable, no API

## File Changes

| File | Action |
|------|--------|
| `core/application/admin/agents/{list,get-available-users-for-linking,create,update,deactivate,reactivate}-agent.use-case.ts` + `.spec.ts` | Create (12 files) |
| `core/application/admin/agents/index.ts` | Create |
| `core/application/admin/linking/manage-agent-user-linking.use-case.ts` + `.spec.ts` | Create (2 files) |
| `core/application/admin/linking/index.ts` | Create |
| `core/application/admin/users/{list,create,update,deactivate,reactivate}-user.use-case.ts` + `.spec.ts` | Create (10 files) |
| `core/application/admin/users/index.ts` | Create |
| `core/application/admin/index.ts` | Create |
| `core/application/reports/{get-reports-dashboard,build-time-filter-params}.use-case.ts` + `.spec.ts` | Create (4 files) |
| `core/application/reports/index.ts` | Create |
| `core/application/index.ts` | Modify — add `export * from './admin';` and `export * from './reports';` |
| `features/admin/agents/agents-page.component.ts` | Modify — wire to 6 use cases; remove all `adminApi.*` calls; drop error mapping; expected ~150 lines |
| `features/admin/users/users-page.component.ts` | Modify — wire to 5 use cases; same reductions; expected ~150 lines |
| `features/reports/pages/reports-page/reports-page.component.ts` | Modify — wire to 2 use cases; drop `timeParams` computed and all response mapping; expected ~80 lines |
| `core/api/admin-api.service.ts`, `core/api/reports-api.service.ts` | Unchanged |

## Interfaces / Contracts

```ts
// Pattern (every use case)
export interface ListAgentsInput { page: number; limit?: number; }
export interface ListAgentsOutput { agents: AdminAgentDTO[]; totalPages: number; total: number; }
export class AgentListFailed extends Error { constructor(msg: string, public readonly cause?: unknown) { super(msg); this.name = 'AgentListFailed'; } }

@Injectable({ providedIn: 'root' })
export class ListAgentsUseCase {
  private readonly api = inject(AdminApiService);
  execute(input: ListAgentsInput): Observable<ListAgentsOutput> { /* ... */ }
}
export function createListAgentsUseCase(api: AdminApiService): ListAgentsUseCase { return new ListAgentsUseCase(api); }
```

Custom exceptions: `AgentListFailed`, `AgentCreationFailed`, `AgentUpdateFailed`, `AgentDeactivationFailed`, `AgentReactivationFailed`, `AvailableUsersFetchFailed`, `AgentLinkingFailed`, `UserListFailed`, `UserCreationFailed`, `UserUpdateFailed`, `UserDeactivationFailed`, `UserReactivationFailed`, `ReportsFetchFailed`, `InvalidTimeFilter`. Each carries the extracted user-facing message and the original error as `cause`.

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit | Each use case: happy path, validation failure, API error mapping | Jasmine spec per file; `jasmine.createSpyObj('AdminApiService', [...])`; use factory function |
| Component | Smoke test only — verify wiring, no logic changes | Manual: CRUD flows on `/admin/agents`, `/admin/users`, `/reports` |
| Build | No TS errors after each phase | `ng build` (apps/web) |

Target: 14 spec files, ~6–10 cases each = ~80+ assertions. Coverage target 80% on `core/application/admin/**` and `core/application/reports/**`.

## Migration / Rollout

No data migration. Five phase-rolled commits (one per task group), each independently buildable. Rollback = `git revert` per commit; per-page revert possible. Each commit must pass `ng build apps/web`.

**Risk mitigations baked into the design:**
- Error messages: each use case's `extractApiError` reads `err?.error?.error ?? fallback` — identical to today's inline mapping; UX unchanged.
- Response shapes: use case outputs reuse the API DTOs (`AdminAgentDTO`, `AgentMetric`, `VolumeDataPoint`); no new public types.
- Pagination: components keep `currentPage` / `totalPages` signals; use cases receive page in the input DTO.

## Open Questions

- None. All six decision points from the proposal are resolved above. `GetAvailableAgentsForLinking` is folded into `CreateUserUseCase.loadAvailableAgents()` to honor the "14 use cases" count without losing functionality — the dropdown pre-fetch is a step of the create flow, not an independent domain action.
