# Tasks: Bulk Contact Import from CSV

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | Backend ~935 ln / Frontend ~230 ln / Total ~1165 ln |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR1 Creation hardening → PR2 Import core → PR3 Endpoint wiring → PR4 Frontend |
| Delivery strategy | single-pr |
| Chain strategy | pending — user decision required |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | `PhoneNormalizer` + `CreateContactUseCase` EM-safety fix, greenfield unit tests (~265 ln) | PR1 | `docker compose exec php bin/phpunit apps/api/tests/Unit/CreateContactUseCaseTest.php` | N/A — pure unit test, no live scenario needed | Revert `PhoneNormalizer.php`, `CreateContactUseCase.php` diff, its test file — behavior-preserving bugfix, independently valuable |
| 2 | `league/csv` dep + `ImportContactsFromCsvUseCase` + DTO, unit tests (~440 ln) | PR2 | `docker compose exec php bin/phpunit apps/api/tests/Unit/ImportContactsFromCsvUseCaseTest.php` | N/A — use case has no HTTP caller yet | `composer remove league/csv`; delete use case/DTO/test files; nothing else references them yet |
| 3 | Controller endpoint, role gate, EM-recovery + functional integration tests (~230 ln) | PR3 | `docker compose exec php bin/phpunit apps/api/tests/Integration/ContactImportEmRecoveryTest.php apps/api/tests/Controller/ContactImportControllerTest.php` | Manual: `curl -F file=@roster.csv` against `inbiox_php` as ROLE_SUPERVISOR vs ROLE_AGENT | Revert controller action/route + 2 test files; use case stays inert but harmless |
| 4 | Dialog + service method + page wiring (~230 ln) | PR4 | N/A — design specifies no new frontend automated tests | Manual: `ng serve`, upload CSV as supervisor and agent, verify trigger visibility + summary | Revert 3 frontend files; backend endpoint unaffected |

## Phase 0: Dependency Verification (blocks PR2)

- [x] 0.1 Run `composer require league/csv:^9` in `apps/api`; confirm resolves cleanly against `php >=8.2`, `doctrine/orm ^3.6`, `symfony/* 7.4.*`; commit `composer.json`+`composer.lock`. **Done**: resolved cleanly to `league/csv 9.28.0`, no conflicts. `composer.json`/`composer.lock` updated in working tree (not committed — left for user review per convention).
- [x] 0.2 If a conflict surfaces, pin the compatible `league/csv` minor and record the constraint here. **N/A** — no conflict surfaced, default `^9` constraint kept.

## Phase 1: Contact Creation Hardening — PR1

- [x] 1.1 RED — `apps/api/tests/Unit/CreateContactUseCaseTest.php`: phone required, normalization, `<10`-digit rejection, duplicate pre-check, optional-field whitelist, `UniqueConstraintViolationException` → `InvalidArgumentException`.
- [x] 1.2 GREEN — Create `apps/api/src/Domain/Contact/PhoneNormalizer.php` (`normalize()`, throws on short phone).
- [x] 1.3 GREEN — `CreateContactUseCase.php`: delegate to `PhoneNormalizer`; wrap `flush()` in try/catch `UniqueConstraintViolationException`.
- [x] 1.4 REFACTOR — confirm baseline PHPUnit failing/erroring count (58/530, re-verify live) does not increase. **Live baseline measured**: 57/530 failing+erroring (18 errors + 39 failures) before any change; unchanged after Phase 1 (verified — only new test file affects results, 8/8 pass).

## Phase 2: CSV Import Use Case Core — PR2

- [x] 2.1 RED — `apps/api/tests/Unit/ImportContactsFromCsvUseCaseTest.php`: happy path; BOM+`;` file; missing `phone` header; empty file; 1001-row reject (zero `execute()` calls); in-file dup → `duplicate_in_file`; DB dup → `duplicate_phone`; invalid/missing phone rows; `>100` errors → `errorsTruncated`.
- [x] 2.2 GREEN — Create `apps/api/src/Application/DTO/ImportContactsResultDTO.php` (`toArray()` per response contract).
- [x] 2.3 GREEN — Create `apps/api/src/Application/UseCase/ImportContactsFromCsvUseCase.php`: size/MIME check, `Reader` (`skipInputBOM`, delimiter via `Info::getDelimiterStats`), header map, 1000-row cap before any insert, per-row `PhoneNormalizer` dedupe, delegate to `CreateContactUseCase`, `isOpen()`/`resetManager()` recovery. Also created `App\Exception\ImportRejectedException` (whole-file 400 reasons), not separately called out in tasks.md but required by design's documented interface contract.

## Phase 3: Import Endpoint Wiring — PR3

- [x] 3.1 RED — `apps/api/tests/Integration/ContactImportEmRecoveryTest.php`: row 2 throws `UniqueConstraintViolationException` (uncaught by pre-check), rows 3–5 still import, no 500. **Proof confirmed**: simulated a genuine TOCTOU race via a `prePersist` listener that raw-inserts a colliding phone right before Doctrine's own INSERT; the `resetManager()` proxy assumption held — test passes against the real EM/DB, no fallback (`aborted: true`) needed.
- [x] 3.2 RED — `apps/api/tests/Controller/ContactImportControllerTest.php`: `ROLE_AGENT` → 403 (and 201 still on `POST /contacts`); `ROLE_SUPERVISOR` → 200; non-CSV → 400; missing file → 400.
- [x] 3.3 GREEN — `ContactController.php`: `POST /contacts/import`, `#[IsGranted('ROLE_SUPERVISOR')]`, multipart extraction, reference-ID error path (existing pattern). Also registered `app_contacts_import` in `config/routes.yaml` (this codebase routes explicitly via `routes.yaml`, not solely attribute routing).

## Phase 4: Frontend Dialog + Wiring — PR4

- [x] 4.1 `contacts-api.service.ts`: add `importContacts(file: File)` via `FormData`, no manual `Content-Type`.
- [x] 4.2 Create `contact-import-dialog/` (standalone, signals): file input, submit, result summary, Spanish `reason` mapping.
- [x] 4.3 `contacts-page.component.ts/html`: `showImportDialog`, `onImportContacts()`, `onImportDialogClose()`; trigger rendered only for `ROLE_SUPERVISOR` (via existing `AuthStore.isSupervisor()` computed signal — same gating precedent used elsewhere in the app); reload contacts when `imported > 0`. Verified via `ng build` (development config) — compiles cleanly, no new errors (only pre-existing unrelated warnings).
