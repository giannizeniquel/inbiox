# Tasks: Team/Area Entity (Tier 2 #7)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines (whole change) | ~1500–2000 (backend entity/migration/API/RED tests ~950–1350; frontend admin UI+use-cases+filter UI ~550–650) |
| 400-line budget risk | High |
| 800-line budget risk (project override) | High — ~2x over 800-line override |
| Chained PRs recommended | Yes |
| Suggested split | Slice 1 → Slice 2 / Slice 3 (parallel, both depend on 1) |
| Delivery strategy | single-pr |
| Chain strategy | pending — needs user choice |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

`single-pr` needs `size:exception` before a single-PR apply; ~1500–2000 lines is ~2x the 800-line override — don't default silently. Ask user: accept exception, or chain via the 3 units below.

### Suggested Work Units

| Unit | Goal | PR | Focused test | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | Team entity+migration+Admin CRUD+`GET /api/teams`+setTeam | PR 1 | `phpunit --filter=Team` | `docker compose exec php bin/phpunit` | `down()` migration, revert new files |
| 2 | Angular admin teams page + DTO/use-cases | PR 2 (base: PR1) | `ng build --configuration production` | N/A — no Chrome, no `ng test` | Remove `features/admin/teams/` + route |
| 3 | Filter threading: builder+queries+ReportingRepository+UI | PR 3 (base: PR1) | `phpunit --filter=TicketFilterBuilder\|ReportingRepository` | `docker compose exec php bin/phpunit` | Revert `teamId` params (nullable, no-op) |

## Phase 1: Foundation — Domain & Migration (Slice 1) — ✅ DONE

- [x] 1.1 RED: `TeamTest.php` — `addAgent()` rejects agent outside team's workspace; accepts one inside (spec: team-management).
- [x] 1.2 GREEN: create `Domain/Team/Team.php` (mirror `Workspace.php`; `is_active`; invariant throws `\InvalidArgumentException`).
- [x] 1.3 Create `migrations/Version20260912000002.php` — `teams`, `agent_team`, `tickets.team_id` FK `SET NULL`.
- [x] 1.4 Modify `Domain/Agent/Agent.php` — `$teams` M:N owning side, `agent_team` JoinTable, `addTeam()`/`removeTeam()`.
- [x] 1.5 Modify `Domain/Ticket/Ticket.php` — `?int $teamId`, `getTeamId()`/`setTeamId()`.

## Phase 2: API — Admin CRUD & Ticket Assignment (Slice 1) — ✅ DONE

- [x] 2.1 RED: `TeamControllerTest.php` — list/create(409 dup)/get/update/deactivate (spec: administration).
- [x] 2.2 GREEN: create `Controller/Admin/TeamController.php` mirroring `Admin/WorkspaceController.php`.
- [x] 2.3 RED: bulk `PUT /admin/teams/{id}/agents`, one invalid agent → 400, zero rows written (spec: administration).
- [x] 2.4 GREEN: add `assignAgents` — copy `WorkspaceController::assignAgents` transaction/catch pattern.
- [x] 2.5 RED: `TicketControllerTest.php` — setTeam success/cross-workspace 400/clear-null (spec: team-management).
- [x] 2.6 GREEN: add `setTeam()` to `TicketController.php` — `PUT /api/tickets/{id}/team`, ROLE_AGENT.
- [x] 2.7 GREEN: add `GET /api/teams` (any ROLE_AGENT) per confirmed open-question decision.
- [x] 2.8 Register routes in `config/routes.yaml` — admin CRUD, assignAgents, setTeam, `app_teams_list`.
- [x] 2.9 Integration test: deactivate team retains `tickets.team_id` (spec: team-management).

## Phase 3: Filter Threading — Backend (Slice 3)

- [ ] 3.1 RED: extend `TicketFilterBuilderTest.php` — `teamId=null` byte-identical; `teamId=7` appends one clause last (spec: ticket-list-filters).
- [ ] 3.2 GREEN: append `?int $teamId = null` to `TicketFilterBuilder::build()`.
- [ ] 3.3 Modify `ListTicketsQuery.php`, `ExportTicketsCsvQuery.php` — thread `teamId` last.
- [ ] 3.4 Modify `InboxController.php` — read `teamId` query param.
- [ ] 3.5 RED: `ReportingRepositoryTest.php` — `teamId=null` identical for all 5 methods; `teamId` set narrows correctly (spec: reports).
- [ ] 3.6 GREEN: thread `teamId` through `getFrtMetrics`, `getRtMetrics`, `getCountsByStatus` (DQL `t.teamId`), `getVolumeOverTime`, `getAgentPerformance` (sub+join clauses).
- [ ] 3.7 Modify `ReportingController.php` — forward `teamId` to metrics + CSV export.

## Phase 4: Frontend Admin UI (Slice 2)

- [ ] 4.1 Create `core/dto/team.dto.ts`, `core/api/team-api.service.ts`.
- [ ] 4.2 Create `list/create/update/deactivate-team.use-case.ts`.
- [ ] 4.3 Create `features/admin/teams/teams-page.component.{ts,html,scss}` — copy `workspaces-page.component.ts`; surface 400 via `err?.error?.error`.
- [ ] 4.4 Modify `app.routes.ts` — `admin/teams`, `roleGuard('ROLE_ADMIN')`, lazy-loaded.

## Phase 5: Frontend Filter UI (Slice 3)

- [ ] 5.1 Modify inbox feature — single-select team `<select>` from `GET /api/teams`, composable with tag filter.
- [ ] 5.2 Modify reports UI — team filter control.

## Phase 6: Verification

- [ ] 6.1 `docker compose exec php bin/phpunit` — no new failures beyond 58-failure baseline.
- [ ] 6.2 `cd apps/web && ./node_modules/.bin/ng build --configuration production` — exit 0.
- [ ] 6.3 Manual E2E: create team, assign roster, filter inbox by team.
