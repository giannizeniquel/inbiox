# Delta for Administration

## MODIFIED Requirements

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
