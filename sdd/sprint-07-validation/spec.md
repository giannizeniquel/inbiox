# Delta Specs for Sprint 07 Validation

## ADDED Requirements

### Requirement: Idempotent Event Processing

The system MUST generate a deterministic event key for each outbound message processed by `HandleOutboundMessageUseCase`. The event key SHALL follow the format `outbound:{ticketId}:{contentHash}`. The system MUST check for an existing event key before processing and return the previous result if the key already exists.

#### SCENARIO: Process new outbound message

- GIVEN No event key exists for the outbound message
- WHEN `HandleOutboundMessageUseCase` is invoked with a ticket and message content
- THEN A new event key is generated and stored
- AND The message is processed normally with a new TicketMessage created

#### SCENARIO: Reprocess duplicate outbound message

- GIVEN An event key `outbound:123:abc123` already exists in the event log
- WHEN `HandleOutboundMessageUseCase` is invoked with the same ticket ID and content
- THEN The use case returns early with the previously stored result
- AND No new TicketMessage is created
- AND No state transition is attempted

#### SCENARIO: Event key format validation

- GIVEN A outbound message for ticket ID 456 with content "Hello customer"
- WHEN The event key is generated
- THEN The key MUST follow format `outbound:456:{hash}` where hash is a deterministic content hash
- AND The same content MUST always produce the same hash

### Requirement: Optimistic Locking on Ticket Entity

The Ticket entity MUST include a `@Version` annotated field for Doctrine optimistic locking. The system SHALL retry failed operations due to `OptimisticLockException` up to 3 times with exponential backoff. The system MUST NOT lose updates during concurrent modifications.

#### SCENARIO: Successful concurrent update with retry

- GIVEN Two concurrent requests attempt to modify ticket 123
- WHEN Both requests read version 0 and attempt to flush
- THEN The first request succeeds with version 1
- AND The second request receives `OptimisticLockException`
- AND The second request retries after re-reading version 1
- AND The second request succeeds with version 2

#### SCENARIO: Retry exhaustion on high contention

- GIVEN A ticket is under extreme concurrent modification
- WHEN A request fails 3 times due to `OptimisticLockException`
- THEN The system MUST throw a `ConcurrencyException` after 3 failed retries
- AND The error message SHALL indicate max retries exceeded

#### SCENARIO: Version field automatically managed by Doctrine

- GIVEN A new Ticket entity is created
- WHEN The entity is persisted and flushed
- THEN The `version` field MUST be initialized to 0 or 1 (database default)
- AND Each subsequent successful update MUST increment the version by 1

### Requirement: AutoClose Activity Check After Resolution

The `AutoCloseTicketUseCase` MUST verify that no customer messages (inbound direction) exist after `resolved_at` before closing a ticket. The system SHALL only count inbound messages in the activity check. Agent messages (outbound) SHALL NOT prevent auto-close.

#### SCENARIO: Close ticket with no customer activity after resolution

- GIVEN A ticket in `resolved` state with `resolved_at` set to 2026-05-01 10:00:00
- AND No inbound messages exist after 2026-05-01 10:00:00
- WHEN `AutoCloseTicketUseCase` executes after the auto-close delay
- THEN The ticket status MUST transition to `closed`
- AND `closed_at` MUST be set to the current timestamp

#### SCENARIO: Skip auto-close due to customer message after resolution

- GIVEN A ticket in `resolved` state with `resolved_at` set to 2026-05-01 10:00:00
- AND An inbound message exists with timestamp 2026-05-01 11:00:00
- WHEN `AutoCloseTicketUseCase` executes
- THEN The ticket MUST remain in `resolved` state
- AND No status change occurs
- AND A log entry SHOULD indicate customer activity prevented auto-close

#### SCENARIO: Auto-close proceeds with only agent messages after resolution

- GIVEN A ticket in `resolved` state with `resolved_at` set to 2026-05-01 10:00:00
- AND Only outbound messages exist after 2026-05-01 10:00:00
- WHEN `AutoCloseTicketUseCase` executes
- THEN The ticket status MUST transition to `closed`
- AND Agent messages SHALL NOT prevent auto-close

## MODIFIED Requirements

### Requirement: Ticket Workflow Transition Guards

The ticket workflow MUST validate transitions using both FSM validation and additional guards. Idempotency checks MUST prevent duplicate processing of the same event. Optimistic locking MUST protect against concurrent modifications during state transitions.

(Previously: Ticket workflow only validated FSM transitions without idempotency or optimistic locking)

#### SCENARIO: Valid FSM transition proceeds

- GIVEN A ticket in `active` state
- WHEN A transition to `waiting` is requested
- THEN The FSM validates the transition is allowed
- AND The transition proceeds normally

#### SCENARIO: Idempotency guard prevents duplicate event processing

- GIVEN An outbound message was already processed with event key `outbound:123:hash1`
- WHEN The same outbound message is submitted again
- THEN The idempotency guard detects the existing event key
- AND The use case returns early without processing

#### SCENARIO: Optimistic lock guard handles concurrent modification

- GIVEN Two processes attempt to modify the same ticket simultaneously
- WHEN A `OptimisticLockException` is thrown during flush
- THEN The system catches the exception
- AND Retries the operation up to 3 times with refreshed entity state

### Requirement: SLA Timestamp Determinism

The system MUST set SLA timestamps (FRT and RT) exactly once and never modify them. FRT MUST be set on the first inbound message or first status change to `active`. RT MUST be set when the ticket reaches `resolved` state. The system SHALL provide integration tests proving SLA determinism.

(Previously: SLA timestamps were set during workflow but without explicit determinism guarantees)

#### SCENARIO: FRT set exactly once on first activity

- GIVEN A new ticket in `new` state with no FRT timestamp
- WHEN The first inbound message arrives or ticket transitions to `active`
- THEN FRT MUST be set to the current timestamp
- AND Reprocessing the same event MUST NOT change FRT

#### SCENARIO: FRT unchanged on reprocessing

- GIVEN A ticket with FRT set to 2026-05-01 09:00:00
- WHEN The same inbound message that triggered FRT is reprocessed (idempotency)
- THEN FRT MUST remain 2026-05-01 09:00:00
- AND No update to FRT occurs

#### SCENARIO: RT reflects actual resolution after multiple waiting cycles

- GIVEN A ticket transitions: `active` → `waiting` → `active` → `waiting` → `resolved`
- WHEN The ticket reaches `resolved` state
- THEN RT MUST be set to the timestamp when `resolved` was first reached
- AND Previous `waiting` cycles MUST NOT affect RT value

#### SCENARIO: Waiting state counts as active for assignment queries

- GIVEN A ticket in `waiting` state assigned to agent ID 5
- WHEN The assignment query counts active tickets per agent
- THEN The `waiting` state MUST be included in active ticket counts
- AND The count for agent 5 MUST include the waiting ticket

## Integration Test Requirements

### Requirement: SLA Integrity Integration Tests

The system MUST include PHPUnit integration tests that verify SLA timestamp determinism end-to-end. Tests SHALL cover FRT set-once behavior, FRT immutability on reprocess, and RT correctness across multiple waiting cycles.

#### SCENARIO: Integration test verifies FRT set once

- GIVEN A test database with a ticket in `new` state
- WHEN An inbound message is processed via `HandleInboundMessageUseCase`
- THEN FRT timestamp MUST be set immediately after processing
- AND A second call with same message (idempotent) MUST NOT change FRT

#### SCENARIO: Integration test verifies RT after waiting cycles

- GIVEN A ticket with transitions: `new` → `active` → `waiting` → `active` → `resolved`
- WHEN The test queries the ticket after all transitions
- THEN RT MUST equal the timestamp of the final `resolved` transition
- AND RT MUST NOT reflect any intermediate state

#### SCENARIO: Integration test verifies idempotency skips transition

- GIVEN A processed outbound message with stored event key
- WHEN The same message is submitted again through the test
- THEN The test MUST assert no new TicketMessage entity was created
- AND The test MUST assert the ticket state did not change

#### SCENARIO: Integration test verifies AutoClose with customer activity

- GIVEN A resolved ticket with an inbound message after `resolved_at`
- WHEN `AutoCloseTicketUseCase` runs in the test
- THEN The test MUST assert the ticket remains in `resolved` state
- AND The test MUST assert `closed_at` is null
