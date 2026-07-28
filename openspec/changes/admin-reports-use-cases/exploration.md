# Exploration: Use Case Layer for Admin + Reports

**Date**: 2026-07-27
**Topic**: Extract business logic from admin and reports Angular components into dedicated use case classes following the existing `core/application/` pattern.
**Change**: `admin-reports-use-cases`
**Mode**: openspec

---

## Current State

### How Admin and Reports Work Today

**AgentsPageComponent** (`agents-page.component.ts`, 473 lines):
- Directly injects `AdminApiService` — no abstraction layer
- All business logic lives in the component: pagination (`loadAgents`, `goToPage`), form validation (`createAgent`, `updateAgent`), soft-delete lifecycle (`executeDelete`, `reactivateAgent`), user-agent link/unlink (`executeLink`, `executeUnlink`)
- 8 signal state holders for UI (loading, saving, error, success, modal state, form data, pagination)
- Error mapping from HTTP responses done inline in every `subscribe` callback
- No reusability: the same CRUD patterns are duplicated across agents and users pages

**UsersPageComponent** (`users-page.component.ts`, 408 lines):
- Same pattern as AgentsPage — directly calls `AdminApiService`
- Role toggling logic (`toggleRole`) is in-component business logic
- Payload diffing before update (`updateUser`) is in-component
- User-agent linking during creation (`loadAvailableAgents` filter) is in-component
- Pagination via `loadUsers` + `goToPage`

**ReportsPageComponent** (`reports-page.component.ts`, 157 lines):
- Directly injects `ReportsApiService`
- `timeParams` computed signal handles date range calculation (today, 7d, 30d, custom) — **this is business logic about time arithmetic that should be in a use case**
- `loadAllData()` fires 5 concurrent API calls, then transforms each response:
  - Maps raw API response to `VolumeDataPoint[]` with `tickets_created → count` rename
  - Maps `AgentMetricsResponse.agents` to `AgentMetric[]` with derived fields (`loadPercentage = tickets_assigned * 20`, `agentName = \`Agent ${agent_id}\``)
  - Sums open tickets: `(new + active + waiting)` and closed: `(resolved + closed)`
- **No child component has business logic** — KPI cards (response-time, resolution-time, ticket-count, volume-trend) and `AgentLeaderboardComponent` are pure presentational with `@Input()` only

### Existing Use Case Pattern

**Location**: `apps/web/src/app/core/application/messaging/`

**Convention** (2 existing use cases: `SendMessageUseCase`, `RetryMessageUseCase`):
- `@Injectable({ providedIn: 'root' })` — root-level singleton
- Class named `{Verb}{Entity}UseCase` (e.g., `SendMessageUseCase`)
- Input/Output DTOs defined as interfaces in the same file (`{Verb}{Entity}Input`, `{Verb}{Entity}Output`)
- Custom exception classes for domain errors (e.g., `MessageSendFailed`, `MessageNotFound`)
- `execute(input: Input): Observable<Output>` — single entry point
- Dependencies injected via constructor (`@Inject(DI_TOKEN)` pattern)
- No store dependency — use cases call provider/API layer directly
- Factory function exported for manual instantiation (`createXxxUseCase()`)
- Exported via barrel file (`index.ts`) and re-exported from `core/application/index.ts`

**Stats**: 2 use cases, both in `messaging/` subdirectory, each with a `.spec.ts` file.

---

## Component Logic Inventory

### AgentsPageComponent
| Method | Type | Should Move To |
|--------|------|---------------|
| `loadAgents()` | Data fetching + pagination | `ListAgentsUseCase` |
| `loadUnlinkedUsers()` | Data fetching + filter (`is_active && !agent_id`) | `GetAvailableUsersForLinkingUseCase` |
| `createAgent()` | Validation + API call | `CreateAgentUseCase` |
| `updateAgent()` | Payload diff + API call | `UpdateAgentUseCase` |
| `deleteAgent()` | Soft delete API call | `DeactivateAgentUseCase` |
| `reactivateAgent()` | Reactivation API call | `ReactivateAgentUseCase` |
| `executeLink()` | Link user to agent | `LinkUserToAgentUseCase` |
| `executeUnlink()` | Unlink user from agent | `UnlinkUserFromAgentUseCase` |
| `goToPage()` | Pagination state | → stays in component (orchestration) |
| `saveAgent()` | Delegates to create/update | → stays in component (orchestration) |
| Inline error mapping | HTTP error → user message | Should be centralized (interceptor or use case) |

### UsersPageComponent
| Method | Type | Should Move To |
|--------|------|---------------|
| `loadUsers()` | Data fetching + pagination | `ListUsersUseCase` |
| `loadAvailableAgents()` | Data fetching + filter (`active && !user_id`) | `GetAvailableAgentsForLinkingUseCase` |
| `createUser()` | Validation + API call | `CreateUserUseCase` |
| `updateUser()` | Payload diff + API call | `UpdateUserUseCase` |
| `deleteUser()` | Soft delete API call | `DeactivateUserUseCase` |
| `reactivateUser()` | Reactivation API call | `ReactivateUserUseCase` |
| `toggleRole()` | Role array manipulation | `UpdateUserRolesUseCase` (or merge into UpdateUser) |
| `goToPage()` | Pagination state | → stays in component |

### ReportsPageComponent
| Method/Logic | Type | Should Move To |
|--------------|------|---------------|
| `timeParams` computed | Date range calculation per filter | `BuildTimeFilterParamsUseCase` |
| `loadAllData()` | Orchestration + response mapping | `GetReportsDashboardUseCase` |
| FRT response mapping (`avg_frt_minutes → frtValue`) | Response projection | → `GetReportsDashboardUseCase` |
| RT response mapping (`avg_rt_minutes → rtValue`) | Response projection | → `GetReportsDashboardUseCase` |
| Ticket counts mapping (`(new+active+waiting)`, `(resolved+closed)`) | Data aggregation | → `GetReportsDashboardUseCase` |
| Volume data mapping (`tickets_created → count`) | Response projection | → `GetReportsDashboardUseCase` |
| Agent metrics mapping (derived fields, `agentName`) | Response projection + computation | → `GetReportsDashboardUseCase` |
| `setTimeFilter()` | UI state update + trigger | → stays in component |
| `applyCustomFilter()` | Validation + trigger | → stays in component |

### KPI Card Components
- **Purely presentational** — no business logic. `volume-trend-card` has `totalCount` and `chartData` getters which are display logic (acceptable to stay as-is with `OnPush`).

### AgentLeaderboardComponent
- `formatResponseTime()` — display formatting, acceptable to stay

---

## Affected Areas

| Path | Why |
|------|-----|
| `apps/web/src/app/core/application/` | New `admin/` and `reports/` subdirectories with use cases |
| `apps/web/src/app/core/application/index.ts` | Barrel re-export for new use case directories |
| `apps/web/src/app/features/admin/agents/agents-page.component.ts` | Becomes thin orchestrator; wires to use cases |
| `apps/web/src/app/features/admin/users/users-page.component.ts` | Becomes thin orchestrator; wires to use cases |
| `apps/web/src/app/features/reports/pages/reports-page/reports-page.component.ts` | Becomes thin orchestrator; wires to `GetReportsDashboardUseCase` |
| `apps/web/src/app/core/api/admin-api.service.ts` | Unchanged — use cases call it |
| `apps/web/src/app/core/api/reports-api.service.ts` | Unchanged — use case calls it |
| `apps/web/src/app/features/reports/models/reports.models.ts` | May need DTO additions for use case outputs |

---

## Approaches

### Approach 1: One Use Case Per Component Page (Feature Use Cases)

Create a single use case per admin page (`GetAgentsUseCase`, `ManageUsersUseCase`) that encapsulates all operations for that page.

**Pros**:
- Matches current component boundaries — minimal refactoring of component structure
- Good first step if the team wants incremental change
- Single class per page keeps things grouped

**Cons**:
- Violates single responsibility — a use case should represent one business action
- Harder to reuse individual operations (e.g., `deleteAgent` can't be used elsewhere without the whole `ManageAgentsUseCase`)
- Doesn't align with the existing pattern where `SendMessageUseCase` and `RetryMessageUseCase` are granular

**Effort**: Low-Medium

---

### Approach 2: One Use Case Per Business Action (Granular Use Cases)

Create one use case per distinct action: `ListAgentsUseCase`, `CreateAgentUseCase`, `UpdateAgentUseCase`, `DeactivateAgentUseCase`, `ReactivateAgentUseCase`, `LinkUserToAgentUseCase`, `UnlinkUserFromAgentUseCase`, and similar for users. For reports: `GetReportsDashboardUseCase`, `BuildTimeFilterParamsUseCase`.

**Pros**:
- Aligns with the existing `SendMessageUseCase` / `RetryMessageUseCase` pattern (one verb, one action)
- Each use case is independently testable and reusable
- Business logic is truly extractable from the component
- Follows clean architecture "use case = one business operation"

**Cons**:
- More files (12+ new use case classes)
- Slightly more wiring in components (but components become much thinner)
- Overkill if admin pages are never reused in other contexts

**Effort**: Medium-High

---

### Approach 3: Hybrid — Grouped Admin, Granular Reports

Apply Approach 1 to admin (operations are naturally grouped per page) and Approach 2 to reports (dashboard data aggregation is a single coherent operation with multiple data transformations).

**Pros**:
- Pragmatic: admin pages have independent operations, reports has one dashboard
- Less ceremony for admin, clear boundary for reports
- Components remain thin in both cases

**Cons**:
- Inconsistent granularity across the two domains

**Effort**: Medium

---

## Recommendation

**Approach 2 (Granular Use Cases)** — with one exception: group the link/unlink operations into `ManageAgentUserLinkingUseCase` since they always appear together in the UI.

Rationale: The existing pattern (`SendMessageUseCase`, `RetryMessageUseCase`) is granular. Following the established pattern means consistency, predictability, and easier onboarding. The component count concern is overblown — Angular's DI handles wiring cleanly, and `admin-api.service.ts` is already well-structured with one method per API call, so the mapping is 1:1. The real win is extracting validation logic, error mapping, and response transformation from components into testable use cases.

For reports specifically, extracting `GetReportsDashboardUseCase` is high-value because `timeParams` computation and response transformations are non-trivial business logic currently hidden in the component.

---

## Risks

1. **Over-abstraction**: Creating 12+ use cases for admin operations may be excessive if the admin UI never expands — consider starting with just `ListAgentsUseCase`, `CreateAgentUseCase`, `UpdateAgentUseCase` and iterating.
2. **Duplicate pagination logic**: Both `AgentsPageComponent` and `UsersPageComponent` implement nearly identical pagination — could be extracted to a shared `PaginatedListUseCase` base or a `PaginationParams` helper.
3. **Error mapping inconsistency**: Currently every component maps `err.error?.error || 'Failed to...'` inline. Centralizing this in the use case layer (or an HTTP interceptor) would be a separate concern worth addressing simultaneously.
4. **Reports time filter**: The `timeParams` computed in `ReportsPageComponent` computes date ranges client-side — this should ideally come from the backend or be validated there too. The use case should document this assumption.
5. **Test coverage**: No existing specs for admin or reports components. If tests are added as part of this work, the use cases should have `.spec.ts` files following the existing `send-message.use-case.spec.ts` pattern.

---

## Ready for Proposal

**Yes** — the codebase is in good shape to proceed. Existing patterns are clear, the application layer already exists at `core/application/`, and the affected components are well-structured (signals, no store coupling). The main clarification needed is the granularity level — the orchestrator should confirm Approach 2 vs. a more conservative first step before `sdd-propose` writes the scope.
