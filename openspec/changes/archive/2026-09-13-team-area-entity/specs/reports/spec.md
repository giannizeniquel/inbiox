# Delta for Reports

## ADDED Requirements

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
