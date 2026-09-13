# Ticket List Filters Specification

## Purpose

Defines the filtering, scoping, and pagination contract for the inbox ticket list (`GET /tickets`): status, assignment, ownership/workspace scope, and tag filters, plus `total`/pagination correctness.

## Requirements

### Requirement: Status Filter

The system MUST filter tickets by a comma-separated `status` parameter restricted to the complete set of first-class `TicketStatus` values: `new`, `active`, `waiting`, `resolved`, `closed`. Unrecognized tokens MUST be silently dropped rather than erroring.

#### Scenario: waiting status is honored

- GIVEN a ticket exists with status `waiting`
- WHEN a request is made to `GET /tickets?status=waiting`
- THEN the response includes that ticket
- AND no ticket with a different status is included

#### Scenario: unknown status token ignored

- GIVEN a request `GET /tickets?status=bogus`
- WHEN executed
- THEN the unrecognized token is silently dropped
- AND no status filter is applied if every token is unrecognized

### Requirement: Assignment Filter

The system MUST support `filter` values `all`, `unassigned`, `assigned`, `mine` to narrow tickets by assignment state.

#### Scenario: unassigned filter

- GIVEN tickets with and without `assigned_agent_id`
- WHEN `GET /tickets?filter=unassigned`
- THEN only tickets with no assigned agent are returned

### Requirement: Ownership and Workspace Scope

The system MUST apply ownership scope (`assigned_agent_id`) and workspace scope (`phone_number_id`) as narrowing constraints that compose with every other filter and MUST NOT be bypassable by any `filter`, `status`, or `tag` value.

#### Scenario: scope narrows regardless of other filters

- GIVEN an AGENT-role request scoped to agent 42
- WHEN `GET /tickets?filter=all&tag=vip`
- THEN only tickets assigned to agent 42 that also carry `vip` are returned

### Requirement: Tag Filter (AND semantics)

The system MUST support an optional `tag` query parameter as a comma-separated list of tag names, requiring each returned ticket to carry ALL specified tags (AND semantics, not OR). Tag names MUST be normalized (trim, lowercase) before matching, matching `Tag::normalize()`. The request MUST accept at most 5 distinct non-blank tags.

#### Scenario: single tag

- GIVEN tickets with and without tag `vip`
- WHEN `GET /tickets?tag=vip`
- THEN only tickets carrying `vip` are returned

#### Scenario: multi-tag AND

- GIVEN a ticket carrying both `vip` and `urgente`, and another carrying only `vip`
- WHEN `GET /tickets?tag=vip,urgente`
- THEN only the ticket carrying both tags is returned

#### Scenario: unknown or non-matching tag name

- GIVEN no ticket carries tag `doesnotexist`
- WHEN `GET /tickets?tag=doesnotexist`
- THEN the response returns an empty item list and `total=0`
- AND no error is raised

#### Scenario: tag count exceeds the cap

- WHEN `GET /tickets?tag=a,b,c,d,e,f` (6 non-blank tags)
- THEN the request is rejected as a validation error
- AND no ticket query is executed

#### Scenario: blank segments in tag list are discarded

- GIVEN tags `vip` and `urgente` exist
- WHEN `GET /tickets?tag=vip,,urgente`
- THEN blank segments are silently discarded
- AND the filter behaves identically to `tag=vip,urgente`

#### Scenario: empty tag parameter treated as absent

- WHEN `GET /tickets?tag=` (present, no value)
- THEN no tag filter is applied
- AND all tickets are returned subject to any other active filters

### Requirement: Team Filter (Single-Select)

The system MUST support an optional `teamId` query parameter selecting exactly ONE team (single-select, not multi-select/OR), narrowing results to tickets whose `Ticket.team_id` matches. This filter is independent of the existing multi-tag AND filter and MUST compose with it and every other active filter (status, assignment, ownership/workspace scope).

#### Scenario: single team filter

- GIVEN tickets assigned to team "Billing" and tickets assigned to team "Support"
- WHEN `GET /tickets?teamId=1` (team 1 = "Billing")
- THEN only tickets with `team_id = 1` are returned

#### Scenario: teamId combined with multi-tag AND filter — both active simultaneously

- GIVEN a ticket assigned to team "Billing" carrying tags `vip` and `urgente`
- AND another ticket assigned to team "Billing" carrying only `vip`
- AND a ticket assigned to team "Support" carrying both `vip` and `urgente`
- WHEN `GET /tickets?teamId=1&tag=vip,urgente`
- THEN only the first ticket (team "Billing" AND both tags) is returned
- AND the team filter and tag filter apply as independent, simultaneously-active narrowing constraints

#### Scenario: teamId omitted preserves backward compatibility

- WHEN `GET /tickets` is called with no `teamId` parameter
- THEN results are byte-identical to pre-change behavior

#### Scenario: teamId for a team with zero matching tickets

- GIVEN team "Empty" has no tickets assigned
- WHEN `GET /tickets?teamId=<empty-team-id>`
- THEN the response returns an empty item list and `total=0`
- AND no error is raised

### Requirement: Pagination and Total Consistency

The system MUST return `total` matching the exact count of tickets satisfying all active filters — including multi-tag AND filters and the single-select team filter — with no inflation or deflation from row duplication, and MUST support `page`/`limit` (max 100 items) without altering `total`.

#### Scenario: total matches filtered count under multi-tag filter

- GIVEN 3 tickets match both requested tags out of a dataset of 20
- WHEN `GET /tickets?tag=vip,urgente&page=1&limit=20`
- THEN `meta.total = 3`
- AND `items.length = 3`

#### Scenario: total matches filtered count when team and tag filters combine

- GIVEN 2 tickets match both team "Billing" and tags `vip,urgente` out of a dataset of 20
- WHEN `GET /tickets?teamId=1&tag=vip,urgente&page=1&limit=20`
- THEN `meta.total = 2`
- AND `items.length = 2`

#### Scenario: backward compatibility when tag and teamId are absent

- WHEN `GET /tickets` is called with no `tag` or `teamId` parameter
- THEN results are byte-identical to pre-change behavior
