# Proposal: Split MercureService via EventBus + Store Handlers

## Intent

`MercureService` (~598 lines) has 8 responsibilities crammed into one injectable. Its `handleEvent()` method directly mutates 4 stores (TicketStore, ConversationStore, ConnectionStore, UiStore), making it a store bus in disguise. Adding one event type requires editing this single file. Decouple `MercureService` from stores via a central `EventBusService` — stores subscribe to events they care about instead of being called imperatively.

## Scope

### In Scope

- **Phase 1**: Create `EventBusService` — an RxJS `Subject<RealtimeEvent>` injectable with typed event emission
- **Phase 2**: Refactor TicketStore, ConversationStore, ConnectionStore, and UiStore to subscribe to EventBus and handle their own relevant event types
- **Phase 3**: Remove direct store imports from `MercureService.handleEvent()`, making it a pure emit to EventBus
- **Deprecated API migration**: Replace `TicketListComponent`'s deprecated `subscribe()` calls with EventBus subscriptions

### Out of Scope

- Full split into 4 focused services (SSE, JWT, Reconnection, EventDispatcher) — deferred to a future change
- Backend changes
- New event types or realtime features
- DebugPanelComponent signal relocation (retain on MercureService for now)

## Capabilities

### New Capabilities

- `realtime-event-bus`: Central typed event dispatch for the realtime layer. Stores self-subscribe by event type instead of being mutated externally.

### Modified Capabilities

None. Pure refactor — no behavioral change.

## Approach

**EventBus + Store Handlers** (Approach 1 from exploration).

Introduce an `EventBusService` wrapping an RxJS `Subject<RealtimeEvent>`. MercureService calls `eventBus.emit(event)` instead of `store.addOrUpdateTicket(...)`. Each store subscribes at construction time, filtering by `RealtimeEventType`:

```
MercureService → handleEvent → EventBus.emit() → [TicketStore, ConversationStore, UIStore, ConnectionStore]
```

`events$` already exposes raw events to `DebugPanelComponent` — this pattern extends that to stores as first-class consumers.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/web/src/app/core/realtime/event-bus.service.ts` | New | Typed `Subject<RealtimeEvent>` injectable |
| `apps/web/src/app/core/realtime/mercure.service.ts` | Modified | Remove store imports; `handleEvent` becomes pure emit |
| `apps/web/src/app/core/stores/ticket.store.ts` | Modified | Subscribe to EventBus; self-handle ticket events |
| `apps/web/src/app/core/stores/conversation.store.ts` | Modified | Subscribe to EventBus; self-handle message/status events |
| `apps/web/src/app/core/stores/connection.store.ts` | Modified | Subscribe to EventBus; self-handle connection-lifecycle events |
| `apps/web/src/app/core/stores/ui.store.ts` | Modified | Subscribe to EventBus; self-handle notification events |
| `apps/web/src/app/features/tickets/components/ticket-list.component.ts` | Modified | Replace deprecated `subscribe()` with EventBus subscription |

## Risks

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Circular DI (stores ↔ MercureService) | Med | `EventBusService` has zero dependencies; stores inject only EventBus, not MercureService |
| Event ordering during resync (`processBufferedEvents`) | Low | Stores are signal-based and synchronous; EventBus emits are FIFO |
| DebugPanelComponent reads signals from MercureService | Low | Retain `reconnectCount`, `connectionState`, `lastEventTime` on MercureService |

## Decision Points (for spec/design)

- Should EventBus use a flat `Subject` or per-type channels (e.g., `Subject<RealtimeEvent>` vs `Map<EventType, Subject>`)?
- Should stores subscribe in constructor or via an `init()` lifecycle hook?
- Should `MercureService` remain as coordinator or become purely SSE + EventBus?

## Rollback Plan

1. Restore direct store imports in `MercureService.handleEvent()` (the old switch/case)
2. Remove `EventBusService` DI from stores
3. Delete `event-bus.service.ts`
4. No data migration — stores and events are in-memory

## Success Criteria

- [ ] `MercureService` has zero store imports
- [ ] All 14 existing realtime integration tests pass without modification
- [ ] `handleEvent()` has ≤ 5 lines (pure emit)
- [ ] DebugPanelComponent continues to display realtime stats unchanged
