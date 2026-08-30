# Authorization Specification

## Purpose

Defines Role-Based Access Control (RBAC), ticket ownership rules, controller protection, webhook carve-out, Mercure token gating, and debug endpoint restrictions.

---

## Requirements

### Requirement: RBAC Permission Matrix

The system SHALL enforce the following permission matrix via Symfony Security `#[IsGranted]` attributes on controllers, plus a per-ticket ownership check for resolving/closing a ticket.

| Action | ROLE_ADMIN | ROLE_SUPERVISOR | ROLE_AGENT |
|--------|:----------:|:---------------:|:----------:|
| Manage users (CRUD) | ✅ | ❌ | ❌ |
| Manage agents (CRUD) | ✅ | ✅ | ❌ |
| View all reports | ✅ | ✅ | ❌ |
| View assigned tickets | ✅ | ✅ | ✅ |
| View all tickets | ✅ | ✅ | ❌ |
| Reassign tickets | ✅ | ✅ | ❌ |
| Resolve own tickets, or close own tickets via a direct transition | ✅ | ✅ | ✅ |
| Resolve/close another agent's ticket | ✅ | ✅ | ❌ |
| Close a ticket via the two-step resolve-then-close action | ✅ | ✅ | ❌ (structurally always unowned by that point — see note) |
| System config | ✅ | ❌ | ❌ |

Roles are hierarchical: `ROLE_ADMIN` includes all `ROLE_SUPERVISOR` permissions, `ROLE_SUPERVISOR` includes all `ROLE_AGENT` permissions. Resolving or closing a ticket — including a generic transition whose target status is `resolved` or `closed` — is allowed only for an admin, a supervisor, or the ticket's current owning agent; any other case is rejected.
(Previously: closing was declared only as a role-level `ROLE_AGENT` check with no per-ticket ownership enforcement, and the ownership scenarios below covered only the generic transition endpoint.)

**Note on the two-step close action**: resolving a ticket unassigns it as a documented side effect (confirmed intentional, for accurate real-time workload reporting). Because of this, a ticket eligible for the two-step resolve-then-close action has no owner by the time that action is reachable — an agent can never satisfy the "current owning agent" condition there, regardless of who resolved it. An agent's own-ticket closure is achieved instead by a direct transition to closed from an active/in-progress state, while ownership still holds. This is a factual consequence of the confirmed unassign-on-resolve behavior, not a separate policy choice.

#### Scenario: Admin accesses user management

- GIVEN a user with `ROLE_ADMIN`
- WHEN `GET /api/admin/users` is called
- THEN the response SHALL be 200

#### Scenario: Supervisor denied user management

- GIVEN a user with `ROLE_SUPERVISOR`
- WHEN `GET /api/admin/users` is called
- THEN the response SHALL be 403

#### Scenario: Agent denied agent management

- GIVEN a user with `ROLE_AGENT`
- WHEN `POST /api/admin/agents` is called
- THEN the response SHALL be 403

#### Scenario: Supervisor can manage agents

- GIVEN a user with `ROLE_SUPERVISOR`
- WHEN `GET /api/agents` is called
- THEN the response SHALL be 200

#### Scenario: Agent can view assigned tickets

- GIVEN a user with `ROLE_AGENT` linked to Agent id=3
- WHEN `GET /api/tickets` is called
- THEN the response SHALL be 200
- AND only tickets with `assigned_agent_id = 3` SHALL be returned

#### Scenario: Agent denied viewing all tickets

- GIVEN a user with `ROLE_AGENT`
- WHEN `GET /api/tickets` is called without agent filter context
- THEN the response SHALL only contain tickets assigned to the user's Agent

#### Scenario: Supervisor can view all tickets

- GIVEN a user with `ROLE_SUPERVISOR`
- WHEN `GET /api/tickets` is called
- THEN the response SHALL be 200 with ALL tickets regardless of assignment

#### Scenario: Agent can resolve own ticket

- GIVEN a user with `ROLE_AGENT` linked to Agent id=3
- WHEN `POST /tickets/{id}/resolve` is called for an active/waiting ticket assigned to Agent 3
- THEN the response SHALL be 200
- AND the ticket is no longer assigned to Agent 3 as a result (confirmed intentional side effect, unrelated to this requirement)

#### Scenario: Agent can close own ticket via a direct transition

- GIVEN a user with `ROLE_AGENT` linked to Agent id=3
- WHEN `POST /tickets/{id}/transition` (target `closed`) is called for an active/waiting ticket assigned to Agent 3
- THEN the response SHALL be 200

#### Scenario: Agent can never close a ticket via the two-step close action, even one they resolved themselves

- GIVEN a user with `ROLE_AGENT` linked to Agent id=3, who has just resolved a ticket previously assigned to them
- WHEN that same agent calls `POST /tickets/{id}/close`
- THEN the response SHALL be 403
- AND this holds regardless of who resolved the ticket, because resolving unassigns it (per the confirmed unassign-on-resolve behavior) before the close action becomes reachable

#### Scenario: Agent cannot resolve or close another agent's ticket

- GIVEN a user with `ROLE_AGENT` linked to Agent id=3
- WHEN `POST /tickets/{id}/resolve`, `/close`, or `/transition` (target `resolved`/`closed`) is called for a ticket assigned to Agent 7
- THEN the response SHALL be 403

#### Scenario: Supervisor can resolve or close another agent's ticket

- GIVEN a user with `ROLE_SUPERVISOR`
- WHEN `POST /tickets/{id}/resolve` or `/close` is called for a ticket assigned to any agent, or with no owner
- THEN the response SHALL be 200

#### Scenario: Admin can resolve or close another agent's ticket

- GIVEN a user with `ROLE_ADMIN`
- WHEN `POST /tickets/{id}/resolve` or `/close` is called for a ticket assigned to any agent, or with no owner
- THEN the response SHALL be 200

#### Scenario: Non-closing transition unaffected

- GIVEN a user with `ROLE_AGENT` not owning a ticket
- WHEN `POST /tickets/{id}/transition` is called with a target status other than `resolved` or `closed`
- THEN the response SHALL behave exactly as before this change, unaffected by the ownership check

#### Scenario: Admin can access system config

- GIVEN a user with `ROLE_ADMIN`
- WHEN `POST /admin/outbox/publish` is called
- THEN the response SHALL be 200

#### Scenario: Supervisor denied system config

- GIVEN a user with `ROLE_SUPERVISOR`
- WHEN `POST /admin/outbox/publish` is called
- THEN the response SHALL be 403

---

### Requirement: Ticket Ownership Rules

The system SHALL scope ticket visibility based on the authenticated user's role and linked agent.

| Role | Ticket Visibility |
|------|-------------------|
| ROLE_AGENT | Only tickets where `assigned_agent_id = user's agent.id` |
| ROLE_SUPERVISOR | All tickets |
| ROLE_ADMIN | All tickets |

This applies to: inbox queries (`GET /api/tickets`), reports (`GET /api/reports/*`), and SSE subscriptions (`GET /api/tickets/{id}/events`).

#### Scenario: Agent sees only own tickets in inbox

- GIVEN a ROLE_AGENT user linked to Agent id=3
- WHEN `GET /api/tickets` is called
- THEN the query SHALL filter by `assigned_agent_id = 3`
- AND tickets assigned to other agents SHALL NOT appear

#### Scenario: Supervisor sees all tickets in reports

- GIVEN a ROLE_SUPERVISOR user
- WHEN `GET /api/reports/counts` is called
- THEN the report SHALL include data from ALL tickets

#### Scenario: Agent SSE subscription scoped

- GIVEN a ROLE_AGENT user linked to Agent id=3
- WHEN the frontend requests a Mercure token via `GET /api/mercure/token`
- THEN the issued Mercure JWT SHALL only include topics for tickets assigned to Agent 3
- AND the agent SHALL NOT receive SSE events for other agents' tickets

#### Scenario: Supervisor SSE subscription includes all topics

- GIVEN a ROLE_SUPERVISOR user
- WHEN the frontend requests a Mercure token
- THEN the issued Mercure JWT SHALL include topics for ALL active tickets

#### Scenario: Agent without linked Agent record

- GIVEN a ROLE_AGENT user with `agent_id = NULL` (edge case)
- WHEN `GET /api/tickets` is called
- THEN the response SHALL return an empty ticket list (no agent to scope to)

---

### Requirement: Controller Protection

The system SHALL protect all API controllers with `#[IsGranted]` attributes. No endpoint SHALL be accessible without authentication except explicitly carved-out paths.

#### Scenario: Unauthenticated request to protected endpoint

- GIVEN no JWT access token is provided
- WHEN any protected endpoint is called (e.g., `GET /api/tickets`)
- THEN the response SHALL be 401 with `{"error": "Authentication required"}`

#### Scenario: Expired JWT access token

- GIVEN an expired JWT access token (> 15 min old)
- WHEN a protected endpoint is called
- THEN the response SHALL be 401

#### Scenario: Valid JWT with insufficient role

- GIVEN a ROLE_AGENT user
- WHEN `GET /api/admin/users` is called (requires ROLE_ADMIN)
- THEN the response SHALL be 403 with `{"error": "Access denied"}`

---

### Requirement: Webhook Carve-out

The system SHALL keep webhook endpoints publicly accessible. Webhook authentication is via HMAC signature, not JWT.

| Endpoint | Auth | Firewall |
|----------|------|----------|
| `GET /webhook` | None (Meta verification) | PUBLIC_ACCESS |
| `POST /webhook` | HMAC `X-Hub-Signature-256` | PUBLIC_ACCESS |

#### Scenario: Webhook POST without JWT

- GIVEN a valid Meta webhook payload with correct HMAC signature
- WHEN `POST /webhook` is called without any Authorization header
- THEN the request SHALL be processed normally (200)
- AND the HMAC signature SHALL be validated by WebhookController

#### Scenario: Webhook GET verification without JWT

- GIVEN a Meta verification request
- WHEN `GET /webhook` is called with `hub.mode`, `hub.verify_token`, `hub.challenge`
- THEN the request SHALL be processed normally (200)

#### Scenario: Webhook with invalid HMAC

- GIVEN a POST to `/webhook` with an incorrect `X-Hub-Signature-256` header
- WHEN the request is processed
- THEN the response SHALL be 403 (HMAC validation fails)

---

### Requirement: Mercure Token Controller Auth Gating

The system SHALL require authentication for `GET /api/mercure/token` and issue Mercure JWTs scoped to the user's access level.

**Flow**: Login → App JWT → `GET /api/mercure/token` (with Authorization header) → Mercure JWT → SSE subscription.

The frontend SHALL NOT have access to `MERCURE_JWT_SECRET`. The MercureTokenController verifies the application JWT and issues a Mercure JWT.

#### Scenario: Authenticated agent requests Mercure token

- GIVEN a ROLE_AGENT user linked to Agent id=3
- WHEN `GET /api/mercure/token` is called with valid app JWT
- THEN the response SHALL contain a Mercure JWT
- AND the Mercure JWT's topics SHALL be limited to tickets assigned to Agent 3

#### Scenario: Authenticated supervisor requests Mercure token

- GIVEN a ROLE_SUPERVISOR user
- WHEN `GET /api/mercure/token` is called with valid app JWT
- THEN the Mercure JWT SHALL include topics for all active tickets

#### Scenario: Unauthenticated Mercure token request

- GIVEN no app JWT is provided
- WHEN `GET /api/mercure/token` is called
- THEN the response SHALL be 401
- AND no Mercure token SHALL be issued

---

### Requirement: Debug Endpoint Restrictions

The system SHALL restrict debug endpoints to the `dev` environment only.

| Endpoint | Environment | Auth |
|----------|-------------|------|
| `DebugController` (`/debug/*`) | dev only | PUBLIC_ACCESS in dev; 404 in prod |
| `DebugJwtController` (`/debug/jwt/*`) | REMOVED | N/A (Sprint 14 removes this controller) |

#### Scenario: Debug endpoints in dev environment

- GIVEN the application runs in `APP_ENV=dev`
- WHEN `POST /debug/inbound` is called
- THEN the request SHALL be processed normally (no auth required)

#### Scenario: Debug endpoints in prod environment

- GIVEN the application runs in `APP_ENV=prod`
- WHEN `POST /debug/inbound` is called
- THEN the response SHALL be 404 (routes not registered)

#### Scenario: DebugJwtController removed

- GIVEN Sprint 14 is deployed
- WHEN `GET /debug/jwt/{agentId}` is called
- THEN the response SHALL be 404 (controller removed)

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 6 |
| Total Scenarios | 30 |

## Coverage

- **Happy paths**: All role-based access patterns, ticket ownership, webhook flow, Mercure token flow
- **Edge cases**: Agent without linked Agent record, expired tokens, debug in prod
- **Error states**: 401 for unauthenticated, 403 for insufficient role, 404 for removed endpoints
