# Ticket Closure Audit Specification

## Purpose

Defines the audit trail and history recording for all ticket closure events, whether system-triggered (automatic window expiry) or manual (agent-initiated resolution/closure). Ensures every closure is distinctly recorded with actor attribution, role labeling, and ownership context.

---

## Requirements

### Requirement: Distinguishable Closure Audit Trail

Every ticket closure — whether triggered by the automatic window-expiry process or by a manual human action — MUST be recorded in a way that is visibly distinguishable as to its source and type. This record MUST appear in the ticket's and the associated contact's history.

#### Scenario: Window-expiry closure recorded distinctly

- GIVEN a ticket is closed by the automatic window-expiry process
- WHEN the ticket's or contact's history is viewed
- THEN a closure entry appears, distinguishable as system/window-expiry-triggered

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
- THEN the closure entry remains attributed to the system, exactly as defined by the window-expiry closure behavior, unaffected by any manual closure audit changes

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 4 |
| Total Scenarios | 14 |

## Coverage

- **Audit trail**: Window-expiry vs. manual closure distinction, system vs. manual attribution
- **Actor identification**: Real user attribution regardless of ownership
- **Ownership context**: Owner self-closure, override, unowned ticket closure
- **Role labeling**: Admin, supervisor, agent distinction
- **Backward compatibility**: Automatic closure attribution unchanged
