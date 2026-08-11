# Design: Reporting v2 — Operational Metrics Expansion

## Technical Approach

Extend the existing reporting stack (Controller → Service → Repository) with 7 new endpoints and 7 new repository methods, following the exact pattern used by the 5 existing endpoints. Reuse `AgentAvailabilityService::listAvailableAgents()` for the available-agents endpoint. Add a single additive migration for `idx_tickets_created_at` to support heatmap performance. Build a `SeedReportsCommand` that refuses to run outside `APP_ENV=test`. On the frontend, restructure `GetReportsDashboardUseCase` to 3 sequential `forkJoin` groups (4 + 4 + 4) for 12-endpoint fan-out, with per-call error isolation. No new page; enhance the existing `ReportsPage` with one card component per new endpoint.

## Architecture Decisions

| # | Decision | Alternatives | Rationale |
|---|----------|--------------|-----------|
| 1 | **Reopened detection** uses `ticket_events` table filtered on `type = 'auto_reopened'` (the event already emitted by `AutoReopenRule` when a resolved ticket transitions back to `waiting`). | (a) Add `reopened_at` column to `tickets`. (b) Approximate via `status != resolved` + `resolved_at`. | (a) requires migration, out of scope per proposal. (b) is unreliable (any non-resolved status matches). `ticket_events` is the existing authoritative trail — no schema change, precise timestamps, already produced by the rule. |
| 2 | **Routes use attribute `#[Route(...)]`** on the existing `ReportingController`, not `routes.yaml`. | Add to `routes.yaml` per orchestrator prompt. | Existing `ReportingController` (and all other controllers) uses attributes. `routes.yaml` only holds webhook, Mercure, auth, and admin routes. Adding 7 entries would break the convention. |
| 3 | **Heatmap uses raw SQL** with `isPostgres` switch (matching `getTimeDiffExpression`). MySQL: `DAYOFWEEK(t.created_at)` + `HOUR(t.created_at)`. PostgreSQL: `EXTRACT(DOW FROM ...)` + `EXTRACT(HOUR FROM ...)`. | DQL via QueryBuilder. | DQL has no portable `DAYOFWEEK`/`HOUR` aggregate; raw SQL matches the pattern used in `getVolumeOverTime` and `getFrtMetrics`. |
| 4 | **Heatmap index migration** added (`CREATE INDEX idx_tickets_created_at ON tickets (created_at)`). | No index (rely on existing `idx_tickets_status` etc.). | Heatmap groups by `DATE(created_at) HOUR(created_at)` — a full scan on large tables. Existing `tickets` indexes (status, assigned_agent_id) do not cover `created_at`. Additive only; no data change. (Proposal has a small internal contradiction: "no schema changes" vs "add `created_at` index" — proceeding with index as explicit user directive in design prompt.) |
| 5 | **Available agents** endpoint wraps `AgentAvailabilityService::listAvailableAgents($now)` directly in the controller (no service-layer method needed). | New `ReportingService::getAvailableAgents()` delegating to the same call. | Adds no logic; just a thin pass-through. Keeps the service layer meaningful. |
| 6 | **Frontend batching**: 3 sequential `forkJoin` groups of 4 calls each, with per-call `catchError` returning a typed default. | (a) Single `forkJoin` of 12. (b) `combineLatest` chain. (c) `exhaustMap` with sequential. | (a) hammers the HTTP client; (b) doesn't isolate errors. 3 groups keeps concurrency bounded, isolates failures, and maps cleanly to "tier-1 KPIs / tier-2 detail / tier-3 operational" card groups. |
| 7 | **New tests** in `tests/Integration/ReportingV2Test.php` (repository + HTTP smoke), leaving `Sprint09AReportingTest.php` untouched. | Replace existing test file. | Smaller diff, isolates new coverage, avoids regression risk on the 5 existing endpoints. Spec already lists 14 new scenarios; old file holds the original 18. |
| 8 | **SeedReportsCommand** hard-fails unless `APP_ENV === 'test'` AND DB host is `inbiox_test` (defense in depth). | Check only `APP_ENV`. | Test env vars can be spoofed; checking the DB name prevents accidental production wipe if env is misconfigured. |

## Data Flow

```
[Browser]──HTTP──[Angular ReportsApiService]
                              │
                              ▼
[GetReportsDashboardUseCase] ── forkJoin (3 groups × 4 calls)
                              │  group A: frt, rt, counts, volume
                              │  group B: agents, agent-load, frt-trend, unassigned
                              │  group C: available-agents, heatmap, recurring, reopened
                              │  per-call catchError → typed default
                              ▼
[ReportsPage] ── renders 12 cards in 3 sections
                  ── each card: @Input() data + loading

[Browser]──HTTP──[ReportingController::agentLoad|unassigned|availableAgents|frtTrend|heatmap|recurring|reopened]
                              │   parseDateTime, getScopePhoneNumberId
                              ▼
[ReportingService] ── validateDateRange (skipped for endpoints without date range)
                              │
                              ▼
[ReportingRepository] ── raw SQL (Connection::fetchAllAssociative)
                              │   or call AgentAvailabilityService::listAvailableAgents(now)
                              ▼
[MySQL/PostgreSQL] ── tickets, contacts, ticket_events, agents, agent_shifts
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/api/src/Controller/ReportingController.php` | Modify | +7 endpoint methods using `#[Route('/api/reports/{name}', methods: ['GET'])]` |
| `apps/api/src/Application/Service/ReportingService.php` | Modify | +7 thin orchestration methods (4 require date-range, 3 don't) |
| `apps/api/src/Repository/ReportingRepository.php` | Modify | +7 SQL methods: `getAgentLoad`, `getUnassigned`, `getFrtTrend`, `getHeatmap`, `getRecurringCustomers`, `getReopened`; `getAvailableAgents` delegates to `AgentAvailabilityService` |
| `apps/api/src/Command/SeedReportsCommand.php` | New | `app:seed-reports` — guard `APP_ENV=test` + DB name, truncate + reinsert deterministic fixtures |
| `apps/api/migrations/Version20260809XXXXXX.php` | New | `CREATE INDEX idx_tickets_created_at ON tickets (created_at)` (idempotent) |
| `apps/api/tests/Integration/ReportingV2Test.php` | New | 7 new repository tests + 7 HTTP smoke tests; populates with `SeedReportsCommand` |
| `apps/web/src/app/core/api/reports-api.service.ts` | Modify | +7 methods: `getAgentLoad`, `getUnassigned`, `getAvailableAgents`, `getFrtTrend`, `getHeatmap`, `getRecurringCustomers`, `getReopened`; new param builder for non-date-range endpoints |
| `apps/web/src/app/core/application/reports/get-reports-dashboard.use-case.ts` | Modify | Replace single `forkJoin` with 3 sequential `forkJoin` groups; per-call error isolation with typed defaults |
| `apps/web/src/app/core/dto/reports.params.ts` | Modify | Add `AgentLoadParams`, `RecurringParams`, `HeatmapParams`, `ReopenedParams`, `UnassignedParams` |
| `apps/web/src/app/features/reports/pages/reports-page/reports-page.component.{ts,html,scss}` | Modify | 3 grouped sections (KPIs / volume / operational), 7 new `@Input()` signals, 7 new `loading` signals |
| `apps/web/src/app/features/reports/components/agent-load-card/` | New | Standalone, OnPush, presentational |
| `apps/web/src/app/features/reports/components/unassigned-card/` | New | Standalone, OnPush, presentational |
| `apps/web/src/app/features/reports/components/available-agents-card/` | New | Standalone, OnPush, presentational |
| `apps/web/src/app/features/reports/components/frt-trend-card/` | New | Standalone, OnPush, presentational (mini line chart) |
| `apps/web/src/app/features/reports/components/heatmap-card/` | New | Standalone, OnPush, 7×24 grid |
| `apps/web/src/app/features/reports/components/recurring-card/` | New | Standalone, OnPush, table |
| `apps/web/src/app/features/reports/components/reopened-card/` | New | Standalone, OnPush, table |

## Interfaces / Contracts

```php
// ReportingRepository — new signatures
public function getAgentLoad(?int $agentId, ?string $phoneNumberId): array;          // [{agent_id, name, active, max, load_pct}]
public function getUnassigned(?string $phoneNumberId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to, int $limit = 50): array;
public function getFrtTrend(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId): array;  // [{date, avg_seconds, count}]
public function getHeatmap(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId): array;  // [{dow, hour, count}]
public function getRecurringCustomers(?string $phoneNumberId, int $threshold, int $days = 90): array;
public function getReopened(\DateTimeImmutable $from, \DateTimeImmutable $to, ?string $phoneNumberId): array;
```

```ts
// ReportsApiService — new response DTOs
AgentLoadEntry { agentId: number; agentName: string; activeTickets: number; maxCapacity: number; loadPercent: number }
UnassignedResponse { count: number; tickets: UnassignedTicket[] }
AvailableAgentEntry { agentId: number; agentName: string; inShift: boolean; activeTickets: number }
FrtTrendPoint { date: string; avgFrtSeconds: number; ticketCount: number }
HeatmapCell { dayOfWeek: number; hour: number; count: number }
RecurringEntry { contactId: number; contactName: string; ticketCount: number; lastTicketAt: string }
ReopenedEntry { ticketId: number; contactName: string; resolvedAt: string; reopenedAt: string; currentStatus: string }
```

## Testing Strategy

| Layer | Scope | Approach |
|-------|-------|----------|
| Unit (PHP) | Date-range validation, env guard in `SeedReportsCommand` | PHPUnit — direct service call, expect `InvalidArgumentException` |
| Integration (PHP) | 7 new endpoints — repository SQL correctness, workspace scoping, edge cases (empty, threshold, no-reopened) | `tests/Integration/ReportingV2Test.php`, runs `app:seed-reports` once in `setUpBeforeClass`, then asserts on the 7 methods + 7 HTTP routes via Symfony test client |
| Unit (TS) | `GetReportsDashboardUseCase` error isolation, 3-group sequencing | Jasmine — mock `ReportsApiService` with one failure per group, assert group continues |
| E2E manual | Dashboard render with 12 cards, scroll/responsive | `ng serve` + seed command |

## Threat Matrix

| Boundary | Applicability | Reason |
|----------|---------------|--------|
| Documentation-like paths | N/A | No `.md`/`.sh` files added; design is a Markdown spec, not an executable. |
| Git repository selection | N/A | No `git` invocations. |
| Commit state | N/A | No automated commits. |
| Push state | N/A | No `git push`. |
| PR commands | N/A | No PR automation in this change. |

The `SeedReportsCommand` is a Symfony console command that mutates the local test database only — guarded by `APP_ENV === 'test'` AND DB-name check. No shell subprocess, no VCS interaction.

## Migration / Rollout

- **Migration**: Single additive `CREATE INDEX idx_tickets_created_at ON tickets (created_at)`. Reversible via `CREATE INDEX` drop. Idempotent in fresh DBs; safe in existing DBs (CONCURRENTLY on Postgres would be ideal, but a single `addSql` matches the existing migration style — see `Version20260613000001.php`).
- **Rollout**: Standard merge to `feature/reporting-v2` → `main`. No data backfill required; index is created on the fly. The 7 new endpoints become available immediately on deploy. The 5 existing endpoints are unchanged.
- **Rollback**: Revert the feature branch. Drop the index in a new migration (or leave it — harmless additive index).
- **Feature flags**: None. New endpoints are additive, no existing behavior changes.

## Open Questions

- [ ] **Reopened limit**: The spec returns "tickets that transitioned out of resolved" without a `limit`. The SQL has no `LIMIT`, so a year-long query with 10k reopens returns 10k rows. Recommend adding `?limit=100` (default 100, max 500). Decision needed in `sdd-tasks` — does the spec want pagination, or is the assumption "date range is always narrow enough"?
- [ ] **Recurring customers email field**: Spec response includes `email`, but the `Contact` entity has `name` and `phone`. Should `email` be optional and return `null` if absent? Verify in `apps/api/src/Domain/Contact/Contact.php` during `sdd-apply`; if `email` is not a field, drop it from the response shape.
- [ ] **Heatmap day numbering**: MySQL `DAYOFWEEK()` returns `1=Sunday, 7=Saturday`. PostgreSQL `EXTRACT(DOW)` returns `0=Sunday, 6=Saturday`. Spec says `dayOfWeek: 0-6`. Decision: normalize to ISO (`1=Monday, 7=Sunday`) on the backend to match the spec range. Confirm during `sdd-apply`.
- [ ] **FTR vs FRT naming**: Existing endpoint `/frt` (response `avg_frt_minutes`); spec uses `frt-trend` and `avgFrtSeconds`. Consistent. No action.
- [ ] **Reopened response date format**: Spec says `resolvedAt` and `reopenedAt`. Backend stores `DateTimeImmutable`; Symfony's `JsonResponse` serializes to ISO-8601. No action.
