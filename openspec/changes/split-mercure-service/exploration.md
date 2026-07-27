## Exploration: Split MercureService

### Current State

`MercureService` (`apps/web/src/app/core/realtime/mercure.service.ts`, ~598 lines) has **8 distinct responsibilities** crammed into a single Angular injectable:

| # | Responsibility | Methods |
|---|---------------|---------|
| 1 | **SSE Connection Management** | `connect()`, `disconnect()`, raw `fetch` loop with `NgZone.run()` |
| 2 | **JWT Token Management** | `fetchMercureToken()`, `setMercureToken()` |
| 3 | **Reconnection & Circuit Breaker** | `onError()`, `handleError()`, `forceReconnect()`, exponential backoff (max 10 attempts) |
| 4 | **Event Parsing** | `handleMessage()` — SSE line parsing, JSON deser, idempotency via `eventId` Set |
| 5 | **Per-Ticket Gap Detection FSM** | `seqState` Map per ticket (`connected → gap_detected → resyncing`) |
| 6 | **Resync** | `resyncTicket()`, `resyncAllTickets()`, `processBufferedEvents()` — paginated HTTP fetch, buffering |
| 7 | **Event Dispatch to Stores** | `handleEvent()` — switch over `RealtimeEventType`, **directly mutates 3 stores** |
| 8 | **Public Surface** | `isConnected$`, `events$` observables; `connectionState`, `reconnectCount`, `lastEventTime` signals |

### Store Coupling (Direct Mutations in `handleEvent`)

| Store | Called By | What Gets Mutated |
|-------|----------|-------------------|
| `TicketStore` | `handleEvent` | `addOrUpdateTicket()`, `updateUnreadCount()` — line 337-364 |
| `ConversationStore` | `handleEvent` | `addMessage()`, `updateMessageStatus()` — line 337-398 |
| `ConnectionStore` | `connect()`, `handleError()`, `disconnect()`, `handleEvent` | `setOnline()`, `setOffline()`, `setPermanentFailure()`, `setReconnecting()`, `setLastEventTime()`, `incrementEventCount()` — lines 113, 266, 411, 521, 531, 542, 592 |
| `UiStore` | `handleEvent` | `notifyNewMessages()` — line 338 |

This tight coupling means `handleEvent` is a **store bus** in disguise. Adding one new event type requires editing this single file.

### Consumers

| Consumer | What It Uses | Notes |
|----------|-------------|-------|
| `AuthStore` | `connect()`, `disconnect()`, `setMercureToken()` | Lifecycle only |
| `TicketListComponent` | `subscribe()` (deprecated no-op), `events$` (implicitly via subscribe) | Line 111 — deprecated API, subscribe is no-op |
| `DebugPanelComponent` | `events$`, `connectionState`, `reconnectCount`, `lastEventTime`, `isConnected$`, `forceReconnect()`, `connect()`, `disconnect()` | Pure read-only + manual reconnect |
| `ConnectionStore` | (doc only) | No direct import |

### Existing Patterns Already in Codebase

- **RxJS `Subject` / `Observable`** — `events$` and `isConnected$` already expose raw events; `DebugPanelComponent` already subscribes to `events$` to build its own UI list
- **Angular Signals** — stores are already signal-based, not NgRx
- **No EventBus yet** — no central event emitter pattern; stores are mutated directly

### Approaches

1. **EventBus + Store Handlers** — Replace direct store mutations with an `EventBus` (RxJS `Subject<RealtimeEvent>`). Each store subscribes to relevant events and updates itself. `handleEvent()` becomes a pure emit.
   - Pros: Maximum decoupling, stores become self-contained event handlers, easy to add new event types, testable in isolation
   - Cons: Adds indirection layer, stores must be initialized before events arrive, potential ordering issues
   - Effort: **Medium-High** — requires new service + store refactoring

2. **Facade Split (Minimal Change)** — Keep `MercureService` but split only the connection/transport layer into `SSEConnectionService`. `handleEvent` stays but event dispatch is refactored to call store methods via interfaces (not concrete classes).
   - Pros: Smaller surface area, easier to test SSE in isolation, avoids full EventBus indirection
   - Cons: `handleEvent` still owns all dispatch logic; coupling remains, adding event types still requires editing MercureService
   - Effort: **Medium**

3. **Full Split into Focused Services** — Extract: `SSEConnectionService` (connection lifecycle), `JwtTokenService` (token management), `ReconnectionManager` (circuit breaker + backoff), `EventDispatcher` (handles events + resync state machine). `MercureService` becomes a thin coordinator.
   - Pros: Each service is single-responsibility, independently testable, clear ownership boundaries
   - Cons: Most complex refactor, 4+ new files, careful DI wiring needed to avoid circular deps (stores ↔ MercureService)
   - Effort: **High**

### Recommendation

**Approach 1 (EventBus + Store Handlers)** is the recommended path.

Rationale:
- `handleEvent()` is structurally a pub/sub switch — making it a literal event emitter eliminates the coupling without losing functionality
- `events$` is already public and working (`DebugPanelComponent` already uses it)
- Stores already have the methods needed (`addMessage`, `addOrUpdateTicket`, etc.) — they just need to subscribe instead of being called
- Adding new event types today requires editing `handleEvent` in `MercureService` — with EventBus it only requires registering a new store handler
- The stores (`TicketStore`, `ConversationStore`, `ConnectionStore`, `UiStore`) already exist as independent Angular injectables — they can each subscribe to the bus at construction time

### Risks

- **Circular DI**: If stores subscribe to the EventBus injected by `MercureService`, and `MercureService` still injects stores, the initialization order must be validated carefully — stores must not call back into `MercureService` during construction
- **Event ordering during resync**: `processBufferedEvents()` applies buffered SSE events in sequence order; EventBus-based stores must preserve this ordering (likely fine since they're signal-based and synchronous)
- **DebugPanelComponent** currently reads signals directly from `MercureService` (reconnectCount, connectionState, lastEventTime) — these would need to remain accessible on the service or be moved to `ConnectionStore`
- **TicketListComponent** uses deprecated `subscribe()` API — after splitting, this component needs a real subscription to either the EventBus or to the store's event handlers

### Ready for Proposal

**Yes** — Ready for `sdd-propose`.

The change is a refactor with two clean phases: (1) extract `EventBus` as a new injectable service, (2) convert stores to subscribe to the bus instead of being called by `MercureService`. No new features, no behavioral change for users.
