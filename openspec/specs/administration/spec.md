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

The system SHALL provide agent management operations accessible to `ROLE_ADMIN` and `ROLE_SUPERVISOR`. Create and update SHALL accept an optional `timezone` field, validated as an IANA time zone name via `Agent::setTimezone()`. The serialized agent representation and the admin agent-list SHALL include `timezone`.

(Previously: `timezone` was not exposed on any endpoint or in the serialized response; the controller's class-level `#[IsGranted]` attribute enforced `ROLE_ADMIN` only, silently 403-ing supervisors despite the firewall, docblock, and this spec all intending `ROLE_SUPERVISOR` access — corrected here as an implementation fix, not a policy change.)

| Endpoint | Method | Role |
|----------|--------|------|
| `/api/admin/agents` | GET | ADMIN, SUPERVISOR |
| `/api/admin/agents` | POST | ADMIN, SUPERVISOR |
| `/api/admin/agents/{id}` | GET | ADMIN, SUPERVISOR |
| `/api/admin/agents/{id}` | PUT | ADMIN, SUPERVISOR |
| `/api/admin/agents/{id}` | DELETE | ADMIN, SUPERVISOR (soft delete) |

Out of scope for this requirement's timezone behavior: `AgentVoter::EDIT_AGENT` activation, self-service editing by `ROLE_AGENT`, and auditing the same gating pattern in other admin controllers.

#### Scenario: List agents with user link info

- GIVEN a ROLE_SUPERVISOR user
- WHEN `GET /api/admin/agents` is called
- THEN the response SHALL include each agent's `id`, `name`, `timezone`, `is_available`, `active`, `user_id` (if linked), and linked user's `email` (if available)
- AND the admin agent-list table SHALL display `timezone` as a column

#### Scenario: Create agent without user link

- GIVEN a ROLE_ADMIN user
- WHEN `POST /api/admin/agents` is called with `{name}` (no `user_id`, no `timezone`)
- THEN the agent SHALL be created with `user_id = NULL` and `timezone = 'UTC'`
- AND the response SHALL be 201

#### Scenario: Agent denied agent management

- GIVEN a ROLE_AGENT user
- WHEN `GET /api/admin/agents` is called
- THEN the response SHALL be 403

#### Scenario: Create agent with an explicit valid timezone

- GIVEN a ROLE_ADMIN or ROLE_SUPERVISOR user
- WHEN `POST /api/admin/agents` is called with `{name, timezone: "America/Sao_Paulo"}`
- THEN the agent SHALL be created with that `timezone`
- AND the response SHALL be 201 with `timezone` in the serialized body

#### Scenario: Create agent with an invalid timezone

- GIVEN a ROLE_ADMIN or ROLE_SUPERVISOR user
- WHEN `POST /api/admin/agents` is called with `{name, timezone: "Not/A_Zone"}`
- THEN `Agent::setTimezone()` SHALL reject the value
- AND the response SHALL be 400 with the setter's validation message

#### Scenario: Update agent timezone

- GIVEN an existing agent with `timezone = 'UTC'`
- WHEN `PUT /api/admin/agents/{id}` is called with `{timezone: "Europe/Lisbon"}`
- THEN the agent's `timezone` SHALL be updated to `Europe/Lisbon`
- AND the response SHALL be 200 with the updated value

#### Scenario: Omitting timezone on update leaves it unchanged

- GIVEN an existing agent with `timezone = 'Europe/Lisbon'`
- WHEN `PUT /api/admin/agents/{id}` is called without a `timezone` key
- THEN the agent's `timezone` SHALL remain `Europe/Lisbon`

#### Scenario: Update agent with an invalid timezone

- GIVEN an existing agent
- WHEN `PUT /api/admin/agents/{id}` is called with `{timezone: "Not/A_Zone"}`
- THEN the response SHALL be 400 with the setter's validation message
- AND the agent's stored `timezone` SHALL remain unchanged

#### Scenario: Admin and supervisor reach every AgentController operation

- GIVEN a ROLE_ADMIN user and, separately, a ROLE_SUPERVISOR user
- WHEN each calls `GET /api/admin/agents`, `POST /api/admin/agents`, `PUT /api/admin/agents/{id}`, and `DELETE /api/admin/agents/{id}` in turn
- THEN neither role SHALL receive 403 on any of these calls
- AND both SHALL receive the same success status per operation (200/201 as applicable)

#### Scenario: Agent create/edit modal offers all supported IANA zones

- GIVEN a ROLE_ADMIN or ROLE_SUPERVISOR user opens the agent create or edit modal
- WHEN the timezone control renders
- THEN it SHALL list every zone identifier returned by `Intl.supportedValuesOf('timeZone')`
- AND on create it SHALL default to no pre-selection (server defaults to `'UTC'` when omitted); on edit it SHALL pre-select the agent's stored `timezone`

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

## Summary

| Type | Count |
|------|-------|
| Requirements | 4 |
| Total Scenarios | 23 |

## Coverage

- **Happy paths**: User CRUD, Agent CRUD, linking/unlinking, bootstrap
- **Edge cases**: Duplicate email, already-linked constraints, supervisor role limits
- **Error states**: 403 for insufficient role, 400 for constraint violations
