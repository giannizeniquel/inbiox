# Delta for application-layer-messaging

## ADDED Requirements

### Requirement: SendMessageUseCase must perform optimistic reconciliation

The system SHALL provide a `SendMessageUseCase` that accepts `ticketId` and `content`, creates an optimistic message with a `tempId`, sends it through the ProviderInterface, and returns the reconciled `Message` with provider-assigned `providerId`.

The use case MUST:
- Generate a unique `tempId` before provider call
- Emit a domain event for SSE broadcast after provider response
- Map `tempId` to `providerId` on successful delivery
- Throw `MessageSendFailed` if provider rejects the message

#### Scenario: Successful outbound message send

- GIVEN a valid `ticketId` and `content`
- WHEN `SendMessageUseCase.execute({ ticketId, content })` is called
- THEN the system creates an optimistic message with `tempId`
- AND sends it via ProviderInterface
- AND returns a `Message` with `providerId` populated after confirmation

#### Scenario: Provider rejects the message

- GIVEN a valid `ticketId` and `content`
- WHEN the provider returns a rejection error
- THEN the system throws `MessageSendFailed` with provider error details
- AND marks the message as `failed` status
- AND does NOT emit duplicate events

### Requirement: RetryMessageUseCase must idempotently retry failed messages

The system SHALL provide a `RetryMessageUseCase` that accepts a `tempId`, retrieves the original message payload, and resubmits through the ProviderInterface.

The use case MUST:
- Retrieve the original message by `tempId` from the optimistic queue
- Maintain the same `tempId` for idempotency
- Return a new `Message` with updated `providerId` after retry
- Throw `MessageNotFound` if `tempId` does not exist in queue

#### Scenario: Retry of failed message succeeds

- GIVEN a message with `tempId` in `failed` status
- WHEN `RetryMessageUseCase.execute({ tempId })` is called
- THEN the system retrieves the original payload
- AND resubmits via ProviderInterface
- AND returns a `Message` with new `providerId`

#### Scenario: Retry with unknown tempId

- GIVEN no message exists for the given `tempId`
- WHEN `RetryMessageUseCase.execute({ tempId })` is called
- THEN the system throws `MessageNotFound`

### Requirement: MarkConversationReadUseCase must update provider and local state

The system SHALL provide a `MarkConversationReadUseCase` that accepts `ticketId`, marks the conversation as read via ProviderInterface, and emits a domain event for SSE broadcast.

The use case MUST:
- Call `provider.markAsRead(ticketId)` to update provider state
- Emit `ConversationMarkedRead` domain event
- Handle provider errors gracefully (log and continue)

#### Scenario: Mark conversation as read successfully

- GIVEN a valid `ticketId`
- WHEN `MarkConversationReadUseCase.execute({ ticketId })` is called
- THEN the provider is notified
- AND `ConversationMarkedRead` event is emitted

### Requirement: HandleInboundMessageUseCase must process inbound provider events

The system SHALL provide a `HandleInboundMessageUseCase` that accepts a provider `InboundEvent`, maps it to a domain `Message`, stores it, and emits an `InboundMessageReceived` domain event.

The use case MUST:
- Parse the inbound payload into domain `Message` format
- Check for duplicate `providerId` before storing
- Store the message in the conversation store
- Emit `InboundMessageReceived` with the stored message

#### Scenario: New inbound message processed

- GIVEN a valid inbound provider event with `providerId`
- WHEN `HandleInboundMessageUseCase.execute({ event })` is called
- AND no existing message has the same `providerId`
- THEN the message is stored in the conversation
- AND `InboundMessageReceived` event is emitted

#### Scenario: Duplicate inbound message ignored

- GIVEN a valid inbound provider event
- WHEN a message with the same `providerId` already exists
- THEN the system ignores the duplicate
- AND does NOT emit a duplicate event

### Requirement: ReconcileDeliveryStatusUseCase must update message state idempotently

The system SHALL provide a `ReconcileDeliveryStatusUseCase` that accepts a `DeliveryStatusEvent` (sent/delivered/read/failed), updates the corresponding message's status, and emits a `MessageStatusUpdated` domain event.

The use case MUST:
- Find the message by `providerId` (reconciled from tempId)
- Validate status transitions per FSM rules
- Reject invalid transitions (e.g., read → delivered)
- Emit `MessageStatusUpdated` with new status

#### Scenario: Valid status transition (sent → delivered)

- GIVEN a message in `sent` status
- WHEN `ReconcileDeliveryStatusUseCase.execute({ providerId, status: 'delivered' })` is called
- THEN the message status is updated to `delivered`
- AND `MessageStatusUpdated` event is emitted

#### Scenario: Invalid status transition rejected

- GIVEN a message in `read` status
- WHEN `ReconcileDeliveryStatusUseCase.execute({ providerId, status: 'delivered' })` is called
- THEN the system rejects the transition
- AND logs a warning with the invalid transition attempt
- AND does NOT emit an event

### Requirement: Application Layer must be the sole entry point for provider interactions

The system MUST ensure that stores and components interact with the ProviderInterface ONLY through Application Layer use cases. Direct API service calls for messaging operations MUST be deprecated.

All provider-bound operations SHALL flow: Store → UseCase → ProviderInterface → Provider

#### Scenario: Store uses Application Layer exclusively

- GIVEN `ConversationStore` needs to send a message
- WHEN sending the message
- THEN `SendMessageUseCase` is called
- AND NOT `MessageApiService.sendMessage()` directly

#### Scenario: SSE events routed through Application Layer

- GIVEN an inbound provider event arrives via SSE
- WHEN the event is processed
- THEN `HandleInboundMessageUseCase` is invoked
- AND the resulting domain event is broadcast via Mercure
- AND the store receives the update via SSE, not direct injection

### Requirement: Application Layer services must be injectable and testable

All use cases MUST be provided via dependency injection, accepting `ProviderInterface` as a constructor dependency.

Use cases MUST NOT have direct dependencies on Angular services, stores, or signals.

#### Scenario: Use case instantiated with provider

- GIVEN `SendMessageUseCase` requires `ProviderInterface`
- WHEN `SendMessageUseCase` is created
- THEN it receives `ProviderInterface` via constructor
- AND can be instantiated with a fake provider in tests