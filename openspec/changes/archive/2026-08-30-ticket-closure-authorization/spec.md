# Delta Specs: ticket-closure-authorization

## Domain: authorization (Modified Capability)

No change to `Ticket Ownership Rules` or reporting/inbox visibility — shared-inbox, unrestricted visibility for reports and ticket lists is unaffected by this change; only closing/resolving actions gain a per-ticket ownership check.

### MODIFIED Requirements

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

## Domain: ticket-closure-audit (Modified Capability — pending; introduced by `whatsapp-24h-window-compliance`, not yet under `openspec/specs/`)

This change extends the sibling's pending delta. Its "Manual closure is unaffected" scenario (under "Distinguishable Closure Audit Trail") is superseded by the requirement below: once this change lands, a manual resolve/close does produce a distinguishable history entry — that scenario's premise no longer holds.

### ADDED Requirements

### Requirement: Actor-Attributed Closure and Resolution Audit Trail

The system MUST record every manual resolve or close action in the ticket's and contact's history, identifying the real acting user, regardless of whether the ticket had a prior owner. The record MUST distinguish three outcomes: action taken by the ticket's own current owner, action taken by a non-owner overriding the ticket's actual owner, and action taken on a ticket with no owner.

**Verification note**: resolving a ticket does not structurally require an assigned owner — no existing behavior blocks resolving an unowned ticket. All three outcomes are equally reachable for resolve as for close; this is not asymmetric.

#### Scenario: Owner closes own ticket
- GIVEN a ticket owned by Agent 3
- WHEN Agent 3 closes the ticket
- THEN the history shows a closure entry attributed to Agent 3, marked as closed by its own owner

#### Scenario: Non-owner overrides and closes another agent's ticket
- GIVEN a ticket owned by Agent 7
- WHEN a supervisor or admin closes that ticket
- THEN the history shows a closure entry attributed to the acting supervisor/admin, marked as an override of Agent 7's ticket

#### Scenario: Unowned ticket is closed
- GIVEN a ticket with no assigned owner
- WHEN an admin, supervisor, or eligible agent closes the ticket
- THEN the history shows a closure entry attributed to the real acting user, marked as closed with no prior owner

#### Scenario: Owner resolves own ticket
- GIVEN a ticket owned by Agent 3
- WHEN Agent 3 resolves the ticket
- THEN the history shows a resolution entry attributed to Agent 3, marked as resolved by its own owner

#### Scenario: Non-owner overrides and resolves another agent's ticket
- GIVEN a ticket owned by Agent 7
- WHEN a supervisor or admin resolves that ticket
- THEN the history shows a resolution entry attributed to the acting supervisor/admin, marked as an override of Agent 7's ticket

#### Scenario: Unowned ticket is resolved
- GIVEN a ticket with no assigned owner
- WHEN an admin, supervisor, or eligible agent resolves the ticket
- THEN the history shows a resolution entry attributed to the real acting user, marked as resolved with no prior owner

### Requirement: Actor Role Labeling in Closure Audit

The system MUST label the acting user in a closure/resolution history entry as one of three distinct roles: admin, supervisor, or agent — never merged into one generic label. An admin MUST always be labeled as admin, even though an admin also holds supervisor permissions via the role hierarchy.

#### Scenario: Admin actor labeled distinctly
- GIVEN a user holding both ROLE_ADMIN and ROLE_SUPERVISOR
- WHEN that user closes or resolves a ticket
- THEN the history entry labels the actor as admin, not supervisor

#### Scenario: Supervisor-only actor labeled distinctly
- GIVEN a user holding ROLE_SUPERVISOR without ROLE_ADMIN
- WHEN that user closes or resolves a ticket
- THEN the history entry labels the actor as supervisor, distinct from admin and agent

#### Scenario: Agent actor labeled distinctly
- GIVEN a user holding ROLE_AGENT
- WHEN that user closes or resolves their own ticket
- THEN the history entry labels the actor as agent, distinct from admin and supervisor

### Requirement: No Regression to Automatic Closure Attribution

An automatic closure — whether from the pre-existing inactivity-based auto-close rule or from window-expiry closure — MUST continue to be attributed to the system, unaffected by the manual closure audit behavior above. An automatic closure MUST NOT be subject to the manual resolve/close authorization check, since it is not a human-initiated request.

#### Scenario: Inactivity auto-close remains system-attributed
- GIVEN a resolved ticket eligible for automatic inactivity-based closure
- WHEN the automatic closure process closes it
- THEN the history shows the closure attributed to the system, with no owner/override/unowned distinction and no authorization check performed

#### Scenario: Window-expiry auto-close remains system-attributed
- GIVEN a ticket closed by the automatic window-expiry process
- WHEN the ticket's history is viewed
- THEN the closure entry remains attributed to the system, exactly as defined by the sibling change, unaffected by this change
