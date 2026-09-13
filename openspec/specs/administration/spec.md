# Administration Specification

## Purpose

Defines admin and supervisor capabilities for managing users, agents, and the User ↔ Agent linking workflow.

---

## Requirements

### Requirement: User CRUD (Admin Only)

The system SHALL provide full CRUD operations for User management, restricted to `ROLE_ADMIN`.

| Endpoint | Method | Role |
|----------|--------|------|
| `/api/admin/users` | GET | ROLE_ADMIN |
| `/api/admin/users` | POST | ROLE_ADMIN |
| `/api/admin/users/{id}` | GET | ROLE_ADMIN |
| `/api/admin/users/{id}` | PUT | ROLE_ADMIN |
| `/api/admin/users/{id}` | DELETE | ROLE_ADMIN (soft delete) |

#### Scenario: List all users

- GIVEN a ROLE_ADMIN user
- WHEN `GET /api/admin/users` is called
- THEN the response SHALL be 200 with a paginated list of all users
- AND each user SHALL include: `id`, `email`, `roles`, `is_active`, `agent_id` (if linked), `created_at`

#### Scenario: Create a new user

- GIVEN a ROLE_ADMIN user
- WHEN `POST /api/admin/users` is called with `{email, password, roles}`
- THEN the response SHALL be 201 with the created user
- AND the password SHALL be stored hashed (never returned in response)

#### Scenario: Create user with duplicate email

- GIVEN a user already exists with email `test@inbiox.local`
- WHEN `POST /api/admin/users` is called with the same email
- THEN the response SHALL be 400 with a validation error

#### Scenario: Update user roles

- GIVEN a user with `ROLE_AGENT`
- WHEN `PUT /api/admin/users/{id}` is called with `roles: ["ROLE_SUPERVISOR"]`
- THEN the user's roles SHALL be updated
- AND the response SHALL be 200 with the updated user

#### Scenario: Soft delete user

- GIVEN an active user
- WHEN `DELETE /api/admin/users/{id}` is called
- THEN the user's `is_active` SHALL be set to `false`
- AND the response SHALL be 200
- AND the user's Agent link SHALL remain intact

#### Scenario: Supervisor denied user management

- GIVEN a ROLE_SUPERVISOR user
- WHEN `GET /api/admin/users` is called
- THEN the response SHALL be 403

---

### Requirement: Agent CRUD (Admin/Supervisor)

The system SHALL provide agent management operations accessible to `ROLE_ADMIN` and `ROLE_SUPERVISOR`.

| Endpoint | Method | Role |
|----------|--------|------|
| `/api/admin/agents` | GET | ADMIN, SUPERVISOR |
| `/api/admin/agents` | POST | ADMIN, SUPERVISOR |
| `/api/admin/agents/{id}` | GET | ADMIN, SUPERVISOR |
| `/api/admin/agents/{id}` | PUT | ADMIN, SUPERVISOR |
| `/api/admin/agents/{id}` | DELETE | ADMIN, SUPERVISOR (soft delete) |

#### Scenario: List agents with user link info

- GIVEN a ROLE_SUPERVISOR user
- WHEN `GET /api/admin/agents` is called
- THEN the response SHALL include each agent's `id`, `name`, `is_available`, `active`, `user_id` (if linked), and linked user's `email` (if available)

#### Scenario: Create agent without user link

- GIVEN a ROLE_ADMIN user
- WHEN `POST /api/admin/agents` is called with `{name}` (no user_id)
- THEN the agent SHALL be created with `user_id = NULL`
- AND the response SHALL be 201

#### Scenario: Agent denied agent management

- GIVEN a ROLE_AGENT user
- WHEN `GET /api/admin/agents` is called
- THEN the response SHALL be 403

---

### Requirement: User ↔ Agent Linking

The system SHALL provide dedicated endpoints for linking and unlinking Users to Agents.

| Endpoint | Method | Role |
|----------|--------|------|
| `/api/admin/agents/{id}/link-user` | POST | ROLE_ADMIN |
| `/api/admin/agents/{id}/link-user` | DELETE | ROLE_ADMIN |

#### Scenario: Link agent to user

- GIVEN Agent id=5 with `user_id = NULL` and User id=10 with no linked agent
- WHEN `POST /api/admin/agents/5/link-user` is called with `{user_id: 10}`
- THEN Agent 5's `user_id` SHALL be set to 10
- AND the response SHALL be 200 with the updated agent

#### Scenario: Unlink agent from user

- GIVEN Agent id=5 linked to User id=10
- WHEN `DELETE /api/admin/agents/5/link-user` is called
- THEN Agent 5's `user_id` SHALL be set to `NULL`
- AND User 10 SHALL remain active
- AND the response SHALL be 200

#### Scenario: Link to already-linked agent

- GIVEN Agent id=5 already linked to User id=10
- WHEN `POST /api/admin/agents/5/link-user` is called with `{user_id: 20}`
- THEN the response SHALL be 400 with error "Agent is already linked to a user"

#### Scenario: Link to already-linked user

- GIVEN User id=10 already linked to Agent id=5
- WHEN `POST /api/admin/agents/8/link-user` is called with `{user_id: 10}`
- THEN the response SHALL be 400 with error "User is already linked to an agent"

#### Scenario: Supervisor denied linking

- GIVEN a ROLE_SUPERVISOR user
- WHEN `POST /api/admin/agents/5/link-user` is called
- THEN the response SHALL be 403

---

### Requirement: Bootstrap Admin Flow

The system SHALL provide a first-run admin bootstrap that creates the initial admin user from environment configuration.

#### Scenario: Admin bootstrap on fresh install

- GIVEN a fresh database with no users
- GIVEN `INITIAL_ADMIN_PASSWORD=SecureP@ss123` in `.env`
- WHEN the migration runs
- THEN a user SHALL be created: `admin@inbiox.local` / `SecureP@ss123` / `ROLE_ADMIN`
- AND the admin SHALL be able to login immediately

#### Scenario: Admin bootstrap skipped when users exist

- GIVEN at least one user exists in the database
- WHEN the migration runs
- THEN no admin user SHALL be created
- AND existing data SHALL remain untouched

---

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

---

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

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 6 |
| Total Scenarios | 21 |

## Coverage

- **Happy paths**: User CRUD, Agent CRUD, linking/unlinking, bootstrap
- **Edge cases**: Duplicate email, already-linked constraints, supervisor role limits
- **Error states**: 403 for insufficient role, 400 for constraint violations
