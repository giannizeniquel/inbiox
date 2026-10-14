# Inbiox Agents Guide

## Architecture

- **Type:** SaaS customer support platform. WhatsApp conversations become tickets that are assigned to agents.
- **Stack:** Symfony 7.4 API (`apps/api`), Angular 21 (`apps/web`), MySQL 8, Mercure (SSE realtime via a transactional outbox), WhatsApp Cloud API, MinIO/S3 for media.
- **Current-system knowledge base:** start at `docs/02_sistema-actual/README.md`. It maps features, flows, business rules, API permissions, known risks, and the manual test guide, all verified against the code. Most other docs under `docs/` are partially stale; that README lists which ones to trust.
- **Target architecture, rules, and glossary:** `docs/arquitectura.md` (backend and frontend). The code does not follow it yet; the cleanup runs in phases tracked in `odd/tasks/limpieza-backend.md`.

---

## Development Rules

Full rules and glossary in `docs/arquitectura.md`. New code follows them; existing code is adapted in its cleanup phase.

- **No new features** until the backend cleanup is finished. Cleanup phases never change behavior; product contradictions are decided separately.
- **Organize by business module** (`src/Ticket/`, `src/Conversacion/`…), each with `Controller/`, `UseCase/`, `Query/`, `Repository/`. Doctrine entities are the domain model.
- **Controllers only handle HTTP**: no `EntityManager`, `flush()`, or SQL. Every write goes through a use case; the outbox is written in one place; errors are handled by one exception listener.
- **No interface without two real implementations.**
- **SQL:**
  - Use the Doctrine QueryBuilder by default.
  - Native SQL is allowed only inside a repository: in a named method, with bound parameters, a comment saying why DQL is not enough, and its own test.
  - Never put SQL in use cases, controllers, or commands; never concatenate SQL fragments; no stored procedures or views.
- **Naming:**
  - Business vocabulary in Spanish; technical vocabulary in English (layer folders, technical suffixes, jargon like webhook/outbox/token). Framework contracts are never translated.
  - No accents or `ñ` in identifiers.
- **Minimalism:** every class, layer, or dependency needs a concrete current use.
- **Check twice before deleting "dead" code.** Before removing anything that looks unused, (1) search every reference, including config, docker, systemd, host crontabs (`crontab -l`), scripts, and the frontend, and (2) read the code that uses it to confirm what it really does. Live code has nearly been deleted this way before.

---

## Workflow

Work follows **Organic Driven Development (ODD)**, defined in the user-level agent instructions installed by gentle-ai (4.1.1+).

- Small, understood changes are made directly, with their focused test and the suite run once each.
- Large work is tracked in `odd/tasks/<feature-name>.md` (Specs, Tasks, Log), mirrored in Engram under `odd/<feature-name>/tasks`, and delivered as work-unit commits on a feature branch. Push, PR, and merge remain human decisions.
- **SDD is retired (2026-10-13).** Do not create new `openspec/changes/`. `openspec/specs/` remains a reference for confirmed behavior. `openspec/changes/`, `openspec/config.yaml`, `sdd/`, `docs/05_sdd/`, and `docs/sdd-model-routing.md` are historical.

---

## Testing

Use these commands as the test runner for test-first work.

### Backend (PHPUnit)

```bash
# 1. Clear the login rate limiter first, or ~140 false failures appear (HTTP tests log in repeatedly).
docker exec inbiox_php php bin/console cache:pool:clear cache.rate_limiter --env=test
# 2. Run with more memory; the default 128M runs out mid-suite.
docker exec inbiox_php php -d memory_limit=512M vendor/bin/phpunit            # full suite
docker exec inbiox_php php -d memory_limit=512M vendor/bin/phpunit --filter X # focused
```

- `make test` does **not** apply either step above, so it produces false failures.
- The suite is **not green**: 6 tests fail on purpose because they expose real code problems (groups F, G, H in `docs/02_sistema-actual/00-estado-y-pendientes.md`); the baseline list is in `odd/tasks/limpieza-backend.md`. Compare failing test **names** before and after a change, not just the count.
- Known flake: 0–2 random HTTP tests per full run get `401 Invalid JWT Token`; a new failure with exactly that signature is not a regression.
- Never run two full suites at the same time: they share one test database.
- The test database is built from the Doctrine mapping, not from migrations, so tests do not validate the migration chain.

### Frontend (Angular)

- Karma/Jasmine specs cannot run in this WSL2 environment (no Chrome). Write specs, but the enforceable gate is the production build:

```bash
cd apps/web && ./node_modules/.bin/ng build --configuration production
```

---

## Local Development

- **Location:** always work inside WSL2 (`/home/...`), never in `/mnt/c`.
- **Setup:** see `docs/02_sistema-actual/09-guia-de-pruebas-manuales.md` section 1 (`docs/07_dev/setup_local.md` is a generic template and does not apply).
- **Services (docker-compose):** `php`, `nginx` (API on :8000), `db` (MySQL), `mercure` (:3000), `publisher` (outbox → Mercure), `web` (`ng serve` on :4200), `minio`, `adminer`. `window-closer` (24 h window auto-close) only runs with `--profile window-closer`. Redis (cache) and a mailer are planned but not installed; add them when there is a real use case.
- **Commands:** `make up`, `make bash`, `make migrate`. `make seed` calls a command that does not exist; seed users with `docker exec inbiox_php php bin/console app:seed-users`.

---

## Key Conventions

- HTTP routes are **currently** registered only in `apps/api/config/routes.yaml`. Controller `#[Route]` attributes are not loaded and are stale; a route missing from that file is unreachable.
  - Target (cleanup phase 2): attributes become the single source, loaded via `resource: routing.controllers`, and are regenerated from the YAML. The check is that `debug:router` output is identical before and after.
- States and fixed values are backed PHP enums, not catalog tables; use a table only when the business must configure the values without a deploy.
- Effective permissions combine `access_control` in `security.yaml`, class-level `#[IsGranted]`, method-level `#[IsGranted]`, and voters. All of them must pass.
- Frontend role checks are not hierarchical: `isAdmin()` and `isSupervisor()` are separate flags, so supervisor-gated UI must check `isAdmin() || isSupervisor()`.
- Ticket status changes follow the FSM in `apps/api/src/Domain/Ticket/TicketStateMachine.php`.
- Realtime events are written to the `outbox_events` table and published by the `publisher` service; use cases never publish to Mercure directly.

---

## Additions (minimalism)

- Every addition — dependency, skill, plugin, MCP server, agent configuration — needs a real, concrete use case in this project. Without one, it does not go in.
- gentle-ai's `branch-pr` and `issue-creation` skills enforce the gentle-ai repository's process (an issue first). They do not apply to inbiox: do not open or require an issue before a PR. Push, PR, and merge are human decisions.
