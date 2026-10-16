# Backend cleanup (apps/api)

Objective: regain control of the backend. Make it minimal, with an honest architecture and written development rules, and add no new features.
Branch: one branch per phase (phase 0: `chore/limpieza-backend-fase-0`, from `test` at `b543593`). Delivery: one PR per phase into the integration branch `test-refactor` (created from `test` at `b543593`; `test` keeps the working app and is not merged into); push, PR and merge are user decisions. Test runner: PHPUnit (see `AGENTS.md` § Testing).

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
    - A module may call another module's use cases, queries and repositories, but never writes SQL on its tables (adjusted in L31).
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
- **S8 — Phase 3, split by the user.** User: "si, y si crees necesario dividirlo en mas partes hazlo, esta es la parte mas critica." (answering whether rule 4 should be split: one listener only for the generic 500 now, with error formats and exception→status mapping unified with the new contract in phase 5).
  - 3a: characterization tests for uncovered actions that will be touched; no production change.
  - 3b: one `kernel.exception` listener for the generic 500 only; specific error arms and the 13 error shapes stay until phase 5.
  - 3c: SQL into repositories; locking reads keep their statement order and transaction.
  - 3d: controllers only do HTTP, module by module, Administracion first.
  - 3e: single outbox writer. Open decisions for 3e: outbox timing (today post-commit) and the `EventCollector` leak (events collected but never published).
  - Inventory (from the explorer): `odd/fase-3/violaciones.csv`, `errores.md`, `cobertura.md`.
- **S9 — Phase 4, outbound first.** User: "dale" (to "¿Arrancamos la fase 4 con la parte de salida?"). Plan line: "Split the god methods, with characterization tests first: outbound, then inbound, then reporting."
  - Behavior is preserved even where it looks wrong (S1). These quirks stay as they are and go to the contradiction track:
    - The message is committed before the Graph call; on a Graph failure it stays `sent` without a WAMID.
    - A missing ticket on `send` gives a 500.
    - Template sends skip the outbound use case.
  - 4a-0: characterization tests for `/api/provider/send`, `template/send`, `media/upload`, `media/{id}` and the `HandleOutboundMessageUseCase` branches not yet covered, with Graph stubbed via `container->set(GraphClient::class, ...)`.
  - 4a-1: split `HandleOutboundMessageUseCase::executeTransactionWithRetry` (192 lines) into private steps; no new classes without a concrete reason.
  - 4a-2: `send` business logic (media metadata, recipient phone, Graph dispatch, WAMID mark) into a use case; the controller keeps parsing, `isGranted`, agent resolution and the exception→JSON mapping, with byte-identical bodies.
  - 4a-3: `sendTemplate` into a use case, sharing the recipient phone resolution with 4a-2.
  - 4a-4: `uploadMedia` into a use case; `downloadMedia` stays a thin proxy.
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
| T10 | S1 | inline | done | Phase 1 items that change a contract or the environment (see L9): L11–L16 |
| T11 | S2 | inline | done | Phase 2: routes regenerated as `#[Route]` attributes from the YAML; `routes.yaml` = `routing.controllers` (L19) |
| T12 | S2 | inline | done | Phase 2: removed 8 single-implementation interfaces (7 repository interfaces + `EventCollector`) (L20) |
| T13 | S2 | inline | done | Phase 2: moved 162 classes into 11 modules in one commit, plus the event_type migration (L21) |
| T14 | S2, S7 | inline | done | Phase 2: docs paths and line refs updated after the moves (L22) |
| T15 | S8 | delegated (context backstop) | done | 3a: 82 characterization tests in `tests/Characterization/` (L25) |
| T16 | S8 | delegated (context backstop) | done | 3b: `InternalServerErrorListener`, 71 catch-alls removed (L26) |
| T17 | S8, S3 | delegated (context backstop) | done | 3c-1 (L28) and 3c-2 (L29) |
| T18 | S8 | inline | done | 3d thin controllers, per module. 3d-1 Administracion (L32), 3d-2 `MeController` split (L33), 3d-3 small controllers (L34), 3d-4 Ticket and Asignacion (L35), 3d-5 Conversacion (L36); independent verifier PASS (L37) |
| T19 | S8 | inline | done | 3e single outbox writer: `OutboxWriter` (`a286b03`), decisions L40/L41, implementation L42 |
| T20 | S9 | inline | done | 4a-0 outbound characterization tests (`364d163`, L47) |
| T21 | S9 | inline | done | 4a-1 split `executeTransactionWithRetry` (`187360a`, L47) |
| T22 | S9 | inline | done | 4a-2 `send` into a use case (`8d9d0fb`, L48) |
| T23 | S9 | inline | done | 4a-3 `sendTemplate` into a use case (`dc30fac`, L49) |
| T24 | S9 | inline | pending | 4a-4 `uploadMedia` into a use case |

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
- **L12 (2026-10-14), T10 item 2:** the unit is installed on the dev machine but disabled and inactive; nothing in the repo references it except docs. User: "no, solo docker. borralo". Deleted `inbiox-outbox-publisher.service` and updated docs README/02/08. P-9 was rewritten, not removed, because the publisher still reads without row locks. The user must remove the installed copy (`/etc/systemd/system/`), which needs sudo.
- **L13 (2026-10-14), T10 item 3:** the user asked "pero mercure no lo usamos para notificaciones en tiempo real y SSE?". Answer: the Mercure hub (docker `mercure`) stays; only the PHP bundle goes. The publisher POSTs with `curl`, and `MercureJwtService` signs with `lcobucci/jwt`. User: "si, sacalo".
  - `composer remove symfony/mercure-bundle` also removed `symfony/mercure`, `symfony/web-link`, `psr/link`, `symfony/http-client` and its contracts. `cache:clear` then failed: `GraphClient` needs `HttpClientInterface`, which only arrived transitively. Fixed by requiring `symfony/http-client:7.4.*` explicitly. This is the case the "check twice" rule warns about.
  - Flex also unconfigured the recipe: it deleted `config/packages/mercure.yaml`, removed the bundle from `bundles.php` (reformatted), and removed the Mercure blocks from `apps/api/compose.yaml` and `compose.override.yaml`. It also edited `apps/api/.env` (gitignored, mtime changed; not read by Claude).
  - Checks: `lint:container` OK. A real outbox event (`ticket.read`, test row deleted afterwards) reached the hub with HTTP 200 and status `published`. Full suite: 692 tests, exactly the 6 baseline failures.
- **L14 (2026-10-14), T10 item 4:** re-verified that nothing in src/config/tests uses Flysystem (all `League\` hits are `league/csv`). `aws/aws-sdk-php` is required directly, not only through Flysystem. User: "ok". `composer remove league/flysystem-aws-s3-v3` removed it plus `league/flysystem`, `league/flysystem-local` and `league/mime-type-detection`. There was no recipe, so no `.env` was touched. Checks: `lint:container` OK; full suite 692 tests, exactly the 6 baseline failures.
- **L15 (2026-10-14), T10 item 5:** the user asked "redis estaba pensado para cache y mailer para enviar mails eventualmente aunque ahora no lo hagamos, conviene quitarlos y agregarlos de nuevo cuando realmente los usemos?". Recommendation: remove them under the minimalism rule; re-adding is cheap, and the container alone is not the real cost. The intent is recorded in the docs as planned. User: "si, avanzá".
  - Re-verified: no Redis usage (only the commented recipe lines in `cache.yaml`); no mailer package or config.
  - Removed both services and the `php` → `redis` dependency from `docker-compose.yml`, and the commented Redis block from `cache.yaml`. Updated `AGENTS.md` and docs 02/08 to say Redis (cache) and a mailer are planned.
  - `docker compose up -d --remove-orphans` removed both containers; no others changed.
  - Checks: `docker compose config` OK; `lint:container` OK; full suite 692 tests, exactly the 6 baseline failures.
- **L16 (2026-10-14), T10 item 6:** re-verified that no code or config reads `MEDIA_ENABLED` or `JWT_COOKIE_NAME`. The refresh cookie name is hardcoded as `token_parameter_name: inbiox_refresh` in `gesdinet_jwt_refresh_token.yaml`, so the `api-contracts.md` row saying it was required was wrong. Claude must not read `.env*`, so the user removed the lines by hand (`.env` lines 18 and 30, `.env.example` 32, `.env.test` 10); a first `sd` attempt matched nothing, for an unknown reason. Docs 02/08/api-contracts updated. Checks: `lint:container` OK; full suite 692 tests, exactly the 6 baseline failures.
- **L17 (2026-10-14), integration branch:** user: "no vamos a mergear a test, ahi vive la app funcionando (con errores pero anda). Podemos crear una rama nueva test-refactor o algo asi a donde ir mergeando lo que estamos haciendo". Created and pushed `test-refactor` from `origin/test` (`b543593`). Phase PRs target it in order (0, then 1); the frontend phases also target it. If `test` gets fixes, merge `test` into `test-refactor`.
- **L18 (2026-10-14), missed caller of `--status` (T10 item 1):** the frontend's smoke baseline (`32d97cb`) mentioned a host crontab running `app:outbox:publish`. The user's crontab had `* * * * * docker exec inbiox_php php /var/www/html/bin/console app:outbox:publish --status=pending 2>/dev/null`. Since `e04c3a4` it failed every minute, silently because of `2>/dev/null`. No impact, because the docker `publisher` runs every 2 s, but it was a third publisher. Root cause: the reference search covered the repo, docker and systemd, but not host crontabs. User: "si, borrala y agregá lo de crontab a la regla". Removed the line (it was the only one; backup in `/tmp/claude-1000/crontab.bak`), and the `AGENTS.md` rule now lists host crontabs.
- **L19 (2026-10-14), phase 2 start:** user: "si, arrancá la fase 2". Branch `chore/limpieza-backend-fase-2` stacked on `97c0eec`. The user asked whether the frontend should wait; the answer was no, because phase 2 does not change the API contract.
  - **T11 (routes):** a script turned the `debug:router` dump (YAML order) into one `#[Route]` per action: 90 routes in 24 controllers. It deleted the stale attributes and switched the `Annotation` import to `Attribute`. The stale `requirements` on `TicketEventsController` was not carried over, because the YAML did not have it.
  - Checks: `debug:router` gives the same 91 names, each with identical content (path, regex, methods, requirements, defaults, options). Only the listing order changed. A route-match probe (a concrete URL per route and method, 92 probes) resolves every URL to the same route before and after, with no shadowing in either. `lint:container` OK. Full suite: 692 tests, exactly the 6 baseline failures.
  - **Explorer map** (`f2-map.csv`, session scratchpad): 171 files, 38 with debatable placement. Blockers it found:
    1. `outbox_events.event_type` stores event FQCNs, and `EventTypeMapper` rejects unknown names, so moving event classes breaks stored rows (pending retry and resync).
    2. `RulePass` registers rules by hardcoded class-name strings.
    3. The Doctrine mapping only scans `src/Domain`, with prefix `App\Domain`.
    4. The service config hardcodes FQCNs (`services.yaml`, `services_test.yaml`, `packages/test.yaml`, `security.yaml`, `gesdinet_jwt_refresh_token.yaml`, `bin/outbox-publish.php`).
  - Single-implementation interfaces: Assignment, Contact, Conversation, Message, Tag, Ticket and RuleExecutionLog repository interfaces, plus `EventCollector`. The only test doubles are PHPUnit mocks, which also work on concrete classes.
- **L20 (2026-10-14), T12:** removed the 7 repository interfaces and `EventCollector`. Type hints now use the concrete repositories; `DefaultEventCollector` became `EventCollector` and is no longer `final`, so the existing PHPUnit mocks still work. Gesdinet's `RefreshTokenRepositoryInterface` is a framework contract and stays. User decision on the event blocker: "si, vamos con la migración" (rewrite `outbox_events.event_type` when the event classes move). Checks: `php -l` on every touched file; `lint:container` OK; full suite 692 tests, exactly the 6 baseline failures.
- **L21 (2026-10-14), T13 (module move):** a script did the move in one commit instead of one per module, because the cross-references only compile once every class is in place.
  - Script steps: map old→new FQCN from the explorer CSV. Add an explicit `use` wherever a class referenced a same-namespace neighbour by its short name and the two end up apart (26 imports). Rewrite every plain and double-escaped FQCN in src, tests, config and bin (193 files). `git mv` 162 files and fix their `namespace` lines. Drop imports that became same-namespace, and delete the emptied folders (including `Controller/`, `Entity/` and `Repository/`, which only held `.gitignore`).
  - Placement changes vs the explorer map: `StateConflictException` goes to `Compartido`, because several modules use it. All `Controller/Admin/*` controllers go to `Administracion/Controller`.
  - Manual fix: `InvalidTransitionException` is declared inside `TicketStateMachine.php`, so its two references were rewritten by hand.
  - Doctrine mapping changed to `dir: src`, `prefix: App`.
  - Migration `Version20261014000000` rewrites `outbox_events.event_type` and `payload.type` (old→new event FQCN), with bound parameters and a reversible `down()`. It was executed alone on the dev DB, because that DB already had an unrelated problem: `Version20260912000002` is pending but its `teams` table exists. 309 rows rewritten; down→up round trip verified.
  - Checks:
    - `lint:container` OK; `doctrine:mapping:info` finds 19 entities.
    - `schema:validate` reports `TicketTag` inverse sides (`ticketTags`) missing. This is pre-existing: the same `inversedBy` was there at HEAD.
    - `debug:router`: same 91 routes, only the controller FQCNs changed; route-match probe identical.
    - PHPStan level 0 (docker image, not a project dependency): the same 29 messages before and after. They are pre-existing: Validator constraint attributes whose package is not installed, so those validations never run, plus `AsService`.
    - `RulePass` still registers both rules (2× `addRule`).
    - A live outbox event with the new class name was published with HTTP 200; the test row was deleted.
    - Full suite: 692 tests, exactly the 6 baseline failures.
- **L22 (2026-10-14), T14 (docs):** a script rewrote moved paths in `02_sistema-actual`, `AGENTS.md` and `arquitectura.md` (174 rewrites). It shifted 97 `File.php:line` refs from the phase-2 base (`97c0eec`) to HEAD, using a per-file line diff. 5 ambiguous `Admin/*Controller` refs and 6 bare `Admin/` mentions were fixed by hand. The doc 02 folder table was rewritten as a module table. Spot check: `HandleInboundMessageUseCase.php:381-384` still points at the auto-assignment dispatch. Remaining old-folder mentions are struck-through deleted files only.
- **L23 (2026-10-14), phase 2 review:** the full range `4f28857..a73adc1` was refused with `lens_context_budget_exceeded` (442 files). User: "si, vamos así". It was split into three candidates, each reviewed in its own temporary worktree:
  - T10 (`4f28857..97c0eec`): risk + reliability.
  - Routes (`97c0eec..b971b31`): risk + reliability.
  - Interfaces (`b971b31..e2d847f`): reliability + readability.
  - Outcome: all three approved and acknowledged (authority burned), with 0 blocking findings. Readability advisories: R2-001 (the B4 doc row was stale and the 7+1 vs 8 counts confusing) is fixed here; R2-002 (import order in `AddTagToTicketUseCase`) is not applied, because the project has no import-order rule or formatter.
  - The module move `3f70628` and the docs commit `a73adc1` are not reviewed: the move is too large for the reviewer, so it relies on the mechanical checks in L21. The temporary worktrees were removed.
- **L24 (2026-10-14), phase 3 start:** user: "ok, arrancá la fase 3". Branch `chore/limpieza-backend-fase-3` from `db54c40`. A read-only explorer found 389 violations: rule 1: 95, rule 2: 56, rule 3: 12 outbox writers, rule 4: 174, rule 5: 52. The worst module is Administracion (120). Risks:
  - There is no exception listener and there are 13 error shapes.
  - Every use case writes the outbox after commit, in its own flush, with payload casing that differs per use case.
  - `EventCollector` leaks events.
  - There are raw `FOR UPDATE` reads, nested transactions, and repositories that flush inside `save()`.
  - Many actions have no tests.

  Split decided with the user (S8).
- **L25 (2026-10-14), T15 (3a):** a bounded writer (route: delegated, context-backstop reason; edit surface `tests/Characterization/**`) wrote 82 characterization tests in 9 files.
  - **Covered:** all `app_admin_workspaces_*`, `app_me_workspace(s)` and `_set`, inbox detail and messages, `mark_read` (exact outbox row), auto-assign and assignment history, contacts detail/update/delete, the 4 realtime and report routes, events resync, and both 500 envelopes (S1 through stubbed collaborators, S2 on transition/resolve/close).
  - **Skipped:** debug routes, the webhook (HMAC), and media upload/download/url (they need S3 and Graph doubles). The S1 500 is not pinned for EM-only controllers.
  - **Likely bugs pinned as-is**, marked `// Characterization`:
    - ticket detail has no ownership or workspace scope;
    - the detail response has hardcoded null `owner.name` and `closed_at`;
    - messages of an unknown ticket return 200;
    - auto-assign has no scope, and its `InvalidArgumentException` becomes a 500;
    - the event_type format differs between writers;
    - the admin `me/workspaces` returns fixed `agent_count` and `has_pending` values;
    - `PUT me/workspace` creates an Agent row;
    - an unknown contact gives 400 on update but 404 on delete;
    - resync filters by `aggregate_id` only, has an unreachable header branch, and `verifyToken` skips the expiry check;
    - resolve and close return a 500 on an invalid transition.
  - **Parent spot check:** `phpunit tests/Characterization` gives OK (82 tests, 485 assertions). The full suite has 774 tests and exactly the 6 baseline failures.
- **L26 (2026-10-14), T16 (3b):** a bounded writer added `Compartido/EventListener/InternalServerErrorListener`. It acts only on `/api` paths and only on throwables that are neither HTTP nor security exceptions. It returns S1 `{"error":"Internal server error","reference":<16 hex>}` and logs `Unhandled exception` with the reference and the trace. The writer removed 71 controller catch-alls in 15 controllers (`git diff -w`: +6/−639). Kept:
  - TicketWorkflow's audit swallow;
  - ProviderProxy's storage swallow;
  - the 3 Webhook catches (it must keep returning 200);
  - the 4 Debug catches;
  - every specific arm, and every catch in use cases and services.
  - **Intended visible changes, approved by the user as the "500 genérico" unification:** S2 `"Internal error"` becomes S1, and uncaught non-HTTP exceptions on `/api` return S1 instead of Symfony's problem+json, which leaked the exception message. Per-action log messages were replaced by one log format.
  - **Tests:** 4 new listener tests. They pin the 404, 401 and 403 bodies as unchanged, and the forced uncaught exception now returns S1 (RED before the listener). The 3 S2 characterization tests now assert S1.
  - **Parent checks:** `lint:container` OK; full suite 778 tests, exactly the 6 baseline failures.
- **L27 (2026-10-14), shared smoke (guide 09):** the frontend agent ran it with front `e47b12e`+`d85ccbe` against back `3f2b83f`. P-01..P-24 match the phase-0 baseline. P-07 got further (upload reached Meta's 502), which is not a regression. Findings, all pre-existing and not caused by the cleanup:
  1. Resolving a `new` or closed ticket gives a 500. It was the same 500 before, only with the S2 text; pinned in 3a.
  2. `/assign` without `assignedBy` gives a 500: an uninitialized typed property in `AssignTicketRequest`, because `symfony/validator` is not installed.
  3. `GET /api/tickets/{id}` has `owner.name: null` (hardcoded in `GetTicketDetailQuery`), so "Asignado:" is empty after a reload.
  4. Unknown URLs give an HTML 404 to browsers (Symfony's default; the 3b listener leaves HTTP exceptions alone). Error formats are deferred to phase 5.
  - Smoke data left in the dev DB: tickets 468–480, `Smoke2 …` contacts, team 2, workspace 4, agents 13–14, user 12. Overrides of agents 1–3 were cleared, and message 219's `created_at` was backdated.
- **L28 (2026-10-14), T17 3c-1:** a bounded writer moved the SQL in use cases, services and commands into named repository methods.
  - **Native SQL** (bound params, a why-comment, own test): `TicketRepository::findRowByIdForUpdate`, `AgentRepository::lockRowsForUpdate` and `ReportingRepository::truncateSeedTables`.
  - **DBAL QueryBuilder:** `updateStatusAfterTransition`, `markClosed`, `markReopened`, `findLatestEventType`, `markFirstResponse`, `findPhoneNumberId`, `findIdsWithExpiredWindow`, `TagRepository::isAttachedToTicket`, and the recreated `Compartido/Repository/OutboxEventRepository` (7 methods).
  - The `ReportingRepository` agents `IN` list is now bound. 22 new tests in `tests/Integration/Repository/`, two of which prove the row lock holds with `NOWAIT` from a second connection.
  - **Parent fix:** a characterization mock of `TicketRepository` now forwards `findRowByIdForUpdate` to `new TicketRepository(ManagerRegistry)`. The pinned behavior is unchanged (`InvalidArgumentException` returns the generic 500, with no assignment).
  - **Independent verifier** (high risk: locking and writes): PASS on statement equivalence, transactions/locking, wiring, scope and S3, with no blockers. It captured the SQL each new method renders, inside a rolled-back transaction, and it matches the old statements. Full suite: 800 tests, exactly the 6 baseline failures.
  - **Advisories, for 3c-2:**
    1. `InvalidTransitionException` is declared inside `TicketStateMachine.php`, so a test that builds it first fails when run alone.
    2. The existing native `ReportingRepository::getTicketParticipation` has no why-comment and no test.
    3. `countActiveForAgent` has no test of its own.
- **L29 (2026-10-14), T17 3c-2:** a bounded writer moved the read-side SQL out of `src/*/Query/*` and into repository methods.
  - **DBAL QueryBuilder:**
    - `TicketRepository`: `findListPage`, `countList`, `countUnreadInboundByConversation`, `findDetailRow`, `findConversationId`, `findExportChunk`, `findLatestOpenTicketIdsByContact`.
    - `MessageRepository`: page asc/desc.
    - `ContactRepository`: list, count and detail.
    - `TagRepository::findNamesByTicketIds`, `AgentRepository::findByIds`.
  - **Native:** `ContactRepository::findTimelineRows` (UNION ALL in a derived table). Its SQL is now one fixed string with `(:p IS NULL OR …)` and bound type lists, where it used to concatenate fragments.
  - `TicketFilterBuilder` now returns a `Ticket/Repository/TicketFilters` value object (same normalization), and the private `TicketRepository::applyListFilters` applies it in the old clause order. `InvalidTransitionException` moved into its own file (same FQCN).
  - `getTicketParticipation` got its why-comment. It already had 5 direct tests in `ReportingResponseDelayTest`, so the L28 "no test" advisory was wrong. `countActiveForAgent` got a test.
  - **Writer's proof:** 61 old-vs-new cases with 0 result differences; 29 new repository tests.
  - **Independent verifier:** PASS, no blockers. It ran the old classes from `e1425d3` under a renamed namespace next to the new services in one rolled-back transaction, plus edge rows (timestamp ties, an out-of-allow-list event, an unknown source user, a phone with `+`, 1001 export tickets). 9,597 cases, 0 differences, including identical exceptions, the export keyset at 499/500/501/1000/1001 rows, and the timeline `before` equal to a row timestamp. Full suite: 826 tests, exactly the 6 baseline failures.
  - **Advisories:**
    1. Timeline tie order is unstable, and the strict `before <` cursor drops ties at page edges. Already at baseline; contradiction track.
    2. `(:p IS NULL OR …)` may hurt index use; not measured.
    3. The `TimelineEventDTO` deprecation is pre-existing.
    4. Rule-7 tension: some Query classes read another module's repository. Revisit in 3d.
- **L30 (2026-10-14), shared smoke after 3c (guide 09):** the frontend agent ran it with its phase 3 (`9f1f174..a9671c7`) against back `4df4131`. No regressions. It compared inbox, detail, messages, contacts, timeline and CSV reads directly in MySQL against the `e1425d3` SQL: identical in every filter and in pagination. `/assign`: 200 on assign and reassign, 409 `AGENT_NOT_ACTIVE` for an inactive agent. New pre-existing findings for the contradiction track, confirmed in the code (neither handler checks that the parent exists):
  1. `GET /api/contacts/{id}/timeline` for an unknown contact returns 200 with empty `events` instead of 404 (`ContactTimelineController::getTimeline`).
  2. `GET /api/tickets/{id}/messages` for an unknown ticket returns 200 with empty `data` instead of 404 (`InboxController::getMessages`).
  - **User decision (verbatim):** "cuando terminemos el refactor, los rename al español de todo lo que dijimos, etc, vamos a hacer una pasada tranquila por funcionalidad y dependencia para ir viendo si el codigo es entendible-legible o conviene cambiar algo. eso ya seria otra fase. primero nos enfocamos en el trabajo actual". A comprehension pass is a later, separate phase, not part of this feature.
  - Next: T18 (3d).
- **L31 (2026-10-14), rule 7 adjusted (T18 prep):** about 33 imports of another module's repository already exist (outside Compartido), and the Administracion controllers manage Agente entities (`Agent`, `Team`). User, choosing option 1: "si, 1. Ajustar la regla 7 (recomendada)."
  - New rule 7 in `docs/arquitectura.md`: a module may use another module's use cases, queries and repositories. The SQL on a table lives only in its owner module's repository, and other modules call its methods. Importing another module's controllers stays forbidden.
  - Existing cross-module calls to write or lock methods (`Conversacion` → `TicketRepository::markFirstResponse`, `Asignacion` → `AgentRepository::lockRowsForUpdate`) comply, because the SQL stays in the owner's repository.
  - The L29 advisory 4 (Query classes reading another module's repository) is closed by this rule.
- **L32 (2026-10-14), T18 3d-1 Administracion (route: inline, fresh context after compact; no writer reason):** the 5 Administracion controllers no longer use `EntityManager`.
  - 16 use cases in `Administracion/UseCase/` (create, update and deactivate for users, agents, teams and workspaces; link and unlink; team and workspace agent assignment). Each keeps the old check order and messages. Repositories: new `UserRepository`, `WorkspaceRepository`, `Agente/Repository/TeamRepository`; `AgentRepository::findPageWithUser`. Lists use `count([])` and the same fetch joins.
  - Error mapping stays in the controller: `InvalidArgumentException` → 400, new `Compartido/NotFoundException` → 404, `StateConflictException` → 409, all with `{"error": message}`. The controller loads the route entity first (404) and then decodes JSON (400), as before. A non-object JSON body becomes `[]`, which matches the old `??`/`isset` reads.
  - `UpdateAgentUseCase` keeps the reflection write of `name` (it does not bump `updated_at`), so behavior stays the same; candidate for phase 4.
  - 11 new characterization tests (`AdministracionEdgeCasesCharacterizationTest`), green before the change: check order (409 before 404/400), JSON `null` body, 404 before invalid JSON, rollback of team agent assignment, unknown ids skipped.
  - Checks: `lint:container` OK; route and `#[IsGranted]` lines unchanged in the diff; PHPStan level 0 identical (29 lines, base vs new); admin + characterization tests 167/167; full suite 837 tests with the 6 baseline failures plus `AutoReplyServiceTest::testDoesNotSendWhenAgentIsAvailable`.
  - That extra failure is a pre-existing time-of-day bug, not a regression: `makeAgentAvailable` builds a shift from `hour-1` to `min(23, hour+2)`, so between 23:00 and 23:59 (container time) the shift ends at 23:00 and the agent is unavailable. The run was at 23:24 UTC. Added to the known flakes.
  - Commits: `cbaed47` (rule 7), `7f5601d` (3d-1), `4ee34c7` (flake note in `AGENTS.md`). RDD assess on `db54c40..HEAD`: medium, `under_budget`. Next: 3d-2, `MeController` split.
- **L33 (2026-10-14), T18 3d-2 `MeController` split (route: inline):** following the map the frontend already uses.
  - `Agente/Controller/MeController` keeps settings, profile and notifications (new `UpdateAgentProfileUseCase`, `UpdateNotificationPreferencesUseCase`).
  - `Automatizacion/Controller/MeAutoReplyController` (new `UpdateAutoReplySettingsUseCase`), `Disponibilidad/Controller/MeShiftsController` (existing `ReplaceAgentShiftsUseCase`), `Administracion/Controller/MeWorkspaceController` (new `SetActiveWorkspaceUseCase` with the `ensureAgent` side write, and `WorkspaceNotAssignedException` → 403).
  - The embedded `has_pending` SQL moved to `TicketRepository::hasPendingForAgent` (DBAL QueryBuilder, same predicates, `LIMIT 1` instead of `SELECT EXISTS`). 4 new repository tests, RED before the method.
  - Route names, paths, methods and every `#[IsGranted]` are unchanged: the removed attribute lines reappear verbatim, plus one class-level `IS_AUTHENTICATED_FULLY` per new controller, as on the old class. 91 routes.
  - One order change, not observable: in `PUT /api/me/workspace` the `Unauthorized` 401 guard now runs before the workspace checks. It is unreachable, because the only user provider is the `User` entity and the class requires `IS_AUTHENTICATED_FULLY`.
  - Checks: `lint:container` OK; PHPStan level 0 identical; `/api/me` tests (`SettingsPageTest`, `MeWorkspaceControllerCharacterizationTest`) 24/24; full suite 841 tests, the 6 baseline failures plus the time-of-day `AutoReplyServiceTest` failure (run at 23:34 UTC, see L32).
- **L34 (2026-10-14), T18 3d-3 small controllers (route: inline):**
  - `TicketEventsController`: the resync SQL moved to `OutboxEventRepository::findResyncRows` (DBAL QueryBuilder, same predicates and `LIMIT 100`), and the hydration and response building to `Compartido/Query/ResyncTicketEventsQuery` (moved verbatim, without `strict_types`, like its origin). The controller keeps the JWT and `afterSequence` checks. 2 new repository tests, written before the method but not run RED first.
  - `AuthController::logout`: refresh-token deletion moved to `Autenticacion/UseCase/LogoutUseCase` unchanged. New test `testLogoutAlsoDeletesTheRefreshTokenSentInTheCookie` pins the cookie branch; it passes against the old and the new controller.
  - `ReportingController`: its private DQL count was identical to `TicketRepository::countActiveForAgent` (same statuses), so it now calls that method.
  - `Agente/TeamController` → `TeamRepository::findByWorkspaces`; `WorkspaceAgentController` → `WorkspaceRepository::find`. `AgentAvailabilityController` had no violation (only a comment).
  - Checks: `lint:container` OK; PHPStan level 0 identical; endpoint tests (26 files, 283 tests) only the baseline `ReportingV2Test` failure; full suite 844 tests, the 6 baseline failures plus the time-of-day `AutoReplyServiceTest` failure (run at 23:52 UTC).
  - The assertion total dropped 4614 → 4563. The per-test diff shows that only `ReportingV2Test::testGetHeatmapReturnsData` (497 → 442) and `testGetFrtTrendReturnsData` (57 → 52) changed. They assert once per data row in a date window relative to now, and this change does not touch them. Advisory: time-dependent reporting tests.
- **L35 (2026-10-15), T18 3d-4 Ticket and Asignacion (route: inline):**
  - `InboxController::markRead` → `Ticket/UseCase/MarkTicketReadUseCase` (same owner-only rule). Its raw outbox insert moved verbatim to `OutboxEventRepository::insertTicketReadEvent` (same columns and values; phase 3e unifies the outbox writers).
  - `TicketWorkflowController`: the closure audit write → `Ticket/UseCase/RecordClosureAuditUseCase`, with the same catch-and-log (D8). The `TicketVoter::CLOSE` check stays in the controller. `TicketWorkflowControllerAuditFailureTest` now builds the controller with a real use case over the same mocked `EntityManager` and logger, so it pins the same behavior.
  - `Asignacion/TicketController::setTeam` → `Asignacion/UseCase/SetTicketTeamUseCase`, with the same order and messages (an unknown team is still a 400).
  - Checks: `lint:container` OK; PHPStan level 0 identical; endpoint tests (20 files, 236 tests) green; full suite 844 tests, exactly the 6 baseline failures. Run at 00:36 UTC, so the time-of-day `AutoReplyServiceTest` test passed, which confirms L32. Per-test assertion diff vs 3d-3: only `ReportingV2Test` heatmap/FRT trend and `AutoReplyServiceTest`, all time-dependent.
- **L36 (2026-10-15), T18 3d-5 Conversacion (route: inline):** no controller in `src/` uses `EntityManager` or SQL any more.
  - `WebhookController`: the raw payload write moved to `Conversacion/UseCase/RecordWebhookPayloadUseCase`. The controller keeps the HMAC check, the always-200 try/catch and the dispatch. `/webhook` had no endpoint test, so `WebhookControllerCharacterizationTest` (3 tests: signed status payload stored as `status`, a payload with neither messages nor statuses stored as `unknown`, invalid signature 401 and not stored) was added and passes against both the old and the new controller.
  - `ProviderProxyController`: the WAMID update → `MarkOutboundMessageSentUseCase`; the template message write → `RecordSentTemplateUseCase` (content built the same way; `templateName` is already a string because `GraphClient::sendTemplate` requires one); agent auto-creation → new `Agente/UseCase/EnsureAgentForUserUseCase`, still wrapped in the controller's catch-all that degrades to null. `SetActiveWorkspaceUseCase` now uses the same use case instead of its own copy of `ensureAgent` (identical logic). Splitting the send flow stays in phase 4.
  - `ProviderProxyControllerResolveAgentTest` now builds the real use cases over the same mocked `EntityManager`, and an `AgentRepository` mock replaces the `getRepository` expectations; the same 3 scenarios hold.
  - Gotcha: after a `git stash` round trip the test container cache kept the old constructor (`TypeError ... EntityManagerGhost given`); `cache:clear --env=test` fixed it.
  - Checks: `lint:container` OK; PHPStan level 0 identical; provider, webhook and `/me/workspace` tests green; full suite 847 tests, exactly the 6 baseline failures (01:04 UTC). The per-test assertion diff covers only time-dependent `ReportingV2Test` cases, the adapted unit test (one more `never()` expectation) and the new tests.
- **L37 (2026-10-15), T18 independent verifier (route: delegated, verification trigger: auth logout touched):** PASS for S1, S2, S3 and S8, no blockers. Baseline `4df4131` vs `98d95dd`.
  - Probes ran in throwaway containers on scratch copies of both trees. The 3d-added tests copied into the baseline gave 44/44 on both trees. A custom probe of about 95 requests (`/me/workspace` malformed bodies and role paths, the `has_pending` matrix, mark-read, team set, about 50 admin edge requests) gave identical status, content type, body and DB side-effect counts. Full suites had the same 6 failing names. `debug:router` JSON without `_controller` was identical, and `router:match` agreed for the 6 moved path+method pairs.
  - The L33 order change was confirmed unobservable.
  - Advisories:
    1. Route registration order changed; matching is unaffected.
    2. `UnlinkUserFromAgentUseCase`: an `InvalidArgumentException` raised by `flush()` would now be a 400 instead of a 500; no realistic input raises it.
    3. `insertTicketReadEvent` is covered end to end by `InboxControllerCharacterizationTest`, not by a repository test.
    4. `resolveActingAgentId` in `sendTemplate` now runs before the message is built (equivalent: the message was not persisted).
  - New pre-existing findings for the contradiction track (identical on both trees):
    1. `PUT /api/admin/teams/{id}/agents` with a non-object body clears all of the team's agents and returns 200.
    2. `PUT /api/admin/workspaces/{id}` with a duplicate `phoneNumberId` gives a 500 (unique constraint).
    3. `POST /api/admin/users` with `roles` as a string gives a 500 (`TypeError`).
  - Parent spot check: workspace clean; 91 routes in the dev environment (the verifier counted 90 in the test environment).
- **L38 (2026-10-15), shared smoke after 3d (guide 09):** the frontend agent ran it against back `9a10e2f`. No regressions: P-01..P-24 match the baseline, 403s unchanged; admin, settings, `has_pending` (same as the old SQL), mark-read, resolve/close, team, sends and webhook match the old code in status, body and DB effect. New pre-existing findings for the contradiction track (neither 3d nor the front phase 3 touched this logic):
  1. `provider/send` to a nonexistent ticket gives a 500.
  2. An agent can `PUT /tickets/{id}/team` and gets 200.
  3. User creation accepts `ROLE_BOGUS`.
  4. `InboxUpdatedEvent` outbox rows always end in `dead_letter` (input for T19).
  5. Resolving a ticket is not published in real time: no `ticket.status_changed` is emitted (input for T19).
  6. The inbox list `contact.id` differs from the detail one (ticket 489: 75 vs 85).
  - Also reported: the duplicate `phoneNumberId` 500 (already in L37) and workspace 2 having a real Meta number, so sends there give 422 `RECIPIENT_NOT_ALLOWED` (environment data, not code).
  - Next: T19 (3e), evidence first, then the two user decisions one at a time.
- **L39 (2026-10-15), T19 evidence (route: delegated explorer, mapping trigger):**
  - 10 outbox writers, all post-commit in their own flush or insert; none inside the business transaction:
    - AssignTicket and AutoAssign: camelCase plus `assignee_id`/`assignment_type`.
    - HandleInbound and HandleOutbound: snake_case. HandleOutbound skips `InboxUpdatedEvent` on purpose.
    - HandleStatusUpdate: snake_case, no transaction; the only writer that fills `sequence`.
    - 4 Disponibilidad shift/availability use cases: snake_case, `aggregate_type='agent'`.
    - Mark-read: raw DBAL insert, `ticket.read`, camelCase.
    - Every other writer: `sequence=0`, with the number kept in payload `seq`.
  - Leak: `TransitionTicketStatusUseCase` (resolve, close, transition, window-expiry close, AutoReopen) collects the UNASSIGN `TicketUpdatedEvent` and `InboxUpdatedEvent` through `AssignmentService` and never drains them. They are written only when HandleInbound or HandleOutbound call it inside their own drain.
  - `dead_letter`: `EventTypeMapper::MAP` has no `InboxUpdatedEvent`, so `RealtimeEventFactory::build` throws and the row dead-letters after 5 retries (spot-checked).
  - No `status_changed` on resolve: the status change is a raw `updateStatusAfterTransition` that emits no event; no code path produces a real `ticket.status_changed` with `previous_status`.
  - Pins: `Sprint09BOutboxTest`, `AsignacionControllersCharacterizationTest::testAutoAssignPicksAnAvailableAgentAndWritesOutbox` (event types and mixed key set), the mark-read row in `InboxControllerCharacterizationTest`, and `MessageAgentAttributionOutboxTest`.
- **L40 (2026-10-15), T19 decision (a), outbox timing:** user: "bueno, vamos con la opcion 1, despues vemos como subsanamos el hueco de cuando falla la escritura del outbox. te parece?" Option 1: keep writing post-commit; 3e only unifies the writers behind one, behavior identical. Contradiction track: when the outbox write fails after commit, the business change persists without its event (to be fixed later).
- **L41 (2026-10-15), T19 decision (b), `EventCollector` leak:** user: "si, vamos con la opcion 1, despues vemos como solucionamos esos temas que no estan resueltos". Option 1: keep dropping. The single writer writes only where rows are written today; Transition's collected events stay dropped. Contradiction track: the leak, `InboxUpdatedEvent` dead-letters, and no `ticket.status_changed` on resolve/close.
- **L42 (2026-10-15), T19 implementation (`a286b03`, route: inline, no writer trigger):**
  - New `Compartido/Outbox/OutboxWriter::write(OutboxEvent ...$events)`: it persists the events and always flushes once, as each replaced writer did.
  - Every one of the 10 writers now goes through it:
    - The 5 collector drains keep their own payload builders and early returns, and still clear the collector themselves.
    - The 4 Disponibilidad use cases keep their pending-event pattern.
    - `MarkTicketReadUseCase` builds an `OutboxEvent` with the same values the removed `OutboxEventRepository::insertTicketReadEvent` used: same columns, ULID id, sequence 0, and the same camelCase payload.
  - Timing (post-commit) and the dropped Transition events are unchanged (L40, L41).
  - New test `tests/Integration/Outbox/OutboxWriterTest.php`.
  - `HandleInboundMessageUseCaseTest` builds the real writer over its mocked EntityManager.
  - Docs 02/04/05/08 updated.
  - Checks:
    - `lint:container` OK; PHPStan level 0 identical (29 lines).
    - Focused outbox tests: 113 tests, only the baseline `Sprint09BOutboxTest` failure.
    - Full suite: 848 tests, exactly the 6 baseline failures.
    - No `outbox_events` insert or `persist($outbox...)` left outside the writer.
    - RDD assess: medium, `under_budget`.
- **L43 (2026-10-15), T19 independent verifier (route: delegated, verification timing: phase 3 end):** PASS for S1, S2, S3 and S8, no blockers. Baseline `9a10e2f` vs `a286b03`.
  - It ran in throwaway containers with its own MySQL, on `git archive` copies; `inbiox_php` and the shared databases were untouched.
  - Static: no `outbox_events` write outside `OutboxWriter::write`.
  - Probe: each of the 10 writers called through its public entry on both trees. 17 normalized rows, identical. Rejected commands added 0 rows on both trees.
  - Full suite: the 6 baseline failures plus one known 401 flake, which passed on the focused rerun.
  - Advisories: auto-assign right after inbound writes 0 rows on both trees; the JSON column normalizes payload bytes.
  - Parent spot check: workspace clean, no verifier containers or network left.
- **L44 (2026-10-15), shared realtime smoke after 3e:** the frontend agent ran it against back `4919f7a`. No differences: outbox rows matched the `9a10e2f` smoke (event_type, topic, payload keys and shape, sequence, count and order) for inbound, agent reply, delivery status, manual and auto assignment, mark-read, shift create/delete, and availability on/off; 25 rows published, 25 Mercure events.
  - Delivery status, auto-assign and shifts were compared against the `9a10e2f` code, because the old window had no rows for them.
  - Availability "off" was tested via DELETE of the override, not `POST isAvailable:false`.
  - Confirmed as expected (L41): resolve/close emit nothing, and `InboxUpdatedEvent` dead-letters ("Unknown event class").
  - New pre-existing findings for the contradiction track:
    1. `ticket.created` carries `contactId` 0, hence "Unknown" in the UI.
    2. Assignment events carry `assigneeName` null and an empty `assignedAt`.
  - Phase 3 complete. Next: push and PR #4 (base `chore/limpieza-backend-fase-2`), with user approval.
- **L45 (2026-10-16), delivery and environment:**
  - Phase 3 was pushed as PR #4 (https://github.com/giannizeniquel/inbiox/pull/4), base `chore/limpieza-backend-fase-2`.
  - The dev WhatsApp numbers were replaced in place (workspace 1 → `1305366182668267`, workspace 2 → `1371728396024028`, both real). Both numbers send and receive end to end.
  - New findings for the contradiction track:
    1. A failed Graph send leaves the outbound message `sent` without a WAMID.
    2. `GraphClient` drops `error_subcode` and `error_data`.
    3. The `WS2_TEST_PHONE_NUMBER_ID` normalization in `GraphClient` is now dead.
- **L46 (2026-10-16), phase 4 start (route: delegated explorer, mapping trigger):** user: "dale". Branch `chore/limpieza-backend-fase-4` from `6fe5483`. Outbound map:
  - `HandleOutboundMessageUseCase`: `processOutboundMessage` (95 lines; idempotency, retry loop with 3 attempts, outbox after commit) and `executeTransactionWithRetry` (192 lines; lock, ownership guard before the window check, auto-claim, message, FSM, ProcessedEvent, event, rules).
  - `ProviderProxyController::send` (143 lines) holds business logic: media metadata, recipient phone, Graph dispatch, WAMID mark. `sendTemplate` duplicates the phone resolution; `uploadMedia` holds the storage key and the best-effort storage policy.
  - Coverage gaps: the Graph happy path, media send, Graph error mapping, PHONE_NOT_FOUND, the 400s, everything in upload and download, the FSM matrix, retry exhaustion. Graph is not stubbed in `services_test.yaml`; tests use `container->set` plus `disableReboot()`.
  - Specs S9 and tasks T20–T24 added.
- **L47 (2026-10-16), T20 and T21:**
  - 4a-0 (`364d163`, tests only): new `tests/Characterization/ProviderProxyControllerCharacterizationTest.php`, 32 tests that pass on the unchanged code. It covers:
    - Send: text and media happy paths, the FSM after a reply, each Graph error code mapping, a missing WAMID, the duplicate send, the 400s, a missing ticket giving 500, and PHONE_NOT_FOUND ×2.
    - Template send: happy path, missing WAMID, Graph error, validation, PHONE_NOT_FOUND.
    - Upload: 400, 415, 413, Graph 502, the storage key, and a storage failure.
    - Download: success and error.
  - New observation: a reply to a `new` unassigned ticket leaves it `waiting`, not `active`; auto-claim moves it to active and the reply FSM then moves it to waiting. It is pinned as-is.
  - 4a-1 (`187360a`): `executeTransactionWithRetry` keeps the transaction, the lock and the order of every flush. Its steps moved into private methods: `guardSend`, `claimTicketForReplyingAgent`, `createOutboundMessage`, `applyReplyTransition` and `collectMessageCreatedEvent`. No new classes.
  - Checks:
    - `lint:container` OK; PHPStan level 0 identical (29 lines).
    - Outbound-related tests: 81 tests; only the 3 baseline Sprint09B failures fail.
    - Full suite: 880 tests, exactly the 6 baseline failures. A first run had a 7th failure whose name was cut from the output; it did not reproduce on the rerun.
- **L48 (2026-10-16), T22:**
  - 4a-2 (`8d9d0fb`): new final `Conversacion/UseCase/SendOutboundMessageUseCase` holds the media metadata, `processOutboundMessage`, the recipient phone resolution, the Graph dispatch and the WAMID mark.
  - The controller keeps parsing, agent resolution, `isGranted` and the exception→JSON mapping. Bodies are unchanged: a missing ticket after storing is `NotFoundException` → 404 `{"error":"Ticket not found"}`; the two PHONE_NOT_FOUND cases are the new `RecipientPhoneNotFoundException` → 422; `GraphApiException` goes through the same mappers. A missing ticket before storing still throws `InvalidArgumentException` → 500.
  - The media fields stay untyped (`mixed`) so a non-string value fails at the same point as before (the Graph call, after the message is stored).
  - `ProviderProxyControllerResolveAgentTest` builds the final use case over mocks; docs updated to point at the use case.
  - Checks: `lint:container` OK; PHPStan level 0 identical (29 lines); focused provider/outbound/window/media tests 93, only the 2 baseline MediaValidator failures; full suite 880 tests, exactly the 6 baseline failures.
- **L49 (2026-10-16), T23:**
  - 4a-3 (`dc30fac`): new final `Conversacion/UseCase/SendTemplateUseCase` holds the ticket lookup, the recipient phone, the Graph template send and the template message record.
  - The recipient phone resolution shared by send and template is now `Ticket::recipientPhone()`, which throws `RecipientPhoneNotFoundException` with the same two messages.
  - The sending agent is resolved lazily through a closure from the controller, only after a send that returned a WAMID, as before: resolving it can create an agent. New characterization test `testSendTemplateCreatesTheMissingAgentOnlyAfterASuccessfulSend` pins it; it passes on the old and the new code.
  - `templateName` and `language` stay `mixed` so a non-string value still fails at the Graph call, after the ticket and phone checks.
  - The controller no longer needs `TicketRepository` or `RecordSentTemplateUseCase`. Docs updated (also stale line refs in `06-api-y-permisos.md`).
  - Checks: `lint:container` OK; PHPStan level 0 identical (29 lines); focused provider/template/outbound/window tests 65 OK; full suite 881 tests, exactly the 6 baseline failures.
