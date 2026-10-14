# Backend cleanup (apps/api)

Objective: regain control of the backend. Make it minimal, with an honest architecture and written development rules, and add no new features.
Branch: one branch per phase (phase 0: `chore/limpieza-backend-fase-0`, from `test` at `b543593`). Delivery: one PR per phase; push, PR and merge are user decisions. Test runner: PHPUnit (see `AGENTS.md` § Testing).

## Specs

- **S1 — Order first, no new features.** User: "debemos retomar el control y hacer la app lo mas minimalista posible, con la arquitectura correcta, reglas claras de desarrollo, etc. No escribiria mas codigo ni agregaria mas features, la prioridad es ordenar este desastre. Necesito que armes un plan, tenemos que hacerlo por partes porque sino corresmos riesgo de volver a cometer los mismos errores."
  - Every phase preserves behavior. Product contradictions (C-1..C-20 in `docs/02_sistema-actual/08`) go on a separate track that needs user decisions.
  - Phase gate: the list of failing test names after the phase is identical to or a subset of the baseline.
- **S2 — Architecture by business module.** User: "vamos a organizar el codigo por modulo de negocio."
  - Agreed rules:
    - Doctrine entities are the domain model.
    - Controllers only handle HTTP.
    - Every write goes through a use case.
    - No interface without two real implementations.
    - A module may call another module's use cases but never touch its tables via SQL.
    - Routes are declared only with `#[Route]` attributes, loaded via `routing.controllers`. They are regenerated from `routes.yaml` in phase 2, and `debug:router` must be identical before and after (L7).
    - States are backed enums, not catalog tables (L7).
- **S3 — SQL rule.** User: "evitar SQL puro tipo string. ej: $sql='SELECT...'. SIEMPRE debemos tener un repository que ejecute funciones que utilicen metodos de doctrine".
  - The adjusted rule, accepted by the user ("si me cierra"):
    - Use the Doctrine QueryBuilder (ORM or DBAL) by default.
    - Native SQL is allowed only inside a repository: in a named method, with bound parameters, a comment explaining why DQL is not enough, and its own test.
    - SQL is forbidden in use cases, controllers and commands, and concatenating SQL fragments is forbidden.
  - Stored procedures and views were rejected.
- **S4 — Spanish code.** User: "todos los metodos, variables, interfaces, entidades, comentarios, etc. necesito que esten en español." and "entiendo los contratos del framework no se pueden cambiar, pero todo lo demas que podamos cambiar debemos hacerlo. La app no esta en produccion, esta en desarrollo, asi que renombrar tablas-columnas y perder datos no es un problema".
  - Naming rule: "negocio en español y técnico en inglés".
  - Glossary answers: "ticket, espacio de trabajo, jerga en inglés, disponibilidadManual".
  - The renaming happens in phase 5. The glossary is fixed in phase 0.
- **S5 — Glossary shared with the frontend.** The module names and the naming rule are the same in `apps/web`. The API JSON contract is redefined jointly in phase 5.
- **S6 — Safety net (phase 0).** Repair the tests that are broken by themselves, delete obsolete ones, and keep the tests that expose real bugs or code drift failing and documented. Record the baseline of failing test names.
- **S7 — Docs policy (assumption, not explicitly confirmed by the user).** Each phase fixes the docs it invalidates in the same PR. A full docs pass happens in phase 6, after the renaming.

## Tasks

| ID | Specs | Route | Status | Evidence |
|---|---|---|---|---|
| T1 | S2, S3, S4, S5 | inline | done | `0150477`; frontend structure + rules added in the T4 commit (L5) |
| T2 | S2, S3, S4 | inline | done | `0150477` |
| T3 | S6 | inline | done | `1afba20`. Fixed groups A–E and K, plus a leaking concurrency fixture; deleted groups I and J (3 tests). 46 → 6 stable failures |
| T4 | S6, S7 | inline | done | Baseline below; `00` and `AGENTS.md` updated (T4 commit) |

| T5 | S1 | inline | done | Phase 1, block 1: unreferenced classes and methods |
| T6 | S1 | inline | done | Phase 1, block 2: dead code exercised only by tests (tests removed or retargeted) |
| T7 | S1 | inline | done | Phase 1, block 3: inert `ActiveUserListener` and the unreachable `'supersecret'` JWT fallback |
| T8 | S1 | inline | done | Phase 1, block 4: obsolete root tooling and `bin/outbox-direct.php` |
| T9 | S1, S7 | inline | done | Phase 1: docs in `02_sistema-actual` updated for the deletions |
| T10 | S1 | inline | in progress | Phase 1 items that change a contract or the environment (see L9). Item 1 done (L11); items 2–6 pending user decision |

### Baseline of failing tests (phase 0, after `1afba20`)

There are 725 tests. The 6 stable failures are all real problems, kept red on purpose:

```
App\Tests\Integration\ReportingV2Test::testGetUnassignedReturnsTickets                    (F: real bug)
App\Tests\Integration\Sprint09BOutboxTest::test_outbox_event_persisted_after_inbound_message (G: drift)
App\Tests\Integration\Sprint09BSecurityTest::test_jwt_contains_assigned_ticket_topics      (G)
App\Tests\Integration\Sprint09BSecurityTest::test_jwt_denies_unassigned_tickets            (G)
App\Tests\Unit\Infrastructure\Storage\MediaValidatorTest::test_rejects_application_octet_stream (H: drift)
App\Tests\Unit\Infrastructure\Storage\MediaValidatorTest::test_rejects_exe_mime_type        (H)
```

Known flake: 0–2 random HTTP tests per full run get `401 {"message":"Invalid JWT Token"}`. It is not a regression. Root cause not investigated; it is a candidate for a later phase.

Later phases, each with its own branch and task list when it starts:

1. Delete dead code.
2. Move code to the module structure and remove single-implementation interfaces.
3. Enforce the layer rule: controllers only handle HTTP, a single outbox writer, a global exception listener.
4. Split the god methods, with characterization tests first: outbound, then inbound, then reporting.
5. Spanish renaming, a fresh migration baseline, and the joint API contract.
6. Docs pass.

## Log

- **L1 (2026-10-13), user request:** "si, debemos retomar el control y hacer la app lo mas minimalista posible, con la arquitectura correcta, reglas claras de desarrollo, etc. No escribiria mas codigo ni agregaria mas features, la prioridad es ordenar este desastre. Necesito que armes un plan, tenemos que hacerlo por partes porque sino corresmos riesgo de volver a cometer los mismos errores."
- **L2 (2026-10-13), analysis:** read-only audit of `apps/api/src` (Engram `inbiox/backend-architecture-audit`).
  - The architecture is a "hexagonal costume": the folder names are there, but the rules are not.
  - The outbound flow is split between `ProviderProxyController` and its use case.
  - Raw SQL appears in 23 files.
  - God methods reach ~239 lines.
  - The 94 `#[Route]` attributes are dead: verified that there is no attribute route loader.
- **L3 (2026-10-13), decisions:**
  - Simplify instead of completing hexagonal.
  - Organize by business module.
  - SQL rule (S3).
  - Spanish rule and glossary (S4).
  - Glossary moved from phase 5 to phase 0, so the frontend can reorganize in parallel on its own branch.
- **L4 (2026-10-13):** user: "si, arrancá con la fase 0". Branch `chore/limpieza-backend-fase-0` created from `test` (`b543593`).
- **L5 (2026-10-13), frontend structure:** the frontend agent proposed splitting `data-access` by backend module from `pages`/`ui` by screen, plus two rules: one-way dependencies, and UI state lives in the screen. User: "si, aceptala y escribilas en este PR". Written in `docs/arquitectura.md` § Frontend. The frontend works in a separate worktree.
- **L6 (2026-10-13), T3 evidence:**
  - Baseline run: 46 failures. The 45 triaged in doc 00, plus one flaky `401 Invalid JWT Token`.
  - After the repairs: 6 stable failures (F, G, H) plus 0–2 flaky 401s per run.
  - A first full run showed 111 failures. Cause: the repaired `Sprint09BConcurrencyTest` now reached assignment and left `assignments` rows that referenced its fixed-id agents, so other tests' `DELETE FROM agents` failed. Fixed with a `tearDown` cleanup and verified with 0 rows left.
  - RDD assess on `0150477`: medium, `under_budget` (no review due).
- **L7 (2026-10-13), user review of `docs/arquitectura.md`:** the user questioned enums vs a status catalog table ("EstadosTickets") and routes only in `routes.yaml` ("puede volverse un archivo muy extenso e inmantenible").
  - Enums are kept: states carry behavior (the state machine), and a table would add a second source of truth plus catalog data that the test DB, built from the mapping, does not seed.
  - Routes are switched to attributes as the single source: `routes.yaml` is already 466 lines with 98 routes, and Symfony 7.4 `resource: routing.controllers` loads every controller.
  - The existing 94 attributes are stale, so phase 1 does not touch them. Phase 2 regenerates them from the YAML and diffs `debug:router`.
  - User: "si, ajustalo con un commit más".
- **L8 (2026-10-13), phase 1 start:**
  - User: "ya revisé, pusheá y arrancá la fase 1". Pushed `chore/limpieza-backend-fase-0` (4 commits up to `4d6a972`).
  - Branch `chore/limpieza-backend-fase-1` stacked on it, because phase 0 is not merged into `test` yet.
  - Phase 1 gates: the failing-names baseline, plus `debug:router` identical to the 91-route snapshot. Deleting dead code must not add or remove routes.
- **L9 (2026-10-13), phase 1 evidence:**
  - A read-only explorer verified 23 candidates.
  - Parent spot check: the User, Workspace and Outbox repositories have 0 references, and their entities declare no `repositoryClass`.
  - After each block: `lint:container` OK and `debug:router` identical. After block 3, the `publisher` container was restarted and runs clean.
  - Finding: inactive users ARE rejected with a valid JWT, by `UserChecker::checkPostAuth()` (`AuthControllerTest::testDeactivatedUserGets403OnAuthenticatedRequest` passes). The explorer's "A5 gap" warning was wrong; the old docblock was what was misleading.
  - The `TicketVoter` docblock said VIEW/EDIT were "kept intentionally — do not remove". They were removed anyway under the minimalism rule (reserved for the future, no current use). This is flagged to the user.
  - Kept, with live wiring: `DefaultEventCollector`, `AddTagToTicketUseCase` (used by `KeywordTaggingRule`), `AutoAssignTicketUseCase::executeInTransaction()` (used by the listener).
  - `config/packages/test.yaml` loads in ALL environments: it supplies the `MERCURE_JWT_SECRET` parameter that `MercureJwtService` reads. Do not delete it.
  - `OutboxPublisherService` publishes to a hardcoded Mercure URL and ignores `MERCURE_URL`.
  - **Needs user decision (T10)**, because each changes a contract or the environment:
    1. `OutboxPublishCommand --status`: read and ignored, but passed by `docker-compose.yml` and the systemd unit.
    2. `inbiox-outbox-publisher.service`: an alternative deploy of the publisher; which one runs in each environment is unknown.
    3. `symfony/mercure-bundle`: configured, no service used (dependency removal).
    4. `league/flysystem-aws-s3-v3`: unused after `FlysystemFactory` was deleted (dependency removal).
    5. docker `redis` and `mailhog`: unused by the app; `php` depends on `redis`.
    6. env vars `MEDIA_ENABLED` and `JWT_COOKIE_NAME`: never read, but they live in `.env*` files, which Claude must not read.
- **L10 (2026-10-14), phase 1 review:** RDD assess high (`hot_path`); the user granted review with lenses risk + reliability. Result: approved, acknowledged (lineage `review-3f503f4a969f9e93`, authority burned). The reviewed boundary advances to `4f28857`. Phase 1 is still unpushed; push is the user's decision.
- **L11 (2026-10-14), T10 item 1:**
  - User rule: "siempre mirar dos veces antes de eliminar codigo que se considere muerto, ya paso en el front que casi borran codigo vivo pensando que era muerto." Added to `AGENTS.md` § Development Rules.
  - Re-verified: `--status` was only echoed. `publishPending()` takes no argument and hardcodes `status = 'pending'`. The only callers were `docker-compose.yml` and `inbiox-outbox-publisher.service`, both passing the default.
  - User: "si, agregala y borrá el --status". Removed the option and updated both callers and docs 02/08.
  - Checks: the command runs; `--status` now fails with "option does not exist"; the publisher was recreated and runs clean; `lint:container` OK; full suite 692 tests, exactly the 6 baseline failures, no flake.
