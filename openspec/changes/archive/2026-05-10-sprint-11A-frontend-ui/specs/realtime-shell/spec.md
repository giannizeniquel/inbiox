# Realtime Shell Specification

## Purpose

Implement SSE (Server-Sent Events) connection management with connection status indicators and incremental store updates.

## Requirements

### Requirement: ConnectionStore

The application MUST provide a ConnectionStore with signal `status: signal<'online'|'reconnecting'|'offline'>` to track SSE connection state.

- GIVEN ConnectionStore exists
- WHEN SSE connects
- THEN `status` signal MUST be 'online'

- GIVEN SSE is attempting to reconnect
- WHEN reconnecting
- THEN `status` signal MUST be 'reconnecting'

- GIVEN SSE is disconnected
- WHEN disconnection occurs
- THEN `status` signal MUST be 'offline'

### Requirement: SSE Connection Indicator

The sidebar MUST display an SSE connection indicator with 8px dot and label. It MUST have role="status" and aria-live="polite" for accessibility.

#### Scenario: Online indicator

- GIVEN ConnectionStore.status is 'online'
- WHEN indicator renders
- THEN dot color MUST be --conn-online (green #22c55e)
- AND label MUST show "Conectado"

#### Scenario: Reconnecting indicator

- GIVEN ConnectionStore.status is 'reconnecting'
- WHEN indicator renders
- THEN dot color MUST be --conn-reconnect (amber #f59e0b)
- AND label MUST show "Reconectando..."
- AND dot MUST have pulse animation (opacity 1→0.3→1, 1.2s infinite)

#### Scenario: Offline indicator

- GIVEN ConnectionStore.status is 'offline'
- WHEN indicator renders
- THEN dot color MUST be --conn-offline (red #ef4444)
- AND label MUST show "Sin conexión"

### Requirement: Incremental Store Updates

SSE events MUST update stores incrementally WITHOUT full list reloads.

- GIVEN a new message event arrives via SSE
- WHEN processing the event
- THEN ConversationStore.messages MUST have the new message pushed
- AND NOT reload all messages

- GIVEN a new chat event arrives via SSE
- WHEN processing the event
- THEN InboxStore.chats MUST have the new chat unshifted
- AND NOT reload all chats

- GIVEN an FSM status change event arrives via SSE
- WHEN processing the event
- THEN the affected chat item in InboxStore MUST be patched
- AND NOT reload the entire list

### Requirement: Event Idempotency

Each SSE event MUST include `eventId`, `aggregateId`, and `occurredAt` fields. The frontend MUST maintain a `Set<string>` of processed eventIds. If an eventId is already in the Set, the event MUST be ignored.

- GIVEN an SSE event with eventId "evt-123"
- WHEN the event arrives
- THEN "evt-123" MUST be added to processed Set
- AND event MUST be processed

- GIVEN an SSE event with eventId "evt-123" arrives again
- WHEN processing
- THEN the event MUST be ignored (duplicate)
- AND Set MUST already contain "evt-123"

#### Scenario: Idempotency Set is per session

- GIVEN the page is refreshed
- WHEN SSE reconnects
- THEN the idempotency Set MUST be cleared
- AND events replay with new eventIds

### Requirement: Automatic Reconnection

When SSE disconnects, the system MUST automatically attempt reconnection with exponential backoff: 1s, 2s, 4s, 8s, max 30s.

- GIVEN SSE disconnects
- WHEN reconnecting
- THEN first retry MUST be after 1s
- AND second retry after 2s
- AND continue doubling until max 30s

### Requirement: Offline Banner

When ConnectionStore.status is 'offline', a banner MUST display "Sin conexión en tiempo real" in the topbar or sidebar.

- GIVEN status is 'offline'
- WHEN rendering
- THEN offline banner MUST appear
- AND display reconnection status

## ADDED Requirements

### Requirement: SSE Service Integration

The existing MercureService MUST be integrated with the new ConnectionStore and idempotency logic.

- GIVEN MercureService receives an event
- WHEN event is processed
- THEN it MUST check idempotency Set before updating stores

### Requirement: REST Fallback

REST API MUST be used for initial hydration and manual fallback when SSE fails.

- GIVEN application initializes
- WHEN first load
- THEN REST MUST hydrate InboxStore and ConversationStore
- AND SSE MUST take over for live updates

- GIVEN SSE is offline
- WHEN user manually refreshes
- THEN REST MUST fetch fresh data