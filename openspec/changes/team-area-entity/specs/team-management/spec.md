# Team Management Specification

## Purpose

Defines the `Team` org-unit entity: workspace-scoped teams, `agent_team` membership, and manual `Ticket.team_id` assignment. Auto-routing (tag→team) is explicitly OUT of scope (tracked separately, tech-debt `tag-based-team-auto-routing`).

## Requirements

### Requirement: Team Entity and Workspace Scoping

The system MUST define a `Team` entity scoped to exactly one `Workspace` via a required, non-nullable `workspace_id` FK. A team's `name` MUST be unique within its own workspace (not globally). A team MUST support soft-deactivation via `is_active` and MUST NOT be hard-deleted while any ticket references it (see Deletion requirement below for the FK-cascade exception).

#### Scenario: Team created within a workspace

- GIVEN workspace "wns-001" exists
- WHEN a Team named "Billing" is created with `workspace_id = "wns-001"`
- THEN the team is persisted, active, scoped to "wns-001"

#### Scenario: Duplicate team name within same workspace rejected

- GIVEN a team "Billing" already exists in workspace "wns-001"
- WHEN another team named "Billing" is created in "wns-001"
- THEN the request is rejected as a validation error

#### Scenario: Same team name allowed across different workspaces

- GIVEN a team "Billing" exists in workspace "wns-001"
- WHEN a team named "Billing" is created in workspace "wns-002"
- THEN the creation succeeds

### Requirement: Agent Team Membership Requires Workspace Membership

The system MUST hard-reject (not warn) any attempt to add an agent to a team when the agent is not already a member of that team's workspace. When adding multiple agents in one bulk request, the system MUST validate every agent ID against the team's workspace membership BEFORE applying any change; if any agent fails validation, the entire request MUST be rejected with no partial membership changes.

#### Scenario: Agent already in the team's workspace joins successfully

- GIVEN agent 7 belongs to workspace "wns-001"
- AND team "Billing" belongs to workspace "wns-001"
- WHEN agent 7 is added to team "Billing"
- THEN the membership is created

#### Scenario: Agent outside the team's workspace is hard-rejected

- GIVEN agent 9 belongs only to workspace "wns-002"
- AND team "Billing" belongs to workspace "wns-001"
- WHEN agent 9 is added to team "Billing"
- THEN the request is rejected with a 400 error
- AND no membership row is created

#### Scenario: Bulk membership request with one invalid agent rejects atomically

- GIVEN agents 7 (in "wns-001") and 9 (only in "wns-002")
- AND team "Billing" belongs to workspace "wns-001"
- WHEN `PUT /api/admin/teams/{id}/agents` is called with `[7, 9]`
- THEN the entire request is rejected
- AND agent 7 is NOT added either (no partial application)

### Requirement: Ticket Team Assignment

Any authenticated agent (any role) MAY set or clear `Ticket.team_id` on any ticket they can access, at the same permission level as tag assignment — no Supervisor/Admin restriction. A ticket's assigned team MUST belong to the same workspace as the ticket's `phone_number_id`; assignment attempts violating this invariant MUST be rejected with 400.

#### Scenario: Agent assigns ticket to a team in the same workspace

- GIVEN a ticket with `phone_number_id = "wns-001"`
- AND team "Billing" belongs to workspace "wns-001"
- WHEN any authenticated agent sets the ticket's team to "Billing"
- THEN the assignment succeeds

#### Scenario: Cross-workspace team assignment rejected

- GIVEN a ticket with `phone_number_id = "wns-001"`
- AND team "Support" belongs to workspace "wns-002"
- WHEN an agent attempts to set the ticket's team to "Support"
- THEN the request is rejected with a 400 error
- AND `Ticket.team_id` remains unchanged

#### Scenario: Ticket team cleared

- GIVEN a ticket currently assigned to team "Billing"
- WHEN any authenticated agent clears the ticket's team (sets `team_id = null`)
- THEN the ticket's `team_id` becomes `null`

### Requirement: Team Deactivation Retains Historical Ticket Attribution

Deactivating a team (`is_active = false`) MUST NOT clear `Ticket.team_id` on tickets currently attributed to it. Deactivated teams MUST be excluded from pickers offered for NEW assignment but MUST remain valid for historical reporting/filtering and MUST NOT be hard-deleted.

#### Scenario: Deactivate team with existing ticket attributions

- GIVEN team "Billing" has 10 tickets with `team_id` pointing to it
- WHEN team "Billing" is deactivated
- THEN all 10 tickets retain `team_id` pointing to "Billing"
- AND "Billing" no longer appears in the active-team assignment picker
- AND "Billing" remains selectable in inbox/report team filters

### Requirement: Team Hard Deletion Clears Ticket Attribution (FK Cascade)

If a `Team` row is hard-deleted (a distinct, non-standard lifecycle path from deactivation), the database FK (`ON DELETE SET NULL`) MUST clear `Ticket.team_id` on all referencing tickets.

#### Scenario: Team hard-deleted, referencing tickets cleared

- GIVEN team "Legacy" is referenced by 3 tickets' `team_id`
- WHEN the "Legacy" team row is hard-deleted
- THEN those 3 tickets' `team_id` becomes `null`
