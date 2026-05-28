# Delta for ticket-entity

## ADDED Requirements

### Requirement: last_event_seq Field

The Ticket entity MUST have a `last_event_seq` integer field that tracks the sequence number of the most recent domain event emitted for this ticket.

The field SHALL be initialized to 0 when the ticket is created and MUST be incremented atomically each time a domain event is persisted to the outbox.

#### Scenario: Sequence initialized on ticket creation

- GIVEN a new ticket is created via CreateTicketUseCase
- WHEN the ticket is persisted to the database
- THEN `last_event_seq` MUST be set to 0
- AND no domain event is emitted for ticket creation

#### Scenario: Sequence increments on message received

- GIVEN a ticket with last_event_seq = 5 exists
- WHEN HandleInboundMessageUseCase persists a new message and domain event
- THEN last_event_seq MUST be incremented to 6
- AND the emitted event MUST include seq = 6

#### Scenario: Sequence increments on assignment

- GIVEN a ticket with last_event_seq = 3 exists and is unassigned
- WHEN AssignTicketUseCase assigns the ticket to an agent
- THEN last_event_seq MUST be incremented to 4
- AND a ticket.updated event MUST be emitted with the new owner

## MODIFIED Requirements

### Requirement: Optimistic Locking Integration

The Ticket entity MUST use the last_event_seq field in conjunction with the existing version field for optimistic locking.

(Previously: version field only for concurrency)

#### Scenario: Concurrent message events handled

- GIVEN ticket T1 has last_event_seq = 2, version = 1
- WHEN two concurrent HandleInboundMessageUseCase calls attempt to process messages
- THEN the first transaction SHALL succeed and increment last_event_seq to 3
- AND the second transaction SHALL fail due to version conflict
- AND no duplicate events are emitted