# Proposal: Sprint 12.3 Reports MVP

## Intent

Add MVP observability layer to Inbiox by exposing existing backend reporting endpoints through a simple Angular UI with KPI cards and time-based filtering. This addresses the need for basic operational metrics without building a full BI platform.

## Scope

### Task 1: ReportsApiService (Frontend)
- **Description**: Create Angular service to consume existing backend reporting endpoints
- **Files**:
  - `apps/web/src/app/core/api/reports-api.service.ts`
- **Endpoints**: getFirstResponseTime, getResolutionTime, getTicketCounts, getVolumeTrend, getAgentMetrics

### Task 2: KPI Cards Components
- **Description**: Create presentational components for each KPI metric
- **Files**:
  - `apps/web/src/app/features/reports/components/response-time-card/response-time-card.component.ts`
  - `apps/web/src/app/features/reports/components/resolution-time-card/resolution-time-card.component.ts`
  - `apps/web/src/app/features/reports/components/ticket-count-card/ticket-count-card.component.ts`
  - `apps/web/src/app/features/reports/components/volume-trend-card/volume-trend-card.component.ts`

### Task 3: ReportsPage (Smart Container)
- **Description**: Main page with time range filter and KPI grid layout
- **Files**:
  - `apps/web/src/app/features/reports/pages/reports-page/reports-page.component.ts`
  - `apps/web/src/app/features/reports/reports.routes.ts`
- **Features**: Time filter (today, 7d, 30d, custom), service calls on init and filter change

### Task 4: Agent Leaderboard
- **Description**: Simple table showing agent performance metrics
- **Files**:
  - `apps/web/src/app/features/reports/components/agent-leaderboard/agent-leaderboard.component.ts`
- **Columns**: Agent name, tickets assigned, avg response time, tickets resolved, load %

### Task 5: Navigation Integration
- **Description**: Enable Reports nav item in sidebar and add route
- **Files**:
  - `apps/web/src/app/shell/sidebar/sidebar.component.ts` (change isFuture: true → false)
  - `apps/web/src/app/app.routes.ts` (add reports route)

## Approach

Sequential implementation via sdd-apply: backend already complete, focus on frontend. Start with API service, then components, then page integration, finally navigation. No charts in MVP scope — cards only with numeric values.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/web/src/app/core/api/` | New | ReportsApiService |
| `apps/web/src/app/features/reports/` | New | Complete feature module |
| `apps/web/src/app/shell/sidebar/` | Modified | Enable nav item |
| `apps/web/src/app/app.routes.ts` | Modified | Add reports route |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Backend lacks channel field in queries | Medium | Display metrics as aggregate only |
| Message aggregation may affect FRT accuracy | Medium | Document limitation in UI tooltip |
| Time filter custom range UX complexity | Low | MVP uses simple date inputs |

## Rollback Plan

1. Remove reports route from `app.routes.ts`
2. Revert sidebar component to `isFuture: true`
3. Delete entire `features/reports/` folder
4. Delete `reports-api.service.ts`
5. No database changes needed (backend unchanged)

## Dependencies

- Backend ReportingController already deployed (`/reports/*` endpoints)
- No new npm packages required

## Success Criteria

- [ ] All 5 backend endpoints callable from frontend
- [ ] 4 KPI cards render with data
- [ ] Time filter updates all cards on change
- [ ] Agent leaderboard displays agent metrics
- [ ] Sidebar shows "Informes" as active nav item
- [ ] Page accessible at /reports route

## Capabilities

### New Capabilities
- `reports-view`: Display operational KPIs (FRT, RT, counts, volume) in card grid
- `agent-leaderboard`: View agent performance metrics sorted by load
- `time-filter`: Filter all reports by time range (preset and custom)

### Modified Capabilities
- None — existing capabilities unchanged