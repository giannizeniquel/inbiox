# Tasks: Fix Reports Workspace Filtering

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 25–40 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | single-pr |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

## Phase 1: Controller — Wire Workspace Scope

- [ ] 1.1 In `apps/api/src/Controller/ReportingController.php` method `agents()` (~line 212): add `$phoneNumberId = $this->getScopePhoneNumberId();` and pass it as 4th argument to `$this->reportingService->getAgentPerformance($from, $to, $agentId, $phoneNumberId)`.

## Phase 2: Service — Accept and Forward Parameter

- [ ] 2.1 In `apps/api/src/Application/Service/ReportingService.php` method `getAgentPerformance()` (~line 98): add `?string $phoneNumberId = null` parameter to signature. Update PHPDoc. Forward `$phoneNumberId` as 4th argument to `$this->reportingRepository->getAgentPerformance()` (~line 102).

## Phase 3: Repository — Apply SQL Filter

- [ ] 3.1 In `apps/api/src/Repository/ReportingRepository.php` method `getAgentPerformance()` (~line 305): add `?string $phoneNumberId = null` parameter. When non-null, build `$workspaceJoinClause = ' AND t.phone_number_id = :phoneNumberId'` and append to LEFT JOIN ON clause (~line 325). Build `$workspaceSubClause = ' AND t2.phone_number_id = :phoneNumberId'` and append to `current_active_tickets` subquery (~line 322). Bind `$params['phoneNumberId'] = $phoneNumberId` when set. Follow exact pattern from `getFrtMetrics()` (lines 99–101).

## Phase 4: Tests

- [ ] 4.1 Unit test in `tests/Unit/Service/ReportingServiceTest.php`: mock `ReportingRepository`, call `getAgentPerformance()` with `$phoneNumberId = 'wns-001'`, assert repository receives 4th argument `'wns-001'`. Call with `null`, assert `null` forwarded.
- [ ] 4.2 Integration test in `tests/Integration/Sprint09AReportingTest.php` (or equivalent): seed tickets across 2 `phone_number_id` values. Assert AGENT with active workspace `wns-001` sees only tickets from that workspace. Assert SUPERVISOR with null scope sees all tickets (backward compatible). Assert agents with zero workspace tickets still appear with zeroed metrics (SC-3).

## Phase 5: Verification

- [ ] 5.1 Run full test suite (`php bin/phpunit`). Confirm all existing tests pass — especially FRT/RT/Counts/Volume report tests (regression check).
- [ ] 5.2 Manual smoke test: `GET /reports/agents` as AGENT with active workspace returns filtered metrics. `GET /reports/agents` as SUPERVISOR returns unfiltered metrics (unchanged behavior).
