# Delta for Administration

## ADDED Requirements

### Requirement: Team CRUD (Admin/Supervisor)

The system SHALL provide full CRUD operations for Team management, scoped to a single workspace, accessible to `ROLE_ADMIN` and `ROLE_SUPERVISOR` (mirroring Agent CRUD access, not the stricter User-CRUD-only-Admin pattern).

| Endpoint | Method | Role |
|----------|--------|------|
| `/api/admin/teams` | GET | ADMIN, SUPERVISOR |
| `/api/admin/teams` | POST | ADMIN, SUPERVISOR |
| `/api/admin/teams/{id}` | GET | ADMIN, SUPERVISOR |
| `/api/admin/teams/{id}` | PUT | ADMIN, SUPERVISOR |
| `/api/admin/teams/{id}` | DELETE | ADMIN, SUPERVISOR (soft-deactivate, not hard delete) |

#### Scenario: List teams for a workspace

- GIVEN a ROLE_SUPERVISOR user
- WHEN `GET /api/admin/teams?workspaceId=wns-001` is called
- THEN the response includes each team's `id`, `name`, `workspace_id`, `is_active`

#### Scenario: Create a team

- GIVEN a ROLE_ADMIN user
- WHEN `POST /api/admin/teams` is called with `{name: "Billing", workspace_id: "wns-001"}`
- THEN the response is 201 with the created team

#### Scenario: Deactivate a team (soft delete)

- GIVEN an active team "Billing" with existing ticket attributions
- WHEN `DELETE /api/admin/teams/{id}` is called
- THEN the team's `is_active` is set to `false`
- AND referencing tickets' `team_id` values are retained unchanged
- AND the response is 200

#### Scenario: Agent denied team management

- GIVEN a ROLE_AGENT user
- WHEN `GET /api/admin/teams` is called
- THEN the response is 403

### Requirement: Team Membership Bulk Assignment

The system SHALL provide a bulk membership endpoint mirroring the Workspace membership pattern: `PUT /api/admin/teams/{id}/agents` accepting an array of agent IDs, replacing the team's full roster in one call. The system MUST hard-reject the entire request (no partial application) if any supplied agent ID is not already a member of the team's workspace.

#### Scenario: Bulk-assign valid agents to a team

- GIVEN agents 7 and 8 both belong to workspace "wns-001"
- AND team "Billing" belongs to workspace "wns-001"
- WHEN `PUT /api/admin/teams/{id}/agents` is called with `[7, 8]`
- THEN the team's roster becomes exactly agents 7 and 8

#### Scenario: Bulk-assign rejects when one agent is outside the team's workspace

- GIVEN agent 7 belongs to workspace "wns-001" and agent 9 belongs only to workspace "wns-002"
- AND team "Billing" belongs to workspace "wns-001"
- WHEN `PUT /api/admin/teams/{id}/agents` is called with `[7, 9]`
- THEN the response is 400
- AND the team's roster remains unchanged (no partial application)
