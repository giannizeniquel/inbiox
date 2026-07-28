# Identity Management Specification

## Purpose

Defines the User entity, the User ↔ Agent relationship, and the migration from Agent.email to User.email as the single source of identity.

---

## Requirements

### Requirement: User Entity

The system SHALL provide a `User` entity representing an authenticated identity with email, password, roles, and active status.

| Field | Type | Constraints |
|-------|------|-------------|
| id | int (auto) | PK |
| email | string(255) | UNIQUE, NOT NULL |
| password_hash | string | NOT NULL (hashed via auto algorithm) |
| roles | json | NOT NULL, array of strings (e.g. `["ROLE_ADMIN"]`) |
| is_active | boolean | default `true` |
| created_at | datetime_immutable | auto-set |
| updated_at | datetime_immutable | auto-set |

The User entity MUST implement Symfony `UserInterface` and `PasswordAuthenticatedUserInterface`.

#### Scenario: Create user with valid data

- GIVEN no user exists with email `admin@inbiox.local`
- WHEN a user is created with email, password, and roles
- THEN the user SHALL be persisted with a hashed password
- AND `is_active` SHALL default to `true`

#### Scenario: Duplicate email rejected

- GIVEN a user exists with email `alice@inbiox.local`
- WHEN another user is created with the same email
- THEN the system SHALL reject the creation with a unique constraint violation

#### Scenario: User without Agent (ADMIN/SUPERVISOR)

- GIVEN a user is created with role `ROLE_ADMIN`
- WHEN no Agent is linked
- THEN the user SHALL exist independently with `agent_id = NULL`
- AND the user SHALL be able to authenticate and access the system

---

### Requirement: Agent Entity Update

The system SHALL modify the existing Agent entity to remove the `email` column and add an optional `user_id` foreign key.

| Change | Column | Constraint |
|--------|--------|------------|
| REMOVE | email | was UNIQUE NOT NULL |
| ADD | user_id | FK → user.id, NULLABLE, UNIQUE |

The Agent constructor SHALL no longer require an email parameter.

#### Scenario: Agent created without user link

- GIVEN an agent named "Support Bot" is created
- WHEN no user_id is provided
- THEN the agent SHALL be persisted with `user_id = NULL`
- AND the agent SHALL remain functional for ticket assignment and availability

#### Scenario: Agent email column removed

- GIVEN the migration runs on a database with existing agents
- WHEN the migration completes
- THEN the `agents.email` column SHALL be dropped
- AND existing agent email data SHALL be preserved by creating corresponding User records linked via `user_id`

---

### Requirement: User ↔ Agent Linking

The system SHALL support an optional one-to-one relationship between User and Agent. A User MAY exist without an Agent. An Agent MAY exist without a User.

#### Scenario: Link existing Agent to User

- GIVEN an Agent with `user_id = NULL` and a User with no linked Agent
- WHEN an admin links the Agent to the User
- THEN the Agent's `user_id` SHALL be set to the User's id
- AND the User SHALL now be associated with that Agent

#### Scenario: Unlink Agent from User

- GIVEN an Agent linked to a User (`user_id` is set)
- WHEN an admin unlinks the Agent
- THEN the Agent's `user_id` SHALL be set to `NULL`
- AND the User SHALL remain active (not deleted)

#### Scenario: Cannot link Agent to already-linked User

- GIVEN User A is already linked to Agent X
- WHEN an admin attempts to link User A to Agent Y
- THEN the system SHALL reject the operation (unique constraint on `user_id`)

#### Scenario: Cannot link two Agents to same User

- GIVEN Agent X is linked to User A
- WHEN an admin attempts to link Agent Y to User A
- THEN the system SHALL reject the operation (unique constraint on Agent.user_id)

---

### Requirement: Soft Delete Behavior

The system SHALL use soft delete via `is_active` flag. No hard deletion of User or Agent records.

#### Scenario: Deactivate user

- GIVEN an active User
- WHEN the user is deactivated
- THEN `is_active` SHALL be set to `false`
- AND the user SHALL NOT be able to authenticate (login rejected)
- AND if linked to an Agent, the Agent SHALL remain intact

#### Scenario: Delete user preserves Agent

- GIVEN a User linked to Agent X
- WHEN the User is soft-deleted (`is_active = false`)
- THEN Agent X SHALL retain its `user_id` link
- AND Agent X SHALL continue to function for ticket assignment

#### Scenario: Reactivate user

- GIVEN a deactivated User (`is_active = false`)
- WHEN the user is reactivated
- THEN `is_active` SHALL be set to `true`
- AND the user SHALL be able to authenticate again

---

### Requirement: Agent Email Migration

The system SHALL migrate existing Agent email data to User records during the schema migration.

#### Scenario: Migration with existing agents

- GIVEN 3 agents exist with emails: `a@inbiox.local`, `b@inbiox.local`, `c@inbiox.local`
- WHEN the migration runs
- THEN 3 User records SHALL be created with those emails
- AND each Agent's `user_id` SHALL be set to the corresponding User's id
- AND the `agents.email` column SHALL be dropped

#### Scenario: Migration idempotency

- GIVEN the migration has already run
- WHEN the migration is executed again
- THEN no changes SHALL be applied (idempotent)

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 5 |
| Total Scenarios | 12 |

## Coverage

- **Happy paths**: User creation, Agent update, linking/unlinking, soft delete, migration
- **Edge cases**: Duplicate email, double-link prevention, migration idempotency
- **Error states**: Unique constraint violations, invalid link operations
