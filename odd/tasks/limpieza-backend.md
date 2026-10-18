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
- **S10 — Phase 4b, inbound.** User: "dale, arrancá con lo que teniamos previsto, suma lo que te sirva de las orbseravciones del otro agente, pero nos mantenemos en nuestro plan". Same rules as S9: behavior is preserved even where it looks wrong, characterization tests first. Branch `chore/limpieza-backend-fase-4b`, stacked on `chore/limpieza-backend-fase-4`.
  - 4b-0: characterization tests for signed `POST /webhook` with `GraphClient` stubbed: text, each media type (label, caption, missing `mediaId`), unsupported type and missing `from` skipped, duplicate WAMID, new ticket, reopen, `waiting` → `active`, auto-reply, media download success and failure, status updates (forward, backward skip, failed with reason, unknown WAMID). From the second opinion: a mixed payload where one status in the middle throws (freezes today's cut of the remaining entries, still 200), and invalid JSON → 400 (bad signature → 401 is already covered).
  - 4b-1: split `HandleInboundMessageUseCase::executeInternal` into private steps named after what they do today, even when it is wrong (for example `downloadMediaInsideTransaction`). Keep the 3 flushes, the lock, the refresh and the sequence increments in the same order. `processInboundMessage` keeps its signature (`DebugController` also calls it).
  - 4b-2: `HandleStatusUpdateUseCase` is not touched; the only change worth making there (a catch per status) changes behavior.
  - 4b-3: move `WebhookController::dispatch` payload parsing into a final `ProcessWebhookPayloadUseCase`. The controller keeps HMAC → JSON → record payload → use case → 200, with the same log lines, the per-message catch and no per-status catch.
  - To the contradiction track, not changed in 4b (decided separately with the user):
    - High priority bug: no catch per status entry; one failing status drops the rest and Meta gets 200, so it never resends them.
    - Critical debt: the media download (Graph I/O) runs inside the open inbound transaction, holding the rows just inserted; the ticket lock comes later (L308).
    - Outbox written after commit (already decided in L40).
    - Topic `tickets` vs `ticket/{id}` and R4 workspace isolation (group G; product/security decision).
    - Product decisions: a duplicate WAMID can still create the contact/conversation (it runs after they are persisted; when the duplicate is the last message of a payload from a new sender, nothing is stored, L58); auto-reply window constant 2 h vs docblock 4 h.
    - Bug found in 4b-0: two messages in one payload on a `waiting` ticket lose the second one. The first moves the ticket to active with raw SQL, the managed entity still says waiting, and the second fails its waiting → active transition and is rolled back; Meta still gets 200.
- **S11 — Phase 4c, reporting.** User: "ok, es logico que hayan tantos hallazgos porque el modulo de reportes fue una de las ultimas cosas que se agregaron junto con el modulo de teams y no hubo mucho analisis previo, anota todo donde venimos anotando los bugs/contradicciones para encarar todo lo acumulado ahi despues de la limpieza que estamos realizando. Arrancamos". Same rules as S9: behavior is preserved even where it looks wrong, characterization tests first. Branch `chore/limpieza-backend-fase-4c`, stacked on `chore/limpieza-backend-fase-4b`.
  - 4c-0: exact-body HTTP characterization tests for the reporting endpoints not yet pinned (frt, agent-load, unassigned with today's `count` 0, available-agents, frt-trend, heatmap, recurring-customers, reopened, response-delay, ticket participation 200), with their own fixtures in a fixed date window (no seeder, no dependency on the current time where avoidable), plus the missing 400 date-parameter cases.
  - 4c-1: one private `ReportingRepository` helper for the optional agent/phone/team filter block repeated in the native queries.
  - 4c-2: `ReportingController::availableAgents` logic (workspace filtering, per-agent active count) into a `Reporte/Query/` class; same output, N+1 kept.
  - 4c-3: split `ReportingRepository::getTicketParticipation` into private steps; same SQL.
  - 4c-4: split `ReportingRepository::getResponseDelayMetrics` the same way (query vs PHP aggregation).
  - 4c-5: `is_in_shift` enrichment moves from `getAgentPerformance` into `ReportingService`; the repository stops depending on `AgentAvailabilityService`.
  - 4c-6: why-comments on the remaining native queries, `LIMIT` bound as a parameter, wrong return shapes fixed in the `ReportingService` docblocks.
  - Not touched: `SeedReportsCommand` (test-only tooling); no conversion of the native SQL to a query builder (high risk, no gain).
  - To the contradiction track, not changed in 4c (decided separately with the user after the cleanup):
    - Bug F / R8: `GET /api/reports/unassigned` always returns `count` 0 (the `str_replace` on the SELECT never matches).
    - Security: `GET /api/reports/tickets/{id}/participation` applies no workspace scope (a hole like R13, not listed there).
    - `agent-load` and `recurring-customers` ignore the `teamId` the frontend sends.
    - `agent-load` applies the phone filter only to tickets, so agents from other workspaces show up with load 0.
    - The attribution cutoff query runs twice per response-delay request (`getAttributionCutoff` and inside `getResponseDelayMetrics`).
    - `available-agents` runs one active-ticket count per agent (N+1).
    - To check (verifier, L69): `response-delay` returns `partial_data: true` whenever the range starts before the first attributed message, even if every message in the range is attributed (`cutoff > from`); same before 4c.
    - Frontend bug (front smoke, L70): the dashboard's "Volumen diario" shows 0 and the agent ranking is empty because the frontend reads `.data`/`.agents` while the API returns plain arrays.
    - Also seen in the map: dead `agent_id` override branches in the controller (the ownership scope is always null, R-V3); `recurring-customers` with `threshold=0` falls back to 3; Postgres branches in `ReportingRepository::detectPlatform` are dead on MySQL; R6/C-2 (per-agent resolved metrics empty because resolving unassigns).
- **S12 — Phase 5, internal renaming and migration baseline.** User: "dale, arrancá con el mapeo de la fase 5" and "dale, creá la rama y escribí el plan". Scope agreed with the frontend (L72, L73): "el front hace su Fase 4 contra el contrato actual; vos, el renombrado interno y la migración base; el contrato conjunto, al final." Names follow the glossary in `docs/arquitectura.md` (completed and approved in L74: "dale, aprobada, agregala al glosario"). Behavior is preserved. Branch `chore/limpieza-backend-fase-5`, from `cf228c0`.
  - 5-0: pin persisted names without renaming anything: explicit `name:` on every `#[ORM\Column]` that relies on the naming strategy, explicit `joinColumns`/`inverseJoinColumns` on `agent_workspace` and `agent_team`, and fix the known mapping/schema drift (`TicketTag` inverse sides, `idx_tickets_created_at`, `lastReadAt` type) only where the fix keeps the schema identical or is the schema the migrations already create. Add a characterization test pinning the persisted event class names (the `EventTypeMapper` list) and the Mercure `type` and payload of one event per type.
  - 5-1: fresh migration baseline: one migration generated from the mapping replaces the 28 files; users come from `app:seed-users`. The dev DB keeps its data (option A, L77: the frontend's smokes use it) and only gets the baseline registered with `doctrine:migrations:version --add --all`. High risk (migrations): independent verifier after its checks.
  - 5-2..5-n: renames, one module per part, smallest first: `Ticket` (Tag, TicketTag, TicketEvent, owner terms), `Contacto`, `Automatizacion`, `Disponibilidad`, `Asignacion`, `Agente` (entities, then the rest), `Administracion`, `Autenticacion`, `Conversacion` (entities, then the rest), `Reporte`, `Compartido`. Each part renames entity classes, use cases, queries, repositories, controllers, DTOs, methods, properties, local variables, enum case names and comments, and updates the matching yaml, `ReflectionProperty` strings, DQL strings, `findBy` keys and tests.
  - Kept as they are in phase 5 (persisted, sent, or framework contracts):
    - JSON keys (all literal arrays), outbox payload keys (including `ownerAgentId`), native SQL aliases.
    - Table and column names, enum values, role strings, route names and paths, Mercure topics, console command names, `aggregate_type`, `ticket_events.type`, `rule_execution_logs.rule_name`.
    - The event classes whose class name is stored in `outbox_events.event_type` (`TicketCreatedEvent`, `TicketUpdatedEvent`, `MessageCreatedEvent`, `MessageUpdatedEvent`, `AgentAvailabilityChangedEvent`, `AgentShiftChangedEvent`), plus `InboxUpdatedEvent` and `DomainEvent` (L73).
    - Framework contracts: `UserInterface`/`PasswordAuthenticatedUserInterface` methods, `RefreshToken`, the gesdinet `username` field, `property: email`.
    - Test class and test method names, so failing names stay comparable with the baseline; tests only get their references updated.
  - Gates per part: failing test names equal the baseline (plus 0–2 `401 Invalid JWT Token` flakes), `lint:container`, `debug:router` output identical, `doctrine:schema:update --dump-sql` empty and `doctrine:schema:validate` in sync on a DB created from the baseline (from 5-1 on); on the existing dev and test DBs, `--dump-sql` shows only the known cosmetic statements logged in L77 (`agent_team` index renames, `DC2Type` comment drops, and on dev the backup table), PHPStan level 0 diff with no new errors, and `rg` with no hits for the old names in DQL strings, `ReflectionProperty` strings and yaml.
  - Out of scope, for the joint API contract: JSON keys and Mercure `type`s, enum values, decoupling `event_type` from class names (L73), and renaming tables and columns (native SQL result keys come from column names, so it changes the API; S4 allows it, decided with the contract).
  - Notes for the joint API contract (L79): the frontend still requests `GET /api/mercure/token` on login and refresh but no longer uses the token; it no longer calls `GET /api/tickets/{id}/events`; dev data has no case for changing a ticket, the 403 on resolve or live assignment (the frontend suggests adding them to the test data). To the contradiction track: the workspace seed's `getenv('META_PHONE_NUMBER_ID')` returns `false` when unset and the guard lets it through (inherited, now in the baseline).
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
| T24 | S9 | inline | done | 4a-4 `uploadMedia` into a use case (`4dc42f4`, L50) |
| T25 | S10 | inline | done | 4b-0 inbound webhook characterization tests (`3d72b65`, L55) |
| T26 | S10 | inline | done | 4b-1 split `executeInternal` into private steps (`e9bbd90`, L56) |
| T27 | S10 | inline | done | 4b-3 webhook payload parsing into `ProcessWebhookPayloadUseCase` (`8e0e099`, L57) |
| T28 | S11 | inline | done | 4c-0 reporting characterization tests (`094fffe`, L62) |
| T29 | S11 | inline | done | 4c-1 shared filter helper in `ReportingRepository` (`525660d`, L63) |
| T30 | S11 | inline | done | 4c-2 `availableAgents` into a `Reporte/Query/` class (`e966cbb`, L64) |
| T31 | S11 | inline | done | 4c-3 split `getTicketParticipation` (`21aa251`, L65) |
| T32 | S11 | inline | done | 4c-4 split `getResponseDelayMetrics` (`e41bd28`, L66) |
| T33 | S11 | inline | done | 4c-5 `is_in_shift` from the repository into the service (`5365bc0`, L67) |
| T34 | S11 | inline | done | 4c-6 why-comments, bound `LIMIT`, service docblocks (`216be83`, L68) |
| T35 | S12 | inline | done | 5-0 pin persisted names, fix drift, event-name characterization test (`8115742`, L76) |
| T36 | S12 | inline + verifier | done | 5-1 fresh migration baseline (`4f6d95e`, verifier PASS L78) |
| T37 | S12 | inline | pending | 5-2..5-n renames per module (one sub-task and commit per module, added when each starts) |

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
- **L50 (2026-10-16), T24:**
  - 4a-4 (`4dc42f4`): new final `Conversacion/UseCase/UploadMediaUseCase` holds the MIME/size validation, the Graph upload and the best-effort storage copy. The controller keeps reading the multipart file and the exception→JSON mapping (413, 415, 502). `downloadMedia` stays a thin proxy to Graph.
  - Edge kept: a non-scalar `ticketId` made `InputBag::get()` throw inside the storage `try`, so storage was skipped and `key` was null. The controller now passes the raw form value and the use case throws inside the same `try`. New characterization test `testUploadWithANonScalarTicketIdSkipsStorageAndStillReturnsTheMediaId` pins it; it passed on the old code first.
  - The controller now depends only on `GraphClient` (download), the three provider use cases and `EnsureAgentForUserUseCase`. Docs updated; B1 marked resolved in phase 4a.
  - Checks:
    - `lint:container` OK; PHPStan level 0 identical (29 lines); focused provider/media/template tests 68, only the 2 baseline MediaValidator failures.
    - Full suite, first run: 9 failures, the 6 baseline plus `TicketWorkflowControllerCharacterizationTest::testTransitionUnexpectedFailure`, `AgentLinkControllerTest::testUnlinkUserFromAgent` and `TicketClosureAuditClassificationTest::testSupervisorResolvesAnAutoReopenedUnownedTicketProducesManualResolvedUnowned`. Their messages were cut by the output filter, so the signature is unknown. None touches the provider code; the 3 pass in isolation.
    - Full suite, rerun with full output: 882 tests, exactly the 6 baseline failures.
  - Phase 4a code is complete (T20–T24). Next: independent verifier, front smoke with real sends, then push and PR #5 with the user's approval.
- **L51 (2026-10-16), phase 4a independent verification:**
  - Verdict PASS for 4a-0..4a-4, no blockers. Baseline `6fe5483` vs HEAD, both trees run from scratch copies with Graph and storage stubbed (no Meta calls).
    - 4a-1: the order of the lock, guards, claim flushes, message persist, FSM, ProcessedEvent and event collect is unchanged; the outbox rows for a happy send are identical on both trees after normalization.
    - 4a-2 to 4a-4: raw bodies are byte-identical (409 escaping, 429, 404, 422, upload key order); non-string payload values fail at the same point; the template agent is still created lazily; a non-scalar upload `ticketId` still fails inside the storage `try`.
  - 15 probes pass on both trees and are committed as `tests/Characterization/ProviderProxyVerifierProbeTest.php` (`7834d4b`), without the verifier's `PROBE_OUT` dump. Parent spot check: `--filter 'ProviderProxyVerifierProbe|ProviderProxyController'` gives 55 tests OK.
  - Advisories, already present at the baseline:
    - An unrecognized binary (`.exe`, `application/octet-stream`) passes `MediaValidator` and is uploaded; this is the known MediaValidator baseline failure.
    - The controller catches now wrap the whole use case, so a future `NotFoundException` thrown inside the outbound transaction would become 404 instead of 500. Nothing throws it there today.
  - Next: front smoke with real sends, then push and PR #5 (base `chore/limpieza-backend-fase-3`), with the user's approval.
- **L52 (2026-10-16), phase 4a front smoke with real sends (front report against `482b84c`):**
  - No differences caused by 4a. Four real sends to ticket #501 (ws1) reached the user's phone, each with a WAMID and delivered in 2–4 s, like messages 284/286 before 4a:
    - Admin without an agent: agent 19 was created and the ticket reassigned.
    - Text: the ticket ended `waiting` with agent1.
    - Image and PDF: delivered with caption and file name.
  - Errors same as before 4a: 422 `CONVERSATION_WINDOW_EXPIRED`, 409 `TICKET_NOT_OWNED`, 415, 413. Inbound image and PDF fine.
  - Gap: the outbox was published with the baseline pattern, but the Mercure events of the 4 sends were not captured.
  - Leftover test data: user 17 and agent 19; one real media upload to Meta with no message (a `.txt`, which is allowed), stored as MinIO object `default/463/01M52JHMDKJSF4EWT8R41SR3QF.txt`.
  - Next: push and PR #5 (base `chore/limpieza-backend-fase-3`), with the user's approval.
- **L53 (2026-10-16):** User: "dale, hacé el push y abrí el PR #5". Pushed `chore/limpieza-backend-fase-4` and opened PR #5 (https://github.com/giannizeniquel/inbiox/pull/5), base `chore/limpieza-backend-fase-3`, 13 commits from `6fe5483`. Phase 4a is closed. Next part: 4b (inbound), still to be planned.
- **L54 (2026-10-16), phase 4b plan:**
  - An explorer mapped the inbound flow (spot-checked `HandleInboundMessageUseCase.php:79,148,234,308`, `WebhookController.php:296-300`).
  - The user got a second opinion from another agent. Adopted from it: the two extra 4b-0 tests and the step naming. Not adopted, after checking the code:
    - "Postgres": the DB is MySQL 8.
    - "The ticket is locked while waiting for Graph": the lock comes after the download (L234 vs L308).
    - "A failing auto-reply loses the event": the auto-reply has its own catch (L85-98).
    - Adding a transaction and a per-status catch, and fixing `Sprint09BOutboxTest`, would change behavior; they go to the contradiction track.
  - User: "dale, arrancá con lo que teniamos previsto, suma lo que te sirva de las orbseravciones del otro agente, pero nos mantenemos en nuestro plan". S10 and T25–T27 added (4b-2 needs no task: nothing is changed there).
- **L55 (2026-10-16), T25:**
  - 4b-0 (`3d72b65`, tests only): new `tests/Characterization/WebhookInboundCharacterizationTest.php`, 18 tests through a signed `POST /webhook` with Graph and storage stubbed. It covers:
    - Text: first message (contact, conversation, ticket `new`/`medium`, 2 outbox rows with seq 1 and 2, `webhook_payloads` row), second message reusing the ticket (seq 3), the contact name filled only when missing, a duplicate WAMID, `waiting` → `active`.
    - Skips: unsupported types, no `from`, and a failing message not stopping the next one. Malformed JSON → 400.
    - Media: label and caption text, the stored key under the message id with `.bin` when there is no filename, a document filename, no media id, a failed download.
    - Auto-reply from an unavailable agent: Graph send, stored outbound message, third outbox row with seq 3.
    - Statuses: forward with a sequenced row, backward/terminal/unknown/missing fields changing nothing, failed reason from the first error, and a failing status cutting the remaining ones while still returning 200.
  - Findings pinned as-is:
    - The outbox `event_type` and the payload `type` are the event class names (`App\Ticket\TicketCreatedEvent`), not the realtime names.
    - MySQL's JSON column reorders the payload keys, so the tests compare key sets.
    - New bug (added to S10's contradiction list): two messages in one payload on a `waiting` ticket lose the second one.
  - Checks: the new file passes (18 tests); full suite 915 tests, the 6 baseline failures plus one `401 Invalid JWT Token` in `TicketClosureAuthorizationTest` (the known flake).
- **L56 (2026-10-16), T26:**
  - 4b-1 (`e9bbd90`): `HandleInboundMessageUseCase::executeInternal` now reads as the sequence of its steps, each a private method named after what it does today: `findOrCreateContact`, `findOrCreateOpenConversation`, `findAlreadyStoredMessage`, `storeInboundMessage` (first flush), `downloadMediaInsideTransaction` (second flush on success), `findOrReopenOrCreateTicketAndLock`, `moveWaitingTicketToActive`, `touchTimestamps`, the third flush, `collectInboundEvents` and `runRulesAndRequestAutoAssignment`.
  - The order, the 3 flushes, the lock, the refresh after a reopen and the sequence increments are unchanged. Two unused locals were dropped (`$isNewConversation`, and the transition result `$result`). `processInboundMessage` and `persistEventsToOutbox` are untouched. No new classes.
  - Docs: line references to the use case updated.
  - Checks:
    - `lint:container` OK; PHPStan level 0 identical (29 lines).
    - Inbound-related tests (`Inbound|Webhook|Reopen|Sprint09B|Sprint08Automation|AutoReply`): 110 tests, only the 3 baseline Sprint09B failures.
    - Full suite: 915 tests, exactly the 6 baseline failures.
- **L57 (2026-10-16), T27:**
  - 4b-3 (`8e0e099`): `WebhookController::dispatch` moved verbatim into the new final `Conversacion/UseCase/ProcessWebhookPayloadUseCase::execute(array $payload)`. It keeps the type filter, the label and caption text, the per-message catch with its log lines, and no catch per status entry (a throwing status still reaches the controller's catch, so Meta gets 200).
  - The controller keeps only HTTP: verify (GET), HMAC → JSON → record payload → use case → 200. It no longer depends on the inbound or status use cases. The stale "Sprint 13.2: log only" docblock went away with `dispatch`, so C-20 drops that example.
  - Docs: the F1 step table in `04-flujos.md` (controller and inbound use case lines, stale since 4b-1), R-M1/R-M7/R-M8 and the architecture tables.
  - Checks:
    - `lint:container` OK; PHPStan level 0 identical (29 lines).
    - Inbound/webhook tests: 110 tests, only the 3 baseline Sprint09B failures.
    - Full suite: 915 tests, the 6 baseline failures plus two `401` in `TeamControllerTest::testListTeamsDeniedForPlainAgent` and `AuthorizationTest::testAgentWithoutLinkedAgentGets403OnTicketEndpoint` (the known JWT flake; neither touches the webhook).
  - Phase 4b code is complete (T25–T27; 4b-2 changes nothing). Next: independent verifier, then a front smoke with real inbound messages, push and PR #6 with the user's approval.
- **L58 (2026-10-16), phase 4b independent verification:**
  - Verdict PASS for 4b-0, 4b-1, 4b-2 and 4b-3, no blockers. Baseline `eaa7cc6` vs HEAD `091dfd1`, both trees run from scratch copies with their own vendor, Graph and storage stubbed (no Meta calls): 69 tests, identical results, only the baseline Sprint09BOutbox failure.
    - 4b-0: `WebhookInboundCharacterizationTest` passes unchanged on the baseline tree.
    - 4b-1: same step order, 3 flushes, lock only for an existing ticket, refresh only after a reopen, sequence increments, rules and auto-assign inside the transaction, auto-reply before the outbox; same log lines and keys; `processInboundMessage` signature unchanged.
    - 4b-3: the use case body is the old `dispatch` character for character; the per-message catch, no per-status catch and the controller's outer catch are unchanged; same logger service.
  - 15 probes pass on both trees and are committed as `tests/Characterization/WebhookInboundVerifierProbeTest.php` (`c2d001b`). Parent spot check: `--filter 'WebhookInboundVerifierProbe|WebhookInbound|WebhookController'` gives 36 tests OK.
  - Advisories (same on both trees or out-of-domain):
    - The `trace` text of `Webhook dispatch error` and PHP warning locations now name `ProcessWebhookPayloadUseCase` instead of `WebhookController::dispatch`; this comes with moving the code.
    - The duplicate-WAMID contradiction wording was narrowed in S10.
    - A storage failure after a successful download is absorbed by `MediaDownloadService` (no `Failed to process inbound media` line); media without `mime_type` is stored with `kind: document`.
    - `{"entry":"x"}` raises PHP warnings and still returns 200.
    - New pinned cuts: an array `from` or a non-string `phone_number_id` stops the rest of the payload (still 200).
  - Next: front smoke with real inbound messages, then push and PR #6 (base `chore/limpieza-backend-fase-4`), with the user's approval.

- **L59 (2026-10-16), phase 4b front smoke with real inbound messages:**
  - Run by the front against `e6e724c` (front log L21, `9be0977`). No differences caused by 4b and no lost messages (no grouped webhooks arrived).
    - Text, image with caption, PDF and audio are stored with the same labels as before 4b (compared with messages 154/288/289); media in MinIO and visible live.
    - Sticker and location reach `webhook_payloads` and are discarded, as before.
    - #501: waiting → active on the first inbound; the agent reply (WAMID, `delivered` in 3 s) moved it to waiting; the next inbound moved it back to active.
    - Mercure captured `message.added` ×6, `message.updated` (delivered) ×1 and `ticket.read` ×1; all outbox rows published.
  - Notes, unchanged from before 4b: waiting/active transitions emit no ticket event (no outbox row, same as resolve and close). Meta sent no `read` status for the reply (only `sent` and `delivered`), most likely the phone's read receipts setting; not verified.
  - Phase 4b is verified. Next: push and PR #6 (base `chore/limpieza-backend-fase-4`), with the user's approval.
- **L60 (2026-10-16):** User: "dale, hacé el push y abrí el PR #6". Pushed `chore/limpieza-backend-fase-4b` and opened PR #6 (https://github.com/giannizeniquel/inbiox/pull/6), base `chore/limpieza-backend-fase-4`, 10 commits from `eaa7cc6`. Phase 4b is closed. Next part: 4c (reporting), still to be planned.
- **L61 (2026-10-16), phase 4c plan:**
  - A read-only explorer mapped the `Reporte` module: 5 files; the controller has no EntityManager or SQL left; all native SQL lives in the 1155-line `ReportingRepository`, whose largest methods are `getTicketParticipation` (181 lines), `getResponseDelayMetrics` (117) and `getAgentPerformance` (108). Existing exact-body HTTP coverage is limited to rt, counts, volume and agents; `ReportingV2Test` is shape-only and seeder-based. Parent spot check confirmed the participation endpoint has no workspace scope (`ReportingController.php:480`).
  - User accepted the plan and asked to log every finding in the contradiction list for after the cleanup (quote in S11). S11 and T28–T34 added. Route: inline for every task (no writer trigger). Forecast: above ~400 changed lines, mostly tests; delivered as one stacked PR like 4a and 4b.
- **L62 (2026-10-16), T28:**
  - 4c-0 (`094fffe`, tests only): `ReportingControllerCharacterizationTest` now pins, with exact bodies over HTTP and its own fixtures in a fixed window: frt, agent-load, unassigned (with and without a window), available-agents (supervisor, and an agent filtered by its active workspace, using availability overrides so the result does not depend on the time), frt-trend, heatmap, recurring-customers (default threshold, `threshold=2`, and `threshold=0` falling back to 3), reopened, response-delay and ticket participation 200. The date-parameter error cases now also cover frt, frt-trend, heatmap, reopened and response-delay, plus a malformed date on unassigned. 29 tests in the file (was 12 methods).
  - Findings pinned as-is: unassigned `count` is 0 while its `tickets` list is not empty (F); the default ticket priority in reports is `medium`; JSON floats with no fraction come out as integers (`JsonResponse` without `JSON_PRESERVE_ZERO_FRACTION`).
  - Checks: the file passes (29 tests, 132 assertions); full suite 947 tests, the 6 baseline failures plus one `401` (expected 403) in `TicketClosureAuthorizationTest::testAgentWithNoLinkedAgentIsDenied`, the known JWT flake, which passes on an isolated rerun.
- **L63 (2026-10-17), T29:** User: "estamos de vuelta, seguimos".
  - 4c-1 (`525660d`): new private `ReportingRepository::ticketFilterClause(array &$params, string $alias, ?int $agentId, ?string $phoneNumberId, ?int $teamId): string` builds the optional ticket filters in the existing order (agent, workspace, team) and binds their parameters. It replaces the 12 copies in frt, rt, volume, agent performance (subquery `t2` and join `t`), agent load, unassigned, frt trend, heatmap, recurring customers, reopened and response delay. The generated SQL text and parameters are the same; `getAgentPerformance` now defines `$params` before the SQL (named parameters, so the order does not matter). The `a.id = :agentId` filters on agents stay as they are.
  - Checks:
    - `lint:container` OK; PHPStan level 0 identical (29 lines).
    - Reporting-related tests (`Reporting|Reports|Sprint09AReporting|AuthorizationTest`): 149 tests, only the baseline `ReportingV2Test` failure.
    - Full suite: 947 tests, the 6 baseline failures plus two known JWT flakes: `AgentControllerTest::testUpdateAgentOmittingTimezoneLeavesItUnchanged` (401 instead of 200) and `InboxControllerCharacterizationTest::testMessagesForwardDirection` (null body). Neither touches reporting, and both pass on an isolated rerun.
- **L64 (2026-10-17), T30:** User: "dale, seguí con la 4c-2".
  - 4c-2 (`e966cbb`): the body of `ReportingController::availableAgents` moved verbatim into the new final `Reporte/Query/ListAvailableAgentsQuery::execute(?string $phoneNumberId, \DateTimeImmutable $atUtc)`: the available-agents cascade, the workspace filter in PHP and one active ticket count per agent (N+1 kept). The controller passes the workspace scope and the current time, and no longer depends on `AgentAvailabilityService` or `TicketRepository`.
  - Docs: the `available-agents` row in `06-api-y-permisos.md`.
  - Checks:
    - `lint:container` OK; PHPStan level 0 identical (29 lines).
    - Reporting-related tests: 159 tests, only the baseline `ReportingV2Test` failure.
    - Full suite: 947 tests, exactly the 6 baseline failures.
- **L65 (2026-10-17), T31:** User: "dale, seguí con la 4c-3".
  - 4c-3 (`21aa251`): `ReportingRepository::getTicketParticipation` now reads as its steps, each a private method: `findParticipationEpisode` (ticket row and episode end), `getAttributionCutoff` (unchanged), `fetchParticipationPairRows` (the burst CTE), `countEpisodeMessagesBySender` (the rollup), `foldParticipationPairs` and `buildParticipants` (with the fallback agent-name lookup). The SQL text, the query order (ticket, episode end, cutoff, pairs, rollup, names) and the result are unchanged.
  - Docs: line references into `ReportingRepository.php` in `docs/02_sistema-actual` (00 group F, 04 FRT row, 05, 08 C-2) were already shifted by 4c-1; they are updated once at the end of 4c, in the same PR (S7).
  - Checks:
    - `lint:container` OK; PHPStan level 0 identical (29 lines).
    - Reporting-related tests: 149 tests, only the baseline `ReportingV2Test` failure.
    - Full suite: 947 tests, the 6 baseline failures plus two `401` JWT flakes (`InboxControllerCharacterizationTest::testMarkReadOfUnassignedTicketByAdminWithoutAgentIsANoOp`, `AgentControllerTest::testUpdateAgentTimezone`), both passing on an isolated rerun.
- **L66 (2026-10-17), T32:** User: "dale, seguí con la 4c-4".
  - 4c-4 (`e41bd28`): `ReportingRepository::getResponseDelayMetrics` is now its two steps: private `fetchResponseDelayRows` (the single CTE query, unchanged) and `aggregateResponseDelayRows` (the PHP fold into aggregate and per-agent). The duplicated cutoff probe stays inside the CTE (contradiction track).
  - Checks:
    - `lint:container` OK; PHPStan level 0 identical (29 lines).
    - Reporting-related tests: 149 tests, only the baseline `ReportingV2Test` failure.
    - Full suite: 947 tests, the 6 baseline failures plus two JWT flakes, both passing on an isolated rerun: `SettingsPageTest::testReplaceShiftsReplacesAll` (401) and `ReportsCsvExportTest::test_date_range_filters_on_created_at` (0 rows instead of 1; its `requestExport` helper does not check the status, so a 401 body parses as an empty CSV; export does not use the changed code).
- **L67 (2026-10-17), T33:** User: "dale, seguí con la 4c-5".
  - 4c-5 (`5365bc0`): `ReportingRepository::getAgentPerformance` returns only query data; the new private `ReportingService::addIsInShift` loads the agents with `AgentRepository::findByIds` (the same DQL the repository had inline), evaluates `AgentAvailabilityService::isInShift` with the current time, and inserts `is_in_shift` right after `current_load`, so the JSON key order is unchanged. An agent that cannot be loaded is still reported as not in shift. `ReportingRepository` no longer depends on `AgentAvailabilityService` or imports `Agent`.
  - New characterization test `testAgentPerformanceReportsAnAgentInShift`: seven 00:00-00:00 shifts (read as cross-midnight shifts covering the whole day) make the result independent of the time; it asserts `is_in_shift` true and the key position. It passes on the pre-change code too.
  - Checks:
    - `lint:container` OK; PHPStan level 0 identical (29 lines).
    - Reporting-related tests plus `Sprint10ControllerTest`: 166 tests, only the baseline `ReportingV2Test` failure.
    - Full suite: 948 tests, exactly the 6 baseline failures.
- **L68 (2026-10-17), T34:** User: "dale, seguí con la 4c-6".
  - 4c-6 (`216be83`):
    - Every native query in `ReportingRepository` now says why it is native and which tests cover it: TIMESTAMPDIFF (frt, rt, agent performance, frt trend), DATE() (volume, frt trend), DAYOFWEEK()/HOUR() (heatmap), UNION ALL of `LIMIT 1` subqueries (attribution cutoff), CTEs and window functions (response delay), and MySQL-formatted date strings that DQL would hydrate into objects (unassigned, reopened). For agent load and recurring customers the comment says plainly that DQL could express them and that converting them is a separate change.
    - `getUnassigned` and `getReopened` bind `LIMIT` as an integer parameter instead of concatenating it.
    - `ReportingService` docblocks now describe the real return shapes (frt, rt, agent performance with `is_in_shift`, unassigned tickets, participation).
    - Docs: line references into `ReportingRepository.php` updated (00 group F, 04 FRT row, 05 active tickets, 08 C-2), and the 08 row about `str_replace` counts now records that only `getUnassigned` uses it.
  - Checks:
    - `lint:container` OK; PHPStan level 0 identical (29 lines).
    - Reporting-related tests (`Reporting|Reports|AuthorizationTest`), four runs: the baseline `ReportingV2Test` failure plus one random JWT flake per run. In the first run the flake hit `ReportingControllerCharacterizationTest::testReopenedTickets`, whose message was not captured; that test passed in the other three grouped runs and three isolated runs, and a later run showed the same file's `testDateParameterErrors` failing with `401 Invalid JWT Token`.
    - Full suite: 948 tests, the 6 baseline failures plus two `401` flakes (`AgentLinkControllerTest::testLinkUserReturns404ForNonExistentUser`, `ReportsCsvExportTest::test_agent_is_denied`), both passing on an isolated rerun.
  - Phase 4c code is complete (T28–T34). Next: independent verifier over S11 (baseline `c96ffa6`), then a front smoke of the reports dashboard, push and PR #7 with the user's approval.
- **L69 (2026-10-17), phase 4c independent verification:** User: "dale, lanzá el verificador".
  - Verdict PASS for 4c-0 to 4c-6 and S11, no blockers. Baseline `c96ffa6` vs HEAD `1b5cba1`, both run from scratch copies (`mktemp -d`, own vendor), one test process at a time, no remote calls.
    - 4c-0: the HEAD characterization file (30 tests, including the in-shift test) passes on the baseline tree.
    - 4c-1: `ticketFilterClause` produces the same SQL text and parameters as the old blocks (order agent, workspace, team; `t2`/`t` aliases in agent performance; workspace before agent in agent load).
    - 4c-2: `ListAvailableAgentsQuery` is the old controller body word for word.
    - 4c-3/4c-4: same SQL in the same order; participation and response-delay bodies identical, scoped and unscoped.
    - 4c-5: `is_in_shift` value and key position unchanged; a missing agent is false.
    - 4c-6: 55 unassigned tickets are capped at 50 and 105 reopened events at 100, newest first, on both trees; bug F unchanged; docblocks match the real shapes; the updated doc references point at the right code.
  - 7 probes (252 assertions, exact bodies with ids replaced by stable tokens) pass on both trees and are committed as `tests/Characterization/ReportingVerifierProbeTest.php` (`73802ff`). Parent spot check: `--filter 'ReportingVerifierProbeTest|ReportingControllerCharacterizationTest'` gives 37 tests OK; full suite 955 tests, exactly the 6 baseline failures.
  - Advisories:
    - The metrics table in `04-flujos.md` still had stale references (RT, agent performance, unassigned, and also reopened, response delay and participation, found by the parent): fixed in `a093968`.
    - `response-delay` reports `partial_data: true` when the range starts before the first attributed message even if all messages in the range are attributed; same on the baseline, added to S11's list to check.
  - Setup note: the verifier copied the ignored `apps/api/.env`, `.env.test.local` and `config/jwt` into its scratch trees (without reading them) because the `inbiox_php` environment has no `DATABASE_URL`; the scratch trees were removed and the workspace has no new files.
  - Next: front smoke of the reports dashboard, then push and PR #7 (base `chore/limpieza-backend-fase-4b`), with the user's approval.
- **L70 (2026-10-17), phase 4c front smoke:**
  - Run by the front against `832e79e`. No differences caused by 4c; every `/api/reports/*` call returned 200 (no 500).
    - API values match the SQL of the `c96ffa6` `ReportingRepository` for the cards, volume, agent performance with `is_in_shift`, agent load, FRT trend, heatmap, recurring customers, reopened and response delay, with and without team and workspace filters. `partial_data` is true only when the range starts before 2026-10-12 01:34:08 (the local attribution cutoff).
    - CSV export and ticket participation are identical to the baseline.
  - Notes for the contradiction track: "Volumen diario" at 0 and the empty agent ranking are a frontend bug (it reads `.data`/`.agents`; the API returns plain arrays), added to S11's list. 396 of the 400 local tickets belong to a number with no workspace.
  - Phase 4c is verified. Next: push and PR #7 (base `chore/limpieza-backend-fase-4b`), with the user's approval.
- **L71 (2026-10-17):** User: "dale, hacé el push y abrí el PR #7". Pushed `chore/limpieza-backend-fase-4c` and opened PR #7 (https://github.com/giannizeniquel/inbiox/pull/7), base `chore/limpieza-backend-fase-4b`, 19 commits from `c96ffa6`. Phase 4c is closed, and with it phase 4 (4a #5, 4b #6, 4c #7).
- **L72 (2026-10-17):** Phase ordering with the frontend, relayed by the user. Front: "Ojo con algo: la Fase 4 del front todavía no se hizo. Es la que parte conversation.component, ticket.store y mercure.service. Lo dejé anotado para definir el orden: arrancar ya con la Fase 5 junto al back, o hacer primero la Fase 4." Agreed order (front reply): "De acuerdo con el orden: el front hace su Fase 4 contra el contrato actual; vos, el renombrado interno y la migración base; el contrato conjunto, al final. Agregá a lo que vas a verificar que renombrar las clases de eventos no cambie el event_type del outbox ni el type y el payload que llegan por Mercure. Hoy el publisher mapea por el nombre completo de la clase (App\Ticket\TicketCreatedEvent), y las filas pendientes o en dead_letter quedarían con el nombre viejo."
  - Verified: `src/Compartido/Realtime/EventTypeMapper.php:12-17` maps event FQCNs to `RealtimeEventType`, and the use cases store `XxxEvent::class` or `$event->type` in `outbox_events.event_type`. Renaming an event class therefore changes the persisted value and breaks the mapping of pending and `dead_letter` rows.
  - Phase 5 constraints: (1) the internal Spanish renaming must leave every JSON key unchanged; (2) it must leave `outbox_events.event_type` values and the Mercure `type` and payload unchanged, so event classes either keep their FQCN or the persisted value is decoupled from the class name before renaming. The phase 5 map must cover both. The joint API contract comes last, after the frontend's phase 4.
- **L73 (2026-10-17):** Front recommendation, relayed by the user: "Sobre los eventos: mi recomendación es dejar las clases de eventos con su nombre actual durante el renombrado interno. Separar event_type del nombre de la clase cambia un formato persistido (migrar filas y tocar el mapper), y encaja mejor en el contrato conjunto, donde igual vamos a definir los type de Mercure, con su propio smoke de tiempo real." Accepted: the event classes mapped in `EventTypeMapper` (and every other class whose FQCN is persisted in `outbox_events.event_type`) keep their current name and namespace during the internal renaming. Decoupling `event_type` from the class name moves to the joint API contract.
- **L74 (2026-10-17):** User: "dale, arrancá con el mapeo de la fase 5". Explorer map (internal renaming + migration baseline): no JSON key derives from a PHP name (all responses are literal arrays); all 18 entities declare table names, but ~44 `#[ORM\Column]` have no `name:` and the `agent_workspace`/`agent_team` join tables (`src/Agente/Agent.php:76,86`, spot-checked) have no `joinColumns`, so renames would change the schema unless names are pinned first; `ReflectionProperty` string names in `OutboxPublisherService`, `ResyncTicketEventsQuery` and `UpdateAgentUseCase` break silently on property renames; 28 migrations, 4 with row fix-ups not needed on a fresh DB; known mapping/schema drift (`TicketTag` inverse sides, `idx_tickets_created_at`, `lastReadAt` type) to confirm. Proposed order: 5-0 pin names, 5-1 migration baseline, 5-2..n renames per module, docs last.
  - User: "si dejamos los terminos que no estan en el glosario para despues, no tendriamos doble trabajo?" Agreed: the glossary is completed before the renames (5-2+), so each identifier is renamed once.
  - User approved the glossary proposal: "dale, aprobada, agregala al glosario". Added to `docs/arquitectura.md`: entity `TicketEvent`→`EventoDeTicket`; concepts status/direction/sender/availability, owner/ownership→`responsable`/`responsabilidad`, priority, customer→`contacto`, timeline, closure/audit, read/unread, import, load/capacity, participation/episode/attribution, keyword; `sequence` and phone number id stay English; method prefixes `get/set/is/has/find/count/create/update/delete` stay English.
- **L75 (2026-10-17):** User: "dale, creá la rama y escribí el plan". Created `chore/limpieza-backend-fase-5` from the local phase 4c tip (it carries the unpushed L72–L74 commits `d6afcef`, `f893138`, `c33c388`); local `chore/limpieza-backend-fase-4c` reset to `origin` (`cf228c0`), so PR #7 is unchanged. Plan written as S12 with T35–T37. Table and column renames are left for the joint API contract because native SQL result keys come from column names.
- **L76 (2026-10-17):** User: "dale, arrancá con la 5-0". T35 done (`8115742`).
  - 43 `#[ORM\Column]` without `name:` (found through Doctrine metadata, not regex) now declare it. The `agent_workspace`/`agent_team` join tables already had explicit `JoinColumn`/`InverseJoinColumn` (`src/Agente/Agent.php:77-78,89-90`); the map was wrong there.
  - Drift fixed to the schema the migrations create: `Ticket::$team` is a `ManyToOne` to `Team` with `JoinColumn(team_id, onDelete: SET NULL)`, keeping `FK_TICKETS_TEAM` (`Version20260912000002:57`); `getTeamId()` is kept, `setTeamId(int)` becomes `setTeam(?Team)` (only `SetTicketTeamUseCase` and 4 test fixtures used it; `SetTicketTeamUseCase` already loads and validates the team). DQL `t.teamId` → `t.team` in `ReportingRepository`. Indexes declared: `idx_tickets_created_at`, `idx_tickets_status_last_inbound`, `idx_tickets_team_id`, `idx_ticket_events_ticket_created`, `idx_messages_sender_agent_id`, `idx_teams_workspace`. `TicketTag` drops the invalid `inversedBy` (unidirectional).
  - `doctrine:schema:validate`: mapping OK (was FAIL on `TicketTag`). `schema:update --dump-sql` went from 11 (dev) / 10 (test) statements to 4 / 3, all DB-side leftovers that 5-1 removes: `DC2Type` comments on `teams.created_at/updated_at` (and dev `tickets.last_read_at`), and the auto-generated index names of `agent_team` (a ManyToMany join table cannot name its indexes in the mapping). Discovery: the test DB carries migration-only indexes and the FK, so it was not built purely from the mapping.
  - New `tests/Characterization/PersistedEventTypeCharacterizationTest.php` (8 tests): the 8 event class names as string literals, and the exact Mercure message built from a stored row for each of the 7 realtime types (TicketUpdatedEvent covers status changed and assigned via `ownerAgentId`). Characterization, so no RED.
  - Docs: `02:247`, `08` C-19 marked resolved, `03` ER diagram and `TicketTag` row.
  - Gates: full suite 963 tests, failing names exactly the 6 baseline; `lint:container` OK; PHPStan level 0 identical (29 lines vs `82f9451`). One run of the focused filter showed `ReportingVerifierProbeTest::testReopenedIsCappedAtOneHundredNewestFirst` failing before the test fixtures were updated; it passed in the next focused run and in the full suite (not captured; same pattern as L68). RDD assess vs `cf228c0`: medium, `under_budget`.
  - Next: 5-1 migration baseline (T36).
- **L77 (2026-10-17):** User: "estamos de vuelta, seguí con la 5-1". T36 implemented (`4f6d95e`), pending the independent verifier (Risk item 1: migrations).
  - The old chain does not apply to an empty DB: `Version20260616000001` fails with "Illegal mix of collations (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_0900_ai_ci,IMPLICIT)" (`user` is created `unicode_ci`, `agents` gets the server default). The dev DB works because all its tables are `unicode_ci`.
  - Baseline `Version20261017210004` generated with `doctrine:migrations:diff` against an empty scratch DB (`DATABASE_URL` override; scratch DBs on the same MySQL server, dropped after). `config/packages/doctrine.yaml` gets `default_table_options` `utf8mb4`/`utf8mb4_unicode_ci`, without which every table would be created `utf8mb4_0900_ai_ci` (different string comparison than today).
  - Only two old migrations inserted rows. Their fix-ups of existing rows are not needed on a fresh DB, but their seeds are: the admin `admin@inbiox.local` (`INITIAL_ADMIN_PASSWORD`, throws when missing) and the "Default" workspace from `META_PHONE_NUMBER_ID` are carried over verbatim at the end of `up()`; without the workspace, `app:seed-users` fails ("No workspaces found. Run migrations first.").
  - Evidence: a DB from the baseline vs the dev DB (`mysqldump --no-data`, per-table line sets) differs only in FK/auto-index names (`agent_team`, `teams`, `tickets.team_id`) and the `DC2Type` comments; no column, type, nullable, index or FK missing. Fresh DB: `schema:validate` mapping OK and in sync, `schema:update --dump-sql` empty, admin and "Default" rows present, `app:seed-users` exit 0.
  - Dev DB (front recommendation relayed by the user, option A): "Opción A. Los datos de dev los usa el front para sus smokes (ws1 + ticket #501 con envío real, smoke7.admin, los 400 tickets). Condición: antes de borrar las filas de doctrine_migration_versions, mostrá la salida de doctrine:schema:update --dump-sql contra esa base para confirmar que solo quedan las diferencias cosméticas (DC2Type y nombres de FK/índices). Si aparece otra cosa, frenamos." The dump showed only 2 `RENAME INDEX` on `agent_team` and 2 `CHANGE` on `teams`/`tickets` that keep type and nullability (dropping the `DC2Type` comment). Then: the 27 rows backed up to `doctrine_migration_versions_backup_20261017`, deleted, `doctrine:migrations:version --add --all`; status 1 executed / 0 new, `migrate` "Already at the latest version", 400 tickets and 17 users kept. The backup table now appears as a `DROP TABLE` in the dev dump; drop it when no longer needed.
  - Docs: `02` migrations section and `INITIAL_ADMIN_PASSWORD` row, `03` Ticket→Team row, `08` test-DB row and the open question removed, `00` item 7 and test-DB note, `09` dev DB row and admin row.
  - Gates: full suite 963 tests, the 6 baseline failures plus 2 `401 Invalid JWT Token` flakes (`TicketEventsControllerCharacterizationTest::testMissingMercureCookieReturns401`, `AgentControllerTest::testUpdateAgentWithNonStringTimezoneReturns400`), both passing on an isolated rerun. RDD assess vs `cf228c0`: medium, `under_budget`.
- **L78 (2026-10-17):** Independent verifier for T36: PASS, no blockers (baseline `c0d21b9` vs `4f6d95e`, scratch DBs only).
  - Confirmed: fresh DB from the baseline validates and has an empty dump, all 20 app tables `utf8mb4_unicode_ci`, both seeds present and `app:seed-users` exit 0; schema equal to dev except FK/auto-index names and `DC2Type` comments; old chain at `c0d21b9` fails at `Version20260616000001` with error 1267; seed code verbatim (`Version20260616000001:53-60`, `Version20260728161023:48-58`); empty `INITIAL_ADMIN_PASSWORD` throws the same message before any SQL; `down()` leaves only `doctrine_migration_versions`; dev status 1 executed / 0 new, backup table 27 rows, dev dump exactly the 5 statements of L77, test dump 3 cosmetic statements; `lint:container` OK.
  - Needs-decision item (spec drift): S12 still said "the dev DB is recreated" and required an empty dump and `schema:validate` on dev and test. S12 rewritten to record option A (L77) and to scope the strict gates to a DB created from the baseline; existing dev/test DBs keep only the known cosmetic statements.
  - Correction to L77: the scratch DB `inbiox_baseline` had not been dropped (only `inbiox_chain` was); dropped now. Databases left: `inbiox`, `inbiox_test`.
  - Probe kept as a regression test: `tests/Characterization/DatabaseTableOptionsCharacterizationTest.php` pins `defaultTableOptions` (`utf8mb4` / `utf8mb4_unicode_ci`); observed RED with the option removed from `doctrine.yaml`, GREEN restored. Docblocks of `Sprint14MigrationTest` and `MessageAgentAttributionMigrationTest` now say their migrations were folded into the baseline.
  - Advisories, not changed: inherited `getenv()` `false` guard in the workspace seed (doc 02); drop `doctrine_migration_versions_backup_20261017` from dev when no longer needed.
- **L79 (2026-10-17):** Frontend phase 4 (branch `chore/limpieza-frontend-fase-4`: `7acf510`, `96c899b`, `9737d60`, `69773a0`) smoke-tested against the closed back commit `f2f5096`; the back tree stayed frozen during the smoke. Result relayed by the user: "Smoke de la Fase 4 del front contra f2f5096: sin regresiones. Pasaron la bandeja, el scroll y la paginación, mark-read, la reconexión de Mercure y la parte real (un entrante desde el celular y una respuesta desde la UI en el #501, con los 4 eventos del outbox publicados). Hallazgo previo, no causado por esta fase: si el hub cierra la conexión limpiamente, el front no se reconecta y sigue mostrando "Conectado". Ya estaba documentado en 07-frontend.md. Para el contrato conjunto: no hay datos de dev para probar el cambio de ticket, el 403 al resolver ni la asignación en vivo, porque solo se ven dos tickets, uno por workspace y los dos asignados. Conviene sumar esos casos a los datos de prueba de la migración base. Podés volver a tocar el árbol del back." Contract notes added to S12. The test-data suggestion is recorded there, not decided: seeding demo cases is new behavior, so it goes with the contract rather than into the baseline now. Next: 5-2 (`Ticket` module renames).
- **L80 (2026-10-17):** 5-2 (`Ticket` module) naming approved. User: "apruebo los nombres". Classes: `Tag`→`Etiqueta`, `TicketTag`→`EtiquetaDeTicket`, `TicketEvent`→`EventoDeTicket`, `TicketStatus`→`EstadoDeTicket`, `TicketEventType`→`TipoDeEventoDeTicket` (values unchanged), `TicketStateMachine`→`MaquinaDeEstadosDeTicket`, `TicketOwnershipGuard`→`TicketResponsabilidadGuard`, `ClosureAuditClassifier`→`ClasificadorDeAuditoriaDeCierre`, `InvalidTransitionException`→`TransicionInvalidaException`, `TicketFilters`→`FiltrosDeTicket`, `TicketFilterBuilder`→`FiltrosDeTicketBuilder`, `TicketDetailDTO`→`DetalleDeTicketDTO`, `TicketListItemDTO`→`TicketDeListaDTO`, `GetTicketDetailQuery`→`GetDetalleDeTicketQuery`, `TagRepository`→`EtiquetaRepository`, `InboxController`→`BandejaController`, `TicketWorkflowController`→`FlujoDeTicketController`, `AddTagToTicketUseCase`→`AgregarEtiquetaATicketUseCase`, `MarkTicketReadUseCase`→`MarcarTicketLeidoUseCase`, `RecordClosureAuditUseCase`→`RegistrarAuditoriaDeCierreUseCase`, `ResolveTicketUseCase`→`ResolverTicketUseCase`, `TransitionTicketStatusUseCase`→`CambiarEstadoDeTicketUseCase`. Unchanged: `Ticket`, `TicketRepository`, `TicketVoter`, `ListTicketsQuery`, and the persisted `TicketCreatedEvent`, `TicketUpdatedEvent`, `InboxUpdatedEvent`. Methods, properties and variables follow the glossary mechanically.
  - Tool: user asked "que es Rector?", then "dale, usemos rector de forma descartable". Rector runs in a throwaway `composer:2` container installed under the session scratchpad, never added to `composer.json`; type-aware method/property renames; strings (DQL, `findBy` keys, yaml) checked with `rg`.
- **L81 (2026-10-17):** 5-2a done (`295ee4b`): the 22 `Ticket` module classes approved in L80 renamed (files moved with `git mv`, declarations, imports, FQCN and short-name references in `src`, `tests`, `config`, `docs/02_sistema-actual` and `AGENTS.md`; the glossary rows in `docs/arquitectura.md` are kept).
  - Rector's `RenameClassRector` was tried first and reverted: it inlined FQCNs (`\App\Ticket\UseCase\CambiarEstadoDeTicketUseCase`) instead of updating the `use` lines and did not rename declarations. Class renames are done by an exact FQCN replace plus the short name in files that import the class or share its namespace; Rector stays for the type-aware method renames (5-2b).
  - Process slip, fixed before any push: the first L80 commit also took 22 staged `git mv` renames; it was undone (`reset --soft`), the files restored, and L80 recommitted alone. The index is checked before every commit since.
  - Checks: PHP tokenizer comparison of every changed file shows no string literal changed; `debug:router` identical to `HEAD` (96 lines, run from a `git archive` copy); `lint:container` OK; PHPStan level 0 identical after mapping the renamed paths (29 = 29); schema dumps unchanged (dev 5, test 3 cosmetic). Full suite 964 tests: the 6 baseline failures plus 2 `401 Invalid JWT Token` flakes (`ProviderProxyVerifierProbeTest::testSendGraphErrorRawBody`, `UserControllerTest::testCreateUserReturns409OnDuplicateEmail`), both passing on an isolated rerun.
  - RDD: the stop hook required the review preflight; the user granted review twice (workspace candidate, then the 5-2a commit with lenses risk + readability). Both STARTs failed in preflight with `lens_context_budget_exceeded` (114–115 files, ~4300 lines as measured by Gentle AI); no review authority was created. Terminal per the contract: smaller candidates or disabling RDD for the clone. Pending the user's decision.
- **L82 (2026-10-17):** User on the RDD budget failure: "dale, la 1, seguí con la 5-2b". Rename commits continue without Gentle AI review; each part keeps its gates and each module closes with an independent verifier. Later stop-hook reminders for rename candidates were not started for that reason.
  - 5-2b done (`b7fc9f4`): methods, properties, constants and enum case names of the `Ticket` module classes renamed with a throwaway Rector 2.7 run (`RenameMethodRector`, `RenamePropertyRector`, `RenameClassConstFetchRector`; mapping in the session scratchpad). Rector renamed method and property declarations but not constant/case declarations (done by script). Dates follow the approved `ultimaLecturaAt` pattern (`creadoAt`, `resueltoAt`, ...). Kept: `execute`, `build`, `getId`, `findById`, `findAll`, `ForUpdate`, `phoneNumberId`, `version`, `lastEventSeq`, `getVersion`/`get|set|incrementLastEventSeq`. Parameters, locals and DTO/filter properties are left for 5-2c.
  - Strings fixed by hand: ORM DQL in `TicketRepository` and `ReportingRepository::getCountsByStatus` (`t.estado AS status` keeps the `$row['status']` key the code reads), `findOneBy` keys (`conversacion`, `nombre`), and PHPUnit mock method names only where the mock is a `Ticket`-module type (`Contact`/`Conversation` mocks keep `getName`/`getUpdatedAt`/`findOpenByContact`). DBAL queries use column names and were not touched.
  - First full suite: 30 errors + 39 failures from calls Rector could not type (`getContainer()->get(...)` returns `mixed`), including two in `src`: `$ticket->recipientPhone()` in `SendOutboundMessageUseCase` and `SendTemplateUseCase` (500s in the provider proxy). PHPStan level 0 does not catch calls on untyped variables. Fixed, and from now on each part also scans `src` and `tests` for `->oldName(` before the suite.
  - Checks: string-token comparison shows only the intended DQL/`findBy`/mock strings; PHPStan level 0 identical (29); `lint:container` OK; mapping valid, schema dumps unchanged (dev 5, test 3). Full suite 964 tests, exactly the 6 baseline failures.
- **L83 (2026-10-18):** Comments decision. User: "los comentarios dejalos en inglés, los dejamos para lo ultimo, seguí con la 5-2c". Phase 5 renames identifiers only; code comments stay in English and are translated at the end of the cleanup (S4 asks for them in Spanish). Also: the session's PC rebooted on 2026-10-17 during 5-2c; verified that nothing of 5-2c had been written (last file change = `8e0769d`, no stash or untracked files). The session scratchpad (Rector install, rename scripts, mapping JSON) was lost; the mappings are recorded in L80/L82 and the commits.
- **L84 (2026-10-18):** User: "dale, seguí con la 5-2c". 5-2c done (`f0426c3`): parameters, local variables and promoted properties in `src/Ticket` (the frozen `TicketCreatedEvent`, `TicketUpdatedEvent`, `InboxUpdatedEvent` excluded) renamed with a PHP-tokenizer script that renames `T_VARIABLE` tokens, `$this->prop` for properties declared in the same class, and `$name` inside docblocks/comments, with a collision check (a target name already used as a local variable skips that rename; property declarations do not count). Generic programming words stay English (`$row`, `$result`, `$qb`, `$data`, `$limit`, `$items`, `$actor`, `$ticketId`, ...).
  - Public promoted properties of `DetalleDeTicketDTO`, `TicketDeListaDTO` and `FiltrosDeTicket` (`estado`, `prioridad`, `contacto`, `responsable`, `marcasDeTiempo`, `metricas`, `ultimoMensaje`; `asignacion`, `misAgenteId`, `scopeAgenteId`, `estados`, `etiquetas`, `equipoId`): external fetches with Rector `RenamePropertyRector`, and two untyped test fetches by hand.
  - Named arguments: a tokenizer scan listed every `name:` argument matching a renamed parameter (attributes excluded); fixed only where the call targets a `Ticket`-module signature (`FiltrosDeTicketBuilder::build` in `ReportingController`, `ExportTicketsCsvQuery`, `ListTicketsQuery`; `ListTicketsQuery::execute` in `BandejaController`; the DTO and `FiltrosDeTicket` constructors; `TicketResponsabilidadGuard` and the three related tests). Calls into other modules (`AssignmentService::recordOwnershipChange`, `GetTicketMessagesQuery`, events) keep their argument names.
  - Checks: no string literal changed (tokenizer comparison); no `compact()`/variable variables in the module; PHPStan level 0 identical (29); `lint:container` OK; schema dumps unchanged (dev 5, test 3). First full suite: 3 errors from an untyped `$dto->timestamps` fetch and one more failure from `->status` on a list DTO, both fixed. Final full suite 964 tests: the 6 baseline failures plus 7 `401 Invalid JWT Token` failures (more than the usual 0–2; the run before also had several), all 7 passing on an isolated rerun; host and container clocks verified in sync; no auth code was touched.
  - The `Ticket` module renames (5-2a, 5-2b, 5-2c) are complete; next per L82: an independent verifier for the module.
- **L85 (2026-10-18):** Independent verifier for the `Ticket` module renames (5-2a/b/c, baseline `950c15b` vs `9da1240`): PASS, no blockers.
  - Residual-diff probe: PHP token streams of both trees aligned with `difflib` after reverse-mapping 249 old→new identifier pairs (no merges, no conflicting targets); the only non-rename residues are the intended DQL/`findOneBy`/mock strings (L82) and 43 comment-only changes. String literals, JSON keys, native SQL, payload keys, topics, enum values, routes (96 = 96) and test class/method names (1007) unchanged; the frozen events only changed a type hint (`TicketStatus` → `EstadoDeTicket`); no PHP-serialized data. Black-box: two throwaway containers (baseline and HEAD) with an in-process kernel script and lexik-generated JWTs against the dev DB, GET only: 2043 URLs for an admin (ticket lists with filters, detail/messages/assignment/events for all 400 tickets, contacts, timelines, reports, CSV export) and 17 users × 43 list queries, all byte-identical. Untyped call-site scan: no missed renames. `lint:container` OK; PHPStan level 0 29 = 29 and level 2 37 = 37.
  - Advisories fixed in `f98aba4`: stale method names in two comments (`aplicarFiltrosDeLista`, `normalizar`). Regression test kept: `tests/Characterization/TicketDTOJsonKeysCharacterizationTest.php` pins the JSON keys and order of `DetalleDeTicketDTO` and `TicketDeListaDTO` (characterization, no RED).
  - Finding for the 401 flake (pre-existing, not changed): the main firewall's `login_throttling` also counts failed JWT checks; after 5 invalid tokens per minute from one IP it answers `401 "Too many failed login attempts"` even for valid tokens. Possible cause of the `401 Invalid JWT Token` test flake; to the contradiction track. Dev data note: `/api/tickets` shows about 2 tickets per user (lists are scoped to the active workspace), so list comparisons cover little data.
  - Next: 5-3 (`Contacto` module), per S12's order. Session paused by the user: "ok, cuando termine el verificador dame el reporte, guarda todo en engram y seguimos mas tarde".
- **L86 (2026-10-18):** 5-3a, `Contacto` module classes (`7671fea`). Mapping approved by the user ("dale, aprobados, seguí con la 5-3"): `Contact→Contacto`, `ContactController→ContactoController`, `ContactTimelineController→LineaDeTiempoDeContactoController`, `ImportRejectedException→ImportacionRechazadaException`, `PhoneNormalizer→NormalizadorDeTelefono`, `ContactDetailDTO→DetalleDeContactoDTO`, `ContactListItemDTO→ContactoDeListaDTO`, `GetContactDetailQuery→GetDetalleDeContactoQuery`, `GetContactTimelineQuery→GetLineaDeTiempoDeContactoQuery`, `ListContactsQuery→ListContactosQuery`, `TimelineEventDTO→EventoDeLineaDeTiempoDTO`, `ContactRepository→ContactoRepository`, `Create/Delete/UpdateContactUseCase→Create/Delete/UpdateContactoUseCase`, `ImportContactsFromCsvUseCase→ImportarContactosDesdeCsvUseCase`, `ImportContactsResultDTO→ResultadoDeImportacionDeContactosDTO`. No `Contacto` class name is persisted or referenced as a string (config, migrations, frontend, literals checked).
  - Method: the class-rename script from 5-2a, rewritten (the scratchpad was wiped by the reboot) with the short-name pass in the PHP tokenizer (code tokens and doc comments only). A path bug in the rewrite left 17 empty files at the old paths; deleted and the moved files reprocessed before any check. Rector reinstalled disposably in the scratchpad (its bundled PHPStan is the one the gate uses).
  - Gates: string literals identical in all 63 changed PHP files (tokenizer); `lint:container` OK; `debug:router` 91 = 91, only the two controller class names differ; PHPStan level 0 29 = 29 (one line differs only by the renamed file path); schema dumps unchanged (dev 5, test 3). Full suite 966 tests: the 6 baseline failures plus 5 `401 Invalid JWT Token` failures, all 5 passing on an isolated rerun (`ReportsCsvExportTest::test_status_param_narrows_results` shows it as "size 0" because its helper does not check the status code).
  - Next: 5-3b, `Contacto` methods, properties and constants.
- **L87 (2026-10-18):** 5-3b, `Contacto` methods, properties and constants (`2f9d682`, docs `d842ca1`). Entity: `phone→telefono`, `name→nombre`, `conversations→conversaciones`, `status→estado`, `channel→canal`, `ownerId→responsableId`, `createdAt→creadoAt`, `updatedAt→actualizadoAt` (`email` stays), with their getters/setters. Controllers: `list→listar`, `import→importar`, `getTimeline→getLineaDeTiempo`. `getReason→getMotivo`, `NormalizadorDeTelefono::normalize→normalizar`, `resolveActiveTicketIds→agregarTicketsActivos`. Repository: `findOneByPhone→findOneByTelefono`, `findListPage→findPaginaDeLista`, `countList→countLista`, `findDetailRow→findFilaDeDetalle`, `findTimelineRows→findFilasDeLineaDeTiempo`, `applyListFilters→aplicarFiltrosDeLista`. CSV import: `MAX_ROWS→MAX_FILAS`, `MAX_ERRORS→MAX_ERRORES`, `ALLOWED_MIME_TYPES→MIME_TYPES_PERMITIDOS`, `OPTIONAL_COLUMNS→COLUMNAS_OPCIONALES` (`MAX_BYTES` stays), private helpers translated. No serializer reads the entity, so the getter renames cannot change JSON.
  - Manual edits Rector does not make: constant declarations, `NormalizadorDeTelefono::normalizar` declaration (caught by PHPStan), `findOneBy(['telefono' => ...])`, `Conversation` `inversedBy: 'conversaciones'` (the only two literal diffs), and mock method strings on `Contacto`/`ContactoRepository` mocks in two unit tests (the first suite errored on them; `Conversation` mocks untouched). The repository's DBAL query builder uses column names and did not change.
  - Gates: `lint:container` OK; `schema:validate` mapping OK; `debug:router` 91 = 91, only the renamed actions differ; PHPStan level 0 29 = 29; schema dumps unchanged; untyped call-site scan clean. Full suite 966: the 6 baseline failures plus 5 `401 Invalid JWT Token` failures, all passing on an isolated rerun.
  - Incident: an unscoped `git diff` printed `apps/api/.env.dev` (Meta access token) into the session transcript; reported to the user. All later diffs are scoped to `src`, `tests` and `docs`.
  - Next: 5-3c, variables, parameters and DTO properties.
- **L88 (2026-10-18):** 5-3c, `Contacto` parameters, variables and DTO properties (`4623081`). PHP-tokenizer script (rewritten): `T_VARIABLE` tokens, `$this->prop` only for properties declared in the same file, `$name` in doc comments, aborting on a collision. Business names translated (`contacto`, `telefono`, `nombre`, `estado`, `canal`, `responsableId`, `marcasDeTiempo`, `tipo`, `ocurridoAt`, `contenido`, `direccion`, `motivo`, `errores`, `importados`, `fallidos`, `totalDeFilas`, injected use cases and queries after their new class names); technical and generic names kept (`row`, `data`, `result`, `limit`, `cursor`, `before`, `qb`, `email`, `phoneNumberId`, CSV `delimiter`/`column`/`mimeType`). DTO public property reads from other classes via Rector; `toArray()` keys unchanged.
  - Named arguments: fixed from PHPStan's "Unknown parameter" lines. Level 0 only checks `new X(...)` and `$this` calls, so it missed `ListContactosQuery::execute(estado:, canal:, responsableId:)` and `GetLineaDeTiempoDeContactoQuery::execute(contactoId:)` in the controllers; the first suite had 5 HTTP 500s (`Sprint15ContactTimelineTest` ×4, `AuthorizationTest::testAgentCanAccessContacts`). PHPStan level 2 is now a gate: identical to baseline `9f30b9c` except the renamed file and parameter names. Three untyped DTO reads fixed by hand (`$dto->ticketActivoId` in `ListContactosQuery`, `->tipo` in two timeline tests).
  - Gates: string literals identical in all 21 changed files; `lint:container` OK; `schema:validate` mapping OK; `debug:router` 91 = 91 identical; PHPStan level 0 and 2 equivalent; schema dumps unchanged. Full suite 966: the 6 baseline failures plus 7 failures, 6 of them `401 Invalid JWT Token` and one hidden 401 (`ReportsCsvExportTest`, helper ignores the status); all 7 pass on an isolated rerun together with every contact, timeline and authorization test (104 tests).
  - Next: independent verifier for the `Contacto` module (5-3a/b/c, baseline `9f30b9c`).
