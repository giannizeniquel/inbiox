# Delta for Authorization

## MODIFIED Requirements

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

The "Reassign tickets: ROLE_AGENT ❌" row governs only user-initiated reassignment requests (e.g. an explicit manual reassign action or endpoint). It does NOT govern system-triggered ownership changes, such as the reply-triggered auto-assignment defined by the `reply-auto-assignment` capability, where any replying agent — regardless of role — may become or replace the ticket's owner as a side effect of sending a message. This row and the reply-auto-assignment behavior are independent and do not contradict each other.
(Previously: the matrix did not distinguish user-initiated reassignment from system-triggered reassignment, so the "Reassign tickets: ROLE_AGENT ❌" row appeared to contradict the already-live reply-auto-assignment behavior.)

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

#### Scenario: Agent reply auto-assignment is not blocked by the reassign-tickets restriction

- GIVEN a ROLE_AGENT user who is not the current ticket owner
- WHEN that agent sends an outbound reply to the ticket (the system-triggered reply-auto-assignment path)
- THEN ownership SHALL transfer to the replying agent
- AND this SHALL NOT be treated as a denied "Reassign tickets" action, because that restriction governs only user-initiated reassignment requests
