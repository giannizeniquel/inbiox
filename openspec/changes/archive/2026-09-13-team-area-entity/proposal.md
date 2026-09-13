# Proposal: Team/Area Entity (Tier 2 #7)

## Intent

No team/area concept exists: only individual agents and `Workspace` (= one phone number, not an org unit). Supervisors cannot group agents by area, cannot say "this ticket belongs to Billing", and cannot filter the inbox or reports by team (gap doc #17/#18). This change introduces the missing org-unit primitive so team-based filtering works now and tag/chatbot routing (#2c) has something to route *to* later.

## Scope

### In Scope
- `Team` entity (workspace-scoped), migration + admin CRUD API.
- `agent_team` N:M membership (plain join table).
- `Ticket.team_id` nullable, **manually** set/cleared by agents/admins.
- New admin page `apps/web/src/app/features/admin/teams/` mirroring `workspaces-page`.
- `teamId` filter threaded through `ListTicketsQuery` → `TicketFilterBuilder` and core `ReportingRepository` metrics.

### Out of Scope
- Any automatic routing / team-based auto-assign (see Tracked Tech Debt).
- "Primary team" flag, per-membership metadata, team hierarchy, team-level SLAs/capacity.
- Retrofitting workspace scoping into `AutoAssignTicketUseCase` (pre-existing gap, untouched).
- Cross-workspace/global teams.

## Capabilities

### New Capabilities
- `team-management`: Team lifecycle, workspace scoping, agent membership, ticket↔team assignment.

### Modified Capabilities
- `ticket-list-filters`: adds `teamId` filter dimension.
- `reports`: adds `teamId` filter to core metrics.
- `administration`: adds Team admin CRUD + membership surface.

## Approach

**Team entity** — mirrors `Workspace.php`: `id`, `name`, `workspace_id` (NOT NULL FK), `is_active` (soft-delete via deactivate), `created_at`/`updated_at`. `UNIQUE (workspace_id, name)`.

**`agent_team`** — follows `agent_workspace` exactly (`Version20260728161023`): `#[ORM\ManyToMany]` + `#[ORM\JoinTable]` owned by `Agent`, composite PK `(agent_id, team_id)`, real FKs `ON DELETE CASCADE`, **no junction entity**. Rejected `ticket_tags` pattern: no metadata, no audit need.

**`Ticket.team_id` — deliberate FK decision**: raw `?int $teamId` scalar in the entity (matches Ticket's existing style, avoids hydrating Team in list queries) **but with a real DB FK `ON DELETE SET NULL`**. The missing FK on `assigned_agent_id` is a known defect, not a convention to copy — teams are admin-deactivatable/deletable, so orphaned `team_id` values are a live risk.

**Invariant**: a ticket may only be assigned a team whose `workspace.phone_number_id` matches the ticket's `phone_number_id` (rejected 400 otherwise).

**Filter threading** — follows the Tier 1 #1 tag precedent: append `?int $teamId = null` as the **last** parameter of `TicketFilterBuilder::build()` (positional `?` params must stay aligned; clause appended after tags), and thread through `ListTicketsQuery` + `ExportTicketsCsvQuery`. Reporting: add `?int $teamId` to the five ticket-scoped methods only (`getFrtMetrics`, `getRtMetrics`, `getCountsByStatus`, `getVolumeOverTime`, `getAgentPerformance`) — not all 12.

**Admin UI** — `workspaces-page.component.ts` is the precedent, not `agents-page`: same signals + reactive-form CRUD **and** its bulk membership modal (`openAgentModal` → checkbox list → `PUT /{id}/agents`, `WorkspaceController.php:226`). Team membership reuses that exact shape: `PUT /api/admin/teams/{id}/agents` with an id array.

## TRACKED TECH DEBT: tag-based team auto-routing (deferred, not dropped)

Auto-routing is deliberately deferred, **not abandoned**. Follow-up change should add an interim rule: *if ticket has tag X → assign team Y*, as a tag→team mapping evaluated by the existing `RuleExecutorService` pipeline (which today only auto-tags, never reassigns). This is independent of and should land **before** the Tier 3 chatbot engine (#13/#14). Without it, `Ticket.team_id` stays 100% manual and #2c remains structurally unclosed. Must be revisited at Tier 3 planning.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/api/src/Domain/Team/Team.php` | New | Entity |
| `apps/api/src/Domain/Agent/Agent.php` | Modified | `teams` M:N (`agent_team`) |
| `apps/api/src/Domain/Ticket/Ticket.php` | Modified | `?int $teamId` |
| `apps/api/migrations/` | New | `teams`, `agent_team`, `tickets.team_id` FK |
| `apps/api/src/Controller/Admin/TeamController.php` | New | CRUD + `PUT /{id}/agents` |
| `apps/api/src/Application/Query/TicketFilterBuilder.php` | Modified | `teamId` clause |
| `apps/api/src/Application/Query/ListTicketsQuery.php`, `ExportTicketsCsvQuery.php` | Modified | thread `teamId` |
| `apps/api/src/Repository/ReportingRepository.php` | Modified | `teamId` on 5 methods |
| `apps/web/.../features/admin/teams/` | New | CRUD page + membership modal |
| `apps/web/.../features/inbox/`, `reports/` | Modified | team filter control |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Positional-param drift in `TicketFilterBuilder` breaks tag/status filters | Med | Append `teamId` last; regression tests on existing filter combos |
| Workspace-scoped teams later need to be global | Low | `workspace_id` FK is droppable to nullable; no query assumes NOT NULL |
| Agent in a team but not in that team's workspace → invisible roster | Med | Validate membership against workspace at assign time |
| 400-line PR budget exceeded (API + migration + admin UI + 2 filter surfaces) | High | Slice: (1) entity+migration+CRUD API, (2) admin UI, (3) filter threading |
| Team deactivation orphans ticket assignments | Low | Soft-delete keeps rows; FK `ON DELETE SET NULL` covers hard delete |

## Rollback Plan

Down-migration drops `tickets.team_id` (+FK), `agent_team`, `teams`. Filter params are optional/nullable — reverting API code is behavior-preserving since `teamId=null` reproduces current SQL exactly. Admin route removal is additive-only. No data backfill, so no data loss on rollback.

## Dependencies

None blocking. Consumes existing `Workspace`, `Agent`, `TicketFilterBuilder` (Tier 1 #1).

## Success Criteria

- [ ] Admin can create/edit/deactivate a Team scoped to one workspace.
- [ ] Admin can set a team's agent roster in one bulk save.
- [ ] A ticket can be manually assigned/unassigned a team; cross-workspace assignment is rejected.
- [ ] Inbox and reports return correctly filtered results for `teamId`.
- [ ] Omitting `teamId` produces byte-identical SQL/results vs today.
- [ ] Tag→team auto-routing debt is recorded as a standalone tracked item.

## Proposal question round (answers needed before spec)

1. Team deactivation: should existing `tickets.team_id` pointing at a deactivated team be cleared, or retained for historical reporting? (Assumed: retained.)
2. Who may set `Ticket.team_id` — any agent, or ADMIN/SUPERVISOR only? (Assumed: any authenticated agent, same as tag assignment.)
3. Must an agent already belong to the team's workspace to join the team — hard reject or allow with warning? (Assumed: hard reject.)
4. Inbox team filter: single team, or multi-select with OR semantics? (Assumed: single team.)
