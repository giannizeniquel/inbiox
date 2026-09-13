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

## Phase 3: Filter Threading — Backend (Slice 3) — ✅ DONE

- [x] 3.1 RED: extend `TicketFilterBuilderTest.php` — `teamId=null` byte-identical; `teamId=7` appends one clause last (spec: ticket-list-filters).
- [x] 3.2 GREEN: append `?int $teamId = null` to `TicketFilterBuilder::build()`.
- [x] 3.3 Modify `ListTicketsQuery.php`, `ExportTicketsCsvQuery.php` — thread `teamId` last.
- [x] 3.4 Modify `InboxController.php` — read `teamId` query param.
- [x] 3.5 RED: `ReportingRepositoryTeamFilterTest.php` — `teamId=null` identical for all 5 methods; `teamId` set narrows correctly (spec: reports). (Named `ReportingRepositoryTeamFilterTest.php`, not `ReportingRepositoryTest.php` — no such file existed to extend.)
- [x] 3.6 GREEN: thread `teamId` through `getFrtMetrics`, `getRtMetrics`, `getCountsByStatus` (DQL `t.teamId`), `getVolumeOverTime`, `getAgentPerformance` (sub+join clauses). Also threaded through `ReportingService`'s 5 pass-through methods (intermediate layer, implied by design, not separately listed in this task).
- [x] 3.7 Modify `ReportingController.php` — forward `teamId` to metrics + CSV export.

## Phase 4: Frontend Admin UI (Slice 2) — ✅ DONE

- [x] 4.1 Extended `core/dto/team.dto.ts` (`CreateTeamRequest`, `UpdateTeamRequest`, `TeamDTO` gained optional `agent_count`/`agent_ids`/`created_at`/`updated_at`) and `core/api/team-api.service.ts` (`listAll`/`create`/`update`/`deactivate`/`assignAgents` against `/api/admin/teams/*`) — extended the Slice-3 files in place, not recreated.
- [x] 4.2 Created `core/application/admin/teams/{list,create,update,deactivate}-team.use-case.ts` — 1:1 structural mirrors of the workspace use-cases (same `extractApiError` + typed `*Failed` error pattern).
- [x] 4.3 Created `features/admin/teams/teams-page.component.{ts,html,scss}` — structural copy of `workspaces-page.component.ts`; agent-assignment modal surfaces the 400 body via `err?.error?.error` (same pattern already used by `workspaces-page`).
- [x] 4.4 Modified `app.routes.ts` — `admin/teams` route, lazy-loaded. Also added the nav link in `shell/sidebar/sidebar.component.html` (not separately listed in this task, but required for the page to be reachable).

**Deviation**: task 4.4 specified `roleGuard('ROLE_ADMIN')`; used `roleGuard('ROLE_SUPERVISOR')` instead (and sidebar gate `isAdmin() || isSupervisor()`, matching the `admin/agents` link) to match the spec's explicit requirement ("administration" domain: "Team CRUD... accessible to ROLE_ADMIN + ROLE_SUPERVISOR... mirrors Agent CRUD access, not stricter User-CRUD-only-Admin") and the backend `Admin/TeamController`'s actual `#[IsGranted('ROLE_SUPERVISOR')]` gate (Slice 1). A `ROLE_ADMIN`-only frontend guard would have blocked supervisors the backend explicitly allows.

**Deviation**: Team's `workspaceId` is required at creation and immutable afterwards (backend `update()` never accepts `workspaceId`) — unlike workspace's form, the create/edit form disables the workspace `<select>` when editing and shows a hint. Also added a `workspaces()` signal (loaded via `WorkspaceApiService.listAll(1, 100)`, mirroring the existing `?limit=100` agent-list precedent) to populate that select and to render workspace display names in the teams table/agent-modal instead of raw IDs.

## Phase 5: Frontend Filter UI (Slice 3) — ✅ DONE

- [x] 5.1 Modify inbox feature — single-select team `<select>` from `GET /api/teams`, composable with tag filter. **NOTE**: no tag filter UI/state exists in the inbox frontend today (verified — `tag` is backend-only, unused by any frontend caller; status filtering is 100% client-side over an unfiltered list). Built a new standalone `TeamFilterComponent` (`features/inbox/components/team-filter/`) instead, wired as the first real server-side filter: `TicketStore.selectedTeamId` signal + `TicketApiService.getTickets(teamId?)` sends `?teamId=` to the already-updated `GET /api/tickets`. Composable with the existing client-side status tabs (independent state, both active simultaneously).
- [x] 5.2 Modify reports UI — team filter control. Added `teamId?: number` to `TimeFilterParams`, threaded through `ReportsApiService.buildParams()`; reuses the same `TeamFilterComponent` in `ReportsPageComponent`, wired into `loadAllData()`/`exportCsv()`.

## Phase 6: Verification

- [x] 6.1 `docker compose exec php bin/phpunit` — no new failures beyond baseline. Official cross-cutting sdd-verify pass (post-Slice-2): 699 tests, 18 errors + 39 failures = 57 failing, byte-identical to established baseline, zero overlap between failing names and any team-area-entity file. Focused re-run of all team-area-entity tests: 71/71 green.
- [x] 6.2 `cd apps/web && ./node_modules/.bin/ng build --configuration production` — exit 0, confirmed by official cross-cutting sdd-verify pass, no new errors/warnings from team-area-entity files.
- [x] 6.3 Manual E2E (create team, assign roster, filter inbox by team): code-level end-to-end trace confirmed by sdd-verify (every hop backed by a passing test), but the actual click-through in a running browser is PENDING — deferred to the user's own manual smoke-test pass at the end of Tier 2, per established session convention. Not a verify gap; an intentionally deferred human step.
