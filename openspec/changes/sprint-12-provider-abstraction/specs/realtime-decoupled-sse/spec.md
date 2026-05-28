# Delta for realtime-decoupled-sse

## ADDED Requirements

### Requirement: SSE events must route through Application Layer

The system SHALL route ALL provider events arriving via SSE through the corresponding Application Layer use cases, never directly modifying stores.

The event flow MUST be: Provider SSE → MercureService → Application Layer use case → Domain event → Store update

#### Scenario: Inbound message via SSE routes through use case

- GIVEN an inbound message event arrives via SSE from Mercure
- WHEN `MercureService` receives the event
- THEN `HandleInboundMessageUseCase.execute({ event })` is invoked
- AND the resulting domain event is broadcast
- AND the store receives the update via standard SSE subscription

#### Scenario: Delivery status via SSE routes through use case

- GIVEN a delivery status event arrives via SSE
- WHEN `MercureService` receives the event
- THEN `ReconcileDeliveryStatusUseCase.execute({ providerId, status })` is invoked
- AND `MessageStatusUpdated` domain event is emitted

### Requirement: MercureService must emit domain events, not store patches

The system SHALL ensure `MercureService` emits typed domain events (`InboundMessageReceived`, `MessageStatusUpdated`, `ConversationMarkedRead`) rather than raw store patch instructions.

Domain events MUST:
- Be serializable for SSE broadcast
- Contain sufficient context for store consumers
- NOT include provider-specific payload details

#### Scenario: Domain event emitted instead of direct patch

- GIVEN `HandleInboundMessageUseCase` processes an inbound message
- WHEN the message is stored
- THEN `MercureService.broadcast('InboundMessageReceived', { message })` is called
- AND the store receives a domain event, not a `{ op: 'add', path: ... }` patch

#### Scenario: Store subscribes to domain event topic

- GIVEN `ConversationStore` subscribes to SSE topics
- WHEN `InboundMessageReceived` domain event is broadcast
- THEN the store updates its signals based on the domain event data

### Requirement: SSE topic authorization must use JWT with provider-specific scopes

The system SHALL protect SSE subscriptions using JWT tokens containing provider-specific authorization claims.

The JWT MUST:
- Include `scope: ['provider:read', 'provider:write']` claims
- Authorize topics based on `organizationId` and `userId`
- Validate token on every SSE connection

#### Scenario: Authorized SSE connection

- GIVEN a valid JWT with `organizationId: 'org-123'`
- WHEN the client connects to `/sse?topic=org-123`
- THEN the connection is authorized
- AND events for that organization are delivered

#### Scenario: Unauthorized SSE connection

- GIVEN a JWT with `organizationId: 'org-456'`
- WHEN the client tries to connect to `/sse?topic=org-123`
- THEN the connection is rejected with 401

### Requirement: Reconnect replay must handle application state correctly

The system SHALL replay missed events on SSE reconnect without causing duplicate UI entries or state corruption.

The system MUST:
- Track last acknowledged event sequence number per client
- Request replay of missed events from Mercure on reconnect
- Apply idempotent reconciliation logic on replayed events

#### Scenario: Reconnect replays missed events

- GIVEN a client disconnects and reconnects
- WHEN the client sends `Last-Event-ID: 'event-99'` header
- THEN Mercure replays events 100 and onwards
- AND `HandleInboundMessageUseCase` deduplicates via `providerId`

#### Scenario: Replay does not cause duplicates

- GIVEN a reconnect replays event 100 (message with `providerId: 'msg-5'`)
- WHEN `providerId: 'msg-5'` already exists in store
- THEN the message is ignored (not duplicated)
- AND the store state remains consistent