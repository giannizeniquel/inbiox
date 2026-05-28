# Exploration: Sprint 12 — Provider Abstraction Layer

## Current State

Strong foundation from Sprint 11B. Provider abstraction skeleton exists, MercureService is solid, signal stores are working. Key gap: nothing consumes the ProviderInterface yet — stores and components still talk directly to API services.

### Provider Architecture (PARTIAL — skeleton exists)

- **`core/providers/provider.interface.ts`**: ✅ Full interface defined. Has `sendMessage`, `getMessages`, `getTicket`, `getTickets`, `updateTicketStatus`, `onInboundMessage`. Clean abstraction.
- **`core/providers/fake-whatsapp.provider.ts`**: ✅ Functional mock with configurable `responseDelay` and `failureRate`. Has `simulateInbound()` for testing. Properly registered in `app.config.ts` via `PROVIDER_CONFIG` and `PROVIDER_INTERFACE` tokens.
- **`core/providers/index.ts`**: ✅ Barrel file exporting everything.

### Provider NOT Actually Used

⚠️ **Critical finding**: The ProviderInterface exists but NOTHING consumes it. Components and stores still use:
- `MessageApiService` directly (for send/get messages)
- `TicketApiService` directly (for tickets)
- SSE events patch stores directly, bypassing the provider

**Current flow**: UI → API services / SSE → stores. **Target flow**: UI → Application Layer → ProviderInterface → (FakeWhatsappProvider | RealWhatsAppProvider) → SSE → stores.

### Realtime Pipeline (GOOD — solid)

- **`MercureService`** (`core/realtime/mercure.service.ts`): Complete with idempotency (`processedEventIds`), seq tracking, exponential backoff reconnection, store patching.
- **`ConnectionStore`**: Minimal `online|reconnecting|offline` states. Correct.
- SSE connects on boot via `APP_INITIALIZER` in `app.config.ts`. ✅

### Stores (PARTIAL — dual model mismatch)

- **`ConversationStore`**: Signal-based with `addOptimisticMessage`, `confirmMessage`, `updateMessageStatus`, `retryMessage`. Uses mixed model: `message_id`, `direction`, `body`, `created_at` from legacy plus `id`, `deliveryStatus`, `tempId` from provider spec.
- **`TicketStore`**: Uses backend `Ticket` model with `ticket_id`, `status`, `owner`, `metrics.unread_count`. Has `filteredTickets` computed, `filterCounts`, `totalUnread`.
- **`StateService`**: Legacy BehaviorSubject service. **DEPRECATED but still present.**
- **`InboxStore`**: Legacy Chat interface store. **DEPRECATED.**

### Debug Tools (GOOD)

- **`DebugPanelComponent`**: Already integrated with MercureService signals + ProviderInterface. Shows connection state, reconnect count, event count, provider name. ✅

### Key Architectural Gap: No Application Layer

Sprint 12 spec says: `UI ↔ Stores ↔ Application Layer ↔ ProviderInterface ↔ Provider Implementations`

**No `Application Layer` exists.** Stores call API services directly. The provider is registered in DI but never injected into stores or components for core operations.

### Model Inconsistency

The `Message` model in `core/models/message.model.ts` uses a hybrid shape (old + new fields):
- Has both `message_id: number` AND `id: string`
- Has both `direction: inbound|outbound|system` AND `sender: agent|customer|system`
- Has both `body: string` AND `content: string`

`ProviderInterface.Message` uses: `id`, `sender`, `content`, `tempId`, `ticketId`.

`ConversationStore.addOptimisticMessage` creates: hybrid object with both shapes.

## Affected Areas

| File | Issue |
|------|-------|
| `core/stores/conversation.store.ts` | Uses hybrid Message shape; needs alignment with ProviderInterface |
| `core/stores/ticket.store.ts` | Uses backend Ticket model; needs alignment with ProviderInterface.Ticket |
| `core/api/message-api.service.ts` | Used directly by components instead of ProviderInterface |
| `core/api/ticket-api.service.ts` | Used directly by components instead of ProviderInterface |
| `core/services/state.service.ts` | Legacy duplicate; should be deprecated |
| `core/services/adapter.service.ts` | Purpose unknown; needs investigation |
| **New: Application Layer** | Does NOT exist — must create between stores/providers |

## Approaches

### Approach A: Thin Application Layer (Recommended)

Create a minimal `MessageApplicationService` that wraps `ProviderInterface` and handles:
1. tempId → real ID reconciliation (optimistic flow)
2. Delivery status transitions
3. Retry logic
4. SSE event → store patching integration

Stores and components use this service instead of `MessageApiService` directly.

**Pros:** Minimal surface area, preserves existing code, provider abstraction activates, testable  
**Cons:** Adds a new service to learn  
**Effort:** Medium

### Approach B: Full Store Migration

Migrate ALL stores to consume ProviderInterface directly. Remove `MessageApiService` and `TicketApiService`. Refactor all components.

**Pros:** Clean architecture, no dual path  
**Cons:** High refactoring surface, high risk, more time  
**Effort:** High

### Approach C: Adapter Pattern

Create an `ApiToProviderAdapter` that wraps existing `MessageApiService`/`TicketApiService` behind `ProviderInterface`. Then point stores to use this adapter.

**Pros:** Reuses existing API services, minimal changes to stores  
**Cons:** Indirect — still two layers that could drift; adapter complexity  
**Effort:** Medium

## Recommendation

**Approach A — Thin Application Layer** for Sprint 12:

1. **Create `ApplicationLayerModule` / services**:
   - `MessageApplicationService` — wraps ProviderInterface for send/receive
   - `TicketApplicationService` — wraps ProviderInterface for ticket ops
2. **Align Message model**: Pick one canonical shape (hybrid is fine for now, map in provider)
3. **Wire SSE → Provider → Store**: Make MercureService emit via provider layer
4. **Stabilize FakeWhatsappProvider**: Extend `simulateInbound()` for failure/retry/delayed scenarios
5. **Add structured logging**: `provider.connect`, `provider.send`, `optimistic.reconcile`, etc.
6. **Add debug enhancements**: Provider status, queue depth, reconnect state
7. **Write ADRs**: Document provider abstraction decision, model alignment decision

Skip: StateService migration (not worth the churn), full store refactor.

## Risks

- **Provider not integrated**: ProviderInterface exists but stores use API services directly — abstraction is unused
- **Dual Message model**: `direction` vs `sender`, `body` vs `content` in the same interface — must align
- **No Application Layer**: Spec requires it; nothing exists yet
- **FakeWhatsappProvider limited**: `simulateInbound()` is basic — needs failure injection, delays, out-of-order, duplicates
- **StateService persists**: Legacy BehaviorSubject still in codebase; can cause confusion
- **SSE decoupled from Provider**: MercureService patches stores directly, bypassing provider — per spec this should go through provider

## Ready for Proposal

**Yes** — foundation is solid. Next step should be `sdd-propose` focused on:
1. Application Layer (MessageApplicationService + TicketApplicationService)
2. Message model canonicalization / alignment
3. FakeWhatsappProvider enhancement (failure modes, delays, reconnect simulation)
4. SSE → Provider layer wiring
5. Structured logging
6. ADRs

The sprint's core challenge isn't building the provider — it's wiring everything through it.
