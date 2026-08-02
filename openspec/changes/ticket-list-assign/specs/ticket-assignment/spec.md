# Ticket Assignment Specification

## Purpose

Defines rules for assigning and reassigning tickets from the inbox list, including permission gates, state constraints, and frontend optimistic behavior.

## Requirements

### Requirement: Assign Ticket from List

The system SHALL allow assigning or reassigning a ticket from the inbox list via `POST /api/tickets/{id}/assign`. The requesting user MUST be the ticket's current owner, a ROLE_ADMIN, or a ROLE_SUPERVISOR. Assignment is only permitted on tickets in `new`, `active`, or `waiting` state.

#### Scenario: Owner assigns self to unassigned ticket

- GIVEN a ticket in `new` state with no owner
- WHEN the eligible agent calls `POST /api/tickets/{id}/assign` with their own agent ID
- THEN the ticket SHALL be assigned to that agent (200)

#### Scenario: Admin or supervisor assigns any eligible ticket

- GIVEN a ROLE_ADMIN or ROLE_SUPERVISOR user
- WHEN they assign a `new`/`active`/`waiting` ticket to an available agent
- THEN the assignment SHALL succeed (200)

#### Scenario: Non-owner agent blocked

- GIVEN a ROLE_AGENT who does NOT own the ticket
- WHEN they attempt to assign the ticket
- THEN the response SHALL be 403

#### Scenario: Resolved or closed ticket blocked

- GIVEN a ticket in `resolved` or `closed` state
- WHEN any user attempts to assign it
- THEN the response SHALL be 409 with a state-conflict error

#### Scenario: Waiting ticket transitions to active on assignment

- GIVEN a ticket in `waiting` state
- WHEN it is assigned or reassigned
- THEN the ticket state SHALL transition to `active`
- AND the new owner SHALL be set

#### Scenario: Reassignment changes owner

- GIVEN a ticket owned by Agent A in `active` state
- WHEN a supervisor reassigns it to Agent B
- THEN the ticket's owner SHALL become Agent B
- AND Agent A SHALL no longer see the ticket in their scoped inbox

#### Scenario: Optimistic update with rollback on error

- GIVEN the frontend displays the ticket list with assignment dropdown
- WHEN the user selects an agent from the dropdown
- THEN the UI SHALL immediately reflect the new assignment (optimistic update)
- AND if the backend returns an error, the UI SHALL rollback to the previous state
- AND an inline error badge SHALL appear and auto-dismiss after 5 seconds

### Requirement: Auto-Assignment on Reply Unchanged

The existing behavior where `HandleOutboundMessageUseCase` auto-assigns the replying agent to the ticket SHALL remain unchanged. This path is independent of list-based assignment and is NOT affected by this change.

#### Scenario: Agent replies to ticket owned by another agent

- GIVEN ticket T owned by Agent A
- WHEN Agent B replies to T via conversation
- THEN Agent B SHALL become the new owner of T (existing auto-assign behavior preserved)
