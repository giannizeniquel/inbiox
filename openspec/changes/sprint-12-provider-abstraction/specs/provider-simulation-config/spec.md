# Delta for provider-simulation-config

## ADDED Requirements

### Requirement: FakeWhatsappProvider must support configurable delays

The system SHALL provide a `FakeWhatsappProvider` that accepts a configuration object with `sendDelayMs`, `deliveryDelayMs`, `readDelayMs` parameters controlling timing of status transitions.

The provider MUST:
- Apply configured delays before emitting status events
- Default to 0ms delays if not configured
- Allow per-message override via message metadata

#### Scenario: Outbound message with simulated delay

- GIVEN `FakeWhatsappProvider` configured with `sendDelayMs: 500, deliveryDelayMs: 1000`
- WHEN a message is sent
- THEN `sent` status is emitted after 500ms
- AND `delivered` status is emitted after additional 1000ms

#### Scenario: Immediate delivery when delay is 0

- GIVEN `FakeWhatsappProvider` configured with `deliveryDelayMs: 0`
- WHEN a message is sent
- THEN `delivered` status is emitted without delay

### Requirement: FakeWhatsappProvider must simulate failures

The system SHALL provide configurable failure injection via `failureRate` (0-1), `failureTypes` array, and `timeoutMs` parameters.

The provider MUST:
- Randomly fail messages based on `failureRate`
- Emit `failed` status with error reason
- Support failure types: `network`, `server`, `rate_limit`, `validation`
- Simulate timeout errors after `timeoutMs`

#### Scenario: Random failure injection

- GIVEN `FakeWhatsappProvider` configured with `failureRate: 0.2`
- WHEN 10 messages are sent
- THEN approximately 2 messages fail with `failed` status
- AND remaining messages succeed

#### Scenario: Timeout simulation

- GIVEN `FakeWhatsappProvider` configured with `timeoutMs: 3000`
- WHEN a message send takes longer than 3 seconds
- THEN the provider emits a timeout error
- AND marks the message as `failed` with `timeout` reason

### Requirement: FakeWhatsappProvider must simulate duplicates

The system SHALL provide a `duplicateRate` configuration that causes the provider to emit duplicate `delivered` events for outbound messages.

The provider MUST:
- Track messages that have been delivered
- Randomly emit duplicate delivery events based on `duplicateRate`
- Include a unique `eventId` to allow deduplication by consumers

#### Scenario: Duplicate delivery event emitted

- GIVEN `FakeWhatsappProvider` configured with `duplicateRate: 0.3`
- WHEN a message is delivered
- THEN the consumer may receive 2-3 delivery events for the same message
- AND each event has a unique `eventId`

### Requirement: FakeWhatsappProvider must simulate reconnects

The system SHALL provide `disconnectAfterMessages` and `reconnectDelayMs` parameters that cause the provider to simulate disconnection and reconnection cycles.

The provider MUST:
- Track message count and disconnect after `disconnectAfterMessages`
- Emit `disconnected` status during outage
- Auto-reconnect after `reconnectDelayMs`
- Queue messages during disconnect and flush on reconnect

#### Scenario: Simulated disconnect and reconnect

- GIVEN `FakeWhatsappProvider` configured with `disconnectAfterMessages: 5, reconnectDelayMs: 2000`
- WHEN 5 messages have been sent
- THEN the provider enters `disconnected` state
- AND after 2000ms enters `connected` state
- AND queued messages are flushed

### Requirement: FakeWhatsappProvider must emit out-of-order statuses

The system SHALL provide an `outOfOrderRate` configuration that causes the provider to occasionally emit statuses in invalid sequences.

The provider MUST:
- Randomly delay `delivered` events beyond `read` events
- Emit `read` before `delivered` based on `outOfOrderRate`
- Log when out-of-order events are emitted

#### Scenario: Out-of-order status emission

- GIVEN `FakeWhatsappProvider` configured with `outOfOrderRate: 0.5`
- WHEN a message is sent
- THEN there is a 50% chance `read` status is emitted before `delivered`
- AND the system logs the out-of-order event

### Requirement: FakeWhatsappProvider must support deterministic simulation

The system SHALL provide a `seed` configuration parameter for reproducible simulation runs, and `deterministicMode: true` flag.

The provider MUST:
- Use the `seed` to initialize a deterministic random generator
- Produce identical failure/duplicate sequences across runs when seed is fixed
- Support manual event injection via `injectEvent()` method

#### Scenario: Deterministic failure pattern

- GIVEN `FakeWhatsappProvider` configured with `seed: 12345, failureRate: 0.5`
- WHEN messages are sent on two different runs with same seed
- THEN the same messages fail in both runs

#### Scenario: Manual event injection

- GIVEN a `FakeWhatsappProvider` instance
- WHEN `injectEvent({ type: 'inbound', content: 'test' })` is called
- THEN the injected event is emitted as an inbound message
- AND can be used for testing specific scenarios

### Requirement: FakeWhatsappProvider must track and expose simulation metrics

The system SHALL expose simulation metrics including `messagesSent`, `messagesDelivered`, `messagesFailed`, `duplicatesEmitted`, `disconnectsCount`, `outOfOrderEvents`.

The provider MUST:
- Increment counters on each relevant event
- Expose metrics via `getMetrics()` method
- Reset metrics via `resetMetrics()` method

#### Scenario: Metrics accumulation

- GIVEN a `FakeWhatsappProvider` that has processed 10 messages
- WHEN `getMetrics()` is called
- THEN the returned object shows accumulated counts for all event types

### Requirement: FakeWhatsappProvider configuration must be injectable

The system SHALL allow `ProviderSimulationConfig` to be injected via Angular's DI system with environment-based defaults.

Configuration values MUST be overridable per test scenario.

#### Scenario: Default configuration from environment

- GIVEN `environment.providerSimulation` contains `{ enabled: true, sendDelayMs: 100 }`
- WHEN `FakeWhatsappProvider` is instantiated
- THEN it uses the environment values as defaults

#### Scenario: Test override

- GIVEN a test scenario requiring instant delivery
- WHEN the test configures `deliveryDelayMs: 0` override
- THEN the provider delivers messages immediately in that test