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
| T10 | S1 | inline | done | Phase 1 items that change a contract or the environment (see L9): L11–L16 |
| T11 | S2 | inline | done | Phase 2: routes regenerated as `#[Route]` attributes from the YAML; `routes.yaml` = `routing.controllers` (L19) |
| T12 | S2 | inline | pending | Phase 2: remove single-implementation interfaces (8, from the explorer map) |
| T13 | S2 | inline | pending | Phase 2: move classes into modules (map in L19), one commit per module; event-class rename blocker first |
| T14 | S2, S7 | inline | pending | Phase 2: docs paths (`02_sistema-actual`) after the moves |

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
