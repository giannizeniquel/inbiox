# Proposal: Use Case Layer for Admin + Reports

## Intent

Admin and Reports components embed business logic, validation, and error mapping directly (AgentsPage: 473, UsersPage: 408, ReportsPage: 157 lines). No tests. Duplicated patterns. Extract into granular use cases matching `core/application/` pattern.

## Scope

**In**: 7 agent use cases (List, Create, Update, Deactivate, Reactivate, ManageAgentUserLinking), 5 user (List, Create, Update, Deactivate, Reactivate), 2 reports (GetReportsDashboard, BuildTimeFilterParams). Refactor 3 components into thin orchestrators. Standardize error mapping into custom exceptions. `.spec.ts` per use case.

**Out**: HTTP interceptor. Shared pagination helper (defer to VirtualListDirective SDD). KPI cards (already presentational). Backend API changes.

## Capabilities

### New Capabilities
- `admin-use-cases`: Agent CRUD, user CRUD, linking. Extracts validation, payload diffing, role toggling, error mapping from admin components.
- `reports-use-cases`: Dashboard data aggregation. Extracts time-filter computation and response transformation (FRT, RT, ticket counts, volume, agent metrics).

### Modified Capabilities
None — pure implementation refactor. `administration` spec covers unchanged API contracts.

## Approach

Granular use cases (`{Verb}{Entity}UseCase`) matching existing convention: `@Injectable({ providedIn: 'root' })`, `execute(input): Observable<Output>`, I/O DTOs as interfaces, custom exceptions, constructor-injected API services, factory functions for tests. Link/unlink grouped into `ManageAgentUserLinkingUseCase`. Components keep template logic and signal state only. Estimated reduction: AgentsPage 473→~150, UsersPage 408→~150, ReportsPage 157→~80.

## Affected Areas

| Area | Impact |
|------|--------|
| `core/application/admin/agents/` | New — 6 use cases |
| `core/application/admin/users/` | New — 5 use cases |
| `core/application/admin/linking/` | New — 1 use case |
| `core/application/reports/` | New — 2 use cases |
| `core/application/index.ts` | Modified — add barrel exports |
| `features/admin/agents/` | Modified — wire to use cases |
| `features/admin/users/` | Modified — wire to use cases |
| `features/reports/pages/reports-page/` | Modified — wire to dashboard use case |
| `core/api/*` | Unchanged |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| UX regression | Low | Smoke test per page; use cases independently testable |
| Error message breakage | Low | Preserve existing error mapping; exceptions carry same payload |
| Pagination duplication persists | High | Accepted — deferred to VirtualListDirective SDD |
| Pattern divergence agents vs users | Low | Common exception base; cross-review |

## Rollback Plan

Pure frontend — no API/DB changes. `git revert` per commit. Independent per-page revert possible.

## Dependencies

`AdminApiService` / `ReportsApiService` (stable). Angular 19 `inject()` + Signals. Jest + Angular Testing Library.

## Success Criteria

- [ ] 14 use cases with `.spec.ts`, >80% coverage
- [ ] Components <200 lines, zero business logic in `subscribe`
- [ ] No inline error mapping strings in components
- [ ] All admin/reports flows unchanged (smoke: CRUD, linking, dashboard)
- [ ] Barrel exports wired into `core/application/index.ts`
