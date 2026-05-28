# Proposal: Sprint 11A — CRM Multiagent WhatsApp UI

## Intent

Implement the complete CRM WhatsApp multiagent frontend per the frozen design spec. Builds a 3-column layout (sidebar + chat list + conversation) with theme system, SSE realtime, optimistic UI, and full accessibility — replacing the current scaffold with a production-ready shell.

## Scope

### In Scope
- Shell, layout, and CSS variable/token system
- Theme toggle (light/dark) with smooth transition and localStorage persistence
- Sidebar nav with SSE connection indicator and account footer
- Chat list with CDK Virtual Scroll (64px/item), search, tabs, SLA/FSM/BOT chips, skeletons
- Conversation panel with bubbles, delivery states, empty state, skeletons, composer
- Incremental SSE store updates with `Set<eventId>` idempotency
- Optimistic messages with retry on failure
- Auto-scroll with "new messages" indicator
- Error states with retry per zone
- Responsive: 3 breakpoints (<768px, 768–1199px, ≥1200px)
- Accessibility AA minimum: keyboard nav, focus states, ARIA labels, contrast
- Memory caps: 200 messages/conversation, 1000 chats hydrated

### Out of Scope
- Typing indicator, hover actions, reactions, media preview, audio/video, quick replies, scheduled messages, campaigns, departments, chatbots, tokens, support, subscriptions
- Entry animations, conversation search, context menus, pagination of chat list

## Capabilities

### New Capabilities
- `inbox-ui`: Chat list with virtual scroll, filters, SLA/FSM indicators, skeletons
- `conversation-ui`: Message bubbles, delivery states, empty/skeleton states, composer
- `theme-system`: Light/dark toggle with CSS custom properties, localStorage, transition
- `realtime-shell`: SSE connection indicator (online/reconnecting/offline), incremental store patches
- `ui-layout-shell`: 3-column responsive layout, topbar, sidebar drawer
- `ui-ux-messages`: Optimistic sends, retry on failure, auto-scroll, new-message indicator
- `ui-ux-error-states`: Per-zone error banners with retry actions

### Modified Capabilities
- None (greenfield build)

## Approach

Phased implementation per design spec Section 19:

1. **Shell + CSS** — global styles, CSS variables light/dark, layout 3-col, responsive breakpoints
2. **Theme system** — ThemeStore signal, localStorage, toggle with 200ms transition
3. **Sidebar** — nav items, SSE indicator with pulse animation, account footer, mobile drawer
4. **Chat list** — search sticky, filter tabs, CDK Virtual Scroll, chat card with all chips, skeleton
5. **Conversation** — header, empty/skeleton states, bubbles (agent/client/system), delivery icons
6. **Realtime** — MercureService integration, ConnectionStore, idempotency Set, incremental patches
7. **Auto-scroll** — UiStore isAtBottom tracking, "new messages" indicator
8. **Error states** — per-zone error with retry, SSE offline banner
9. **Mobile** — drawer overlay, single panel, slide transitions
10. **Polish** — keyboard nav, focus outlines, ARIA, edge cases

State migration: existing `StateService` → signal stores (InboxStore, ConversationStore, ConnectionStore, ThemeStore, UiStore). Models extended per spec: `BaseModel` with `id`, `createdAt`, `updatedAt`.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `apps/web/src/app/shell/` | New | Topbar, sidebar, main layout containers |
| `apps/web/src/app/core/models/` | Modified | Add BaseModel, extend Chat/Message interfaces |
| `apps/web/src/app/core/stores/` | New | InboxStore, ConversationStore, ConnectionStore, ThemeStore, UiStore (signals) |
| `apps/web/src/app/features/inbox/` | Modified | Chat list, conversation panel, composer components |
| `apps/web/src/styles/` | New | Global CSS variables, tokens, theme styles, layout utilities |
| `apps/web/src/app/core/realtime/` | Modified | Integrate idempotency Set, ConnectionStore patches |
| `apps/web/src/app/core/api/` | Modified | Migrate to new model contracts |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Model mismatch with API (missing id, timestamps) | High | Add BaseModel fields; validate with API team before impl |
| No CDK installed in project | Med | Install `@angular/cdk` in first shell step |
| SSE idempotency breaks on reconnect | Med | Replay detection via eventId Set; validate MercureService contract |
| Memory growth without caps | Med | Enforce 200 msg / 1000 chat limits in store setters |
| No optimistic rollback UI clarity | Med | Failed messages show ⚠ with retry title; do NOT delete |

## Rollback Plan

1. `git checkout HEAD~1 -- apps/web/src/` — revert to scaffold state
2. Delete `src/styles/theme-*.scss`, `src/app/shell/`, `src/app/core/stores/`
3. Revert model changes if API not yet aligned
4. Uninstall `@angular/cdk` if unused

## Dependencies

- `@angular/cdk` — Virtual Scroll (install pre-phase 4)
- Existing services: `TicketApiService`, `MessageApiService`, `WorkflowApiService`, `MercureService`
- API team: confirm `id`, `createdAt`, `updatedAt` fields in Chat/Message responses

## Success Criteria

- [ ] 3-column layout renders correctly at ≥1200px, 768–1199px, and <768px
- [ ] Theme toggle persists across page reload via localStorage
- [ ] CDK Virtual Scroll renders chat list without jank (64px fixed)
- [ ] SSE events patch stores incrementally without full reload
- [ ] Optimistic message appears immediately; confirmed message replaces via tempId; failed message shows retry
- [ ] Auto-scroll triggers when user is at bottom; indicator shown when not
- [ ] All error states show retry action without blocking layout
- [ ] Keyboard navigation (↑↓ Enter) works in chat list
- [ ] Accessibility: WCAG AA contrast, ARIA labels, role="status" on SSE indicator
- [ ] Memory: ConversationStore never exceeds 200 messages in memory