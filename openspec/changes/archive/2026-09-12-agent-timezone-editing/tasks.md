# Tasks: Agent Timezone Editing

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~240–280 gross (additions+deletions) — backend ~165, frontend ~75–115 |
| 400-line budget risk | Low |
| 800-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR (informational 2-unit split below if smaller review batches are preferred) |
| Delivery strategy | single-pr (session default) |
| Chain strategy | pending — not needed at this estimate |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low
800-line budget risk: Low

**Estimate breakdown** (verified against live file reads, not guesses):
- `AgentController.php`: role attribute (2) + `create()` guard/call (~7) + `update()` block (~6) + `serializeAgent()` (1) = ~16
- `AgentControllerTest.php`: 4 new admin-role-coverage tests (get/create/update/delete, ~8 lines each = ~32) + 8 new timezone CRUD/400 tests (~11–16 lines each = ~102) + section comment = ~137
- `AgentTest.php`: 1 new negative-path unit test = ~9
- Backend subtotal: ~162, well under both 400 and 800 alone
- Frontend: `admin-api.service.ts` (3) + `create-agent.use-case.ts` (3) + `update-agent.use-case.ts` (3) + `agents-page.component.ts` (~24) + `agents-page.component.html` (~9) = ~42 logic
- Frontend tests: `create-agent.use-case.spec.ts` + `update-agent.use-case.spec.ts` mock/test additions = ~30–34
- Frontend subtotal: ~72–76

This is genuinely a small change — single optional field pass-through, one attribute-line role fix, and a bounded set of tests dictated by the design's own testing-strategy table. Unlike this session's prior backend+frontend combos, per-file line counts were read directly from the live source (`AgentController.php`, `AgentControllerTest.php`, `AgentTest.php`, `admin-api.service.ts`, both use-cases + specs, `agents-page.component.{ts,html}`), not guessed. Single PR is recommended; no chain-strategy decision is needed before `sdd-apply`.

### Suggested Work Units (informational — not required at this size)

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | Backend: baseline capture, role-gating fix, timezone CRUD + 400 paths, admin-role coverage | Single PR (or PR 1 if split) | `docker compose exec php bin/phpunit --filter "AgentControllerTest\|AgentTest"` | `POST/PUT /api/admin/agents(/{id})` functional tests, supervisor + admin + agent roles | Revert `AgentController.php`, `AgentControllerTest.php`, `AgentTest.php`; no migration |
| 2 | Frontend: DTO/use-case threading + modal `<select>` + list column | Single PR (or PR 2 if split) | `ng test --include='**/{create,update}-agent.use-case.spec.ts'` | Manual smoke: create/edit agent via modal, confirm timezone round-trip | Revert `admin-api.service.ts`, both use-cases + specs, `agents-page.component.{ts,html}` |

## Phase 0: Baseline Capture (blocking — must complete before Phase 1)

- [x] 0.1 Run full suite `docker exec inbiox_php vendor/bin/phpunit --testdox`, zero changes staged. Record verbatim pass/fail of the 11 named `supervisor@test.local`-authenticated `AgentControllerTest` cases: `testListAgentsAllowedForSupervisor`, `testListAgentsReturnsPaginatedResults`, `testListAgentsIncludesUserEmail`, `testGetAgent`, `testGetAgentReturns404ForNonExistent`, `testCreateAgent`, `testCreateAgentRequiresName`, `testUpdateAgentName`, `testUpdateAgentActiveStatus`, `testDeleteAgentIsSoftDelete`, `testDeleteNonExistentAgentReturns404`, plus full-suite pass/fail totals. **Result**: all 11 failed (403 instead of expected 2xx/404). Full suite: 630 tests, 40 failures, 17 errors, 2 skipped.
- [x] 0.2 STOP and report if any of the 11 named cases are already passing — do not proceed to Phase 1 until confirmed all 11 are red (403) at baseline; if any are green, the root-cause analysis is contradicted and must be re-justified first. **Result**: none were passing; all 11 confirmed red as expected. Proceeded to Phase 1.

## Phase 1: Role-Gating Fix (sequential, depends on Phase 0 confirming all-red)

- [x] 1.1 `AgentController.php:21` — change `#[IsGranted('ROLE_ADMIN')]` → `#[IsGranted('ROLE_SUPERVISOR')]`. No timezone code in this step.
- [x] 1.2 Re-run full suite `--testdox`; diff against the Phase 0 baseline output — confirm the 11 named cases flipped red→green and no other test changed status in either direction. **Result**: all 11 flipped red→green. Full suite: 630 tests, 29 failures (-11), 16 errors (-1). Investigated one extra flip (`AgentLinkControllerTest::testLinkDeactivatedUserReturns400`) — confirmed via isolated run and `git stash` re-run that it is pre-existing order-dependent suite flakiness unrelated to this one-line attribute change (reproduced failing/passing inconsistently across reruns with identical code, alongside unrelated `AuthController`/`GetContactTimelineQuery` tests flipping too).

## Phase 2: Backend Timezone Feature — RED

- [x] 2.1 `AgentTest.php` — add `testSetTimezoneRejectsInvalidIana()` asserting `\InvalidArgumentException` with message `Invalid timezone: "Not/AZone"` (closes existing negative-path gap; setter is unchanged so this is expected green, not a red/green cycle). **Result**: green as expected.
- [x] 2.2 `AgentControllerTest.php` — add create-path cases: without `timezone` → stored `'UTC'`; explicit valid IANA → 201 echoes value; invalid IANA → 400; non-string `timezone` → 400. Expect fail (timezone unhandled/absent today). **Result**: all 4 RED as expected.
- [x] 2.3 `AgentControllerTest.php` — add update-path cases: valid IANA → 200 + persisted on re-`GET`; omitted → unchanged; invalid IANA → 400; non-string → 400. Expect fail. **Result**: all 4 RED as expected.
- [x] 2.4 `AgentControllerTest.php` — add ROLE_ADMIN 2xx coverage for `get`, `create`, `update`, `delete` (only `list` has admin coverage today — confirmed by reading the file). **Result**: all 4 already GREEN (role fix from Phase 1 already covers this).

## Phase 3: Backend Timezone Feature — GREEN

- [x] 3.1 `create()` — after the name guard, before `persist()`: `$timezone = $data['timezone'] ?? 'UTC';` + `is_string()` guard (400 on failure) + `$agent->setTimezone($timezone)`.
- [x] 3.2 `update()` — add `isset($data['timezone'])` block: `is_string()` guard (400) + `$agent->setTimezone($data['timezone'])`.
- [x] 3.3 `serializeAgent()` — add `'timezone' => $agent->getTimezone()` after `'active'`.
- [x] 3.4 Run full suite; confirm all Phase 2 tests pass and no regression vs. the post-Phase-1 result. **Result**: all 8 timezone RED tests now GREEN; only the pre-existing unrelated `WorkspaceAgentControllerTest::testAvailableAgentsReturned` error remains (present since the original Phase-0 baseline).

## Phase 4: Frontend DTO/UseCase Threading — RED then GREEN

- [x] 4.1 [RED] `create-agent.use-case.spec.ts` — add `timezone` to `mockAgent`; assert `timezone` reaches the create payload when provided.
- [x] 4.2 [RED] `update-agent.use-case.spec.ts` — add `timezone` to `mockAgent`; assert `timezone` passes through to the update payload.
- [x] 4.3 [GREEN] `admin-api.service.ts` — add `timezone: string` to `AdminAgentDTO`; `timezone?: string` to `CreateAgentPayload` and `UpdateAgentPayload`.
- [x] 4.4 [GREEN] `create-agent.use-case.ts` — add `timezone?: string` to `CreateAgentInput`, pass through in `execute()`.
- [x] 4.5 [GREEN] `update-agent.use-case.ts` — add `timezone: string` to `UpdateAgentInput`, pass through in `execute()`.
- [x] 4.6 Run `ng test --include='**/{create,update}-agent.use-case.spec.ts'`; confirm green. **Result**: 13/13 pass. Also fixed two incidental compile breaks in `list-agents.use-case.spec.ts` and `reactivate-agent.use-case.spec.ts` (mockAgent literals needed the now-required `timezone` field).

## Phase 5: Frontend UI Wiring

- [x] 5.1 `agents-page.component.ts` — add `FALLBACK_TIMEZONES` static array + `timezones`/`timezoneOptions` computed signals, guarded by `typeof Intl.supportedValuesOf === 'function'`.
- [x] 5.2 Add `timezone: ['UTC', [Validators.required]]` to `agentForm`; widen `hasAgentError` union to `'name' | 'active' | 'timezone'`.
- [x] 5.3 `openCreateForm()` reset — add `timezone: 'UTC'`; `openEditForm()` reset — add `timezone: agent.timezone`.
- [x] 5.4 `createAgent()`/`updateAgent()` — destructure `timezone` from `getRawValue()`, pass through to the use-case `execute()` call.
- [x] 5.5 `agents-page.component.html` — add timezone `<select>` form-group in the modal, after the name group, bound to `timezoneOptions()`.
- [x] 5.6 Add `<th>Timezone</th>` + `<td>{{ agent.timezone }}</td>`; change the empty-state row `colspan="6"` → `colspan="7"`.

## Phase 6: Integration Verification

- [x] 6.1 Run full backend suite one more time; confirm net non-regression vs. Phase 3.4 (only the expected new passing tests). **Result**: 643 tests (+13 new), 32 failures / 16 errors — all failures/errors are the same pre-existing flaky/unrelated cases identified in Phase 1/3, not new regressions. All 27 `AgentControllerTest` cases pass.
- [x] 6.2 Run full `ng test`; confirm no regressions beyond the new specs. **Result**: 244 tests, 58 pre-existing failures (ConversationStore/TicketStore — confirmed via `git stash` re-run to fail identically without our changes, unrelated `-203` IndexedDB environment error; ExportTicketsCsvUseCase/GetReportsDashboardUseCase — unrelated report use-cases). Zero failures in any agent/timezone-related spec.
- [ ] 6.3 Manual smoke: create and edit an agent with an explicit timezone via the modal; confirm round-trip in the list column and edit-modal pre-selection. **Not performed** — no browser/UI environment available to this executor. The equivalent server-side round-trip (create/update with explicit timezone, re-fetch, assert persisted value) is covered by `testCreateAgentWithExplicitValidTimezone` and `testUpdateAgentTimezone`, but the actual `<select>` UI click-through and edit-modal pre-selection needs a human or E2E check.
