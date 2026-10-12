# Reports Specification

## Purpose

Defines the reporting endpoints for operational metrics, including workspace-scoped filtering for agent performance data.

---

## Requirements

### Requirement: Agent Performance Workspace Isolation (REQ-1)

`GET /reports/agents` MUST filter agent performance metrics by the active workspace's `phone_number_id` when a workspace scope is present. The filtering MUST apply to both the ticket JOIN condition and the `current_active_tickets` subquery, matching the pattern already used by `/reports/frt`, `/reports/rt`, `/reports/counts`, and `/reports/volume`.

| Aspect | Constraint |
|--------|-----------|
| Filter target | `tickets.phone_number_id` in the LEFT JOIN ON clause |
| Subquery target | `current_active_tickets` inline COUNT must also filter by `phone_number_id` |
| Null behavior | When `phoneNumberId` is `null`, no workspace filter is applied (all workspaces) |
| Agent visibility | Agents MUST always appear in results regardless of workspace ticket count |

#### Scenario: Agent with active workspace sees only their workspace stats (SC-1)

- GIVEN an authenticated AGENT user with an active workspace (`phone_number_id = "wns-001"`)
- WHEN they call `GET /reports/agents?from=2025-01-01&to=2025-01-31`
- THEN the response MUST include metrics computed only from tickets where `phone_number_id = "wns-001"`
- AND agents with zero tickets in that workspace MUST still appear with zeroed metrics

#### Scenario: Supervisor without active workspace sees all workspaces (SC-2)

- GIVEN an authenticated SUPERVISOR with no active workspace (`phone_number_id = null`)
- WHEN they call `GET /reports/agents?from=2025-01-01&to=2025-01-31`
- THEN the response MUST include metrics computed from tickets across ALL workspaces
- AND the behavior MUST be identical to the current unfiltered behavior (backward compatible)

#### Scenario: Workspace with no tickets returns zeroed results (SC-3)

- GIVEN an authenticated AGENT with active workspace `phone_number_id = "wns-empty"`
- AND no tickets exist with that `phone_number_id` in the date range
- WHEN they call `GET /reports/agents`
- THEN all agents MUST appear in the response with zero values for `assigned_tickets`, `resolved_tickets`, `avg_response_time`, and `current_active_tickets`

#### Scenario: Multiple agents across workspaces (SC-4)

- GIVEN agent A has 10 tickets in workspace "wns-001" and 5 in "wns-002"
- AND agent B has 3 tickets in workspace "wns-002" only
- WHEN a user with active workspace "wns-001" calls `GET /reports/agents`
- THEN agent A MUST show metrics for 10 tickets only
- AND agent B MUST appear with zeroed metrics (no tickets in "wns-001")

### Requirement: Backward Compatible Null Scope (REQ-2)

When no workspace is active (SUPERVISOR/ADMIN global view), the endpoint MUST return data for all workspaces without any filtering. This preserves backward compatibility — the default parameter value `?string $phoneNumberId = null` ensures existing callers are unaffected.

#### Scenario: Null phoneNumberId produces unfiltered query (SC-5)

- GIVEN the repository receives `$phoneNumberId = null`
- WHEN the SQL query is built
- THEN no `AND t.phone_number_id = :phoneNumberId` clause is added to the JOIN or subquery
- AND the result set is identical to the pre-fix behavior

---

### Requirement: Team Filter on Core Reporting Metrics

The system MUST accept an optional `teamId` parameter on exactly the five ticket-scoped reporting methods — `getFrtMetrics`, `getRtMetrics`, `getCountsByStatus`, `getVolumeOverTime`, and `getAgentPerformance` — filtering results to tickets whose `team_id` matches. When `teamId` is omitted (`null`), behavior MUST be byte-identical to current unfiltered output, following the same null-scope pattern already used for `phoneNumberId`.

#### Scenario: team-scoped FRT metrics

- GIVEN tickets assigned to team "Billing" and team "Support" within the requested date range
- WHEN `GET /reports/frt?from=2025-01-01&to=2025-01-31&teamId=1` (team 1 = "Billing")
- THEN the response includes FRT metrics computed only from tickets with `team_id = 1`

#### Scenario: teamId omitted preserves backward compatibility

- GIVEN the repository receives `$teamId = null` for any of the five methods
- WHEN the SQL query is built
- THEN no `team_id` clause is added
- AND the result set is identical to pre-change behavior

#### Scenario: teamId with zero matching tickets returns zeroed metrics

- GIVEN team "Empty" has no tickets in the requested date range
- WHEN `GET /reports/agents?teamId=<empty-team-id>`
- THEN all agents appear in the response with zeroed metrics
- AND no error is raised

#### Scenario: teamId composes with existing phoneNumberId workspace scope

- GIVEN a ticket in workspace "wns-001" assigned to team "Billing", and another ticket in workspace "wns-001" assigned to team "Support"
- WHEN `GET /reports/counts?teamId=1` is called by an agent whose active workspace is "wns-001"
- THEN both the workspace scope and the team filter apply as independent narrowing constraints
- AND only tickets matching BOTH constraints are counted

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 2 |
| Total Scenarios | 5 |

## Coverage

- **Happy paths**: Workspace-scoped filtering on agent performance, backward-compatible null scope
- **Edge cases**: Zero-ticket workspace, multi-agent cross-workspace data isolation
- **Error states**: None — filtering is passive (no errors on mismatch)
