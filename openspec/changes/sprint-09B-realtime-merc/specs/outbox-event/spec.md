# Outbox Event Specification

## Purpose

The outbox-event domain provides the persistence layer for domain events before they are published to Mercure. This ensures reliable event delivery with at-least-once semantics and enables retry on failure.

## Requirements

### Requirement: OutboxEvent Entity

The system MUST have an OutboxEvent entity that persists domain events before publishing.

The entity MUST have these fields:
- `id`: ULID (string, primary key) — uniquely identifies the event
- `aggregate_id`: integer — the ticket ID this event relates to
- `topic`: string — the Mercure topic (e.g., "ticket/123")
- `event_type`: string — the event type (message.created, ticket.updated, inbox.updated)
- `payload`: JSON — the event data
- `published_at`: datetime|null — timestamp when published successfully
- `retry_count`: integer — number of publish attempts (default 0)

#### Scenario: Event persisted with business logic

- GIVEN HandleInboundMessageUseCase is invoked to process an inbound message
- WHEN the message is persisted to the database
- THEN a corresponding OutboxEvent MUST be persisted in the same transaction
- AND the event's aggregate_id matches the ticket ID of the message

#### Scenario: Event available for publishing

- GIVEN an OutboxEvent exists with published_at = NULL
- WHEN OutboxPublisherService queries for unpublished events
- THEN this event MUST be returned
- AND retry_count MUST be less than 5

#### Scenario: Event marked published

- GIVEN an OutboxEvent exists with published_at = NULL
- WHEN OutboxPublisherService successfully publishes to Mercure
- THEN published_at MUST be set to the current timestamp
- AND retry_count MUST remain unchanged

#### Scenario: Event retry on failure

- GIVEN an OutboxEvent exists with published_at = NULL and retry_count = 2
- WHEN publishing to Mercure fails with a network error
- THEN retry_count MUST be incremented to 3
- AND published_at MUST remain NULL

### Requirement: Transactional Integrity

The OutboxEvent MUST be persisted in the same database transaction as the business operation that triggers it.

If the business transaction fails, the outbox event MUST NOT be persisted.

#### Scenario: Transaction rollback discards event

- GIVEN HandleInboundMessageUseCase begins a transaction to persist a message
- WHEN a database constraint violation occurs during the transaction
- THEN the entire transaction MUST be rolled back
- AND no OutboxEvent for that message SHALL exist in the database

### Requirement: Duplicate Prevention

The system MUST prevent duplicate events from being persisted for the same business operation.

Each OutboxEvent MUST have a unique ULID, and the combination of aggregate_id + event_type + event-specific-id MUST be unique.

#### Scenario: Idempotent message handling

- GIVEN a message with external_id = "msg-abc" was already processed
- WHEN HandleInboundMessageUseCase receives the same message again
- THEN a new message and OutboxEvent MUST NOT be created
- AND an appropriate response indicating duplication SHALL be returned