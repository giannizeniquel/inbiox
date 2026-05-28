# Proposal: Sprint 12 — Provider Abstraction Layer

## Intent

Implement complete provider abstraction architecture per Sprint 12 spec: **UI → Stores → Application Layer → Provider Interface → Provider Implementation**. The existing ProviderInterface exists but is unused — stores and components still call API services directly. This creates a clean separation enabling multi-provider support, testable simulation, and proper separation of concerns.

## Scope

### In Scope
- **Application Layer services**: SendMessageUseCase, RetryMessageUseCase, MarkConversationReadUseCase, HandleInboundMessageUseCase, ReconcileDeliveryStatusUseCase
- **Provider boundary stabilization**: Ensure ALL provider interactions flow through Application Layer, not direct API service calls
- **Message model separation**: Domain Message (pure) vs Provider payload mapping (WhatsAppInboundPayload, WhatsAppDeliveryPayload)
- **SSE decoupling**: Provider Event → Application Layer → Domain Event → SSE broadcast → Store patch
- **FakeWhatsappProvider enhancement**: Configurable delays, duplicates, reconnects, failures, out-of-order statuses, replay
- **Optimistic reconciliation**: tempId ↔ providerId mapping, idempotency, no duplicate UI
- **ADRs**: Document architectural decisions (Application Layer, Provider boundary, SSE decoupling, optimistic strategy, ordering/idempotency)
- **Debug tooling**: Provider status, queue depth, reconnect state, event counts

### Out of Scope
- Meta API real integration
- Media uploads, templates, bots, campaigns
- Full StateService migration (legacy but low priority)
- Multi-provider beyond WhatsApp (future)

## Capabilities

### New Capabilities
- `application-layer-messaging`: Explicit use cases wrapping ProviderInterface with tempId reconciliation
- `provider-simulation-config`: Enhanced FakeWhatsappProvider with configurable failure modes
- `realtime-decoupled-sse`: SSE events flow through provider boundary, not direct store patches
- `message-model-separation`: Clear boundaries between Domain, Provider payload, and UI concerns

### Modified Capabilities
- `realtime-events` (from sprint-11B): SSE events now route through Application Layer, not direct store patch

## Approach

**Thin Application Layer** — create MessageApplicationService wrapping ProviderInterface:
1. Wire existing ProviderInterface (already in DI, unused) into Application Layer
2. Create use cases that wrap provider calls and handle optimistic reconciliation
3. Redirect stores to use Application Layer services instead of direct API calls
4. Enhance FakeWhatsappProvider with failure injection, configurable delays, reconnect simulation
5. Decouple SSE: MercureService events emit to Application Layer → domain events → stores
6. Model separation: create payload interfaces for provider ↔ domain mapping

Migration strategy (critical):
- `MessageApiService` calls → Application Layer (no breaking change — service exists but stores migrate)
- `TicketApiService` calls → Application Layer
- Current stores: inject Application Layer, keep signal architecture, preserve OnPush
- Incremental migration: no full reloads, maintain optimistic flow

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `core/providers/` | Modified | Enhance FakeWhatsappProvider with simulation features |
| `core/application/` | **New** | Application Layer services (use cases) |
| `core/stores/conversation.store.ts` | Modified | Consume Application Layer, not API services |
| `core/stores/ticket.store.ts` | Modified | Consume Application Layer |
| `core/realtime/mercure.service.ts` | Modified | Route SSE events through Application Layer |
| `core/models/message.model.ts` | Modified | Align Message shape (or document mapping) |
| `core/api/message-api.service.ts` | Deprecated | Eventually replaced by Application Layer |
| `debug-panel/` | Enhanced | Provider status, queue, simulation controls |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|-------------|
| Event ordering (out-of-order statuses) | Med | FSM validation: never downgrade (read→delivered = reject) |
| Duplicate delivery | Med | Dedupe via processedEventIds + tempId tracking |
| tempId ↔ providerId reconcile failure | Low | Rollback path: mark failed, allow retry |
| Reconnect replay (SSE) | Low | Sequence tracking, idempotency checks |
| Concurrent updates conflict | Med | Optimistic reconciliation with last-write-wins + conflict UI |
| Message state regression | Low | FSM enforcement on all status transitions |
| Migration breaking existing realtime | Med | Incremental: maintain SSE → store path, add Application Layer in parallel |

## Rollback Plan

If Application Layer integration breaks realtime or optimistic flow:
1. Revert stores to call API services directly (temporary)
2. Keep ProviderInterface in DI unused
3. MercureService continues direct store patching (from sprint-11B)
4. FakeWhatsappProvider remains enhanced (no regression)
5. ADRs remain documenting decision

## Dependencies

- ProviderInterface already defined in `core/providers/provider.interface.ts`
- MercureService already implemented in `core/realtime/mercure.service.ts`
- FakeWhatsappProvider already functional (enhancement only)
- Signal-based stores already working (sprint-11A)

## Success Criteria

- [ ] Application Layer services created with all 5 use cases
- [ ] Stores call Application Layer, not direct API services
- [ ] ProviderInterface actually consumed by stores/components
- [ ] FakeWhatsappProvider supports configurable delay, failure, duplicate, reconnect simulation
- [ ] SSE events route through Application Layer (not direct store patch)
- [ ] Message model separated: Domain Message, Provider payloads, UI model
- [ ] Optimistic reconciliation stable: no duplicates, no flickering, no full reloads
- [ ] ADRs written documenting 5 architectural decisions
- [ ] Debug panel shows provider status, queue depth, simulation controls
- [ ] OnPush stability maintained, no rerender storms