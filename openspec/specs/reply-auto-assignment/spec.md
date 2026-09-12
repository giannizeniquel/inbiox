# Reply Auto-Assignment Specification

## Purpose

Formalizes the reply-triggered ownership change already live in `HandleOutboundMessageUseCase`: any outbound (agent) reply that comes from an agent other than the ticket's current owner claims or reassigns that ticket, regardless of role. Adds the missing resolved/closed guard, deterministic concurrency behavior, and event-collector isolation that were never specified.

---

## Requirements

### Requirement: Reply-Triggered Ownership Claim

The system MUST evaluate ticket ownership on every outbound agent message and MUST claim or reassign ownership to the replying agent whenever the replying agent differs from the ticket's current owner. This MUST apply to ROLE_AGENT, ROLE_SUPERVISOR, and ROLE_ADMIN senders alike, with no role-based exclusion.

#### Scenario: Reply claims an unassigned ticket

- GIVEN a ticket with no assigned agent
- WHEN agent 5 sends an outbound reply
- THEN the ticket SHALL be assigned to agent 5
- AND the assignment SHALL be recorded as `AUTO_ASSIGN` with no previous agent

#### Scenario: Reply reassigns a ticket owned by a different agent

- GIVEN a ticket owned by agent 3
- WHEN agent 7 sends an outbound reply to that ticket
- THEN ownership SHALL transfer to agent 7
- AND the assignment SHALL be recorded as `REASSIGN` with agent 3 as `previousAgent`

#### Scenario: Reply by the current owner causes no reassignment

- GIVEN a ticket owned by agent 3
- WHEN agent 3 sends an outbound reply
- THEN ownership SHALL remain unchanged
- AND no assignment/audit event SHALL be recorded

#### Scenario: Any eligible role triggers reassignment

- GIVEN a ticket owned by agent 3, or unassigned
- WHEN a user holding ROLE_AGENT, ROLE_SUPERVISOR, or ROLE_ADMIN, linked to a different agent, sends an outbound reply
- THEN ownership SHALL transfer to that replying agent with no role-based exclusion

---

### Requirement: Resolved/Closed Ticket Ownership Guard

The system MUST NOT change ownership of a ticket in `RESOLVED` or `CLOSED` status as a result of an outbound reply. A ROLE_SUPERVISOR or ROLE_ADMIN sender's message MUST still be delivered with no reassignment. A ROLE_AGENT sender who already owns the ticket MUST have the message delivered with no reassignment. A ROLE_AGENT sender who does NOT own the ticket (unassigned or owned by another agent) MUST be blocked: the send MUST be rejected, no message persisted, no ownership change made.

#### Scenario: Supervisor replies to a resolved/closed ticket

- GIVEN a `RESOLVED` or `CLOSED` ticket owned by any agent, or unassigned
- WHEN a ROLE_SUPERVISOR or ROLE_ADMIN user sends an outbound reply
- THEN the message SHALL be delivered (200)
- AND ownership SHALL remain unchanged

#### Scenario: Owning agent replies to their own resolved/closed ticket

- GIVEN a `RESOLVED` or `CLOSED` ticket owned by agent 3
- WHEN agent 3 sends an outbound reply
- THEN the message SHALL be delivered (200)
- AND ownership SHALL remain unchanged

#### Scenario: Non-owning agent blocked from replying to a resolved/closed ticket

- GIVEN a `RESOLVED` or `CLOSED` ticket unassigned or owned by an agent other than the sender
- WHEN a ROLE_AGENT user who is not the current owner attempts an outbound reply
- THEN the response SHALL be 403 with body `{"type": "TICKET_CLOSED_NOT_OWNER", "message": "Cannot reply to a resolved or closed ticket you do not own"}`
- AND no message SHALL be persisted
- AND no ownership change SHALL occur

---

### Requirement: Deterministic Concurrency Resolution

Under concurrent outbound replies to the same ticket, the system MUST serialize ownership evaluation so only one reply's transaction proceeds at a time, and the final owner MUST deterministically be the agent whose transaction commits last, with an accurate `previousAgent` audit chain.

#### Scenario: Two concurrent replies from different agents

- GIVEN two replies from different agents arrive concurrently for the same ticket
- WHEN both attempt to evaluate and change ownership
- THEN evaluation SHALL be serialized rather than resolved via unordered optimistic retries
- AND the ticket's final owner SHALL be the agent of whichever transaction commits last
- AND exactly one ownership-change audit entry per committed attempt SHALL exist, with correct `previousAgent` linkage
- AND no `ConcurrencyException` SHALL surface to the caller for up to three concurrent replies

---

### Requirement: No Event Leakage from Failed or Retried Attempts

The system MUST discard domain events collected during an attempt that does not commit (rolled back, or superseded before its own retry) before the next attempt begins, and MUST publish only events produced by the attempt that ultimately commits.

#### Scenario: Failed attempt does not leak events into a later successful attempt

- GIVEN a reply-processing attempt collects a `TicketAssigned` event and then fails or rolls back
- WHEN the operation retries and completes successfully
- THEN only events from the successful attempt SHALL be persisted to the outbox
- AND no duplicate or phantom `TicketAssigned` event, and no duplicate `seq` value, SHALL be emitted from the failed attempt

---

## Summary

| Type | Count |
|------|-------|
| Requirements | 4 |
| Total Scenarios | 10 |

## Coverage

- **Happy paths**: unassigned claim, reassignment, same-owner no-op, all roles eligible
- **Edge cases**: resolved/closed guard for all three sender/ownership combinations
- **Error states**: 403 `TICKET_CLOSED_NOT_OWNER` for blocked non-owner replies
- **Non-functional**: deterministic concurrency, event-collector isolation
