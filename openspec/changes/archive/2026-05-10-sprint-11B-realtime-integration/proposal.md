# Proposal: Sprint 11B — Realtime Integration & Provider Abstraction

## Intent

Connect the Angular CRM UI (Sprint 11A) to the existing backend realtime infrastructure (Outbox + Mercure + SSE) so that events flow incrementally into Signal Stores without full-page reloads or polling. Second goal: establish a provider abstraction layer that decouples the UI from WhatsApp/Meta so real WhatsApp Cloud API integration can be added later without touching the frontend.

## Scope

### In Scope
- `Chat`, `Message`, `RealtimeEvent` models with all fields per sprint spec
- `ProviderInterface` + `FakeWhatsappProvider` for simulation
- SSE service with reconnect, heartbeat, deduplication, and ConnectionStore integration
- Incremental store patching (new message, new chat, FSM change, delivery status) — NO reloads
- Optimistic send flow: tempId → real ID, failed states, retry
- Auto-scroll policy with "New messages ↓" floating button
- Error states for inbox/conversation load, SSE disconnect, send failure
- Debug panel (DEV only)
- Integration testing

### Out of Scope
- Real WhatsApp Cloud API integration
- Meta APIs / webhooks
- Any feature not listed above

## Capabilities

### New Capabilities
- `realtime-sse-connection`: SSE service managing Mercure connection lifecycle, events parsing, deduplication
- `provider-abstraction`: Provider interface + fake provider for future WhatsApp integration
- `optimistic-send-flow`: TempId-based optimistic UI with real backend confirmation
- `incremental-store-patch`: Granular store updates (append, unshift, patch-by-id) without reload
- `auto-scroll-policy`: User-aware scroll behavior with new-messages button
- `realtime-debug-panel`: DEV-only debug overlay showing SSE status, event counts, latency

### Modified Capabilities
- `inbox-store`: Add realtime patching via SSE events
- `conversation-store`: Add realtime patching + optimistic message append
- `connection-store`: Add SSE connection state (online/reconnecting/offline)

## Approach

1. **Models & Contracts**: Define Chat, Message, RealtimeEvent types matching backend event structure
2. **Provider Interface**: Create `ProviderInterface` in `core/providers/`; implement `FakeWhatsappProvider` simulating inbound messages, delivery, read receipts, reconnects, failures
3. **SSE Service**: Angular service wrapping EventSource, tracking `processedEventIds` Set for idempotency, dispatching parsed events to stores
4. **ConnectionStore**: Track SSE connection state; update on open/close/reconnect
5. **Inbox Realtime Patching**: SSE event → unshift new Chat to InboxStore (no reload)
6. **Conversation Realtime Patching**: SSE event → append/patch message or Chat FSM status
7. **Optimistic Send**: Generate tempId → insert into UI → await backend → replace tempId with real id
8. **Delivery/Read Updates**: SSE event → patch only affected message's deliveryStatus
9. **Auto-scroll**: Track `isAtBottom` signal; auto-scroll if true, show floating button otherwise
10. **Error States**: Each async operation shows error banner with retry action
11. **Debug Panel**: DEV-only floating panel with SSE state, event counter, reconnect count
12. **Integration Testing**: Verify realtime flow, idempotency, reconnect, performance at scale

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/web/src/app/core/models/` | New | Chat, Message, RealtimeEvent models |
| `apps/web/src/app/core/providers/` | New | ProviderInterface, FakeWhatsappProvider |
| `apps/web/src/app/core/services/` | New/Modified | SseService, ConnectionStore |
| `apps/web/src/app/stores/inbox.store.ts` | Modified | Add realtime patch methods |
| `apps/web/src/app/stores/conversation.store.ts` | Modified | Add realtime patch + optimistic flow |
| `apps/web/src/app/features/inbox/` | Modified | Error states, debug panel, auto-scroll |
| `apps/web/src/app/features/chat/` | Modified | Optimistic send, auto-scroll |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| SSE reconnect race conditions cause event duplication | Medium | `processedEventIds` Set with eventId dedup; verify ordering on append |
| Event ordering mismatch after reconnect | Medium | Append with timestamp sort; OnPush + TrackBy for stable renders |
| Fake provider mock timing diverges from real API | Low | Keep mock simple; interface-first design isolates this |
| Performance degradation with 1000 chats hydrated | Low | Max hydration limits; granular patching; OnPush everywhere |

## Rollback Plan

If the SSE integration introduces regressions:
1. Disable SSE service auto-initialization via feature flag (`environment.enableSse: false`)
2. Revert stores to their Sprint 11A state (polling or static data)
3. Provider abstraction remains — only swap `FakeWhatsappProvider` for stub if needed
4. All new models, services, and stores live in new files — safe to delete without touching existing code

## Dependencies

- Backend Outbox + Mercure + SSE running (already implemented per sprint docs)
- Sprint 11A stores and UI components (already implemented per sprint docs)
- Angular 19 with Signals support

## Success Criteria

- [ ] SSE connection established on app boot; ConnectionStore reflects online/reconnecting/offline
- [ ] New message event → appears in conversation without reload
- [ ] New chat event → appears in inbox list without reload
- [ ] FSM status change → patched only on affected Chat
- [ ] Delivery status update → patched only on affected message
- [ ] Optimistic send: tempId visible immediately; replaced by real id after backend confirms
- [ ] Failed send: status='failed', retry button visible, message not removed
- [ ] Auto-scroll triggers only when user is at bottom; floating button shows otherwise
- [ ] SSE reconnects automatically without UI freeze
- [ ] No duplicate messages after reconnect
- [ ] Debug panel (DEV) shows SSE status, event count, last eventId, reconnect count
- [ ] Error states display for each failure scenario with retry actions
- [ ] ProviderInterface defined; FakeWhatsappProvider simulates all event types