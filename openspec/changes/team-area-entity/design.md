# Design: Team/Area Entity (Tier 2 #7)

## Technical Approach

Additive org-unit primitive. `Team` mirrors `Workspace.php` exactly (soft-deactivation via `is_active`). Membership reuses the `agent_workspace` M:N shape verbatim, owned by `Agent`. `Ticket.team_id` is a raw scalar with a real DB FK. Filtering threads one nullable param appended LAST everywhere, so `teamId = null` reproduces today's SQL byte-for-byte.

## Architecture Decisions

| # | Decision | Alternatives rejected | Rationale |
|---|---|---|---|
| D1 | `Version20260912000002.php` — teams + agent_team + tickets.team_id in ONE migration | 3 migrations | Rollback is one `down()`; matches `Version20260728161023` (workspaces+join+FK+backfill in one). |
| D2 | `teams.workspace_id` FK `ON DELETE CASCADE`; `tickets.team_id` FK `ON DELETE SET NULL` | No FK (copy `assigned_agent_id`) | Missing FK on `assigned_agent_id` is a known defect, not a convention. Teams are admin-managed → orphan risk is live. |
| D3 | Workspace-mismatch check lives in `Team::addAgent()` as an **entity invariant**, throwing `\InvalidArgumentException` | New `AddAgentToTeamUseCase`; `StateConflictException` (409) | Zero use-case precedent exists for the workspace equivalent (logic is inline in `WorkspaceController::assignAgents`). Entity invariant covers every path. `\InvalidArgumentException` → **400** is already caught by the exact copied catch block; `StateConflictException` has no response mapping in `Controller/Admin/*`. This is malformed input, not a state transition. |
| D4 | No join for `teamId` in reporting — plain `t.team_id = :teamId` | `JOIN teams` | `team_id` is a scalar column on `tickets`; a join would only be needed to read team *attributes*, which no metric does. Avoids changing `getAgentPerformance`'s already-fragile LEFT JOIN cardinality. |
| D5 | Ticket↔team assignment on `TicketController` (`#[IsGranted('ROLE_AGENT')]`) | New role gate | Decision #2: any authenticated agent, same level as `TagController` (also `ROLE_AGENT`). |
| D6 | `teamId` appended LAST in `TicketFilterBuilder::build()` | Insert near `phoneNumberId` | **Verified**: all 3 callers (`ListTicketsQuery:56`, `ExportTicketsCsvQuery:71`, `ReportingController:492`) use **named arguments** — appending breaks nothing. Clause emitted after the tag block so positional `?` stay aligned. |

## Migration — `apps/api/migrations/Version20260912000002.php`

```sql
CREATE TABLE teams (id BIGINT AUTO_INCREMENT NOT NULL, workspace_id BIGINT NOT NULL,
  name VARCHAR(255) NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL,
  created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
  UNIQUE INDEX uniq_teams_workspace_name (workspace_id, name),
  INDEX IDX_TEAMS_WORKSPACE (workspace_id), PRIMARY KEY (id))
  DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`;
ALTER TABLE teams ADD CONSTRAINT FK_TEAMS_WORKSPACE
  FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE;

CREATE TABLE agent_team (agent_id BIGINT NOT NULL, team_id BIGINT NOT NULL,
  INDEX IDX_AT_AGENT (agent_id), INDEX IDX_AT_TEAM (team_id),
  PRIMARY KEY (agent_id, team_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`;
ALTER TABLE agent_team ADD CONSTRAINT FK_AT_AGENT FOREIGN KEY (agent_id) REFERENCES agents (id) ON DELETE CASCADE;
ALTER TABLE agent_team ADD CONSTRAINT FK_AT_TEAM  FOREIGN KEY (team_id)  REFERENCES teams  (id) ON DELETE CASCADE;

ALTER TABLE tickets ADD team_id BIGINT DEFAULT NULL;
ALTER TABLE tickets ADD CONSTRAINT FK_TICKETS_TEAM FOREIGN KEY (team_id) REFERENCES teams (id) ON DELETE SET NULL;
CREATE INDEX idx_tickets_team_id ON tickets (team_id);
```
`down()`: drop FKs, `DROP TABLE agent_team`, `DROP TABLE teams`, drop index + column on `tickets`. No backfill → no data loss.

## Interfaces / Contracts

**`Domain/Team/Team.php`** (new) — `?int $id` BIGINT, `#[ORM\ManyToOne(Workspace)] JoinColumn(name:'workspace_id', nullable:false, onDelete:'CASCADE')`, `string $name`, `bool $isActive = true` (`is_active`), `createdAt`/`updatedAt`. Accessors copy `Workspace.php` verbatim: `isActive()` / `setActive(bool)` (both bump `updatedAt`), plus `getWorkspace()`, `getName()`/`setName()`, `getAgents()`, `addAgent()`, `removeAgent()`. Inverse side: `#[ORM\ManyToMany(targetEntity: Agent::class, mappedBy: 'teams')] private Collection $agents;`

**Invariant (D3)** — in `Team::addAgent()`:
```php
if (!$agent->getWorkspaces()->contains($this->workspace)) {
    throw new \InvalidArgumentException(sprintf(
        'Agent %d does not belong to workspace %d of team "%s"',
        $agent->getId(), $this->workspace->getId(), $this->name));
}
```
Thrown inside `assignAgents`' `wrapInTransaction()` → whole batch rolls back (hard reject, all-or-nothing), caught by the existing `catch (\InvalidArgumentException)` → 400.

**`Domain/Agent/Agent.php`** (modify) — owning side, exact `agent_workspace` shape:
```php
#[ORM\ManyToMany(targetEntity: Team::class, inversedBy: 'agents')]
#[ORM\JoinTable(name: 'agent_team')]
#[ORM\JoinColumn(name: 'agent_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
#[ORM\InverseJoinColumn(name: 'team_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
private Collection $teams;
```
plus `$this->teams = new ArrayCollection();` in `__construct`, `getTeams()`, `addTeam()`, `removeTeam()` (mirroring `addWorkspace`/`removeWorkspace`, which do NOT bump `updatedAt`).

**`Domain/Ticket/Ticket.php`** (modify) — `#[ORM\Column(name: "team_id", type: "bigint", nullable: true)] private ?int $teamId = null;` + `getTeamId()`/`setTeamId(?int)` (Ticket's setters do NOT bump `updatedAt` — match `setAssignedAgentId`).

**`TicketFilterBuilder::build()`** — append `?int $teamId = null` LAST; emit after the tag block:
```php
if (null !== $teamId) { $where[] = 't.team_id = ?'; $params[] = (string) $teamId; }
```
(`(string)` cast matches the existing `scopeAgentId` line.) Update the class docblock's order note to `… → tag AND-semantics → team`.

**`ReportingRepository`** — append `?int $teamId = null` LAST on exactly five methods:
| Method | Insertion |
|---|---|
| `getFrtMetrics` (:78), `getRtMetrics` (:132) | `$sql .= ' AND t.team_id = :teamId';` after the `$phoneNumberId` block |
| `getCountsByStatus` (:183) | DQL: `$qb->andWhere('t.teamId = :teamId')->setParameter(...)` |
| `getVolumeOverTime` (:232) | new `$teamClause` concatenated into BOTH `$sqlCreated`/`$sqlResolved` |
| `getAgentPerformance` (:306) | both `$teamSubClause` (`t2.team_id`) and `$teamJoinClause` (`t.team_id`), mirroring the `$workspaceSubClause`/`$workspaceJoinClause` pair |

## Data Flow

    InboxController(?teamId) ─→ ListTicketsQuery ─→ TicketFilterBuilder.build(…, teamId) ─→ WHERE t.team_id = ?
    Admin UI (PUT /teams/{id}/agents) ─→ TeamController ─→ wrapInTransaction ─→ Team::addAgent() [invariant] ─→ 400 on mismatch
    Agent UI (PUT /tickets/{id}/team) ─→ TicketController::setTeam ─→ workspace-match check ─→ Ticket.teamId

## File Changes

| File | Action | Description |
|---|---|---|
| `apps/api/migrations/Version20260912000002.php` | Create | Schema above |
| `apps/api/src/Domain/Team/Team.php` | Create | Entity + membership invariant |
| `apps/api/src/Domain/Agent/Agent.php` | Modify | `$teams` M:N owning side |
| `apps/api/src/Domain/Ticket/Ticket.php` | Modify | `?int $teamId` |
| `apps/api/src/Controller/Admin/TeamController.php` | Create | `list/create/get/update/delete/assignAgents` — structural copy of `Admin/WorkspaceController.php` (same pagination, same `bin2hex(random_bytes(8))` reference-id logging, `serializeTeam()` returning `id, workspace_id, name, is_active, agent_count, agent_ids, created_at, updated_at`). `delete` = soft: `setActive(false)` → 204. Create validates `workspaceId` exists (404-ish → 400) and rejects duplicate `(workspace_id, name)` → **409**, mirroring the duplicate-phoneNumberId branch. |
| `apps/api/src/Controller/TicketController.php` | Modify | `setTeam(int $id, Request)` for `PUT /api/tickets/{id}/team`, body `{"teamId": int\|null}`; `null` clears. Rejects 400 when `team.workspace.phoneNumberId !== ticket.phoneNumberId`. |
| `apps/api/src/Controller/InboxController.php` | Modify | `$teamId = $request->query->get('teamId');` beside `$tag` (:51); pass `teamId: null !== $teamId ? (int) $teamId : null` as last named arg |
| `apps/api/src/Application/Query/ListTicketsQuery.php`, `ExportTicketsCsvQuery.php` | Modify | thread `?int $teamId = null` last |
| `apps/api/src/Controller/ReportingController.php` | Modify | read `teamId` query param; forward to the 5 metrics + CSV validation call (:492) |
| `apps/api/src/Repository/ReportingRepository.php` | Modify | 5 signatures per table above |
| `apps/api/config/routes.yaml` | Modify | 8 entries (see below) |
| `apps/web/.../core/dto/team.dto.ts`, `core/api/team-api.service.ts`, `core/application/admin/teams/{list,create,update,deactivate}-team.use-case.ts` | Create | 1:1 mirrors of the workspace equivalents |
| `apps/web/.../features/admin/teams/teams-page.component.{ts,html,scss}` | Create | Copy of `workspaces-page.component.ts`: signals (`teams/loading/saving/error/success/currentPage/totalPages`, `showForm/editingTeam/showDeleteConfirm`, `showAgentModal/assigningTeam/agentOptions/savingAgents`), `fb.nonNullable.group({ workspaceId: [null, Validators.required], name: ['', [required, minLength(2), maxLength(255)]], isActive: [true] })`, and `openAgentModal → GET /api/admin/agents?limit=100 → checkbox list → saveAgents → PUT`. Reuses `_admin-common.scss`. Membership modal must surface the 400 body via the existing `err?.error?.error` path so the workspace-mismatch reason is visible. |
| `apps/web/src/app/app.routes.ts` | Modify | `admin/teams`, `roleGuard('ROLE_ADMIN')`, lazy `loadComponent` |
| `apps/web/.../features/inbox/` | Modify | single-select team `<select>` (Decision #4), independent of and simultaneous with the multi-tag AND filter |

## Routes (`apps/api/config/routes.yaml` — sole source of truth)

`admin_teams_list|create|get|update|delete` → `/api/admin/teams[/{id}]`, `admin_teams_assign_agents` → `PUT /api/admin/teams/{id}/agents`, `app_ticket_set_team` → `PUT /api/tickets/{id}/team`. Optionally `app_teams_list` → `GET /api/teams` (scoped, for the inbox filter dropdown; otherwise the agent UI must call an admin-only endpoint).

**Verified defect (pre-existing, do not fix here):** `#[Route]` attributes are dead — `config/routes*.yaml` has no `resource: ../src/Controller` attribute import. `TagController`'s `POST/DELETE /api/tickets/{ticketId}/tags` are absent from `routes.yaml`, i.e. currently unreachable. Copy the `#[Route]` attributes for consistency but treat routes.yaml as load-bearing.

## Testing Strategy

| Layer | What | How |
|---|---|---|
| Unit | `TicketFilterBuilderTest` — `teamId=null` produces IDENTICAL `[$where,$params]` as today for every existing combo; `teamId=7` appends exactly one clause LAST | extend existing pure test |
| Unit | `Team::addAgent()` rejects an agent not in the team's workspace; accepts one that is | in-memory entities, no kernel |
| Integration | `PUT /teams/{id}/agents` with one bad agent in the batch → 400 AND zero rows written (transaction rollback) | kernel + DB |
| Integration | Team deactivation retains `tickets.team_id` (Decision #1); cross-workspace ticket→team assignment → 400 | kernel + DB |
| Integration | 5 reporting methods: `teamId=null` returns identical results to pre-change | snapshot |
| E2E | Admin creates team, sets roster, filters inbox by that team | existing admin E2E pattern |

## Threat Matrix

N/A — no shell command, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary. All five matrix rows (documentation-like paths, git repository selection, commit state, push state, PR commands) are N/A. HTTP route additions are declarative Symfony config behind existing `#[IsGranted]` enforcement, not the process routing the matrix governs.

## Migration / Rollout

Single forward migration, no backfill, no feature flag. Every new param is nullable with a `null` default → omitting it is behavior-preserving. Rollback = `down()` + revert code.

## Slicing Assessment (tasks phase decides)

**Agrees with the proposal's 3 slices.** 400-line budget risk: **High**. Recommended chain:
1. Entity + migration + `Admin/TeamController` + routes + `PUT /tickets/{id}/team` (~250 LOC)
2. Angular admin CRUD page + DTO/use-cases/api-service + app.routes (~250 LOC)
3. Filter threading: builder + 2 queries + 5 reporting methods + inbox/reports UI control (~200 LOC)

Slice 1 is independently shippable (API-only, no UI consumer). Slice 3 depends only on slice 1's column existing, not on slice 2 — slices 2 and 3 could run in parallel if the chain is restructured.

## Open Questions

- [ ] Does the inbox team-filter dropdown get a non-admin `GET /api/teams` endpoint, or does it reuse `/api/admin/teams` (which would 403 for ROLE_AGENT)? Design assumes a new scoped `GET /api/teams` in slice 1.
