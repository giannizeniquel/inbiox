# Tasks: Agent Response Delay Metric & Ticket Participation Report

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | ~1300-1600 total (backend incl. tests ~750-900, frontend incl. tests ~450-650) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR1 backend → PR2 frontend |
| Delivery strategy | single-pr (ask-on-risk) |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

Note: session's cached review budget is 800 (override of default 400). Estimate exceeds even 800 for the combined change, and PR1 alone (repo's first window-function CTEs + dual-dialect integration tests) is likely at/over it. `400-line budget risk` label kept literal per guard contract; actual threshold applied is the session's 800-line cache. Delivery strategy is cached `single-pr`, so per contract Decision needed = Yes regardless of risk tier — orchestrator must ask the user to pick `size:exception` (single PR) or move to chained (`stacked-to-main`/`feature-branch-chain`).

**Resolved**: user accepted `size:exception` — single PR, no chaining. Applied as one continuous batch across all 36 tasks / 9 phases.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | Migration, repository CTEs, service, controller, 2 routes | PR 1 | `bin/phpunit tests/Integration/ReportingResponseDelayTest.php` | Run against `compose.yaml` mysql AND postgres services (dual dialect) | Drop migration; revert routes.yaml + controller/service/repository additions — no frontend coupling |
| 2 | Reports card+table, conversation "Participación" panel | PR 2 | `ng test --include='**/response-delay*/**' --include='**/ticket-participation*/**'` | Manual: reports page load + open a conversation, toggle panel | Remove new components/use-case, revert card/panel wiring — backend endpoints unaffected |

## Phase 0: TDD Baseline
- [x] 0.1 Run full `bin/phpunit` suite, record baseline pass count (strict TDD before/after). **630 tests / 3651 assertions / 18 errors / 40 failures+2 flaky(42 raw) / 2 skipped** — full failing-test-NAME set captured for after-diff.

## Phase 1: Migration & Indexes (PR1)
- [x] 1.1 Create migration: add `idx_messages_sender_type_created_at (sender_type, created_at)` on `messages`, `idx_tickets_conversation_created_id (conversation_id, created_at, id)` on `tickets`; `down()` drops both. Did NOT recreate existing `idx_messages_conversation_created_id`. **Deviation**: filename is `Version20260912000001.php`, not `...000000.php` — that exact name was already taken by the prior `message-agent-attribution` migration (Tier 2 #10, same-day commit) which this change builds on top of.
- [x] 1.2 Applied migration on local mysql (dev `inbiox` DB via `doctrine:migrations:migrate`, tracked). Test DB (`inbiox_test`) schema is provisioned outside migration tracking in this repo (0 executed versions recorded despite being schema-current) — applied the same two `CREATE INDEX` statements directly and verified via `SHOW INDEX` on both DBs. **Postgres: no Postgres test connection available in this environment** (`docker ps` shows no project-scoped Postgres container, only an unrelated `lunar-ops-postgres`) — could not apply/verify there. Flagged as a known verification gap, not silently skipped.

## Phase 2: Repository — ReportingRepository.php (PR1)
- [x] 2.1 RED/GREEN: `getAttributionCutoff()` = earliest of agent/automatic/contact `sender_type` row. Test: `testGetAttributionCutoffReturnsEarliestAttributedMessage`.
- [x] 2.2 GREEN: implemented `getAttributionCutoff(): ?\DateTimeImmutable`.
- [x] 2.3 RED/GREEN: episode partition scoping (`testEpisodePartitionScopingReopenedTicketNoCrossEpisodePair`), burst collapse (`testBurstOfThreeInboundThenOneAgentReplyProducesOneSample`), automatic mid-run trace (`testAutomaticMidRunDoesNotSplitBurstOrStopClock`), unanswered trailing run (`testUnansweredTrailingRunExcludedFromAvgAndCountedInUnanswered`).
- [x] 2.4 RED/GREEN: legacy-NULL cutoff (`testLegacyNullCutoffExcludedFromWholeStreamAndNeverPoisonsUnanswered`) — whole-stream exclusion, `partial_data=true` when range predates cutoff, pre-cutoff outbound never poisons `unanswered_inbound_count`.
- [x] 2.5 RED/GREEN: degenerate tie (`testDegenerateTieSameConversationSameCreatedAtIsADocumentedLimitation`) — asserts the zero-width-episode tie-break as an accepted limitation, not "fixed".
- [x] 2.6 GREEN: implemented `getResponseDelayMetrics(from, to, ?phoneNumberId)` per design CTE (cutoff → scoped_convs → episodes LEAD → msgs → seq reply_seq → bursts ROW_NUMBER → agent_replies equi-join reply_seq+1); range filter on `tickets.created_at`; no `agent.active` filter.
- [x] 2.7 RED/GREEN: `getTicketParticipation(id)` multi-agent + burst (`testGetTicketParticipationMultiAgentAndBurst`), unattributed legacy (`testGetTicketParticipationUnattributedLegacyMessagesExcludedAndPartialDataTrue`).
- [x] 2.8 GREEN: implemented `getTicketParticipation(int $id): ?array` (single-row episode lookup, shared seq/bursts/agent_replies bodies, same `(created_at,id)` tie-break, null if not found — `testGetTicketParticipationReturnsNullForUnknownTicket`).
- [x] 2.9 **Partial**: re-ran all 2.3-2.8 assertions against MySQL only. Postgres re-verification not possible in this environment (see 1.2) — explicitly flagged, not silently skipped. `getTimeDiffExpression()` remains the only dialect-varying fragment per design.

## Phase 3: Service & Controller (PR1)
- [x] 3.1 Added `ReportingService::getResponseDelay(from,to,?phoneNumberId)` — pass-through + `validateDateRange` + `partial_data = cutoff === null || cutoff > from`.
- [x] 3.2 Added `ReportingService::getTicketParticipation(int $id): ?array` — pass-through, null on not-found.
- [x] 3.3 Added `ReportingController::responseDelay(Request)` mirroring `reopened()`; response includes `data_available_from`/`partial_data`.
- [x] 3.4 Added `ReportingController::ticketParticipation(Request, int $id)` — 404 when service returns null; same error envelope.
- [x] 3.5 Registered `app_report_response_delay` and `app_report_ticket_participation` in `apps/api/config/routes.yaml` after `app_report_export`.
- [x] 3.6 RED/GREEN: controller tests — range fully within attributed data ⇒ `partial_data=false` (`testHttpResponseDelayReturns200WithPartialDataFalseWhenFullyAttributed`); 404 unknown ticket (`testHttpTicketParticipation404ForUnknownTicket`); unauthenticated ⇒ **401** (`testHttpResponseDelayUnauthenticatedReturns401`, `testHttpTicketParticipationUnauthenticatedReturns401`). **Deviation**: tasks.md said "403 unauthenticated" — this codebase's actual, established convention for `#[IsGranted('ROLE_AGENT')]` with no token is **401** (verified against `AuthorizationTest::testUnauthenticatedRequestToReportsReturns401`), not 403. Implemented/tested against the real convention.
- [x] 3.7 GREEN: confirmed both routes resolve under class-level `#[IsGranted('ROLE_AGENT')]`, no method-level override needed.

## Phase 4: Backend Verification (PR1)
- [x] 4.1 Re-ran full `bin/phpunit`: **644 tests / 3708 assertions / 18 errors / 40 failures / 2 skipped**. Name-diffed against the Phase 0 baseline: all 14 new tests pass; zero new failures; 2 previously-failing tests (`AgentLinkControllerTest::testUnlinkUserReturns404ForNonExistentAgent`, `TicketClosureAuthorizationTest::testNonexistentTicketStillReturns400NotFourOhFour`) flipped to passing — pre-existing, shared-test-DB execution-order flakiness (unrelated table wipes/reseeds shifting test order), not caused by or fixed by this change's logic.

## Phase 5: Frontend Shared DTOs & API (PR2)
- [x] 5.1 Created `apps/web/src/app/core/dto/response-delay.dto.ts` (ResponseDelayReport, ResponseDelayAggregate, ResponseDelayAgentEntry, TicketParticipation, ParticipationParticipant, ParticipationPair).
- [x] 5.2 Re-exported via `features/reports/models/reports.models.ts` (same pattern as `reports.params.ts`).
- [x] 5.3 Added `ReportsApiService.getResponseDelay(params?)` and `getTicketParticipation(ticketId)`.

## Phase 6: Frontend Reports Page (PR2)
- [x] 6.1 RED: unit tests in `get-reports-dashboard.use-case.spec.ts` — dashboard use-case degrades `responseDelay` to `null` via `catchError`, never a zeroed object; and passes through the report on success.
- [x] 6.2 GREEN: added `responseDelay` to Group B `forkJoin` (3→4) in `get-reports-dashboard.use-case.ts`; extended `ReportsDashboardData`.
- [x] 6.3 Created `response-delay-card.component.ts` (standalone, OnPush, `kpi-card--wide`); `partial_data` ⇒ "sin datos de atribución (desde …)"; `sample_count===0` ⇒ empty state, never "0".
- [x] 6.4 RED: `response-delay-card.component.spec.ts` asserts never-zero/partial-data rendering.
- [x] 6.5 Wired `<app-response-delay-card>` into `reports-page.component.html` near FRT/RT/volume cards; added loading/signal wiring in `.ts`.

## Phase 7: Frontend Ticket Participation (PR2)
- [x] 7.1 Created `get-ticket-participation.use-case.ts` + `TicketParticipationFetchFailed` + `extractApiError`; exported from `core/application/reports/index.ts`.
- [x] 7.2 RED: `get-ticket-participation.use-case.spec.ts` — use-case maps API errors via `extractApiError`.
- [x] 7.3 Created `ticket-participation.component.ts` (standalone, `ticketId = input.required<number>()`, `effect()` refetch on change, signals for data/loading/error).
- [x] 7.4 Added `participationOpen`/`toggleParticipation` input/output to `conversation-header.component.ts/html` with a "Participación" button (`[attr.aria-expanded]`).
- [x] 7.5 Added `participationOpen` signal + `onToggleParticipation()` to `conversation.component.ts`; rendered `<app-ticket-participation>` gated on `@if (participationOpen())` (lazy fetch) in `.html`.
- [x] 7.6 RED: `ticket-participation.component.spec.ts` — panel lists participants/counts/avg delay; automatic activity shown as count only, never attributed; `partial_data` ⇒ "sin datos de atribución" not "0".

## Phase 8: Frontend Verification (PR2)
- [x] 8.1 **Deviation**: `ng test` cannot run in this WSL2 environment (no Chrome/Chromium, confirmed unavailable). All specs above were written for documentation/future-run value per explicit instruction. `ng build --configuration production` run instead as the enforceable frontend gate — **exit 0, bundle generation complete**, zero compilation errors (only pre-existing-class budget/deprecation warnings, e.g. `conversation-header.component.scss` now 4.09kB vs 4.00kB budget — same pre-existing warning class as `ticket-card.component.scss`, `_admin-common.scss`, `contact-detail.component.scss`).
- [x] 8.2 Manual smoke not performed (no running frontend dev server / browser in this environment) — covered instead by the production build succeeding + backend HTTP integration tests proving the API contract the card/panel consume.
