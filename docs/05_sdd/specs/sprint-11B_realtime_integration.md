# SDD Spec: Sprint 11B — Realtime Integration & Provider Abstraction

**Project:** inbiox  
**Artifact Mode:** hybrid  
**Change:** sprint-11B-realtime-integration  
**Created:** 2026-05-10

---

## 1. Provider Interface

### 1.1 Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| PRI-001 | ProviderInterface must define `sendMessage(chatId: string, content: string): Observable<Message>` | MUST |
| PRI-002 | ProviderInterface must define `getConversation(chatId: string): Observable<Message[]>` | MUST |
| PRI-003 | ProviderInterface must define `updateChatStatus(chatId: string, status: FsmStatus): Observable<Chat>` | MUST |
| PRI-004 | ProviderInterface must use generic types, not concrete implementations | MUST |
| PRI-005 | FakeWhatsappProvider must implement all ProviderInterface methods | MUST |
| PRI-006 | FakeWhatsappProvider must accept configuration for response delays and failure rates | MUST |

### 1.2 Scenarios

#### Scenario: Send message via provider

**Given** the user types a message in the conversation panel  
**When** the component calls `provider.sendMessage(chatId, content)`  
**Then** an Observable is returned that emits a Message with `deliveryStatus: 'sending'`  
**And** after the backend confirms, the observable emits the same Message with `deliveryStatus: 'sent'` and real `id`

#### Scenario: Fetch conversation via provider

**Given** the user selects a chat from the inbox  
**When** the component calls `provider.getConversation(chatId)`  
**Then** an Observable is returned that emits an array of Message objects ordered by `createdAt` ascending

#### Scenario: Provider abstraction allows swapping

**Given** the application uses `ProviderInterface` throughout  
**When** the DI token is switched from `FakeWhatsappProvider` to `RealWhatsappProvider`  
**Then** all components continue to work without modification  
**And** no component imports `FakeWhatsappProvider` directly

---

## 2. SSE Service Integration

### 2.1 Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| SSE-001 | SseService must establish connection on application bootstrap | MUST |
| SSE-002 | SseService must connect to the configured Mercure hub URL | MUST |
| SSE-003 | SseService must parse incoming EventSource messages into RealtimeEvent objects | MUST |
| SSE-004 | SseService must maintain a `processedEventIds: Set<string>` for idempotency | MUST |
| SSE-005 | SseService must ignore events with eventId already in `processedEventIds` | MUST |
| SSE-006 | SseService must dispatch parsed events to relevant stores via Signal update | MUST |
| SSE-007 | SseService must implement automatic reconnection with exponential backoff | MUST |
| SSE-008 | SseService must emit connection state changes: `online`, `reconnecting`, `offline` | MUST |
| SSE-009 | ConnectionStore must subscribe to SseService connection state changes | MUST |

### 2.2 Scenarios

#### Scenario: SSE connection established on boot

**Given** the Angular application initializes  
**When** the SseService is instantiated  
**Then** an EventSource connection is opened to the Mercure hub  
**And** the connection state is set to `online` in ConnectionStore

#### Scenario: Event parsing and deduplication

**Given** an SSE event arrives with `eventId: "evt-123"`  
**When** SseService processes the event  
**Then** if `"evt-123"` is NOT in `processedEventIds`, the event is parsed and dispatched  
**And** `"evt-123"` is added to `processedEventIds`  
**But** if `"evt-123"` IS in `processedEventIds`, the event is ignored silently

#### Scenario: Automatic reconnection after disconnect

**Given** the SSE connection is closed unexpectedly  
**When** the EventSource `onerror` handler fires  
**Then** SseService attempts reconnection after 1 second  
**And** if it fails, retry with exponential backoff: 2s, 4s, 8s, max 30s  
**And** after each attempt, connection state is set to `reconnecting`  
**And** after successful reconnect, state is set to `online`

#### Scenario: Connection state exposed to UI

**Given** the SseService is connected  
**When** the ConnectionStore `connectionState` signal is read by a component  
**Then** the value is `'online'`

**Given** the SSE connection is attempting to reconnect  
**When** the ConnectionStore `connectionState` signal is read  
**Then** the value is `'reconnecting'`

**Given** the SSE connection has failed all retry attempts  
**When** the ConnectionStore `connectionState` signal is read  
**Then** the value is `'offline'`

---

## 3. Store Patching

### 3.1 Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| PAT-001 | InboxStore must expose `addChat(chat: Chat): void` for realtime patching | MUST |
| PAT-002 | InboxStore must prepend (unshift) new chats to the existing list | MUST |
| PAT-003 | ConversationStore must expose `appendMessage(message: Message): void` | MUST |
| PAT-004 | ConversationStore must expose `patchMessage(messageId: string, updates: Partial<Message>): void` | MUST |
| PAT-005 | ConversationStore must expose `patchChat(chatId: string, updates: Partial<Chat>): void` | MUST |
| PAT-006 | Store patching must NOT trigger full reload or repaint of entire list | MUST |
| PAT-007 | All store patches must be triggered only by SSE events, never by polling | MUST |

### 3.2 Scenarios

#### Scenario: New message appends to conversation

**Given** the user is viewing a conversation with messages `[{id: '1'}, {id: '2'}]`  
**When** an SSE event `message.created` arrives with message `{id: '3', chatId: 'chat-1'}`  
**Then** ConversationStore appends message `3` to the messages array  
**And** the UI updates reactively without page reload  
**And** the conversation now contains `[{id: '1'}, {id: '2'}, {id: '3'}]`

#### Scenario: New chat appears in inbox

**Given** the user has an inbox with existing chats  
**When** an SSE event `chat.created` arrives with chat `{id: 'new-chat', customerName: 'John'}`  
**Then** InboxStore unshifts the new chat to position 0  
**And** the inbox list updates reactively  
**And** existing chats remain in their relative order

#### Scenario: FSM status change patches only affected chat

**Given** a chat in the inbox has `fsmStatus: 'new'`  
**When** an SSE event `chat.fsm_changed` arrives with `{chatId: 'chat-1', fsmStatus: 'active'}`  
**Then** only the chat with `id: 'chat-1'` is patched  
**And** its `fsmStatus` becomes `'active'`  
**And** no other chats in the inbox are affected

#### Scenario: Delivery status update patches only message

**Given** a message in a conversation has `deliveryStatus: 'sent'`  
**When** an SSE event `message.delivery_updated` arrives with `{messageId: 'msg-1', deliveryStatus: 'read'}`  
**Then** only the message with `id: 'msg-1'` is patched  
**And** its `deliveryStatus` becomes `'read'`  
**And** no other messages in the conversation are affected

---

## 4. Optimistic UI Flow

### 4.1 Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| OPT-001 | On send, a `tempId` (UUID v4) must be generated locally | MUST |
| OPT-002 | The message must be inserted into the UI immediately with `deliveryStatus: 'sending'` | MUST |
| OPT-003 | The tempId must be used as the message key for trackBy | MUST |
| OPT-004 | After backend confirmation, tempId must be replaced with real backend ID | MUST |
| OPT-005 | On backend failure, message status must change to `'failed'` | MUST |
| OPT-006 | Failed messages must remain visible in the conversation | MUST |
| OPT-007 | Failed messages must display a retry button | MUST |
| OPT-008 | Retry must reuse the same tempId or generate a new one | MUST |
| OPT-009 | On retry success, deliveryStatus must update to `'sent'` | MUST |

### 4.2 Scenarios

#### Scenario: Optimistic send with success

**Given** the user types "Hello" and presses send  
**When** the conversation component generates `tempId: "temp-uuid"`  
**Then** the message appears immediately in the UI: `{tempId: "temp-uuid", content: "Hello", deliveryStatus: "sending"}`  
**And** the backend processes the message  
**When** the SSE event `message.created` arrives with `{id: "real-123", tempId: "temp-uuid"}`  
**Then** the message is updated: `{id: "real-123", tempId: undefined, deliveryStatus: "sent"}`

#### Scenario: Optimistic send with failure

**Given** the user types "Hello" and presses send  
**When** the message is sent optimistically  
**Then** it appears with `deliveryStatus: 'sending'`  
**But** the backend returns an error (HTTP 500 or SSE error event)  
**Then** the message status changes to `'failed'`  
**And** the message remains visible in the conversation  
**And** a retry button is displayed next to the message

#### Scenario: Retry after failure

**Given** a message has `deliveryStatus: 'failed'` and retry button is visible  
**When** the user clicks the retry button  
**Then** the message is resent to the backend  
**And** status changes back to `'sending'`  
**When** the retry succeeds  
**Then** status changes to `'sent'` with real ID

#### Scenario: Failed state does not remove message

**Given** a message fails to send  
**When** the failure is processed  
**Then** the message remains in the messages array  
**And** it is NOT removed from the UI  
**And** the user can see the failure state and retry

---

## 5. Auto-scroll Policy

### 5.1 Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| ASC-001 | ConversationComponent must track `isAtBottom: Signal<boolean>` | MUST |
| ASC-002 | `isAtBottom` must be true when scroll position is within 50px of message list bottom | MUST |
| ASC-003 | When `isAtBottom` is true and a new message arrives, auto-scroll to bottom | MUST |
| ASC-004 | When `isAtBottom` is false (user is reading history), do NOT auto-scroll | MUST |
| ASC-005 | When `isAtBottom` becomes false after a new message, show floating button "Nuevos mensajes ↓" | MUST |
| ASC-006 | Clicking the floating button scrolls to bottom and hides the button | MUST |
| ASC-007 | After sending a message, auto-scroll to bottom regardless of prior position | MUST |

### 5.2 Scenarios

#### Scenario: User at bottom receives new message

**Given** the user is scrolled to the bottom of the conversation (`isAtBottom: true`)  
**When** a new message arrives via SSE  
**Then** the conversation automatically scrolls to show the new message  
**And** no floating button appears

#### Scenario: User reading history receives new message

**Given** the user has scrolled up to read older messages (`isAtBottom: false`)  
**When** a new message arrives via SSE  
**Then** the view does NOT auto-scroll  
**And** a floating button "Nuevos mensajes ↓" appears in the bottom area

#### Scenario: User clicks floating button

**Given** the floating button "Nuevos mensajes ↓" is visible  
**When** the user clicks the button  
**Then** the conversation scrolls smoothly to the bottom  
**And** the floating button is hidden  
**And** `isAtBottom` becomes `true`

#### Scenario: User sends a message

**Given** the user is reading older messages (`isAtBottom: false`)  
**When** the user sends a new message  
**Then** regardless of scroll position, the conversation scrolls to bottom  
**And** the sent message is visible immediately

---

## 6. Debug Panel (DEV)

### 6.1 Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| DBG-001 | Debug panel must only render in development mode (`NODE_ENV === 'development'`) | MUST |
| DBG-002 | Debug panel must display current SSE connection state (online/reconnecting/offline) | MUST |
| DBG-003 | Debug panel must display total count of received events | MUST |
| DBG-004 | Debug panel must display count of reconnection attempts | MUST |
| DBG-005 | Debug panel must display latency: time from SSE event to store patch | MUST |
| DBG-006 | Debug panel must display the last processed eventId | MUST |
| DBG-007 | Debug panel must be positioned in the bottom-right corner | MUST |
| DBG-008 | Debug panel must have a toggle to collapse/expand | MUST |
| DBG-009 | Debug panel must NOT impact application performance | MUST |

### 6.2 Scenarios

#### Scenario: Debug panel visible in development

**Given** the application runs with `NODE_ENV === 'development'`  
**When** the app loads  
**Then** the debug panel is rendered in the bottom-right corner  
**And** it shows SSE status, event count, reconnect count, latency

#### Scenario: Debug panel hidden in production

**Given** the application runs with `NODE_ENV === 'production'`  
**When** the app loads  
**Then** the debug panel is NOT rendered  
**And** no debug-related code is bundled

#### Scenario: SSE status updates in debug panel

**Given** the debug panel is visible  
**When** the SSE connection changes state to `'reconnecting'`  
**Then** the panel updates to show `'reconnecting'`  
**And** the reconnect counter increments

#### Scenario: Latency calculation displayed

**Given** an SSE event arrives at timestamp T1  
**When** the event is patched to the store at timestamp T2  
**Then** latency (T2 - T1) is displayed in the debug panel  
**And** the value is updated per event

---

## Integration Notes

### Cross-cutting Concerns

1. **Error Handling**: All store patch methods must gracefully handle missing entities (chat/message not found)
2. **Performance**: Use Angular's `OnPush` change detection everywhere; use `trackBy` in all `*ngFor`
3. **Mobile**: Debug panel should be hidden or minimal on mobile viewports
4. **Testing**: Each scenario above should have a corresponding test case

### File Locations (Expected)

```
apps/web/src/app/core/
├── models/
│   ├── chat.model.ts
│   ├── message.model.ts
│   └── realtime-event.model.ts
├── providers/
│   ├── provider.interface.ts
│   └── fake-whatsapp.provider.ts
└── services/
    ├── sse.service.ts
    └── connection.store.ts

apps/web/src/app/stores/
├── inbox.store.ts (add patch methods)
└── conversation.store.ts (add patch + optimistic methods)

apps/web/src/app/features/
├── inbox/components/inbox.component.ts (debug panel, error states)
├── chat/components/conversation.component.ts (auto-scroll, optimistic send)
```

### Dependencies

- Angular 19 with Signals
- Existing Outbox + Mercure backend (Sprint prior work)
- Sprint 11A stores and UI components

---

## Acceptance Criteria

| Criterion | Validation Method |
|-----------|-------------------|
| ProviderInterface defined and implemented | Unit test: FakeWhatsappProvider passes all interface method tests |
| SSE connects on boot | E2E: Verify connection state = online after app loads |
| Events processed without duplication | Integration: Send same event twice, verify single UI update |
| New message appends incrementally | Integration: Add message via SSE, verify append without reload |
| New chat unshifts to inbox | Integration: Add chat via SSE, verify prepending |
| FSM change patches only affected chat | Integration: Send FSM event, verify only target chat updates |
| Delivery status patches only message | Integration: Send delivery update, verify only target message updates |
| Optimistic send shows immediately | UI test: Send message, verify visible before backend response |
| Failed state shows retry button | UI test: Simulate failure, verify retry button appears |
| Auto-scroll when at bottom | UI test: Scroll to bottom, add message, verify auto-scroll |
| Floating button when not at bottom | UI test: Scroll up, add message, verify button appears |
| Debug panel shows in DEV only | Build test: Verify panel absent in production bundle |