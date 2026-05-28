# Design: Sprint 11A — CRM Multiagent WhatsApp UI

## Technical Approach

Greenfield UI rebuild of the WhatsApp CRM frontend following the frozen spec. The 3-column layout (sidebar 220px + chat list 320px + conversation flex) replaces the current flat scaffold. Signal stores replace BehaviorSubject state, SSE patches stores incrementally (no full reload), and optimistic sends provide instant feedback with retry on failure. CDK Virtual Scroll handles chat list performance. Implementation follows the 10-step dependency order from the spec.

## Architecture Decisions

| Decision | Choice | Alternatives | Rationale |
|----------|--------|--------------|-----------|
| Virtual scroll | CDK `ScrollingModule` (itemSize: 64px) | Custom IntersectionObserver | CDK is battle-tested, handles variable-height items, accessibility built-in |
| State migration | Parallel stores + adapter layer | Big-bang replace | Adapter maps API snake_case → camelCase so API services stay unchanged during migration |
| SSE idempotency | `Set<string>` of processed `eventId` (per session) | seq-based (current) | Spec requires `eventId` field; seq is backend-internal, eventId is event-level dedup |
| Theme system | CSS custom properties + `.dark` class on `<html>` | class on `<body>` | Consistent with Angular SSR best practice; avoids `:root` specificity issues |
| Memory caps | Slice in store setters (200 msgs / 1000 chats) | LRU cache | Simple, predictable, zero external dep |
| Optimistic tempId | `crypto.randomUUID()` + `tempId` field on Message | Composite key lookup | Guaranteed unique without coord; `tempId` is nullable so real API msgs have no overhead |

## Data Flow

```
API (snake_case)
    │
    ▼
AdapterService (map to spec models)
    │
    ▼
Signal Stores (InboxStore, ConversationStore, ThemeStore, ConnectionStore, UiStore)
    │
    ├──► Container Components (smart) ──► Presentational Components (pure)
    │
    ▼
MercureService (SSE) ──► Incremental store patches (via eventId Set dedup)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `src/styles/_variables.scss` | Create | Light theme CSS tokens |
| `src/styles/_dark-theme.scss` | Create | Dark theme overrides |
| `src/styles/_layout.scss` | Create | Global layout, z-index, resets |
| `src/styles/_theme-transition.scss` | Create | Controlled 200ms transition |
| `src/styles.scss` | Modify | Import all partials |
| `src/app/shell/topbar/` | Create | Topbar component (48px sticky) |
| `src/app/shell/sidebar/` | Create | Sidebar nav, SSE indicator, footer, drawer |
| `src/app/shell/app-shell.component.ts` | Create | 3-col layout host |
| `src/app/core/models/chat.model.ts` | Create | Chat + BaseModel interfaces per spec |
| `src/app/core/models/message.model.ts` | Modify | Add deliveryStatus, tempId, sentAt, readAt |
| `src/app/core/models/base.model.ts` | Create | BaseModel, SlaStatus, FsmStatus, DeliveryStatus enums |
| `src/app/core/stores/inbox.store.ts` | Create | chats[], activeChat, filter, loading signals |
| `src/app/core/stores/conversation.store.ts` | Create | messages[], loading, hasMore; 200-msg cap |
| `src/app/core/stores/connection.store.ts` | Create | status signal (online/reconnecting/offline) |
| `src/app/core/stores/theme.store.ts` | Create | theme signal + localStorage persistence |
| `src/app/core/stores/ui.store.ts` | Create | hasNewMessages, isAtBottom signals |
| `src/app/core/services/adapter.service.ts` | Create | API snake_case → spec camelCase mapping |
| `src/app/core/realtime/mercure.service.ts` | Modify | Add eventId Set, ConnectionStore patches, incremental store updates |
| `src/app/features/inbox/containers/chat-list.container.ts` | Create | Smart component, connects to InboxStore |
| `src/app/features/inbox/components/chat-card.component.ts` | Create | 64px chat card, presentational |
| `src/app/features/inbox/components/chat-skeleton.component.ts` | Create | 5 shimmer cards |
| `src/app/features/inbox/components/chat-search.component.ts` | Create | Sticky search input |
| `src/app/features/inbox/components/chat-filters.component.ts` | Create | Tabs: Míos / En espera / Interno |
| `src/app/features/inbox/containers/conversation.container.ts` | Create | Smart component, connects to ConversationStore |
| `src/app/features/inbox/components/message-bubble.component.ts` | Create | Agent/client/system bubbles, delivery icons |
| `src/app/features/inbox/components/conversation-skeleton.component.ts` | Create | 4 alternating bubbles |
| `src/app/features/inbox/components/composer.component.ts` | Create | Input + emoji + attach + mic |
| `src/app/features/inbox/components/new-messages-indicator.component.ts` | Create | "↓ Nuevos mensajes" pill |
| `src/app/features/inbox/pages/inbox-page/inbox-page.component.ts` | Modify | Remove debug panel; wire new containers |
| `src/app/features/inbox/components/ticket-list/ticket-list.component.ts` | Delete | Replaced by new chat list |
| `src/app/features/inbox/components/conversation-panel/conversation-panel.component.ts` | Delete | Replaced by new conversation |
| `src/app/features/inbox/components/debug-panel/` | Delete | Debug only |
| `src/app/shared/components/loading-spinner/` | Delete | Not in spec |
| `src/app/app.ts` | Modify | Bootstrap shell + theme init |

## Interfaces / Contracts

```typescript
// core/models/base.model.ts
interface BaseModel { id: string; createdAt: string; updatedAt?: string; }
type SlaStatus = 'ok' | 'warn' | 'breach';
type FsmStatus = 'new' | 'active' | 'waiting' | 'resolved' | 'closed';
type DeliveryStatus = 'sending' | 'sent' | 'read' | 'failed';

interface Chat extends BaseModel {
  contactName: string; contactPhone: string; avatarUrl?: string;
  unreadCount: number; slaStatus: SlaStatus; fsmStatus: FsmStatus;
  isAutomated: boolean; lastMessage: string; lastMessageAt: string;
  connectionName: string; assignedAgent?: string;
}

interface Message extends BaseModel {
  chatId: string; body: string; direction: 'inbound' | 'outbound';
  deliveryStatus: DeliveryStatus; tempId?: string; sentAt?: string; readAt?: string;
}
```

## Testing Strategy

| Layer | What | How |
|-------|------|-----|
| Unit | Stores (signal state, caps, filter logic) | Component test with `provideStore()` |
| Unit | Adapter mapping (snake→camel) | Jasmine unit test per field |
| Unit | Theme toggle + localStorage | `localStorage` mock in test |
| Unit | Message optimistic flow (tempId replace) | Store method unit tests |
| Integration | SSE → store patch | Mock EventSource, verify store mutated |
| E2E | Full flow: load → select → send → SSE confirm | Playwright |
| E2E | Theme toggle persists | `localStorage` assertion |

## Migration / Rollback

No migration required — greenfield build. Existing `StateService`, `TicketListComponent`, and `ConversationPanelComponent` are deleted and replaced. API services (`TicketApiService`, `MessageApiService`, `WorkflowApiService`) remain but receive data through the new `AdapterService` layer that maps snake_case to the spec's camelCase models.

Rollback: `git checkout HEAD~1 -- apps/web/src/app apps/web/src/styles` reverts to scaffold state. No data migration needed.

## Open Questions

- [ ] Confirm backend SSE events include `eventId` field (not just `seq`). MercureService currently uses seq-based dedup — blocking SSE phase.
- [ ] Confirm Chat/Message API responses include `id`, `createdAt`, `updatedAt`. If not, AdapterService needs date generation or we need API team alignment.
- [ ] FAB "+" action: what does "nueva conversación" trigger? New ticket creation? Chat with new contact? Awaiting product input.