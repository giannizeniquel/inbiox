# Proposal: Reporting v2 — Operational Metrics Expansion

## Intent

Expand the existing 5-endpoint reporting system with 7 new operational metrics queries and their corresponding Angular dashboard components. The current reports cover basic volume/response/agent metrics but lack real-time operational visibility (unassigned queue, agent load, availability, heatmaps, recurring customers, reopened tickets, FRT trends).

## Scope

### In Scope
1. `GET /reports/agent-load` — per-agent active tickets vs max capacity (new)
2. `GET /reports/unassigned` — tickets with `assigned_agent_id IS NULL` (new)
3. `GET /reports/agents/available` — real-time in-shift available agents (new)
4. `GET /reports/frt/trend` — FRT aggregated by day within date range (enhancement)
5. `GET /reports/heatmap` — ticket volume grouped by day × hour (new)
6. `GET /reports/recurring` — contacts with ≥3 tickets in last 90 days (new)
7. `GET /reports/reopened` — tickets that left `resolved` status (new)

### Out of Scope
- **Reassignment rate** — requires `reassigned_at` column migration on Ticket. Deferred to follow-up SDD.
- Schema changes of any kind.

## Capabilities

### New Capabilities
None — all additions extend the existing `reports` capability.

### Modified Capabilities
- **reports**: Add 7 new query endpoints, 7 new repository methods, 7 new Angular dashboard components, restructured dashboard use-case for batch loading, fake-data seeder command, and integration tests covering all 12 endpoints (5 existing + 7 new).

## Approach

**Backend (Symfony 7.4):** Follow existing pattern — `ReportingController` (attribute routes) → `ReportingService` (thin orchestration) → `ReportingRepository` (raw SQL). Leverage `AgentAvailabilityService::listAvailableAgents()` for available agents. Add `created_at` index on tickets for heatmap performance. Create `SeedReportsCommand` populating `inbiox_test` with timestamp-varied tickets, contacts, and agents.

**Frontend (Angular 19):** Extend `reports-api.service.ts` with 7 new methods. Restructure `GetReportsDashboardUseCase` from single `forkJoin` to batched loading with error isolation per query. Create one standalone card component per new report type. Update `ReportsPage` layout to grid/tab organization. Add DTO interfaces for all new response shapes. No new page — enhance the existing Informes dashboard.

**Testing:** All 12 endpoints covered by integration tests in `ReportingTest.php`. Populate `inbiox_test` with seed data before test run.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Controller/ReportingController.php` | Modified | 7 new endpoint handlers |
| `apps/api/src/Application/Service/ReportingService.php` | Modified | 7 new orchestrator methods |
| `apps/api/src/Repository/ReportingRepository.php` | Modified | 7 new SQL query methods |
| `apps/api/config/routes.yaml` | Modified | 7 new route definitions |
| `apps/api/src/Command/SeedReportsCommand.php` | New | Fake data seeder for testing |
| `apps/api/tests/Integration/ReportingTest.php` | Modified | Tests for 7 new + 5 existing endpoints |
| `apps/web/src/app/core/api/reports-api.service.ts` | Modified | 7 new HTTP methods |
| `apps/web/src/app/core/application/reports/get-reports-dashboard.use-case.ts` | Modified | Batch loading architecture |
| `apps/web/src/app/features/reports/pages/reports-page/` | Modified | Grid/tab layout for new cards |
| `apps/web/src/app/features/reports/components/` | New | 7 report card components |
| `apps/web/src/app/core/dto/reports.params.ts` | Modified | New param/filter interfaces |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| `created_at` index missing on tickets — heatmap slow on large datasets | Medium | Add migration for index in this change |
| `forkJoin` of 12 calls overloads Angular HTTP concurrency | Low | Batch into 3 parallel groups, isolate errors per query |
| `reopened_tickets` query ambiguous without persisted state history | Medium | Approximate via `status != resolved` + last transition from `resolved`; document limitation |
| Seed data insufficient for edge cases | Low | Include zero-ticket agents, cross-workspace data, and boundary timestamps |

## Rollback Plan

Revert the `feature/reporting-v2` branch. No DB schema changes — rollback is a clean Git revert with no migration reversal needed.

## Dependencies

- `AgentAvailabilityService` (already exists) for available agents endpoint
- `inbiox_test` database for test seeding

## Success Criteria

- [ ] All 12 endpoints (5 existing + 7 new) return 200 with valid JSON in integration tests
- [ ] Angular dashboard renders all report cards without blocking render on any single failure
- [ ] Seed command populates test DB with varied data covering all 7 new query types
- [ ] No schema migration executed — `doctrine:migrations:status` shows zero pending after deploy
- [ ] Existing 5 endpoints unchanged (backward compatible)
