# Tasks: Split MercureService via EventBus + Store Handlers

## Phase Overview

| Phase | Focus | Files | Depends On |
|-------|-------|-------|------------|
| 1 | EventBus foundation | 2 (create + test) | — |
| 2 | Store migration (prepare handlers) | 4 stores | Phase 1 |
| 3 | MercureService cleanup + consumer migration | 3 files | Phase 2 |

**Total files touched**: 8 (1 new, 7 modified)
**Estimated effort**: ~2h for a single agent

---

## Phase 1: EventBus Foundation

Zero impact on existing code. EventBusService is an independent injectable with no consumers yet.

### T-01: Create EventBusService + Unit Test

**Files**:
- Create: `apps/web/src/app/core/realtime/event-bus.service.ts`
- Create: `apps/web/src/app/core/realtime/event-bus.service.spec.ts`

**Description**:
Create `EventBusService` — a thin wrapper around `Subject<RealtimeEvent>`:

```typescript
@Injectable({ providedIn: 'root' })
export class EventBusService {
  private readonly subject = new Subject<RealtimeEvent>();
  readonly events$: Observable<RealtimeEvent> = this.subject.asObservable();
  emit(event: RealtimeEvent): void { this.subject.next(event); }
}
```

Unit tests must cover:
1. `emit()` delivers event to subscribers
2. FIFO ordering — events arrive in emission order
3. Multiple subscribers all receive the same event
4. `events$` is an Observable (not Subject) — subscribers cannot emit

**Verification**:
- `npx ng build` passes from `apps/web/`
- `npx ng test --include='**/event-bus.service.spec.ts'` — all tests pass

**Dependencies**: None

- [x] T-01: Create EventBusService + Unit Test

---

## Phase 2: Store Migration

Each store gets an `onRealtimeEvent()` method and subscribes to `EventBusService` in its constructor. The logic is copied from `MercureService.handleEvent()` (lines 323-412).

**IMPORTANT**: During this phase, `MercureService.handleEvent()` still runs (old path). The store subscriptions are prepared but NOT yet triggered because `MercureService.applyEvent()` still calls `handleEvent()` directly and does NOT emit to the bus. This is intentional — no behavioral change until Phase 3 switches the dispatch path.

### T-02: Migrate TicketStore — Subscribe to EventBus

**File**: `apps/web/src/app/core/stores/ticket.store.ts`

**Description**:
1. Inject `EventBusService` and `DestroyRef` via `inject()`
2. Add `onRealtimeEvent(event: RealtimeEvent)` private method that handles:
   - `RealtimeEventType.TicketCreated` → build ticket object from `TicketCreatedPayload`, call `addOrUpdateTicket()`
   - `RealtimeEventType.TicketStatusChanged` → find existing ticket, update status + timestamps via `addOrUpdateTicket()`
   - `RealtimeEventType.TicketAssigned` → find existing ticket, update owner + timestamps via `addOrUpdateTicket()`
   - `RealtimeEventType.MessageAdded` → if NOT active ticket, increment unread count via `updateUnreadCount()`
3. In constructor, subscribe to `eventBus.events$` with `filter()` for the 4 event types above, piped through `takeUntilDestroyed(destroyRef)`
4. Import `RealtimeEvent`, `RealtimeEventType`, and payload types from `../models/realtime-event.model`
5. Import `Message` model for the `MessageAdded` → unread logic (need `ticketId` from payload)

**Logic copied from** `mercure.service.ts:340-347` (unread count) and `mercure.service.ts:351-391` (ticket CRUD).

**Verification**:
- `npx ng build` passes
- Existing `ticket.store.spec.ts` tests still pass (20 tests) — store behavior unchanged for direct method calls

**Dependencies**: T-01

- [x] T-02: Migrate TicketStore — Subscribe to EventBus

---

### T-03: Migrate ConversationStore — Subscribe to EventBus

**File**: `apps/web/src/app/core/stores/conversation.store.ts`

**Description**:
1. Inject `EventBusService` and `DestroyRef` via `inject()`
2. Add `onRealtimeEvent(event: RealtimeEvent)` private method that handles:
   - `RealtimeEventType.MessageAdded` → build `Message` from `MessageAddedPayload`, call `addMessage()`
   - `RealtimeEventType.MessageUpdated` → call `updateMessageStatus(messageId, status)`
3. In constructor, subscribe to `eventBus.events$` with `filter()` for these 2 event types, piped through `takeUntilDestroyed(destroyRef)`

**Logic copied from** `mercure.service.ts:325-348` (MessageAdded → Message construction) and `mercure.service.ts:393-399` (MessageUpdated).

**Verification**:
- `npx ng build` passes
- Existing `conversation.store.spec.ts` tests still pass (24 tests)

**Dependencies**: T-01

- [x] T-03: Migrate ConversationStore — Subscribe to EventBus

---

### T-04: Migrate UiStore — Subscribe to EventBus

**File**: `apps/web/src/app/core/stores/ui.store.ts`

**Description**:
1. Inject `EventBusService` and `DestroyRef` via `inject()`
2. Add `onRealtimeEvent(event: RealtimeEvent)` private method that handles:
   - `RealtimeEventType.MessageAdded` → call `notifyNewMessages()`
3. In constructor, subscribe to `eventBus.events$` with `filter()` for `MessageAdded`, piped through `takeUntilDestroyed(destroyRef)`

**Logic copied from** `mercure.service.ts:338` (`this.uiStore.notifyNewMessages()`).

**Verification**:
- `npx ng build` passes

**Dependencies**: T-01

- [x] T-04: Migrate UiStore — Subscribe to EventBus

---

### T-05: Migrate ConnectionStore — Subscribe to EventBus

**File**: `apps/web/src/app/core/stores/connection.store.ts`

**Description**:
1. Inject `EventBusService` and `DestroyRef` via `inject()`
2. Add `onRealtimeEvent(event: RealtimeEvent)` private method that handles ALL event types:
   - Call `incrementEventCount()`
   - Call `setLastEventTime(Date.now())`
3. In constructor, subscribe to `eventBus.events$` (NO filter — all events), piped through `takeUntilDestroyed(destroyRef)`
4. Lifecycle setters (`setOnline`, `setOffline`, `setReconnecting`, `setPermanentFailure`) remain unchanged — they are called directly by `MercureService` for connection state-machine transitions (design decision D4).

**Logic copied from** `mercure.service.ts:410-411` (post-dispatch counter increment).

**Verification**:
- `npx ng build` passes

**Dependencies**: T-01

- [x] T-05: Migrate ConnectionStore — Subscribe to EventBus

---

### Phase 2 Gate

**Verification**:
- `npx ng build` passes from `apps/web/`
- All 4 stores have `onRealtimeEvent()` methods and constructor subscriptions
- `MercureService.handleEvent()` still exists and still runs (old path active)
- No behavioral change — store subscriptions are dormant (bus not emitting yet)

---

## Phase 3: MercureService Cleanup + Consumer Migration

Switch the dispatch path from `handleEvent()` to `EventBus.emit()`, then clean up consumers.

### T-06: Refactor MercureService — Remove handleEvent, Emit to EventBus

**File**: `apps/web/src/app/core/realtime/mercure.service.ts`

**Description**:
1. Inject `EventBusService` via `inject()`
2. Remove imports: `TicketStore`, `ConversationStore`, `ConnectionStore`, `UiStore`, `Message`, `TicketStatus`, `assertNever`, and all payload types (`MessageAddedPayload`, `TicketCreatedPayload`, `TicketStatusChangedPayload`, `TicketAssignedPayload`, `MessageUpdatedPayload`)
3. Remove field injections: `ticketStore`, `conversationStore`, `connectionStore`, `uiStore` (lines 54-57)
   - **KEEP**: `connectionStore` calls for lifecycle (`setOnline`, `setOffline`, `setReconnecting`, `setPermanentFailure`, `setLastEventTime`) — these are connection state-machine transitions, not event dispatch (design decision D4). So keep `private connectionStore = inject(ConnectionStore)` ONLY for lifecycle setters used in `connect()`, `handleError()`, `disconnect()`, `handleMessage()`, `forceReconnect()`.
   - Actually, re-checking: `connectionStore.setLastEventTime()` is called in `handleMessage()` (line 266). This is connection metadata, not event dispatch. Keep it.
   - `connectionStore.setOnline/setOffline/setReconnecting/setPermanentFailure` are called in connection lifecycle methods. Keep them.
   - So: keep `connectionStore` injection, remove the other 3.
4. Modify `applyEvent()` (line 174):
   - Remove `this.handleEvent(data)` call (line 184)
   - Replace `this.eventsSubject.next(data)` (line 187) with `this.eventBus.emit(data)`
   - Keep metrics increment and seq tracking
5. Delete `handleEvent()` method entirely (lines 323-412, ~90 lines)
6. Replace `events$` getter: `readonly events$: Observable<RealtimeEvent> = this.eventBus.events$` — delegate to bus (keeps backward compat for DebugPanel until T-07)
   - Alternatively, remove `eventsSubject` and `events$` entirely if DebugPanel is migrated in T-07. **Decision**: remove them in T-07 together with DebugPanel migration to keep this task focused.
7. Remove `Message` model import (no longer constructing messages here)
8. Remove `TicketStatus` import (no longer casting status here)

**Verification**:
- `npx ng build` passes
- `MercureService` has zero imports of `TicketStore`, `ConversationStore`, `UiStore`
- `handleEvent` method does not exist in the file
- `applyEvent` calls `eventBus.emit()` instead of `handleEvent()`
- `connectionStore` is still injected (for lifecycle only)

**Dependencies**: T-02, T-03, T-04, T-05

- [x] T-06: Refactor MercureService — Remove handleEvent, Emit to EventBus

---

### T-07: Migrate DebugPanelComponent to EventBusService

**File**: `apps/web/src/app/features/inbox/components/debug-panel/debug-panel.component.ts`

**Description**:
1. Inject `EventBusService` via `inject()`
2. Replace `this.mercure.events$.subscribe(...)` (line 43) with `this.eventBus.events$.subscribe(...)`
3. Remove `mercure.events$` from `MercureService` (cleanup from T-06):
   - Delete `private readonly eventsSubject = new Subject<RealtimeEvent>()` (line 69)
   - Delete `readonly events$: Observable<RealtimeEvent> = this.eventsSubject.asObservable()` (line 72)
   - Remove `Subject` from rxjs imports if no longer used
4. Keep all other MercureService signal references (`connectionState`, `reconnectCount`, `lastEventTime`, `isConnected$`) — these are connection state, not event bus

**Verification**:
- `npx ng build` passes
- DebugPanel displays recent events (same behavior as before)
- `mercure.events$` no longer exists in `MercureService`

**Dependencies**: T-06

- [x] T-07: Migrate DebugPanelComponent to EventBusService

---

### T-08: Migrate TicketListComponent — Replace Deprecated subscribe()

**File**: `apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.ts`

**Description**:
1. Inject `EventBusService` and `DestroyRef` via `inject()`
2. Replace the per-ticket `mercure.subscribe()` Map pattern (lines 106-117) with EventBus subscriptions:
   - Remove `activeSubscriptions` Map and `unsubscribers` array
   - In `subscribeToTickets()`, for each ticket: `eventBus.events$.pipe(filter(e => e.ticketId === id), takeUntilDestroyed(destroyRef)).subscribe(e => this.handleRealtimeEvent(ticket.ticket_id, e))`
   - Use a `Map<string, Subscription>` to track per-ticket subscriptions for cleanup on re-subscribe
3. Update `ngOnDestroy()` to unsubscribe from EventBus subscriptions instead of calling old unsub functions
4. Remove `MercureService` import if no longer used (check: `mercure` is only used for `subscribe()` — if so, remove the injection entirely)
   - **Check**: `mercure` is injected at line 44 and used only at line 111 (`this.mercure.subscribe`). After migration, remove the `MercureService` injection.
5. The `handleRealtimeEvent()` method (lines 119-128) stays the same — it fetches the ticket from API and updates the store.

**Verification**:
- `npx ng build` passes
- `mercure.subscribe` is no longer called anywhere in the codebase
- `TicketListComponent` no longer injects `MercureService`

**Dependencies**: T-06

- [x] T-08: Migrate TicketListComponent — Replace Deprecated subscribe()

---

### T-09: Final Verification + Cleanup

**Description**:
1. Run full build: `npx ng build` from `apps/web/`
2. Run all existing tests: `npx ng test` — verify all 57 store tests pass (auth: 14, conversation: 24, ticket: 19) + new EventBusService test
3. Verify no remaining references to deprecated APIs:
   - `grep -r "mercure.subscribe" apps/web/src/` → 0 results
   - `grep -r "handleEvent" apps/web/src/app/core/realtime/` → 0 results (method deleted)
   - `grep -r "mercure.events\$" apps/web/src/` → 0 results (replaced by eventBus.events$)
4. Verify MercureService has zero store imports:
   - `grep -E "import.*Store" apps/web/src/app/core/realtime/mercure.service.ts` → only `ConnectionStore` (for lifecycle)
5. Verify `EventBusService` is the single dispatch surface:
   - `grep -r "eventBus.emit" apps/web/src/` → only in `MercureService.applyEvent()`
6. Remove deprecated `subscribe()` method from `MercureService` (lines 148-159) — it's a no-op and no longer called

**Verification**:
- `npx ng build` passes with zero errors
- All existing tests pass
- No deprecated API references remain
- `MercureService` line count reduced from ~598 to ~500 lines

**Dependencies**: T-06, T-07, T-08

- [x] T-09: Final Verification + Cleanup

---

## Verification Gate Summary

| Gate | Check | Command |
|------|-------|---------|
| After T-01 | EventBusService builds + tests pass | `npx ng build && npx ng test --include='**/event-bus.service.spec.ts'` |
| After T-02 to T-05 | Each store builds, existing tests pass | `npx ng build && npx ng test` |
| After T-06 | MercureService has no store imports (except ConnectionStore lifecycle), handleEvent deleted | `grep` checks + `npx ng build` |
| After T-07 | DebugPanel uses eventBus.events$, mercure.events$ removed | `npx ng build` |
| After T-08 | TicketListComponent uses EventBus, deprecated subscribe() gone | `npx ng build` |
| After T-09 | Full build + all tests pass, no deprecated APIs | `npx ng build && npx ng test` |

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Double event processing during Phase 2 | Store subscriptions are dormant until T-06 switches dispatch path. No dual-path window. |
| Circular DI | EventBusService has zero deps. Stores inject EventBus only, never MercureService. |
| Event ordering during resync | EventBus is a single Subject (FIFO). Store handlers are synchronous (signal writes). Same ordering as current `handleEvent`. |
| Missed early events (stores not yet instantiated) | Same as current behavior — events before store injection are dropped. No eager instantiation (design OQ-1). |
| DebugPanel breaks during migration | T-07 migrates DebugPanel after T-06. Between T-06 and T-07, `mercure.events$` delegates to `eventBus.events$` (backward compat). |

---

## File Change Summary

| File | Action | Phase |
|------|--------|-------|
| `apps/web/src/app/core/realtime/event-bus.service.ts` | Create | 1 |
| `apps/web/src/app/core/realtime/event-bus.service.spec.ts` | Create | 1 |
| `apps/web/src/app/core/stores/ticket.store.ts` | Modify | 2 |
| `apps/web/src/app/core/stores/conversation.store.ts` | Modify | 2 |
| `apps/web/src/app/core/stores/ui.store.ts` | Modify | 2 |
| `apps/web/src/app/core/stores/connection.store.ts` | Modify | 2 |
| `apps/web/src/app/core/realtime/mercure.service.ts` | Modify | 3 |
| `apps/web/src/app/features/inbox/components/debug-panel/debug-panel.component.ts` | Modify | 3 |
| `apps/web/src/app/features/inbox/containers/ticket-list/ticket-list.component.ts` | Modify | 3 |
