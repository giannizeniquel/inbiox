# Delta for Ticket List Filters

## ADDED Requirements

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

## MODIFIED Requirements

### Requirement: Pagination and Total Consistency

The system MUST return `total` matching the exact count of tickets satisfying all active filters — including multi-tag AND filters and the single-select team filter — with no inflation or deflation from row duplication, and MUST support `page`/`limit` (max 100 items) without altering `total`.
(Previously: scope limited to multi-tag AND filters; now also covers the team filter.)

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
