# Proposal: Sprint 12.5 — Realtime Stabilization (Pre-Stress)

## Intent

Fix 5 CRITICAL structural defects that make the Mercure realtime pipeline non-functional. The system currently works only because FakeWhatsappProvider writes directly to stores, bypassing SSE entirely. Before any stress testing or load validation, the pipeline must deliver end-to-end: backend event → Mercure → frontend store.

## Scope

### In Scope (P0 — must fix)
- Unify backend→frontend event contract (C-2): align payload shape, field naming, and event type mapping
- Eliminate dual dispatch in MercureService (C-1): single `handleEvent()` path only
- Fix TicketCreatedEvent ticketId to use actual ticket ID post-flush (C-4)
- Implement post-reconnect state sync: full refetch of tickets + active conversation on `onopen` (C-3)
- Enforce MAX_RETRIES=5 in OutboxPublisherService.handleFailure() (C-5)

### In Scope (P1 — high impact)
- Wire ComposerComponent → MessageSenderService.send() to complete send pipeline (H-3, H-6)
- Remove per-ticket HTTP subscriptions from InboxService (H-4)
- Add ngOnDestroy to InboxPageComponent with mercure.disconnect() (H-5)
- Replace setTimeout retry with RxJS retryWhen + exponential backoff (H-2)

### Out of Scope
- Stress testing, chaos simulation, load scenarios
- Meta WhatsApp Cloud API integration
- New features, cosmetic changes, unrelated refactors
- Frontend test suite creation (deferred to Sprint 13)
- Removing ConversationPanelComponent (deferred; mark deprecated only)

## Capabilities

### New Capabilities
- `realtime-event-contract`: Unified backend→frontend event model with adapter layer and type mapping
- `reconnect-state-sync`: Reconnection protocol with full state refresh on onopen

### Modified Capabilities
- None (existing specs cover only contacts; realtime pipeline has no spec yet)

## Target Architecture

```
Backend: UseCase → EventCollector → OutboxEvent (unified JSON payload)
  → OutboxPublisher (enforced retry, dead-letter on MAX_RETRIES)
  → Mercure Hub → SSE

Frontend: EventSource → MercureService.handleMessage()
  → parse → idempotency → seq validation → handleEvent() (single path)
  → TicketStore / ConversationStore (signal-based, no legacy StateService)
  → UI components

Send: ComposerComponent → MessageSenderService.send()
  → SendMessageUseCase → provider → confirm/retry (RxJS retryWhen)
```

## Root Cause Analysis

| Finding | Root Cause | Why Missed |
|---------|-----------|------------|
| C-1 Dual dispatch | Sprint boundary artifact: `applyStorePatch` added as optimization, `handleEvent` kept from legacy | No integration test verifying single store mutation per event |
| C-2 Contract mismatch | Backend was built before frontend model finalized; no shared contract spec | Mercure events never tested end-to-end with real publisher |
| C-3 No reconnect sync | Sprint scope excluded reconnect edge cases | Explored but deferred; fake provider masked the gap |
| C-4 Wrong ticketId | Event collected pre-flush to stay inside transaction | PHP entity lifecycle assumption not validated |

## Migration Plan

**Phase 1 (Foundation — sequential):** Fix C-2 (contract) → Fix C-1 (single dispatch) — must be done first because dispatch depends on correct parsing. Fix C-4 (ticketId) in parallel.

**Phase 2 (Reconnect — builds on Phase 1):** C-3 (reconnect sync) + H-5 (ngOnDestroy). Fix C-5 (MAX_RETRIES) in parallel.

**Phase 3 (Send pipeline — builds on Phase 2):** H-3/H-6 (wire ComposerComponent) + H-2 (RxJS retry). Remove H-4 (per-ticket subscriptions) in parallel.

## Risks

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Contract change breaks FakeWhatsappProvider | Medium | Fake provider writes directly to stores — no Mercure dependency. Verify still works. |
| Reconnect full-refetch causes latency spike | Low | Fetch only tickets + active conversation; use paginated queries |
| Removing per-ticket subscriptions (H-4) breaks ticket-specific callbacks | Medium | Migrate callbacks to MercureService's events$ observable first |
| Deprecated ConversationPanelComponent still referenced | Low | Audit all template imports before removing references |

## Success Criteria

- [ ] Real Mercure event published by OutboxPublisherService is parsed and applied by frontend MercureService without errors
- [ ] Single store mutation per event (no duplicate messages or ticket updates)
- [ ] TicketCreatedEvent payload contains correct ticket ID (not conversation ID)
- [ ] After 5s disconnect + reconnect, ticket list and active conversation match server state
- [ ] Message typed in ComposerComponent → deliveryStatus transitions to 'sent' within 5s
- [ ] InboxPageComponent destroys EventSource on navigation away (zero leaked connections)
- [ ] OutboxEvent with retry_count ≥ 5 is skipped and logged at error level

## Assumptions

- Mercure hub is operational and reachable at configured URL
- FakeWhatsappProvider remains functional during stabilization (store writes unchanged)
- Backend ~66 tests continue to pass
- JWT topic authorization is correctly configured

## Recommended Implementation Order

1. **Checkpoint Alpha**: Contract unified + single dispatch → verify Mercure events reach stores
2. **Checkpoint Bravo**: Reconnect sync + no connection leaks → verify state survives disconnect
3. **Checkpoint Charlie**: Send pipeline wired + retry working → verify message lifecycle
4. **Final**: All P0 + P1 completed → ready for Sprint 13 (stress testing)
