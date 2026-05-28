# Realtime Events Specification

## Purpose

The realtime-events domain defines the structured domain events that are emitted to Mercure for real-time updates. Each event includes guarantees for ordering, idempotency, and versioning.

## Requirements

### Requirement: Event Structure

Every domain event MUST include these required fields:
- `event_id`: ULID — uniquely identifies this event instance
- `type`: string — event type (message.created, ticket.updated, inbox.updated)
- `ticket_id`: integer — the ticket this event relates to
- `occurred_at`: ISO8601 datetime in UTC — when the event occurred
- `seq`: integer — monotonic sequence number for this ticket (starts at 1)
- `version`: integer — event schema version (starts at 1)

#### Scenario: Event emitted after message creation

- GIVEN a message is created for ticket 42
- WHEN the message is persisted
- THEN a domain event of type "message.created" MUST be created with:
  - event_id = unique ULID
  - type = "message.created"
  - ticket_id = 42
  - occurred_at = current UTC time in ISO8601
  - seq = last_event_seq + 1
  - version = 1

#### Scenario: Event emitted after ticket update

- GIVEN ticket 99 has its status changed to "in_progress"
- WHEN ChangeTicketStatusUseCase executes
- THEN a domain event of type "ticket.updated" MUST be created with:
  - event_id = unique ULID
  - type = "ticket.updated"
  - ticket_id = 99
  - occurred_at = current UTC time in ISO8601
  - seq = last_event_seq + 1
  - version = 1
  - payload.status = "in_progress"

### Requirement: message.created Event

The system MUST emit a "message.created" event when a message is added to a conversation.

The event payload MUST include:
- `message_id`: integer — the created message's ID
- `direction`: string — "inbound" or "outbound"

#### Scenario: Inbound message event

- GIVEN an inbound message is created for ticket 77
- WHEN the message persists
- THEN a "message.created" event MUST be published to topic "ticket/77"
- AND the payload.direction = "inbound"

#### Scenario: Outbound message event

- GIVEN an outbound message is created for ticket 77
- WHEN the message persists
- THEN a "message.created" event MUST be published to topic "ticket/77"
- AND the payload.direction = "outbound"

### Requirement: ticket.updated Event

The system MUST emit a "ticket.updated" event when a ticket's status, priority, or owner changes.

The event payload MUST include:
- `status`: string — the new ticket status
- `owner_agent_id`: integer|null — the ID of the assigned agent (null if unassigned)

#### Scenario: Ticket assigned to agent

- GIVEN ticket 55 is assigned to agent 8
- WHEN the assignment persists
- THEN a "ticket.updated" event MUST be published to topic "ticket/55"
- AND the payload.owner_agent_id = 8

#### Scenario: Ticket status changed

- GIVEN ticket 55 status changes from "open" to "resolved"
- WHEN the status change persists
- THEN a "ticket.updated" event MUST be published to topic "ticket/55"
- AND the payload.status = "resolved"

### Requirement: inbox.updated Event

The system MUST emit an "inbox.updated" event when a ticket enters or changes in an agent's inbox.

The event payload MUST include:
- `ticket_id`: integer — the ticket that changed

#### Scenario: Ticket added to agent inbox

- GIVEN ticket 66 is assigned to agent 12
- WHEN the assignment persists
- THEN an "inbox.updated" event MUST be published to topic "agent/12/inbox"
- AND the payload.ticket_id = 66

### Requirement: Topic Routing

Each event type MUST be published to the appropriate Mercure topic:
- message.created → ticket/{ticket_id}
- ticket.updated → ticket/{ticket_id}
- inbox.updated → agent/{agent_id}/inbox

#### Scenario: Events reach correct topics

- GIVEN a ticket.updated event for ticket 88 is emitted
- WHEN the event is published to Mercure
- THEN it MUST be published to topic "ticket/88"

- GIVEN an inbox.updated event for agent 5 is emitted
- WHEN the event is published to Mercure
- THEN it MUST be published to topic "agent/5/inbox"