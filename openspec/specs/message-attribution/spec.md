# Message Attribution Specification

## Purpose

Record WHO produced each `Message` — a human agent, an automated process, or the contact — and WHICH agent identity is associated, so downstream consumers (e.g. response-time metrics, UI) can distinguish a human reply from an automated one without heuristics.

## Requirements

### Requirement: Sender Attribution Schema

The system MUST store, per `Message` row, a nullable `sender_agent_id` (bigint, no foreign-key constraint, indexed) and a nullable `sender_type` (backed enum column).

#### Scenario: New message row created after migration

- GIVEN the migration has run
- WHEN any new `Message` row is persisted through an application write path
- THEN `sender_type` MUST be non-null
- AND `sender_agent_id` MUST be either a valid agent id or null, per the write site's rule

#### Scenario: Migration is reversible

- GIVEN the migration has been applied
- WHEN it is rolled back
- THEN both columns and the index on `sender_agent_id` MUST be dropped without data loss to other columns

### Requirement: Sender Type Enum

The system MUST define `MessageSenderType` as a backed enum with exactly the cases `agent`, `automatic`, and `contact`. No other literal value MUST ever be written to `sender_type`.

#### Scenario: Enum case coverage

- GIVEN a message write site
- WHEN it sets `sender_type`
- THEN the value MUST be one of `agent`, `automatic`, `contact` produced via `MessageSenderType`, never a raw string literal

### Requirement: Attribution at Human Agent Reply

The system MUST record the acting agent's id and `sender_type = agent` when a human agent sends an outbound message via `HandleOutboundMessageUseCase`.

#### Scenario: Agent sends a reply

- GIVEN an authenticated agent sends an outbound message
- WHEN `HandleOutboundMessageUseCase` persists the message
- THEN `sender_agent_id` MUST equal the acting agent's id
- AND `sender_type` MUST be `agent`
- AND a `MessageCreatedEvent` MUST be emitted to the outbox for this message

### Requirement: Attribution at Automated Reply

The system MUST record the responsible agent's id and `sender_type = automatic` when `AutoReplyService` sends an unavailable-agent auto-reply.

#### Scenario: Auto-reply is sent

- GIVEN an inbound message triggers an automated unavailable-agent reply
- WHEN `AutoReplyService` persists the reply message
- THEN `sender_agent_id` MUST equal the mailbox/assigned agent's id (non-null)
- AND `sender_type` MUST be `automatic`, never `agent`

### Requirement: Attribution at Inbound Contact Message

The system MUST record `sender_agent_id = null` and `sender_type = contact` for messages originating from the contact.

#### Scenario: Contact sends an inbound message

- GIVEN a contact sends a message that is processed by `HandleInboundMessageUseCase`
- WHEN the message is persisted
- THEN `sender_agent_id` MUST be null
- AND `sender_type` MUST be `contact`

### Requirement: Attribution at Template Send

The system MUST resolve the acting agent via `#[CurrentUser]` in `ProviderProxyController::sendTemplate()` and record it the same way as a human reply, degrading gracefully rather than failing the send when no `Agent` record exists for the current user.

#### Scenario: Agent sends a template message

- GIVEN an authenticated user calls `sendTemplate()`
- WHEN no error occurs during agent resolution
- THEN `sender_agent_id` MUST equal the resolved agent's id
- AND `sender_type` MUST be `agent`

#### Scenario: Current user has no Agent record

- GIVEN an authenticated user without an associated `Agent` entity calls `sendTemplate()`
- WHEN the message is persisted
- THEN the send MUST NOT fail because attribution could not be resolved
- AND `sender_agent_id` MUST be null while `sender_type` remains `agent`

### Requirement: Query and DTO Exposure

The system MUST expose `sender_agent_id` and `sender_type` through `GetTicketMessagesQuery` (both SELECT variants and both hydration paths) and through `MessageDTO`, including its `toArray()` output.

#### Scenario: Fetching ticket messages

- GIVEN a ticket has messages with attribution set
- WHEN `GetTicketMessagesQuery` is executed via either query path
- THEN each returned `MessageDTO` MUST include `sender_agent_id` and `sender_type`
- AND `MessageDTO::toArray()` MUST include both keys

### Requirement: Legacy NULL Invariant, No Backfill

The system MUST NOT backfill `sender_agent_id`/`sender_type` on rows that existed before the migration. A NULL `sender_type` MUST be interpreted exclusively as "legacy pre-migration row, attribution unknown" and MUST NOT be produced by any post-migration write path.

#### Scenario: Pre-migration row is read after deploy

- GIVEN a `Message` row created before the migration
- WHEN it is read after the migration and code deploy
- THEN `sender_agent_id` and `sender_type` MUST remain null
- AND no scheduled or on-demand process introduced by this change MUST write non-null values to that row

#### Scenario: Consumer distinguishes legacy from attributed rows

- GIVEN a consumer reads `sender_type` for a message
- WHEN the value is null
- THEN the consumer MUST treat it as "legacy/unknown", distinct from any of `agent`, `automatic`, `contact`
