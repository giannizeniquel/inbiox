# Exploration: reporting-v2

## Current State

### Backend — Existing (5 endpoints)

| Endpoint | Handler | Repository Method | Status |
|----------|---------|-------------------|--------|
| `GET /api/reports/frt` | `ReportingController::frt` | `ReportingRepository::getFrtMetrics` | ✅ Works |
| `GET /api/reports/rt` | `ReportingController::rt` | `ReportingRepository::getRtMetrics` | ✅ Works |
| `GET /api/reports/counts` | `ReportingController::counts` | `ReportingRepository::getCountsByStatus` | ✅ Works |
| `GET /api/reports/volume` | `ReportingController::volume` | `ReportingRepository::getVolumeOverTime` | ✅ Works |
| `GET /api/reports/agents` | `ReportingController::agents` | `ReportingRepository::getAgentPerformance` | ⚠️ Has bugs |

**Key files:**
- `apps/api/src/Controller/ReportingController.php` — 226 lines, attribute-based routes
- `apps/api/src/Application/Service/ReportingService.php` — thin orchestration layer, 117 lines
- `apps/api/src/Repository/ReportingRepository.php` — raw SQL + DQL queries, 403 lines
- `apps/api/config/routes.yaml` — lines 50–73 define the 5 routes

**Architecture patterns:**
- `OwnershipScope` + `WorkspaceScope` injected into controller for query-level filtering
- `ReportingRepository` uses raw SQL for time-diff aggregates (MySQL/PostgreSQL abstraction via `isPostgres` flag)
- `AgentAvailabilityService` already exists for schedule-based availability (`isInShift`, `isAvailable`, `listAvailableAgents`)

---

### Backend — Gaps

| Missing Query | Feasibility | Notes |
|---------------|-------------|-------|
| **1. Agent load** (active vs capacity) | ⚠️ Partial | `agents` endpoint returns `current_load` but line 392 computes `currentActive/maxActive` — correct formula but uses `max_active_tickets` as max capacity. Missing: a dedicated endpoint for real-time agent load summary (not tied to date range). |
| **2. Unassigned queue** | ✅ Easy | `Ticket.assignedAgentId IS NULL` query. Ticket entity has no `assigned_agent_id = null` index — should add one. |
| **3. Available agents** (in-shift right now) | ⚠️ Partial | `AgentAvailabilityService::listAvailableAgents()` already exists and works. Need a new endpoint to expose it. The `is_in_shift` in agents endpoint is date-range based, not real-time. |
| **4. FRT daily trend** | ✅ Easy | Repository method already returns per-day grouping for volume. Needs same for FRT (AVG/TIMESTAMPDIFF grouped by DATE). No new infrastructure needed. |
| **5. Heatmap** (day × hour) | ✅ Medium | Needs `GROUP BY DATE(created_at), HOUR(created_at)` — native SQL. `ReportingRepository` already has the infrastructure, just new query. |
| **6. Reassignment rate** | ✅ Medium | Requires `reassigned_at` column on Ticket OR tracking via `assigned_agent_id` changes. Currently **no `reassigned_at` field exists** on Ticket entity. Would need a new DB column. |
| **7. Recurring customers** | ✅ Easy | JOIN tickets to contacts, GROUP BY contact_id HAVING COUNT > 1. Contact entity already linked via `Ticket.contact`. |
| **8. Reopened tickets** | ✅ Easy | AutoReopenRule emits `auto_reopened` event, but no persisted `reopened_at` column. Can approximate by counting tickets with `status = 'active'/'waiting'` that were previously `resolved`. Requires `resolved_at` + state history tracking. |

**Showstopper for #6 (Reassignment rate):** `Ticket` entity has no `reassigned_at` column. Need migration to add it. This is a schema change that affects the existing ticket workflow.

**Frontend — Existing (5 endpoints consumed)**

| UI Component | API Called | Status |
|--------------|-----------|--------|
| `ResponseTimeCardComponent` | `getFirstResponseTime` | ✅ |
| `ResolutionTimeCardComponent` | `getResolutionTime` | ✅ |
| `TicketCountCardComponent` | `getTicketCounts` | ✅ |
| `VolumeTrendCardComponent` | `getVolumeTrend` | ✅ |
| `AgentLeaderboardComponent` | `getAgentMetrics` | ✅ |

**Key files:**
- `apps/web/src/app/core/api/reports-api.service.ts` — 107 lines, 5 GET methods
- `apps/web/src/app/core/application/reports/get-reports-dashboard.use-case.ts` — forkJoin of 5 calls, 129 lines
- `apps/web/src/app/features/reports/pages/reports-page/reports-page.component.ts` — 104 lines, time filter + data loading
- `apps/web/src/app/core/dto/reports.params.ts` — `TimeFilterParams` interface (fromDate, toDate, or preset)

**Frontend — Gaps**

- No UI for 8 missing report types
- `GetReportsDashboardUseCase` uses `forkJoin` — adding 8 more endpoints means restructuring (sequential vs parallel, error isolation)
- `TimeFilterParams` only supports date range — some new queries (heatmap, available agents) don't need date range
- No Angular models/interfaces for new response shapes

---

## Affected Areas

| File/Directory | Why Affected |
|----------------|--------------|
| `apps/api/src/Controller/ReportingController.php` | Add 8 new endpoint handlers |
| `apps/api/src/Application/Service/ReportingService.php` | Add 8 new service methods delegating to repository |
| `apps/api/src/Repository/ReportingRepository.php` | Add 8 new SQL/DQL query methods |
| `apps/api/config/routes.yaml` | Add 8 new route definitions |
| `apps/api/src/Domain/Ticket/Ticket.php` | May need `reassigned_at` column (migration) for reassignment rate |
| `apps/api/src/Command/SeedReportsCommand.php` | New seeder for fake report data (to be created) |
| `apps/api/tests/Integration/ReportingTest.php` | Tests for all new endpoints (to be created) |
| `apps/web/src/app/core/api/reports-api.service.ts` | Add 8 new HTTP methods |
| `apps/web/src/app/core/application/reports/get-reports-dashboard.use-case.ts` | Restructure to handle more endpoints |
| `apps/web/src/app/features/reports/pages/reports-page/` | Add new report components to page |
| `apps/web/src/app/features/reports/components/` | New components for each report type |

---

## Approaches

### Approach A — Minimal Viable (Recommended)

**Strategy:** Implement only queries that require NO schema changes. Skip reassignment rate (#6) until `reassigned_at` is added.

**Endpoints to add:**
1. `GET /reports/unassigned` — tickets with `assigned_agent_id IS NULL`
2. `GET /reports/agents/available` — real-time available agents (leverage `AgentAvailabilityService::listAvailableAgents()`)
3. `GET /reports/frt/trend` — FRT broken down by day
4. `GET /reports/heatmap` — volume grouped by day × hour
5. `GET /reports/recurring` — customers with multiple tickets
6. `GET /reports/reopened` — tickets that left resolved status

**Frontend:** Create new Angular components per report type, add to reports page tab/grid.

**Effort:** Medium | **Pros:** Fast to ship, no migrations, leverages existing infrastructure | **Cons:** Incomplete — reassignment rate missing

### Approach B — Full Scope

**Strategy:** Add all 8 endpoints including reassignment rate (requires new `reassigned_at` DB column + migration).

**Adds to Approach A:**
- Migration to add `reassigned_at` to tickets table
- `GET /reports/reassignment-rate` — tickets reassigned during date range

**Effort:** High | **Pros:** Complete feature set | **Cons:** Schema migration risk, more testing

---

## Recommendation

**Start with Approach A (Minimal Viable)** — no schema migrations, uses existing `AgentAvailabilityService`, fast iteration. Create a separate follow-up SDD for reassignment rate tracking (requires ticket workflow changes).

---

## Risks

1. **Schema change for reassignment rate** — Adding `reassigned_at` column requires migration + affects existing ticket workflow (assign use case must populate it). Low risk if done carefully.
2. **Frontend forkJoin overload** — Adding 8 more parallel API calls to `GetReportsDashboardUseCase` via `forkJoin` will hit Angular's HTTP concurrency limits. Need to restructure to batch or use sequential loading.
3. **Heatmap query performance** — Grouping by hour × day on large ticket tables needs proper indexes on `created_at`. Current indexes: `idx_tickets_status`, `idx_tickets_assigned_agent_id`, `idx_tickets_owner_status`. No index on `created_at`.
4. **Fake data seeder** — Orchestrator requires DB seeding for testing. No existing seeder for tickets with varied timestamps. Need new `SeedReportsCommand`.
5. **Agent availability vs in-shift** — `is_in_shift` in agents endpoint uses a UTC snapshot at query time, not real-time. `listAvailableAgents` uses current time. Need to clarify if "available agents" endpoint should be real-time or historical.

---

## Ready for Proposal

**Yes** — Backend gaps are clear, frontend gaps are clear, approach is straightforward.

### What the orchestrator should tell the user:
- 8 new backend endpoints needed (6 without schema change, 2 with)
- Frontend needs new components + restructuring of dashboard use case
- Fake data seeder required as part of implementation
- All 14 endpoints (5 existing + 9 new) must be tested before commit
