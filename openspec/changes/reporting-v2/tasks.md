# Tasks: Reporting v2 — Operational Metrics Expansion

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1800–2200 |
| 400-line budget risk | High |
| Chained PRs recommended | No (per user directive) |
| Suggested split | single PR (size:exception required) |
| Delivery strategy | single-pr |
| Chain strategy | none |

Decision needed before apply: Yes
Chained PRs recommended: No
Chain strategy: none
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Backend: 7 endpoints + routes + migration | PR 1 (only PR) | `docker exec inbiox_php php bin/phpunit tests/Integration/ReportingV2Test.php` | `docker exec inbiox_php php bin/console app:seed-reports && curl localhost:8080/api/reports/agent-load` | Revert `ReportingController`, `ReportingService`, `ReportingRepository` additions, `routes.yaml` entries, migration file |
| 2 | Seed command | (included in PR 1) | `docker exec inbiox_php php bin/console app:seed-reports` | `APP_ENV=test docker exec inbiox_php php bin/console app:seed-reports` | Remove `SeedReportsCommand.php` |
| 3 | Frontend: API + dashboard + 7 cards | (included in PR 1) | `cd apps/web && ng test --include='**/reports/**'` | `ng serve` + manual dashboard check | Revert `reports-api.service.ts`, `get-reports-dashboard.use-case.ts`, `reports-page`, 7 card component dirs |

## Critical Correction

**All 7 new routes MUST be added to `apps/api/config/routes.yaml`** (source of truth). Controllers keep `#[Route]` attributes for documentation only.

## Phase 1: Backend Foundation

- [ ] 1.1 Add `getAgentLoad(?int $agentId, ?string $phoneNumberId): array` to `ReportingRepository` — raw SQL: JOIN agents LEFT JOIN tickets WHERE status IN (new,active,waiting), GROUP BY agent
- [ ] 1.2 Add `getUnassigned(?string $phoneNumberId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit = 50): array` to `ReportingRepository` — WHERE assigned_agent_id IS NULL
- [ ] 1.3 Add `getFrtTrend(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId): array` to `ReportingRepository` — GROUP BY DATE(first_response_at), AVG(time_diff)
- [ ] 1.4 Add `getHeatmap(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId): array` to `ReportingRepository` — GROUP BY DAYOFWEEK(created_at), HOUR(created_at); normalize to ISO day (1=Mon,7=Sun); use isPostgres switch for EXTRACT(DOW)
- [ ] 1.5 Add `getRecurringCustomers(?string $phoneNumberId, int $threshold = 3, int $days = 90): array` to `ReportingRepository` — GROUP BY contact HAVING COUNT >= threshold
- [ ] 1.6 Add `getReopened(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId, int $limit = 100): array` to `ReportingRepository` — JOIN ticket_events WHERE type='auto_reopened'
- [ ] 1.7 Add 7 thin orchestration methods to `ReportingService` — `getAgentLoad`, `getUnassigned`, `getFrtTrend`, `getHeatmap`, `getRecurringCustomers`, `getReopened`, `getAvailableAgents`; call `validateDateRange` where applicable; `getAvailableAgents` delegates to `AgentAvailabilityService::listAvailableAgents($now)`
- [ ] 1.8 Add 7 endpoint methods to `ReportingController` — `agentLoad`, `unassigned`, `availableAgents`, `frtTrend`, `heatmap`, `recurringCustomers`, `reopened`; follow existing try/catch pattern with `parseDateTime`, `getScopeAgentId`, `getScopePhoneNumberId`; keep `#[Route]` attributes for docs
- [ ] 1.9 Add 7 route entries to `apps/api/config/routes.yaml` — `app_report_agent_load`, `app_report_unassigned`, `app_report_available_agents`, `app_report_frt_trend`, `app_report_heatmap`, `app_report_recurring`, `app_report_reopened`
- [ ] 1.10 Create migration `apps/api/migrations/Version20260809000001.php` — `CREATE INDEX idx_tickets_created_at ON tickets (created_at)` (idempotent)

## Phase 2: Seed Command

- [ ] 2.1 Create `apps/api/src/Command/SeedReportsCommand.php` — `app:seed-reports`; guard `APP_ENV === 'test'` AND DB name contains `inbiox_test`; truncate tickets/contacts/agents/ticket_events; insert 5 agents (varied max_active), 10 contacts, 50+ tickets (distributed over 90 days, varied hours), 5+ unassigned, 3+ recurring contacts, 2+ auto_reopened events, agents with schedules covering current UTC
- [ ] 2.2 Register command in `apps/api/config/services.yaml` if not auto-tagged

## Phase 3: Frontend API

- [ ] 3.1 Add response interfaces to `apps/web/src/app/features/reports/models/reports.models.ts` — `AgentLoadEntry`, `UnassignedResponse`, `AvailableAgentEntry`, `FrtTrendPoint`, `HeatmapCell`, `RecurringEntry`, `ReopenedEntry`
- [ ] 3.2 Add param interfaces to `apps/web/src/app/core/dto/reports.params.ts` — `AgentLoadParams`, `UnassignedParams`, `AvailableAgentsParams`, `FrtTrendParams`, `HeatmapParams`, `RecurringParams`, `ReopenedParams`
- [ ] 3.3 Add 7 methods to `apps/web/src/app/core/api/reports-api.service.ts` — `getAgentLoad`, `getUnassigned`, `getAvailableAgents`, `getFrtTrend`, `getHeatmap`, `getRecurringCustomers`, `getReopened`; use HttpClient GET with typed responses

## Phase 4: Frontend Dashboard

- [ ] 4.1 Create 7 standalone card components under `apps/web/src/app/features/reports/components/` — `agent-load-card/`, `unassigned-card/`, `available-agents-card/`, `frt-trend-card/`, `heatmap-card/` (7×24 grid), `recurring-card/` (table), `reopened-card/` (table); each: standalone, OnPush, presentational, `@Input() data` + `@Input() loading`
- [ ] 4.2 Restructure `apps/web/src/app/core/application/reports/get-reports-dashboard.use-case.ts` — replace single `forkJoin` with 3 sequential groups (4+4+4), per-call `catchError` returning typed defaults
- [ ] 4.3 Update `apps/web/src/app/features/reports/pages/reports-page/reports-page.component.{ts,html,scss}` — 3 grouped sections, 7 new `@Input()` signals, 7 new `loading` signals, wire new card components

## Phase 5: Testing

- [ ] 5.1 Create `apps/api/tests/Integration/ReportingV2Test.php` — `setUpBeforeClass` runs `app:seed-reports`; 7 repository-level tests asserting SQL correctness, workspace scoping, edge cases (empty, threshold, no-reopened); 7 HTTP smoke tests via Symfony test client asserting 200 + valid JSON shape
- [ ] 5.2 Verify all 12 endpoints (5 existing + 7 new) return 200 with seed data loaded

## Implementation Order

Phase 1 → 2 → 3 → 4 → 5 (strict dependency order). Backend must be functional before frontend consumes it. Seed command must exist before integration tests.
