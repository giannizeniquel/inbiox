# Exploration: Sprint 11B — Realtime Integration & Provider Abstraction

## Current State

Solid foundation from Sprint 11A exists. The core realtime pipeline pieces are in place.

### Existing SSE/Realtime (GOOD — needs minor refactor)

- **`MercureService`** (`core/realtime/mercure.service.ts`): Full implementation with seq tracking, idempotency via `processedEventIds` Set, exponential backoff reconnection, topic subscription pattern. State signals: `connectionState`, `reconnectCount`, `lastEventTime`. **Does NOT need replacement — needs integration.**

- **`ConnectionStore`** (`core/stores/connection.store.ts`): Minimal signal store with `online|reconnecting|offline` states. Simple and correct.

- **No dedicated `sse.service.ts`**: The spec says to create one, but MercureService already covers this. Recommend renaming MercureService → SseService or wrapping it.

### Current Stores (PARTIAL — dual state problem)

- **`ConversationStore`**: Good signal-based store with optimistic messages, `tempId`, retry, `MAX_MESSAGES=200`. Methods: `addOptimisticMessage`, `confirmMessage`, `updateMessageStatus`, `retryMessage`. **Solid.**

- **`TicketStore`**: Good signal-based store with `MAX_TICKETS=1000`, incremental patching via `updateUnreadCount`, `addOrUpdateTicket`. **Solid.**

- **`StateService`**: Legacy BehaviorSubject service with duplicate logic (tickets$, messages$, addOrUpdateTicket, addMessage). **DUPLICATE — needs deprecation/migration.**

- **`InboxStore`**: Legacy Chat interface store. **DEPRECATED — TicketStore replaces it.**

### Provider Architecture (MISSING — must create)

- **`src/app/core/providers/`**: **DOES NOT EXIST**
- No `provider.interface.ts`
- No `fake-whatsapp.provider.ts`
- UI currently uses API services directly — **MUST abstract**

### Existing Models

| Model | Status | Issues |
|-------|--------|--------|
| `Message` (`message.model.ts`) | ⚠️ Mismatch | Uses `direction: inbound|outbound|system`, spec wants `sender: agent|customer|system`. Otherwise complete (tempId, deliveryStatus, etc.) |
| `Ticket` (`ticket.model.ts`) | ⚠️ Incomplete | Missing: `slaStatus`, `isAutomated`, `customerName`, `externalId` |
| `TicketEvent` | ✓ Good | Has `type`, `ticketId`, `data`, `seq`, `occurredAt` |
| `RealtimeEvent` | ✓ Good | Wrapper with `ticketId`, `event`, `seq` |
| `Chat` (inline in InboxStore) | ⚠️ Legacy | Uses `fsmStatus`, `assignedAgent` — should be `owner`, `status` |

### Debug Tools

- **`DebugPanelComponent`** exists with full integration to MercureService signals. Shows connection state, reconnect count, last event time, recent events list (20 max), toggle connection button.
- **Need to verify template/styles render correctly in dev mode.**

### Error States

- `InboxErrorComponent`: Exists with `@Output() retry`. Minimal implementation.
- `ConversationErrorComponent`: Exists with `@Output() retry`. Minimal implementation.
- Both need integration into page components.

### Auto-scroll

- `NewMessagesIndicatorComponent` exists. **Need scroll tracking integration** to wire it up properly.

## Affected Areas

| File | Why Affected |
|------|-------------|
| `core/realtime/mercure.service.ts` | Already wired to stores — needs `applyStorePatch()` to handle more event types (status, assignment, delivery updates) |
| `core/services/state.service.ts` | Legacy duplicate — should migrate consumers to signal stores |
| `features/inbox/containers/conversation/conversation.component.ts` | Needs to wire ConversationStore + MessageSenderService + SSE events together |
| `features/inbox/components/conversation-panel/conversation-panel.component.ts` | Currently calls API directly — should use MessageSenderService |
| `features/inbox/pages/inbox-page/inbox-page.component.ts` | Needs SSE subscription on init, error state integration |
| `core/models/ticket.model.ts` | Missing fields per spec |
| `core/models/message.model.ts` | Field rename: `direction` → `sender` (or keep and map) |
| `core/providers/` (dir) | **New — must create** |
| `core/api/message-api.service.ts` | Current send endpoint — consumed by MessageSenderService ✓ |
| `app.config.ts` | Need to provide SseService on init |
| `shell/app-shell.component.ts` | SSE should connect on app boot |

## Approaches

### Approach A: Minimal Incremental (Recommended)

Wire existing infrastructure without major refactors:
1. Add missing model fields to Ticket
2. Create provider abstraction (empty interface + fake for now)
3. Extend `applyStorePatch()` in MercureService to handle status changes and delivery updates
4. Migrate conversation panel to use MessageSenderService
5. Connect SSE on app boot
6. Wire error states and new-messages indicator

**Pros:** Lowest risk, builds on what works, clear incremental value  
**Cons:** Legacy StateService persists, slight model mismatch  
**Effort:** Medium

### Approach B: Clean Signal Migration

Full migration from BehaviorSubjects to Signals:
1. Deprecate StateService, migrate all consumers to TicketStore + ConversationStore
2. Rename MercureService → SseService (no functionality change)
3. Create provider abstraction
4. Fix model mismatches per spec
5. Wire everything

**Pros:** Clean architecture, signals everywhere, no duplication  
**Cons:** More refactoring surface, higher risk for same end state  
**Effort:** High

### Approach C: Hybrid (Pragmatic)

Between A and B:
1. Keep StateService but reduce its role (only for computed `currentTicket`)
2. Everything else uses signal stores
3. Create provider abstraction
4. Fix model mismatches incrementally
5. Wire SSE pipeline

**Pros:** Pragmatic balance, clear migration path  
**Cons:** Two state systems coexist temporarily  
**Effort:** Medium

## Recommendation

**Approach A — Minimal Incremental** is the right move for a sprint. The existing infrastructure is solid and well-designed. Focus energy on:
1. Creating the provider abstraction (strategic for WhatsApp later)
2. Fixing the event→store patching gap in MercureService
3. Integrating error states and auto-scroll
4. Connecting SSE on boot

The model mismatches (`direction` vs `sender`, missing Ticket fields) can be addressed in parallel but are not blockers.

## Risks

- **Dual state**: StateService and signal stores both exist — confusion on which to use
- **ConversationPanel bypasses MessageSenderService**: Currently sends directly via API, skipping optimistic flow
- **MercureService `applyStorePatch()` is incomplete**: Only handles unread count on MessageAdded. Status changes and delivery updates do nothing.
- **No auto-scroll scroll-tracking**: NewMessagesIndicatorComponent exists but isn't wired to detect scroll position
- **Debug panel template/styles**: Need to verify they render (not reviewed)
- **SSE not connected on boot**: App config doesn't call `mercure.connect()` — events only flow after manual connection

## Ready for Proposal

**Yes** — the foundation is strong and clear. Next step should be `sdd-propose` with Approach A scope. Focus proposal on:
1. Provider abstraction (provider.interface.ts + fake-whatsapp.provider.ts)
2. MercureService patching enhancement
3. SSE boot integration
4. Error state wiring
5. Auto-scroll policy
6. Optional: model field additions (can defer)

Skip: StateService migration, renaming MercureService (not worth the churn).

---

**Status**: completed  
**Summary**: Explored Sprint 11B scope. Strong foundations exist (MercureService, signal stores, optimistic messaging). Gaps: no provider abstraction, incomplete event patching, SSE not connected on boot, dual state (StateService vs stores), missing Ticket model fields.
**Next**: sdd-propose
**Risks**: Dual state confusion, incomplete applyStorePatch, auto-scroll not wired, debug panel template unchecked
**Skill Resolution**: none — no Project Standards block, no registry found