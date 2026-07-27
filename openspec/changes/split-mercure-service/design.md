# Design: Split MercureService via EventBus + Store Handlers

## Technical Approach

Introduce `EventBusService` (RxJS `Subject<RealtimeEvent>`) as the single realtime dispatch surface. `MercureService.handleEvent()` is deleted; `applyEvent()` calls `eventBus.emit(event)`. Four stores subscribe in their constructor, filter by `RealtimeEventType`, and self-handle. `DebugPanelComponent` and `TicketListComponent` migrate to the bus. Pure refactor — zero behavioral change.

## Architecture Decisions

### D1: Flat `Subject<RealtimeEvent>` (not per-type channels)

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Flat `Subject` + `filter()` per store | Matches existing `events$`; one allocation | Chosen |
| `Map<RealtimeEventType, Subject>` | N subjects; splits subscribers across N streams | Rejected |

### D2: Store subscription in constructor with `takeUntilDestroyed`

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Constructor + `inject(DestroyRef)` + `takeUntilDestroyed` | Idiomatic; defensive if scope changes | Chosen |
| Explicit `init()` from `appConfig` | More explicit; adds wiring + ordering risk | Rejected |

### D3: MercureService keeps SSE + resync; `applyEvent` is a 3-line emit

| Option | Tradeoff | Decision |
|--------|----------|----------|
| `applyEvent` → metrics + seq + `eventBus.emit`; `events$` → `eventBus.events$` | Stores own dispatch; `handleEvent` deleted (~90 lines) | Chosen |
| Coordinator: `applyEvent` still calls store interfaces | MercureService still couples to store shapes | Rejected |

### D4: Connection lifecycle setters stay direct; counter moves to bus

| Option | Tradeoff | Decision |
|--------|----------|----------|
| Lifecycle (setOnline/setOffline/setReconnecting/setPermanentFailure) stays imperative; counter + lastEventTime move to bus | Lifecycle is connection state-machine, not server-event-driven | Chosen |
| Synthesize "ConnectionEstablished" / "ConnectionLost" events | More uniform, but bus is for *server* realtime events | Rejected |

## Data Flow

```
Mercure SSE ──→ applyEvent ──→ EventBus.emit() ──┬──→ TicketStore        (4 types)
   │                                    │        ├──→ ConversationStore (2 types)
   │                                    │        ├──→ UiStore            (1 type)
   │                                    │        └──→ ConnectionStore    (ALL → counter)
   │                                    │
   │                                    └── events$ passthrough → DebugPanelComponent
   └─ resyncTicket() loop → applyEvent (same path)
```

`processBufferedEvents()` (line 196) calls `applyEvent` in sequence order. EventBus is one `Subject` (FIFO); each store handler is synchronous (signal write) → no interleaving. Resync ordering preserved.

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/web/src/app/core/realtime/event-bus.service.ts` | Create | `Subject<RealtimeEvent>` + `emit()` + `events$` |
| `apps/web/src/app/core/realtime/mercure.service.ts` | Modify | Remove 4 store imports + `Message` + `TicketStatus` + `assertNever`; `applyEvent` calls `eventBus.emit`; `events$` → delegate; `handleEvent` deleted |
| `apps/web/src/app/core/stores/ticket.store.ts` | Modify | Inject `EventBusService` + `DestroyRef`; constructor subscribes; `onRealtimeEvent()` handles TicketCreated / TicketStatusChanged / TicketAssigned / MessageAdded (unread) |
| `apps/web/src/app/core/stores/conversation.store.ts` | Modify | Same pattern; MessageAdded + MessageUpdated |
| `apps/web/src/app/core/stores/ui.store.ts` | Modify | Same pattern; MessageAdded → `notifyNewMessages` |
| `apps/web/src/app/core/stores/connection.store.ts` | Modify | Same pattern; ALL → `incrementEventCount` + `setLastEventTime`; lifecycle setters unchanged |
| `apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.ts` | Modify | Replace per-ticket `mercure.subscribe()` Map with `eventBus.events$.pipe(filter(e => e.ticketId === id), takeUntilDestroyed(destroyRef))` |
| `apps/web/src/app/features/inbox/components/debug-panel/debug-panel.component.ts` | Modify | Replace `mercure.events$` with `eventBus.events$`; signals stay on MercureService |

## Interfaces / Contracts

```typescript
@Injectable({ providedIn: 'root' })
export class EventBusService {
  private readonly subject = new Subject<RealtimeEvent>();
  readonly events$: Observable<RealtimeEvent> = this.subject.asObservable();
  emit(event: RealtimeEvent): void { this.subject.next(event); }
}
```

Store subscription pattern (TicketStore; 4 event types listed, others analogous):
```typescript
this.eventBus.events$.pipe(
  filter(e => e.eventType === RealtimeEventType.TicketCreated
           || e.eventType === RealtimeEventType.TicketStatusChanged
           || e.eventType === RealtimeEventType.TicketAssigned
           || e.eventType === RealtimeEventType.MessageAdded),
  takeUntilDestroyed(inject(DestroyRef)),
).subscribe(e => this.onRealtimeEvent(e));
```

**handleEvent → store migration** (`mercure.service.ts:323-412`):

| Event | Old caller | New owner |
|-------|-----------|-----------|
| MessageAdded | conv.addMessage + ui.notify + ticket.updateUnread | Conv + Ui + Ticket (split) |
| TicketCreated / TicketStatusChanged / TicketAssigned | ticket.addOrUpdateTicket | TicketStore |
| MessageUpdated | conv.updateMessageStatus | ConversationStore |
| ConversationUpdated | no-op | (no subscriber) |
| (post-dispatch) | connection.incrementEventCount | ConnectionStore (ALL) |

`MessageAdded` unread logic (lines 341-347, `activeTicket()` check) moves into `TicketStore.onRealtimeEvent`.

## Angular DI Wiring

EventBusService has no deps; 4 stores + MercureService inject it. No circular DI (stores inject EventBusService only, never MercureService). `AuthStore → MercureService` edge stays for lifecycle. Lazy instantiation preserved — events arriving before any store is injected are dropped (same as today).

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit | `EventBusService` — emit, FIFO, multi-sub | `new EventBusService()`; subscribe; assert |
| Unit | `TicketStore` — 4 event types | `new EventBusService()` + `new TicketStore(bus)`; emit; assert |
| Unit | Conv / Ui / ConnectionStore | Same pattern |
| Unit | `MercureService.applyEvent` calls `eventBus.emit` | Spy on EventBus |
| Integration | 14 existing realtime tests pass | Safety net — proves no behavior change |

## Migration / Rollout

No data migration. No feature flag. Single refactor PR. **Rollback**: restore 4 store imports, re-inline `handleEvent` switch, drop `eventBus` injection. ~120 lines revert.

## Open Questions

- [ ] **Eager store instantiation in `appConfig.providers`?** Prevents missed early events. **Rec: NO** — preserves current behavior.
- [ ] **Remove `mercure.events$` after DebugPanel migrates?** Only consumer. **Rec: YES**.
- [ ] **Per-ticket subscription in TicketListComponent** — keep per-ticket `Map` to preserve `ngOnDestroy` unsubscribe.

## Success Criteria

- `MercureService` has zero store imports
- `handleEvent()` is deleted
- All 14 existing realtime integration tests pass without modification
- `DebugPanelComponent` displays realtime stats unchanged
