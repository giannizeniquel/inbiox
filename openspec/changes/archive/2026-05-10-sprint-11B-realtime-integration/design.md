# Design: Sprint 11B — Realtime Integration & Provider Abstraction

## Technical Approach

Connect Angular UI to existing Mercure/SSE backend for real-time incremental updates. Refactor existing MercureService to expose granular patching APIs and add Provider abstraction layer for future WhatsApp real integration. The design leverages existing stores (ConversationStore, TicketStore, ConnectionStore) which already have optimistic UI support and connection state management.

## Architecture Decisions

### Decision: MercureService-first SSE Integration

**Choice**: Use existing MercureService as primary SSE service, enhance with Provider-agnostic event dispatch
**Alternatives**: Create new SseService wrapper
**Rationale**: MercureService already implements idempotency (processedEventIds), reconnection (exponential backoff), seq tracking, and store patching. Refactoring it to support generic event dispatch is lower effort than parallel implementation.

### Decision: Provider Interface with FakeWhatsappProvider

**Choice**: Define `ProviderInterface<T>` generic interface, implement `FakeWhatsappProvider` for development
**Alternatives**: Direct API calls from components, hardcoded WhatsApp integration
**Rationale**: Spec requires abstraction before WhatsApp real integration. Components must depend on interface, never concrete provider. This enables swap without UI changes.

### Decision: Optimistic Flow via ConversationStore Methods

**Choice**: Use existing `addOptimisticMessage()`, `confirmMessage()`, `retryMessage()` methods
**Alternatives**: Create separate OptimisticMessageService
**Rationale**: Methods already exist in ConversationStore with exact semantics from spec (tempId generation, status transitions, failure handling). No need to duplicate logic.

### Decision: Debug Panel via Angular Environment Flag

**Choice**: Conditionally render debug panel when `environment.production === false`
**Alternatives**: Runtime toggle, separate build
**Rationale**: Angular environment flag is standard pattern, ensures zero production bundle impact.

### Decision: Auto-scroll via ConversationComponent State

**Choice**: Track `isAtBottom` signal in conversation component, trigger scroll on new messages only when true
**Alternatives**: Global scroll service, scroll on every message
**Rationale**: Simple signal-based approach fits Angular 19 signals paradigm. Avoids cross-component coupling.

## Data Flow

```
Backend Event → Mercure SSE → MercureService.handleMessage()
    ↓ (idempotency check via processedEventIds)
    ↓ (seq validation)
    ↓
ConversationStore / TicketStore.patch() → Signal update
    ↓
Angular UI (OnPush) → Reactive render

Optimistic Flow:
User types → ConversationStore.addOptimisticMessage(tempId)
    ↓ → UI shows immediately (sending)
    ↓ → API call (via Provider or direct)
    ↓ → SSE confirms → ConversationStore.confirmMessage(tempId, real)
    ↓ OR failure → ConversationStore.updateMessageStatus(tempId, 'failed')
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `apps/web/src/app/core/providers/provider.interface.ts` | Create | Generic `ProviderInterface<T>` with sendMessage, getConversation, updateChatStatus |
| `apps/web/src/app/core/providers/fake-whatsapp.provider.ts` | Create | FakeWhatsappProvider implementing interface, configurable delays/failures |
| `apps/web/src/app/core/realtime/mercure.service.ts` | Modify | Refactor to accept generic event handlers; expose `dispatchEvent()` for testing |
| `apps/web/src/app/core/stores/conversation.store.ts` | Modify | Already complete; verify addMessage is append (not prepend) per spec |
| `apps/web/src/app/core/stores/ticket.store.ts` | Modify | Already has addOrUpdateTicket; verify unshifts new tickets to position 0 |
| `apps/web/src/app/features/chat/components/conversation.component.ts` | Modify | Add isAtBottom signal, auto-scroll logic, floating "Nuevos mensajes" button |
| `apps/web/src/app/shell/components/debug-panel.component.ts` | Create | DEV-only panel showing SSE status, event count, reconnect count, latency |
| `apps/web/src/app/app.config.ts` | Modify | Call MercureService.connect() in APP_INITIALIZER |
| `apps/web/environments/environment.ts` | Modify | Add `debugPanel: true` flag |

## Interfaces / Contracts

```typescript
// Provider Interface (new)
export interface ProviderInterface<TMessage, TChat> {
  sendMessage(chatId: string, content: string): Observable<TMessage>;
  getConversation(chatId: string): Observable<TMessage[]>;
  updateChatStatus(chatId: string, status: FsmStatus): Observable<TChat>;
}

// Fake Provider Config (new)
export interface FakeProviderConfig {
  responseDelayMs: number;
  failureRate: number; // 0-1
  simulateReconnect: boolean;
}

// Existing ConversationStore methods (already present)
addOptimisticMessage(tempId: string, content: string, chatId: string): Message;
confirmMessage(tempId: string, confirmed: Message): void;
updateMessageStatus(idOrTempId: string, status: Message['deliveryStatus']): void;
retryMessage(tempId: string): void;

// Existing TicketStore methods (already present)
addOrUpdateTicket(ticket: Ticket): void;
updateUnreadCount(ticketId: string, count: number): void;
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | ProviderInterface methods, fake provider delays/failures | Jasmine spec with configurable delays |
| Unit | ConversationStore optimistic methods | Unit test for tempId lifecycle |
| Integration | SSE event → store patch flow | Mock EventSource, dispatch test events |
| Integration | Idempotency (duplicate eventId ignored) | Send same event twice, verify single patch |
| E2E | Full optimistic send + confirm | Playwright: send message, verify shows immediately, verify updates to 'sent' |
| E2E | Auto-scroll behavior | Scroll up, receive message, verify button appears; click button, verify scroll |

## Migration / Rollout

No migration required. This is net-new functionality building on existing infrastructure. Rollout via standard PR → review → merge flow. Feature flag not needed — debug panel already conditional on environment.

## Open Questions

- [ ] How does FakeWhatsappProvider integrate with SSE confirmation? Should it emit events that MercureService processes, or simulate direct confirmation?
- [ ] Should the debug panel show event history log (last N events) or just aggregated stats? (Suggestion: aggregated stats to avoid performance impact)
- [ ] Is there a backend endpoint to trigger fake inbound messages for testing, or should FakeWhatsappProvider handle this internally? (Suggestion: backend debug endpoint per existing pattern)