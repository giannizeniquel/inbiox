# Delta for message-model-separation

## ADDED Requirements

### Requirement: Domain Message must be provider-agnostic

The system SHALL define a `Message` interface in the domain layer that contains only CRM-relevant properties, with no WhatsApp-specific fields.

The `Message` interface MUST contain:
- `id`: string (UUID)
- `conversationId`: string
- `content`: string
- `direction`: `inbound | outbound`
- `status`: `pending | sent | delivered | read | failed`
- `createdAt`: Date
- `metadata`: Record<string, unknown> (for extensibility)

The `Message` interface MUST NOT contain:
- `providerId` (reconciled via tempId mapping)
- `waTemplateName` (provider-specific)
- `mediaUrl` (separate domain concept)

#### Scenario: Domain Message created from provider payload

- GIVEN a `WhatsAppInboundPayload` with `from`, `text`, `timestamp`
- WHEN `HandleInboundMessageUseCase` processes the payload
- THEN a `Message` is created with `content: payload.text`
- AND `direction: 'inbound'`
- AND `conversationId` resolved from `from` address

### Requirement: Provider payload interfaces must map to domain

The system SHALL define `WhatsAppInboundPayload` and `WhatsAppDeliveryPayload` interfaces for provider-specific data, with a mapper service to convert to domain types.

Mapper service MUST:
- Accept provider payload and return domain `Message`
- Extract `providerId` for reconciliation
- Normalize timestamp to UTC ISO format
- Handle missing optional fields gracefully

#### Scenario: Inbound payload mapping

- GIVEN `WhatsAppInboundPayload`:
  ```json
  { "from": "+1234567890", "text": "Hello", "timestamp": 1699900000 }
  ```
- WHEN `ProviderPayloadMapper.toMessage(payload)` is called
- THEN it returns `Message` with `content: 'Hello'` and `providerId: extracted`

#### Scenario: Delivery status payload mapping

- GIVEN `WhatsAppDeliveryPayload`:
  ```json
  { "messageId": "wamid.xxx", "status": "delivered", "timestamp": 1699900001 }
  ```
- WHEN `ProviderPayloadMapper.toStatusEvent(payload)` is called
- THEN it returns `{ providerId: 'wamid.xxx', status: 'delivered' }`

### Requirement: Store must use domain Message, not provider payload

The system SHALL ensure that `ConversationStore` and other stores work exclusively with domain `Message` objects, never with provider-specific payloads.

Stores MUST:
- Receive `Message` via SSE domain events
- Transform `Message` to `MessageViewModel` for UI consumption
- Store only domain types in signals

#### Scenario: Store receives domain Message via SSE

- GIVEN `InboundMessageReceived` event with `Message` payload
- WHEN the store processes the event
- THEN it stores the `Message` in its signals
- AND the UI receives a `MessageViewModel`, not raw provider data

### Requirement: UI ViewModel may exist as an optional presentation layer

The system MAY define a `MessageViewModel` interface for UI-specific formatting needs (timestamps, read receipts, avatar URLs).

The `MessageViewModel`:
- Is derived from `Message`, not the source of truth
- Contains formatted strings (`createdAt: '10:30 AM'`)
- May include computed properties (`isOwnMessage: boolean`)

#### Scenario: UI renders MessageViewModel

- GIVEN a `Message` in the store
- WHEN the UI component subscribes to messages
- THEN it receives `MessageViewModel[]` derived from `Message[]`
- AND formats are applied per locale

### Requirement: tempId to providerId reconciliation must be tracked

The system SHALL maintain a `tempIdMap: Map<string, string>` mapping optimistic `tempId` values to confirmed `providerId` values.

The reconciliation map MUST:
- Be stored in `ConversationStore` or dedicated `ReconciliationService`
- Be consulted when processing status updates
- Support rollback if provider rejects the message

#### Scenario: Reconciliation map updated on send confirmation

- GIVEN message sent with `tempId: 'temp-abc'`
- WHEN provider confirms with `providerId: 'wamid.123'`
- THEN `tempIdMap['temp-abc'] = 'wamid.123'`
- AND subsequent status updates use `providerId`

#### Scenario: Lookup providerId from tempId

- GIVEN `tempId: 'temp-abc'` exists in map
- WHEN status update arrives with `wamid.123`
- THEN the system resolves `wamid.123` to update the correct message

### Requirement: Message status FSM must be enforced

The system SHALL enforce valid status transitions via a finite state machine.

Valid transitions:
- `pending` → `sent | failed`
- `sent` → `delivered | failed`
- `delivered` → `read | failed`
- `read` → (terminal, no transitions allowed)
- `failed` → `pending` (retry only)

Invalid transitions MUST be logged and rejected.

#### Scenario: Valid transition accepted

- GIVEN message in `sent` status
- WHEN status update to `delivered` is received
- THEN the transition is applied
- AND event is emitted

#### Scenario: Invalid transition rejected

- GIVEN message in `read` status
- WHEN status update to `delivered` is received
- THEN the transition is rejected
- AND a warning is logged
- AND no event is emitted