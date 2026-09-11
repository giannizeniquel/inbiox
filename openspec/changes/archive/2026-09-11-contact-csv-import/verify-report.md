# Verification Report: contact-csv-import

**Mode**: Full artifacts (proposal, specs, design, tasks) — all present, verified by direct source inspection and independent test execution.
**Verdict**: PASS

## Completeness

| Phase | Tasks | Status |
|---|---|---|
| 0 Dependency verification | 0.1–0.2 | [x] Done — league/csv 9.28.0 resolved, no conflict |
| 1 CreateContactUseCase hardening | 1.1–1.4 | [x] Done |
| 2 Import use case core | 2.1–2.3 | [x] Done |
| 3 Endpoint wiring | 3.1–3.3 | [x] Done |
| 4 Frontend | 4.1–4.3 | [x] Done |

15/15 tasks checked on disk (`openspec/changes/contact-csv-import/tasks.md`), consistent with code state and the apply-progress artifact (Engram #569).

## Test / Build Evidence (independently re-run, not trusted from apply's self-report)

- `docker compose exec php bin/phpunit` (full suite, working tree as-is): **Tests: 552, Assertions: 2211, Errors: 18, Failures: 40** → 58 failing/erroring, matches apply's reported baseline exactly.
- Filtered run of only the 4 new test files (`tests/Unit/CreateContactUseCaseTest.php`, `tests/Unit/ImportContactsFromCsvUseCaseTest.php`, `tests/Integration/ContactImportEmRecoveryTest.php`, `tests/Controller/ContactImportControllerTest.php`): **OK (22 tests, 73 assertions)** — 22/22 pass in isolation.
- Independent A/B re-verification (redone by this verify pass, not just trusted from apply): quarantined the same 4 new test files to a scratch dir, cleared `var/cache/test` rate-limiter pool, reran full suite → **Tests: 530, Assertions: 2131, Errors: 18, Failures: 40** → 58 failing/erroring — identical to the with-new-files run. This independently confirms the +1 total-test-count delta (530→552) is purely additive (22 new passing tests) and the 58 failing/erroring baseline is pre-existing, unrelated to this change. Quarantined files were restored to their original paths after the check; `git status` confirms no path changes.
- `docker compose exec web sh -lc "cd /app && npx ng build --configuration development"`: **exit 0**, "Application bundle generation complete." Only pre-existing Sass `@import` deprecation warnings and prior ticket-card NG8107/NG8113 warnings — no new errors.

## Spec Compliance Matrix

| Requirement | Scenario | Evidence | Status |
|---|---|---|---|
| CSV Upload Endpoint Contract | Valid CSV → 200 partial-success | `ContactImportControllerTest::testSupervisorGetsTwoHundredOnValidImport` | PASS |
| | Non-CSV → 400 `invalid_file_type` | `ContactImportControllerTest::testNonCsvFileIsRejected` (real PNG magic bytes) | PASS |
| | Missing file → 400 `file_missing` | `ContactImportControllerTest::testMissingFileFieldIsRejected` | PASS |
| CSV Column Contract | Missing phone header → 400, zero writes | `ImportContactsFromCsvUseCaseTest::testMissingPhoneHeaderRejectsWholeFile` (`execute()` never called) | PASS |
| File Size / Row Cap | 1001 rows → 400, zero inserts | `ImportContactsFromCsvUseCaseTest::testTooManyRowsRejectsBeforeAnyInsert` — cap enforced inside `materializeRows()`, thrown before `processRows()` (and thus before any `execute()` call) is ever invoked | PASS |
| Role Gating | Supervisor 200 / Agent 403 + still 201 on POST /contacts | `ContactImportControllerTest::testAgentGets403OnImportButStillCreatesContact` | PASS |
| Per-Row Processing / Partial Success | Mixed valid/invalid rows, correct counts + reasons | `ImportContactsFromCsvUseCaseTest::testMissingAndInvalidPhoneRowsAreReported`, `testDbDuplicatePhoneIsReportedAndDoesNotStopProcessing` | PASS |
| | >100 errors truncated | `testErrorsAreTruncatedPastOneHundred` | PASS |
| Duplicate Phone Handling | In-file dup (normalized) | `testInFileDuplicatePhoneIsSkippedOnSecondOccurrence` (`+543712345678` vs `+54 371 234 5678` — proves normalize-before-compare) | PASS |
| | DB dup via pre-check | `testDbDuplicatePhoneIsReportedAndDoesNotStopProcessing` | PASS |
| **Resilience to per-row uniqueness failures (the single most important correctness property per design.md)** | Row N raises `UniqueConstraintViolationException` mid-batch not caught by pre-check; later rows still import; EM not left closed | `ContactImportEmRecoveryTest::testConcurrentDuplicateMidBatchDoesNotCascade` — genuine race via a `prePersist` Doctrine listener that raw-SQL-inserts the colliding phone *after* the in-memory/DB pre-check already passed, immediately before Doctrine's own INSERT for that row. Asserts row 2 reported `duplicate_phone`, **rows 3–5 still imported** (`imported: 4`), final DB count is 5 (not 1), proving the EM was recovered and used for real subsequent work — not merely "no exception propagated." | PASS — real, not theater |
| Frontend Import Trigger | Visible only to supervisor | `contacts-page.component.html`: `@if (authStore.isSupervisor())` wraps the entire button (not `[disabled]`); `isSupervisor` is a `computed()` signal reading the authenticated user's actual roles from `AuthStore`, not a static flag or 403-reliant guard | PASS |
| contacts / API Contracts (MODIFIED) | Concurrent duplicate phone on create → deterministic error, EM stays usable | `CreateContactUseCaseTest::testConvertsUniqueConstraintViolationOnFlushIntoDuplicateError` (unit) + proven at integration level transitively by `ContactImportEmRecoveryTest` | PASS |

## Correctness — Point-by-Point (per verify task list)

1. **EM-recovery fix is real, not theater**: Confirmed. `ContactImportEmRecoveryTest` forces a genuine `UniqueConstraintViolationException` via a `prePersist` listener performing a raw SQL insert of the same phone immediately before Doctrine's own INSERT for that row — a true TOCTOU race the in-memory/DB pre-checks cannot see. The test asserts `imported: 4` (rows 1, 3, 4, 5) and a final DB row count of 5, not just "no crash." This is the strongest possible proof short of real concurrent HTTP requests.

2. **Three-layer duplicate safety**: All three layers verified present in `ImportContactsFromCsvUseCase.php` / `CreateContactUseCase.php`:
   - Layer 1 (in-file dedupe): `processRows()` calls `PhoneNormalizer::normalize($rawPhone)` and keys `$seenPhones` on the *normalized* value before ever calling `CreateContactUseCase::execute()` — confirmed by `testInFileDuplicatePhoneIsSkippedOnSecondOccurrence` using two differently-formatted strings for the same number.
   - Layer 2 (DB pre-check): `CreateContactUseCase::execute()` calls `$this->contactRepository->findOneByPhone($phone)` before persist.
   - Layer 3 (catch + isOpen + resetManager): `CreateContactUseCase` wraps `flush()` in `try/catch (UniqueConstraintViolationException)`; `ImportContactsFromCsvUseCase::processRows()` additionally checks `$this->entityManager->isOpen()` after every row and calls `$this->managerRegistry->resetManager()` if false.

3. **Row cap enforcement**: Confirmed 1001+ rows → reject with zero inserts. `materializeRows()` throws `ImportRejectedException('too_many_rows', ...)` as soon as the 1001st row is counted, still inside the materialization loop — `processRows()` (which is the only method that calls `CreateContactUseCase::execute()`) is never reached. `testTooManyRowsRejectsBeforeAnyInsert` asserts `execute()` is never called.

4. **CreateContactUseCase hardening**: Confirmed via diff. Before: bare `$this->entityManager->flush();` with no try/catch — an uncaught `UniqueConstraintViolationException` would propagate as an unhandled exception (500) and Doctrine would close the EntityManager. After: `flush()` wrapped in `try/catch (UniqueConstraintViolationException $e)`, rethrowing `InvalidArgumentException('Contact with this phone already exists')` — the same deterministic error the pre-check already produces, which the controller/import-loop both already know how to handle as a clean 400/per-row error. Verified with a real diff read, not just the apply report's claim.

5. **Role gating**: Confirmed. `ContactController::import()` carries `#[IsGranted('ROLE_SUPERVISOR')]` (method-level, overriding the class-level `#[IsGranted('ROLE_AGENT')]`). Route `app_contacts_import` correctly registered in `config/routes.yaml` (`POST /api/contacts/import` → `ContactController::import`), consistent with this codebase's routing source of truth being `routes.yaml` rather than attribute dispatch (verified by reading the file directly, not just trusting the claim).

6. **Frontend visibility**: Confirmed hidden, not disabled. `contacts-page.component.html` wraps the entire "Importar CSV" `<button>` in `@if (authStore.isSupervisor())` — an Angular structural directive that removes the element from the DOM entirely for non-supervisors, not a `[disabled]` binding. `isSupervisor` is a `computed()` signal in `AuthStore` reading `this.currentUser()?.roles.includes('ROLE_SUPERVISOR')` — driven by the actual authenticated session, not a static/mock value, and not solely relying on the backend's 403 as the only enforcement layer.

7. **league/csv usage and dependency diff**: Confirmed genuinely wired, not a redundant/unused import. `ImportContactsFromCsvUseCase` uses `League\Csv\Reader::createFromPath()`, `skipInputBOM()`, `setDelimiter()`, `setHeaderOffset(0)`, `getHeader()`, `getRecords()`, and `League\Csv\Info::getDelimiterStats()` for automatic `,`/`;` delimiter detection. `composer.json` diff adds `"league/csv": "^9"`; `composer.lock` diff adds the full `league/csv` 9.28.0 package entry with matching `require: {"php": "^8.1.2", "ext-filter": "*"}` — consistent with apply's claim and compatible with the project's `php >=8.2` constraint.

8. **Full PHPUnit suite + independent A/B**: Done — see Test/Build Evidence above. Both apply's self-reported numbers and this independent re-run (including a from-scratch quarantine-and-rerun, not merely reading apply's log) agree: 58 failing/erroring both with and without the 4 new files in the tree, 22/22 new tests green in isolation and in the full suite.

9. **Frontend build**: Confirmed, re-run independently: `npx ng build --configuration development` inside the `web` container exits 0 with "Application bundle generation complete," no new errors beyond pre-existing Sass `@import` deprecation warnings.

10. **Deviations from tasks.md — assessed**:
    - `ImportRejectedException` (not explicitly named in tasks.md): reasonable. It is a direct, necessary implementation of design.md's documented `execute()` interface contract (`@throws ImportRejectedException`) for whole-file rejection reasons. Not scope creep.
    - `config/routes.yaml` registration (not explicitly called out in tasks.md/design's File Changes table): reasonable and in fact necessary — this codebase's actual routing dispatch is YAML-based; attribute `#[Route]` decorations alone would not have made the endpoint reachable. Confirmed by inspecting the existing `routes.yaml` pattern for sibling `app_contacts_*` routes.
    - Real temp files (`tempnam()`) instead of `Reader::createFromString()` in unit tests: reasonable. The use case's public `execute(string $path, ...)` signature only accepts a file path, so `Reader::createFromString()` was never reachable through the public interface without adding a test-only seam. Equivalent coverage, no unjustified internal API surface added.

    None of these three deviations weaken or contradict any spec requirement or design decision; all are judged WARNING-free, documentation-only gaps in tasks.md rather than drift.

## Design Coherence

The three-layer EntityManager-survival strategy, the atomic-materialize-before-insert row cap, the shared `PhoneNormalizer`, and the `isOpen()`/`resetManager()` proxy-assumption (with its documented `aborted:true` fallback path, which was never needed because the real integration test passed) all match design.md exactly, including the specific rejected alternatives design called out (fresh EM per row, DBAL savepoints, catch-only without pre-check).

## Issues

**CRITICAL**: None.

**WARNING**: None.

**SUGGESTION**:
- The pre-existing test-suite flakiness (58 failing/erroring baseline, order-dependent DB state bleed across `KernelTestCase`/`WebTestCase` classes sharing one MySQL test DB, filesystem-backed rate-limiter cache persisting across separate `bin/phpunit` invocations) is out of scope for this change but worth a follow-up ticket given it currently masks real signal in CI.
- Nothing is committed yet (by design, per stated convention on this branch) — `git status` still shows the full working tree as modified/untracked. No action needed from verify; flagged for awareness before archive/PR.

## Final Verdict: **PASS**

All 15/15 tasks complete and match the code on disk. All spec requirements have a passing covering test. The single most load-bearing correctness property (EM survival across a real, not simulated-away, unique-constraint race) is proven by a genuine race-inducing integration test, not a trivial no-exception assertion. Independent re-execution of the full suite and a from-scratch A/B quarantine confirm apply's self-reported baseline numbers exactly. Frontend build and role-gating (both backend `#[IsGranted]` + route registration, and frontend template visibility) are confirmed by direct source reading, not by trusting the apply report. Recommended: proceed to `sdd-archive`.
